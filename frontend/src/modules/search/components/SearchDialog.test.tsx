import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import apiClient from '@/modules/shared/api/apiClient'
import SearchDialog from './SearchDialog'

vi.mock('@/modules/shared/api/apiClient', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

function renderDialog() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } })

  return render(
    <QueryClientProvider client={queryClient}>
      <MemoryRouter initialEntries={['/board']}>
        <Routes>
          <Route path="/board" element={<SearchDialog open onClose={() => {}} />} />
          <Route path="/issues/1" element={<div>issue page opened</div>} />
        </Routes>
      </MemoryRouter>
    </QueryClientProvider>,
  )
}

describe('SearchDialog', () => {
  it('shows grouped results and navigates to a hit', async () => {
    vi.mocked(apiClient.get).mockImplementation((async (url: string) => {
      if (url === '/v1/search') {
        return {
          data: {
            query: 'bug',
            types: ['issues'],
            page: 1,
            per_page: 10,
            groups: {
              issues: {
                total: 1,
                hits: [{ id: 1, title: 'Bug report', snippet: 'Something broke', url: '/issues/1', meta: {} }],
              },
            },
          },
        }
      }
      return { data: {} }
    }) as any)

    renderDialog()

    await userEvent.type(screen.getByPlaceholderText('Поиск по проекту…'), 'bug')

    await waitFor(() => expect(screen.getByText('Bug report')).toBeTruthy(), { timeout: 3000 })

    await userEvent.click(screen.getByText('Bug report'))

    await waitFor(() => expect(screen.getByText('issue page opened')).toBeTruthy())
  })
})
