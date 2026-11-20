import { describe, expect, it, vi } from 'vitest'
import { render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { MemoryRouter, Route, Routes } from 'react-router-dom'
import apiClient from '@/modules/shared/api/apiClient'
import SpacePage from './SpacePage'

vi.mock('@/modules/shared/api/apiClient', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), put: vi.fn(), delete: vi.fn() },
}))

describe('SpacePage navigation', () => {
  it('opens a space via client-side routing (no full reload)', async () => {
    vi.mocked(apiClient.get).mockImplementation((async (url: string) => {
      if (url === '/v1/knowledgebase/spaces') {
        return {
          data: [{ id: 1, key: 'KB', name: 'Knowledge Base', type: 'knowledge', visibility: 'team' }],
        }
      }
      if (url === '/v1/knowledgebase/spaces/KB/pages') {
        return { data: [] }
      }
      return { data: [] }
    }) as any)

    render(
      <MemoryRouter initialEntries={['/knowledgebase']}>
        <Routes>
          <Route path="/knowledgebase" element={<SpacePage />} />
          <Route path="/knowledgebase/:spaceKey" element={<div>space opened</div>} />
        </Routes>
      </MemoryRouter>,
    )

    await waitFor(() => expect(screen.getByText('Knowledge Base')).toBeTruthy())

    await userEvent.click(screen.getByText('Knowledge Base'))

    await waitFor(() => expect(screen.getByText('space opened')).toBeTruthy())
  })
})
