import { create } from 'zustand'
import apiClient from '@/modules/shared/api/apiClient'
import type { Widget } from './types'

let started = false

interface WidgetsState {
  widgets: Widget[]
  loaded: boolean
  load: () => Promise<void>
}

/**
 * Embeddable iframe panels configured in the admin panel. Loaded once; on
 * failure the list stays empty (the menu entry is simply hidden).
 */
export const useWidgetsStore = create<WidgetsState>((set) => ({
  widgets: [],
  loaded: false,
  load: async () => {
    if (started) return
    started = true

    try {
      const response = await apiClient.get('/v1/widgets')
      const widgets = (response.data?.data ?? []) as Widget[]
      set({ widgets: Array.isArray(widgets) ? widgets : [], loaded: true })
    } catch {
      set({ widgets: [], loaded: true })
    }
  },
}))
