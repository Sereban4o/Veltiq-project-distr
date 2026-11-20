import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import NotificationPanel from './NotificationPanel'

vi.mock('@/modules/shared/api/apiClient', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

describe('NotificationPanel', () => {
  it('shows the unread badge and lists notifications from the paginator', async () => {
    vi.mocked(apiClient.get).mockImplementation((async (url: string) => {
      if (url === '/v1/auth/notifications') {
        return {
          data: {
            data: [
              { id: 1, type: 'system', read: false, data: { summary: 'System maintenance' } },
              { id: 2, type: 'system', read: true, data: { summary: 'Old news' } },
            ],
          },
        }
      }
      return { data: {} }
    }) as any)

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })
    render(
      <QueryClientProvider client={queryClient}>
        <NotificationPanel />
      </QueryClientProvider>,
    )

    await waitFor(() => expect(screen.getByText('1')).toBeTruthy())

    await userEvent.click(screen.getAllByRole('button')[0])

    await waitFor(() => expect(screen.getByText('System maintenance')).toBeTruthy())
  })
})
