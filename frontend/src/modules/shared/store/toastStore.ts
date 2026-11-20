import { create } from 'zustand'

export interface ChatToast {
  id: number
  chatId: number
  title: string
  body: string
  createdAt: number
}

interface ToastState {
  toasts: ChatToast[]
  push: (toast: Omit<ChatToast, 'id' | 'createdAt'>) => void
  dismiss: (id: number) => void
  clear: () => void
}

const TOAST_TTL_MS = 6000

let seq = 1

/**
 * Lightweight global stack of incoming-chat notifications. Rendered by
 * <ToastHost/> so a message is visible on any page of the app, not only
 * while the chat panel is open.
 */
export const useToastStore = create<ToastState>()((set) => ({
  toasts: [],
  push: (toast) => {
    const id = seq++
    set((state) => ({ toasts: [...state.toasts, { ...toast, id, createdAt: Date.now() }] }))
    if (typeof window !== 'undefined') {
      window.setTimeout(() => {
        set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) }))
      }, TOAST_TTL_MS)
    }
  },
  dismiss: (id) => set((state) => ({ toasts: state.toasts.filter((t) => t.id !== id) })),
  clear: () => set({ toasts: [] }),
}))
