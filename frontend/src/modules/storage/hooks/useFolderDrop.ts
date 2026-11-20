import { useCallback, useState } from 'react'

export interface DroppedFile {
  file: File
  /** Path relative to the dropped root (keeps folder structure). */
  relativePath: string
}

interface DroppedEntry {
  isFile: boolean
  isDirectory: boolean
  fullPath: string
  name: string
  file?: (callback: (file: File) => void, error?: (e: unknown) => void) => void
  createReader?: () => {
    readEntries: (callback: (entries: DroppedEntry[]) => void, error?: (e: unknown) => void) => void
  }
}

function readFile(entry: DroppedEntry): Promise<File | null> {
  return new Promise((resolve) => {
    if (!entry.file) return resolve(null)
    entry.file(
      (file) => resolve(file),
      () => resolve(null),
    )
  })
}

function readEntries(entry: DroppedEntry): Promise<DroppedEntry[]> {
  return new Promise((resolve) => {
    const reader = entry.createReader?.()
    if (!reader) return resolve([])
    const collected: DroppedEntry[] = []
    const readBatch = () => {
      reader.readEntries(
        (batch) => {
          if (!batch.length) return resolve(collected)
          collected.push(...batch)
          readBatch()
        },
        () => resolve(collected),
      )
    }
    readBatch()
  })
}

async function collect(entry: DroppedEntry, prefix: string, out: DroppedFile[]): Promise<void> {
  if (entry.isFile) {
    const file = await readFile(entry)
    if (file) out.push({ file, relativePath: prefix + entry.name })
    return
  }

  if (entry.isDirectory) {
    const children = await readEntries(entry)
    for (const child of children) {
      await collect(child, `${prefix}${entry.name}/`, out)
    }
  }
}

/**
 * Drag & drop of files and whole folders. Folder structure is recovered via the
 * non-standard webkitGetAsEntry() API (the only cross-browser way to read
 * dropped directories).
 */
export function useFolderDrop(onFiles: (files: DroppedFile[]) => void) {
  const [isDragging, setIsDragging] = useState(false)

  const onDragOver = useCallback((event: React.DragEvent) => {
    event.preventDefault()
    if (event.dataTransfer.types.includes('Files')) {
      setIsDragging(true)
      event.dataTransfer.dropEffect = 'copy'
    }
  }, [])

  const onDragLeave = useCallback((event: React.DragEvent) => {
    if (event.currentTarget === event.target) {
      setIsDragging(false)
    }
  }, [])

  const onDrop = useCallback(
    async (event: React.DragEvent) => {
      event.preventDefault()
      setIsDragging(false)

      const items = Array.from(event.dataTransfer.items ?? [])
      const collected: DroppedFile[] = []

      const entryPromises: Promise<void>[] = []
      for (const item of items) {
        const entry = (item as any).webkitGetAsEntry?.() as DroppedEntry | null
        if (entry) {
          entryPromises.push(collect(entry, '', collected))
        }
      }

      if (entryPromises.length) {
        await Promise.all(entryPromises)
      } else {
        for (const file of Array.from(event.dataTransfer.files ?? [])) {
          collected.push({ file, relativePath: file.name })
        }
      }

      if (collected.length) onFiles(collected)
    },
    [onFiles],
  )

  return { isDragging, dropHandlers: { onDragOver, onDragLeave, onDrop } }
}
