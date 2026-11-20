import { useEffect, useMemo, useState } from 'react'
import { Box, Typography } from '@mui/material'
import {
  DndContext,
  PointerSensor,
  KeyboardSensor,
  useSensor,
  useSensors,
  closestCenter,
  type DragEndEvent,
  type DragMoveEvent,
  type DragStartEvent,
} from '@dnd-kit/core'
import {
  SortableContext,
  useSortable,
  sortableKeyboardCoordinates,
  verticalListSortingStrategy,
  arrayMove,
} from '@dnd-kit/sortable'
import { CSS } from '@dnd-kit/utilities'
import type { PageNode } from '@/types'
import PageTreeItem from './PageTreeItem'

const INDENTATION_WIDTH = 16

interface FlatItem {
  node: PageNode
  depth: number
  parentId: number | null
  index: number
}

function flattenTree(nodes: PageNode[], expanded: Set<number>): FlatItem[] {
  const byParent = new Map<number | null, PageNode[]>()
  nodes.forEach((node) => {
    const key = node.parent_id ?? null
    if (!byParent.has(key)) byParent.set(key, [])
    byParent.get(key)!.push(node)
  })
  byParent.forEach((arr) => arr.sort((a, b) => a.position - b.position || a.id - b.id))

  const result: FlatItem[] = []
  const walk = (parentId: number | null, depth: number) => {
    const children = byParent.get(parentId) ?? []
    children.forEach((node, index) => {
      result.push({ node, depth, parentId, index })
      if (expanded.has(node.id)) walk(node.id, depth + 1)
    })
  }
  walk(null, 0)
  return result
}

function getProjection(
  items: FlatItem[],
  activeId: number,
  overId: number,
  dragOffset: number,
): { depth: number; parentId: number | null } {
  const overItemIndex = items.findIndex((i) => i.node.id === overId)
  const activeItemIndex = items.findIndex((i) => i.node.id === activeId)
  if (overItemIndex === -1 || activeItemIndex === -1) {
    return { depth: 0, parentId: null }
  }

  const activeItem = items[activeItemIndex]
  const newItems = arrayMove(items, activeItemIndex, overItemIndex)
  const previousItem = newItems[overItemIndex - 1]
  const nextItem = newItems[overItemIndex + 1]

  const dragDepth = Math.round(dragOffset / INDENTATION_WIDTH)
  const projectedDepth = activeItem.depth + dragDepth
  const maxDepth = previousItem ? previousItem.depth + 1 : 0
  const minDepth = nextItem ? nextItem.depth : 0

  let depth = projectedDepth
  if (projectedDepth >= maxDepth) depth = maxDepth
  else if (projectedDepth < minDepth) depth = minDepth

  const parentId = (() => {
    if (depth === 0 || !previousItem) return null
    if (depth === previousItem.depth) return previousItem.parentId
    if (depth > previousItem.depth) return previousItem.node.id
    const ancestor = newItems
      .slice(0, overItemIndex)
      .reverse()
      .find((item) => item.depth === depth)
    return ancestor ? ancestor.parentId : null
  })()

  return { depth, parentId }
}

interface PageTreeProps {
  nodes: PageNode[]
  selectedId?: number | null
  onSelect: (id: number) => void
  onMove: (id: number, parentId: number | null, position: number) => void
  onAddChild: (parentId: number) => void
  onRename?: (node: PageNode) => void
  onPublish?: (node: PageNode) => void
  onManagePermissions?: (node: PageNode) => void
  onExport?: (node: PageNode) => void
  onDelete?: (node: PageNode) => void
}

function SortableRow(props: {
  item: FlatItem
  selectedId?: number | null
  expanded: boolean
  hasChildren: boolean
  onSelect: (id: number) => void
  onToggle: (id: number) => void
  onAddChild: (parentId: number) => void
  onRename?: (node: PageNode) => void
  onPublish?: (node: PageNode) => void
  onManagePermissions?: (node: PageNode) => void
  onExport?: (node: PageNode) => void
  onDelete?: (node: PageNode) => void
}) {
  const { item } = props
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: item.node.id,
  })

  const style = {
    transform: CSS.Translate.toString(transform),
    transition,
  }

  return (
    <div ref={setNodeRef} style={style}>
      <PageTreeItem
        node={item.node}
        depth={item.depth}
        indentationWidth={INDENTATION_WIDTH}
        selected={props.selectedId === item.node.id}
        expanded={props.expanded}
        hasChildren={props.hasChildren}
        onSelect={props.onSelect}
        onToggle={props.onToggle}
        onAddChild={props.onAddChild}
        onRename={props.onRename}
        onPublish={props.onPublish}
        onManagePermissions={props.onManagePermissions}
        onExport={props.onExport}
        onDelete={props.onDelete}
        dragHandleProps={{ ...attributes, ...listeners }}
        isDragging={isDragging}
      />
    </div>
  )
}

export default function PageTree({
  nodes,
  selectedId,
  onSelect,
  onMove,
  onAddChild,
  onRename,
  onPublish,
  onManagePermissions,
  onExport,
  onDelete,
}: PageTreeProps) {
  const [expanded, setExpanded] = useState<Set<number>>(new Set())
  const [offsetLeft, setOffsetLeft] = useState(0)
  const [activeId, setActiveId] = useState<number | null>(null)

  const parentIds = useMemo(() => {
    const ids = new Set<number>()
    nodes.forEach((node) => {
      if (node.parent_id !== null) ids.add(node.parent_id)
    })
    return ids
  }, [nodes])

  // Keep the branches leading to the selected page open.
  useEffect(() => {
    if (selectedId == null) return
    const byId = new Map(nodes.map((node) => [node.id, node]))
    setExpanded((prev) => {
      const next = new Set(prev)
      let current = byId.get(selectedId)
      let guard = 0
      while (current && current.parent_id !== null && guard++ < 200) {
        next.add(current.parent_id)
        current = byId.get(current.parent_id)
      }
      return next
    })
  }, [selectedId, nodes])

  const flattened = useMemo(() => flattenTree(nodes, expanded), [nodes, expanded])

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  )

  const toggle = (id: number) => {
    setExpanded((prev) => {
      const next = new Set(prev)
      if (next.has(id)) next.delete(id)
      else next.add(id)
      return next
    })
  }

  const handleDragStart = (event: DragStartEvent) => {
    setOffsetLeft(0)
    setActiveId(Number(event.active.id))
  }

  const handleDragMove = (event: DragMoveEvent) => {
    setOffsetLeft(event.delta.x)
  }

  const handleDragEnd = (event: DragEndEvent) => {
    setActiveId(null)
    const { active, over } = event
    if (!over || active.id === over.id) return

    const activeIdNum = Number(active.id)
    const overIdNum = Number(over.id)

    const projection = getProjection(flattened, activeIdNum, overIdNum, offsetLeft)
    const activeIndex = flattened.findIndex((i) => i.node.id === activeIdNum)
    const overIndex = flattened.findIndex((i) => i.node.id === overIdNum)
    const reordered = arrayMove(flattened, activeIndex, overIndex)

    let position = 0
    for (const item of reordered) {
      if (item.node.id === activeIdNum) break
      if (item.parentId === projection.parentId) position++
    }

    if (projection.parentId !== null) {
      setExpanded((prev) => new Set(prev).add(projection.parentId as number))
    }

    if (projection.parentId !== null && projection.parentId === activeIdNum) return

    onMove(activeIdNum, projection.parentId, position)
  }

  if (nodes.length === 0) {
    return (
      <Typography variant="body2" color="text.secondary" sx={{ px: 1, py: 2 }}>
        Страниц пока нет
      </Typography>
    )
  }

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={closestCenter}
      onDragStart={handleDragStart}
      onDragMove={handleDragMove}
      onDragEnd={handleDragEnd}
    >
      <SortableContext items={flattened.map((item) => item.node.id)} strategy={verticalListSortingStrategy}>
        <Box>
          {flattened.map((item) => (
            <SortableRow
              key={item.node.id}
              item={item}
              selectedId={activeId === null ? selectedId : selectedId}
              expanded={expanded.has(item.node.id)}
              hasChildren={parentIds.has(item.node.id) || item.node.has_children}
              onSelect={onSelect}
              onToggle={toggle}
              onAddChild={onAddChild}
              onRename={onRename}
              onPublish={onPublish}
              onManagePermissions={onManagePermissions}
              onExport={onExport}
              onDelete={onDelete}
            />
          ))}
        </Box>
      </SortableContext>
    </DndContext>
  )
}
