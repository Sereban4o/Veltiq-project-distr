import { useEffect } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/modules/auth/store/authStore'
import { getEcho, playNotificationSound } from '@/modules/chat/realtime'

/**
 * Subscribes to the private `user.{id}` channel and reacts to
 * `notification.created` broadcasts by refreshing the notification queries.
 */
export function useNotificationRealtime(): void {
  const queryClient = useQueryClient()
  const { token, user } = useAuth()

  useEffect(() => {
    if (!token || !user?.id) return

    const echo = getEcho(token)
    const channel = echo.private(`user.${user.id}`)

    const handleCreated = () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
      playNotificationSound()
    }

    channel.listen('.notification.created', handleCreated)

    return () => {
      try {
        channel.stopListening('.notification.created')
      } catch {
        /* ignore teardown errors */
      }
    }
  }, [token, user?.id, queryClient])
}
