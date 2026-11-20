import { useCallback, useRef, useState } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import {
  cancelUpload,
  completeUpload,
  createUploadSession,
  storageKeys,
  uploadChunk,
  uploadSimple,
} from '../api/storageApi'

export type UploadStatus = 'queued' | 'uploading' | 'done' | 'error' | 'conflict' | 'canceled'

export interface UploadItem {
  id: string
  file: File
  folder: string
  relativePath: string
  size: number
  status: UploadStatus
  progress: number
  error?: string
  uploadId?: string
  chunkSize?: number
  totalChunks?: number
  overwrite: boolean
}

const CHUNK_THRESHOLD = 32 * 1024 * 1024
const MAX_PARALLEL = 2

function makeId(): string {
  return `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function renameWithSuffix(relativePath: string): string {
  const slash = relativePath.lastIndexOf('/')
  const dir = slash >= 0 ? relativePath.slice(0, slash + 1) : ''
  const name = slash >= 0 ? relativePath.slice(slash + 1) : relativePath
  const dot = name.lastIndexOf('.')
  const base = dot > 0 ? name.slice(0, dot) : name
  const ext = dot > 0 ? name.slice(dot) : ''

  return `${dir}${base} (копия ${Math.floor(Math.random() * 1000) + 1})${ext}`
}

/**
 * Upload queue with automatic chunking for large files, retries, folder-path
 * preservation and conflict resolution. Queue state lives locally (not in the
 * server cache).
 */
export function useUploadQueue() {
  const queryClient = useQueryClient()
  const [items, setItems] = useState<UploadItem[]>([])
  const itemsRef = useRef<UploadItem[]>([])
  const runningRef = useRef<Set<string>>(new Set())
  const canceledRef = useRef<Set<string>>(new Set())
  // `runItem` and `pump` reference each other; the ref breaks the cycle (and the
  // exhaustive-deps requirement) while keeping the callback identity stable.
  const pumpRef = useRef<() => void>(() => {})

  const sync = useCallback((updater: (list: UploadItem[]) => UploadItem[]) => {
    setItems((prev) => {
      const next = updater(prev)
      itemsRef.current = next
      return next
    })
  }, [])

  const patch = useCallback(
    (id: string, changes: Partial<UploadItem>) => {
      sync((list) => list.map((item) => (item.id === id ? { ...item, ...changes } : item)))
    },
    [sync],
  )

  const invalidate = useCallback(
    (folder: string) => {
      queryClient.invalidateQueries({ queryKey: ['storage', 'list'] })
      queryClient.invalidateQueries({ queryKey: storageKeys.quota() })
      queryClient.invalidateQueries({ queryKey: ['storage', 'audit'] })
      void folder
    },
    [queryClient],
  )

  const runItem = useCallback(
    async (item: UploadItem) => {
      patch(item.id, { status: 'uploading', progress: 0, error: undefined })

      try {
        if (item.size > CHUNK_THRESHOLD) {
          await runChunked(item)
        } else {
          await uploadSimple(item.file, item.folder, item.relativePath, item.overwrite)
          patch(item.id, { status: 'done', progress: 100 })
        }

        invalidate(item.folder)
      } catch (e: any) {
        const status = e?.response?.status
        if (status === 409) {
          patch(item.id, { status: 'conflict', error: 'Файл уже существует' })
          return
        }
        patch(item.id, { status: 'error', error: e?.response?.data?.message || 'Не удалось загрузить файл' })
      } finally {
        runningRef.current.delete(item.id)
        pumpRef.current()
      }

      async function runChunked(target: UploadItem) {
        const session = await createUploadSession({
          path: target.folder,
          name: target.relativePath,
          size: target.size,
          overwrite: target.overwrite,
        })

        patch(target.id, {
          uploadId: session.upload_id,
          chunkSize: session.chunk_size,
          totalChunks: session.total_chunks,
        })

        const received = new Set(session.received)
        const total = session.total_chunks

        for (let index = 0; index < total; index++) {
          if (canceledRef.current.has(target.id)) {
            await cancelUpload(session.upload_id).catch(() => undefined)
            patch(target.id, { status: 'canceled' })
            return
          }

          if (received.has(index)) {
            patch(target.id, { progress: Math.round(((index + 1) / total) * 100) })
            continue
          }

          const start = index * session.chunk_size
          const chunk = target.file.slice(start, Math.min(start + session.chunk_size, target.size))

          await withRetry(() => uploadChunk(session.upload_id, index, chunk))
          patch(target.id, { progress: Math.round(((index + 1) / total) * 100) })
        }

        await completeUpload(session.upload_id)
        patch(target.id, { status: 'done', progress: 100 })
      }
    },
    [invalidate, patch],
  )

  const pump = useCallback(() => {
    const queued = itemsRef.current.filter((item) => item.status === 'queued')
    for (const item of queued) {
      if (runningRef.current.size >= MAX_PARALLEL) break
      if (runningRef.current.has(item.id)) continue
      runningRef.current.add(item.id)
      void runItem(item)
    }
  }, [runItem])

  pumpRef.current = pump

  const enqueue = useCallback(
    (files: { file: File; relativePath: string }[], folder: string, overwrite = false) => {
      if (!files.length) return
      const additions: UploadItem[] = files.map((entry) => ({
        id: makeId(),
        file: entry.file,
        folder,
        relativePath: entry.relativePath || entry.file.name,
        size: entry.file.size,
        status: 'queued',
        progress: 0,
        overwrite,
      }))
      sync((list) => [...list, ...additions])
      // Let state settle, then start pumping.
      setTimeout(() => pump(), 0)
    },
    [pump, sync],
  )

  const resolveConflict = useCallback(
    (id: string, action: 'overwrite' | 'skip' | 'rename') => {
      if (action === 'skip') {
        sync((list) => list.filter((item) => item.id !== id))
        return
      }

      const item = itemsRef.current.find((entry) => entry.id === id)
      if (!item) return

      const updated: Partial<UploadItem> =
        action === 'rename'
          ? { relativePath: renameWithSuffix(item.relativePath), overwrite: false, status: 'queued' }
          : { overwrite: true, status: 'queued' }

      patch(id, { ...updated, error: undefined, progress: 0 })
      setTimeout(() => pump(), 0)
    },
    [patch, pump, sync],
  )

  const cancel = useCallback(
    (id: string) => {
      canceledRef.current.add(id)
      const item = itemsRef.current.find((entry) => entry.id === id)
      if (item?.uploadId) {
        void cancelUpload(item.uploadId).catch(() => undefined)
      }
      if (!runningRef.current.has(id)) {
        patch(id, { status: 'canceled' })
      }
    },
    [patch],
  )

  const clearFinished = useCallback(() => {
    sync((list) => list.filter((item) => !['done', 'canceled'].includes(item.status)))
  }, [sync])

  const remove = useCallback(
    (id: string) => {
      sync((list) => list.filter((item) => item.id !== id))
    },
    [sync],
  )

  return { items, enqueue, resolveConflict, cancel, clearFinished, remove }
}

async function withRetry(action: () => Promise<void>, attempts = 3): Promise<void> {
  let lastError: unknown
  for (let attempt = 0; attempt < attempts; attempt++) {
    try {
      await action()
      return
    } catch (e) {
      lastError = e
      await new Promise((resolve) => setTimeout(resolve, 400 * (attempt + 1)))
    }
  }
  throw lastError
}
