import { useEffect, useRef, useState } from 'react'
import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { useAuth } from '@/modules/auth/store/authStore'
import type { Chat, Message } from './types'

let notificationAudio: HTMLAudioElement | null = null

const SOUND_KEY = 'chat_sound_enabled'
const VOLUME_KEY = 'chat_sound_volume'

export const soundSettings = {
  get enabled(): boolean {
    if (typeof window === 'undefined') return true
    return window.localStorage.getItem(SOUND_KEY) !== 'false'
  },
  set enabled(value: boolean) {
    if (typeof window === 'undefined') return
    window.localStorage.setItem(SOUND_KEY, String(value))
  },
  get volume(): number {
    if (typeof window === 'undefined') return 0.5
    const raw = window.localStorage.getItem(VOLUME_KEY)
    const parsed = raw ? parseFloat(raw) : 0.5
    return Number.isFinite(parsed) ? Math.min(Math.max(parsed, 0), 1) : 0.5
  },
  set volume(value: number) {
    if (typeof window === 'undefined') return
    window.localStorage.setItem(VOLUME_KEY, String(value))
  },
}

/** Lazily create the shared notification sound instance. */
function getAudio(): HTMLAudioElement | null {
  if (typeof window === 'undefined') return null
  if (!notificationAudio) {
    notificationAudio = new Audio('/sounds/notification.wav')
    notificationAudio.preload = 'auto'
  }
  return notificationAudio
}

export function playNotificationSound(volume?: number): void {
  if (!soundSettings.enabled) return
  const audio = getAudio()
  if (!audio) return
  audio.volume = volume ?? soundSettings.volume
  audio.currentTime = 0
  audio.play().catch(() => {
    /* autoplay may be blocked until first user interaction - ignore */
  })
}

function getEnv() {
  return ((import.meta as any).env || {}) as Record<string, string | undefined>
}

/** Create (or reuse) the shared Echo/Pusher websocket client. */
export function getEcho(token: string): any {
  const w = window as any
  const env = getEnv()

  if (w.Echo && w.__echoToken === token) return w.Echo

  if (w.Echo) {
    try {
      w.Echo.disconnect()
    } catch {
      /* ignore */
    }
  }

  const scheme =
    env.VITE_REVERB_SCHEME ||
    env.VITE_PUSHER_SCHEME ||
    (typeof window !== 'undefined' && window.location.protocol === 'https:' ? 'https' : 'http')
  const forceTLS = scheme === 'https'
  const locationPort = typeof window !== 'undefined' ? window.location.port : ''
  const defaultPort = forceTLS ? 443 : 80
  const wsPort = Number(env.VITE_REVERB_PORT || env.VITE_PUSHER_PORT || locationPort || defaultPort)

  w.Pusher = Pusher
  w.Echo = new Echo({
    broadcaster: 'pusher',
    key: env.VITE_REVERB_KEY || env.VITE_PUSHER_KEY || 'chat-key',
    cluster: env.VITE_REVERB_CLUSTER || env.VITE_PUSHER_CLUSTER || 'mt1',
    wsHost:
      env.VITE_REVERB_HOST ||
      env.VITE_PUSHER_HOST ||
      (typeof window !== 'undefined' ? window.location.hostname : 'localhost'),
    wsPort,
    wssPort: wsPort,
    forceTLS,
    disableStats: true,
    enabledTransports: ['ws', 'wss'],
    authEndpoint: '/broadcasting/auth',
    auth: {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    },
  })
  w.__echoToken = token

  return w.Echo
}

/** Map a broadcast payload to the Message shape used by the UI. */
function toMessage(data: any): Message | null {
  if (!data || !data.id || !data.chat_id) return null
  return {
    id: Number(data.id),
    chat_id: Number(data.chat_id),
    sender_id: Number(data.sender_id ?? data.sender?.id ?? 0),
    parent_id: data.parent_id ?? null,
    body: data.body ?? '',
    type: data.type ?? 'text',
    metadata: data.metadata ?? null,
    edit_count: data.edit_count ?? 0,
    edited_at: data.edited_at ?? null,
    created_at: data.created_at ?? new Date().toISOString(),
    updated_at: data.updated_at,
    sender: data.sender ?? undefined,
    files: data.files ?? [],
    reactions: data.reactions ?? [],
  }
}

/** Insert a message into the cached list of an open chat (no refetch needed). */
function appendMessage(queryClient: ReturnType<typeof useQueryClient>, chatId: number, data: any): void {
  const message = toMessage(data)
  if (!message) return

  queryClient.setQueryData<Message[]>(['chat-messages', chatId], (previous) => {
    if (!previous) return previous
    if (previous.some((item) => item.id === message.id)) return previous
    const next = [...previous, message]
    next.sort((a, b) => a.id - b.id)
    return next
  })
}

/** Reset the unread badge of a chat once it is being read. */
function clearUnread(queryClient: ReturnType<typeof useQueryClient>, chatId: number): void {
  queryClient.setQueryData<Chat[]>(['chat-my'], (previous) =>
    previous?.map((chat) => (chat.id === chatId && chat.unread_count ? { ...chat, unread_count: 0 } : chat)),
  )
}

/** Chat id -> timestamp of the last typing ping (throttled client-side). */
const typingSentAt = new Map<number, number>()
const TYPING_THROTTLE_MS = 2000

/** Fire-and-forget "I am typing" ping; throttled per chat. */
export function sendTyping(chatId: number): void {
  const now = Date.now()
  const last = typingSentAt.get(chatId) ?? 0
  if (now - last < TYPING_THROTTLE_MS) return

  typingSentAt.set(chatId, now)
  apiClient.post(`/v1/chat/${chatId}/typing`).catch(() => {
    /* indicator is best-effort */
  })
}

/**
 * Tracks who is currently typing in the open chat. The name is cleared after
 * three seconds of silence (no explicit "stopped typing" event).
 */
export function useChatTyping(chatId: number | null): string | null {
  const { token, user } = useAuth()
  const [name, setName] = useState<string | null>(null)

  useEffect(() => {
    if (!chatId || !token) {
      setName(null)
      return
    }

    const echo = getEcho(token)
    const channel = echo.private(`chat.${chatId}`)
    let timer: ReturnType<typeof setTimeout> | null = null

    const handle = (payload: any) => {
      const userId = Number(payload?.user_id ?? 0)
      if (userId && userId === user?.id) return

      const display = String(payload?.name ?? '').trim()
      if (!display) return

      setName(display)
      if (timer) clearTimeout(timer)
      timer = setTimeout(() => setName(null), 3000)
    }

    channel.listen('UserTyping', handle)

    return () => {
      if (timer) clearTimeout(timer)
      try {
        // Do not leave the channel: the message subscription uses it too.
        channel.stopListening('UserTyping')
      } catch {
        /* ignore teardown errors */
      }
      setName(null)
    }
  }, [chatId, token, user?.id])

  return name
}

/**
 * Live read positions for a chat: user id -> last read message id. Seeded from
 * the API on open and advanced by the `ChatRead` broadcast.
 */
export function useChatReads(chatId: number | null): Record<number, number> {
  const { token } = useAuth()
  const [reads, setReads] = useState<Record<number, number>>({})

  useEffect(() => {
    if (!chatId || !token) {
      setReads({})
      return
    }

    let cancelled = false

    apiClient
      .get(`/v1/chat/${chatId}/reads`)
      .then((response) => {
        if (cancelled) return
        const map: Record<number, number> = {}
        for (const row of response.data?.data ?? []) {
          map[Number(row.user_id)] = Number(row.last_read_message_id ?? 0)
        }
        setReads(map)
      })
      .catch(() => {
        /* receipts are best-effort */
      })

    const echo = getEcho(token)
    const channel = echo.private(`chat.${chatId}`)

    const handle = (payload: any) => {
      const userId = Number(payload?.user_id ?? 0)
      if (!userId) return
      setReads((prev) => ({ ...prev, [userId]: Number(payload?.last_read_message_id ?? 0) }))
    }

    channel.listen('ChatRead', handle)

    return () => {
      cancelled = true
      try {
        channel.stopListening('ChatRead')
      } catch {
        /* ignore teardown errors */
      }
    }
  }, [chatId, token])

  return reads
}

/**
 * User ids currently online in the open chat (Reverb presence channel).
 */
export function useChatPresence(chatId: number | null): number[] {
  const { token } = useAuth()
  const [online, setOnline] = useState<number[]>([])

  useEffect(() => {
    if (!chatId || !token) {
      setOnline([])
      return
    }

    const echo = getEcho(token)
    const channelName = `chat.${chatId}.presence`
    const channel = echo.join(channelName)

    channel.here((users: any[]) => {
      setOnline((users ?? []).map((member) => Number(member?.id ?? 0)).filter((id) => id > 0))
    })
    channel.joining((member: any) => {
      const id = Number(member?.id ?? 0)
      if (id > 0) setOnline((prev) => (prev.includes(id) ? prev : [...prev, id]))
    })
    channel.leaving((member: any) => {
      const id = Number(member?.id ?? 0)
      setOnline((prev) => prev.filter((value) => value !== id))
    })

    return () => {
      try {
        echo.leave(channelName)
      } catch {
        /* ignore teardown errors */
      }
      setOnline([])
    }
  }, [chatId, token])

  return online
}

/**
 * Subscribes to the private `chat.{chatId}` channel over Laravel Reverb
 * (Pusher protocol). New messages are appended to the cache immediately, so
 * the open chat updates without a page reload.
 */
export function useChatRealtime(chatId: number | null, onDeleted?: () => void, readEnabled = true): void {
  const queryClient = useQueryClient()
  const { token } = useAuth()
  const channelRef = useRef<any>(null)

  useEffect(() => {
    if (!chatId || !token) return

    const echo = getEcho(token)
    const channel = echo.private(`chat.${chatId}`)
    channelRef.current = channel

    const refresh = () => {
      queryClient.invalidateQueries({ queryKey: ['chat-messages', chatId] })
      queryClient.invalidateQueries({ queryKey: ['chat-my'] })
    }

    // Coalesce bursts of edits/deletes into a single refetch.
    let refreshTimer: ReturnType<typeof setTimeout> | null = null
    const scheduleRefresh = () => {
      if (refreshTimer) clearTimeout(refreshTimer)
      refreshTimer = setTimeout(refresh, 350)
    }

    // Mark the chat as read shortly after the user receives a message in it.
    // A message counts as read only while the tab is actually visible.
    let readTimer: ReturnType<typeof setTimeout> | null = null
    const isVisible = () => typeof document === 'undefined' || document.visibilityState === 'visible'

    const markReadNow = () => {
      if (!readEnabled || !isVisible()) return
      apiClient.post(`/v1/chat/${chatId}/read`).catch(() => {
        /* ignore */
      })
    }

    const scheduleRead = () => {
      if (!readEnabled) return
      if (readTimer) clearTimeout(readTimer)
      readTimer = setTimeout(markReadNow, 600)
    }

    const onVisibilityChange = () => {
      if (isVisible()) markReadNow()
    }
    if (typeof document !== 'undefined') {
      document.addEventListener('visibilitychange', onVisibilityChange)
    }

    const handleCreated = (payload: any) => {
      appendMessage(queryClient, chatId, payload)
      clearUnread(queryClient, chatId)
      scheduleRead()
    }

    const handleDeleted = () => {
      if (refreshTimer) clearTimeout(refreshTimer)
      queryClient.invalidateQueries({ queryKey: ['chat-my'] })
      onDeleted?.()
    }

    channel.listen('MessageCreated', handleCreated)
    channel.listen('MessageUpdated', scheduleRefresh)
    channel.listen('MessageDeleted', scheduleRefresh)
    channel.listen('ChatDeleted', handleDeleted)

    return () => {
      if (refreshTimer) clearTimeout(refreshTimer)
      if (readTimer) clearTimeout(readTimer)
      if (typeof document !== 'undefined') {
        document.removeEventListener('visibilitychange', onVisibilityChange)
      }
      try {
        channel.stopListening('MessageCreated')
        channel.stopListening('MessageUpdated')
        channel.stopListening('MessageDeleted')
        channel.stopListening('ChatDeleted')
        echo.leave(`chat.${chatId}`)
      } catch {
        /* ignore teardown errors */
      }
      channelRef.current = null
    }
  }, [chatId, token, queryClient, onDeleted, readEnabled])
}

export interface IncomingChatEvent {
  chatId: number
  title: string
  body: string
}

interface UserRealtimeOptions {
  /** Chat currently visible to the user (null when the chat panel is closed). */
  activeChatId?: number | null
  /** Called for messages that arrive outside the open chat. */
  onIncoming?: (event: IncomingChatEvent) => void
}

/**
 * Subscribes to the private `user.{userId}` channel: keeps the chat list in
 * sync and reports messages that arrive while the user is not reading the chat.
 */
export function useUserRealtime(options: UserRealtimeOptions = {}): void {
  const queryClient = useQueryClient()
  const { token, user } = useAuth()

  // Keep the latest options without resubscribing on every render.
  const optionsRef = useRef(options)
  optionsRef.current = options

  useEffect(() => {
    if (!token || !user?.id) return

    const echo = getEcho(token)
    const channel = echo.private(`user.${user.id}`)

    const refreshNow = () => {
      queryClient.invalidateQueries({ queryKey: ['chat-my'] })
    }

    // Debounce list refreshes triggered by incoming messages.
    let refreshTimer: ReturnType<typeof setTimeout> | null = null
    const scheduleRefresh = () => {
      if (refreshTimer) clearTimeout(refreshTimer)
      refreshTimer = setTimeout(refreshNow, 500)
    }

    const handleCreated = (payload: any) => {
      scheduleRefresh()

      const chatId = Number(payload?.chat_id ?? 0)
      if (!chatId) return

      // If the chat is currently open, reflect the message instantly here too
      // (safety net in case the chat-channel subscription is still catching up).
      if (chatId === optionsRef.current.activeChatId) {
        appendMessage(queryClient, chatId, payload)
        clearUnread(queryClient, chatId)
        return
      }

      const senderName = String(payload?.sender_name ?? '').trim()
      const chatName = String(payload?.chat_name ?? '').trim()
      const chatType = String(payload?.chat_type ?? '')
      const sender = senderName || 'Сообщение'
      const title = chatType === 'dm' ? sender : [sender, chatName].filter(Boolean).join(' · ') || 'Чат'

      const body =
        String(payload?.body_preview ?? '').trim() ||
        String(payload?.body ?? '').trim() ||
        (payload?.type === 'image' ? '📷 Изображение' : payload?.type === 'file' ? '📎 Файл' : 'Новое сообщение')

      optionsRef.current.onIncoming?.({ chatId, title, body })
    }

    channel.listen('ChatDeleted', refreshNow)
    channel.listen('MessageCreated', handleCreated)

    return () => {
      if (refreshTimer) clearTimeout(refreshTimer)
      try {
        channel.stopListening('ChatDeleted')
        channel.stopListening('MessageCreated')
        echo.leave(`user.${user.id}`)
      } catch {
        /* ignore teardown errors */
      }
    }
  }, [token, user?.id, queryClient])
}
