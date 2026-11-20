import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import type { DigestFrequency, Notification, NotificationPaginator, NotificationPreferencesResponse } from '@/types'

export interface NotificationListParams {
  unread?: boolean
  type?: string
  per_page?: number
  page?: number
}

export function useNotifications(params: NotificationListParams = {}) {
  return useQuery({
    queryKey: ['notifications', 'list', params],
    queryFn: async () => (await apiClient.get<NotificationPaginator>('/v1/auth/notifications', { params })).data,
  })
}

export function useUnreadNotificationCount() {
  return useQuery({
    queryKey: ['notifications', 'unread-count'],
    queryFn: async () => (await apiClient.get<{ count: number }>('/v1/auth/notifications/unread-count')).data.count,
    refetchInterval: 60_000,
  })
}

export function useMarkNotificationAsRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (id: number) => apiClient.patch(`/v1/auth/notifications/${id}/read`),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useMarkAllNotificationsAsRead() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: () => apiClient.patch('/v1/auth/notifications/read-all'),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

export function useNotificationPreferences() {
  return useQuery({
    queryKey: ['notifications', 'preferences'],
    queryFn: async () => (await apiClient.get<NotificationPreferencesResponse>('/v1/notifications/preferences')).data,
  })
}

export interface NotificationPreferenceUpdate {
  type: string
  in_app: boolean
  email: boolean
  websocket: boolean
}

export function useSaveNotificationPreferences() {
  const queryClient = useQueryClient()
  return useMutation({
    mutationFn: (payload: { preferences?: NotificationPreferenceUpdate[]; digest_frequency?: DigestFrequency }) =>
      apiClient.put<NotificationPreferencesResponse>('/v1/notifications/preferences', payload),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['notifications', 'preferences'] })
      queryClient.invalidateQueries({ queryKey: ['notifications'] })
    },
  })
}

/** Map a notification to a human-readable summary. */
export function notificationSummary(notification: Notification): string {
  const data = notification.data ?? {}
  return String(data.summary ?? data.message ?? notification.type)
}
