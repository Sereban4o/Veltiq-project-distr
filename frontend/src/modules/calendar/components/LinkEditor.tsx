import { useQuery } from '@tanstack/react-query'
import { Autocomplete, Stack, TextField } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import { crmApi } from '@/modules/crm/api'

interface IssueOption {
  id: number
  key: string
  summary: string
}

interface DealOption {
  id: number
  title: string
}

interface Props {
  issueIds: number[]
  dealIds: number[]
  onChange: (next: { issue_ids: number[]; deal_ids: number[] }) => void
  disabled?: boolean
}

export default function LinkEditor({ issueIds, dealIds, onChange, disabled }: Props) {
  const { data: issues = [] } = useQuery({
    queryKey: ['calendar-link-issues'],
    queryFn: async () =>
      unwrapList<IssueOption>(await apiClient.get('/v1/tasksbase/issues', { params: { per_page: 100 } })),
  })

  const { data: deals = [] } = useQuery({
    queryKey: ['calendar-link-deals'],
    queryFn: async () => (await crmApi.deals()) as unknown as DealOption[],
  })

  return (
    <Stack spacing={1.5}>
      <Autocomplete
        multiple
        disabled={disabled}
        options={issues}
        value={issues.filter((issue) => issueIds.includes(issue.id))}
        getOptionLabel={(option) => `${option.key} — ${option.summary}`}
        isOptionEqualToValue={(option, value) => option.id === value.id}
        onChange={(_e, next) => onChange({ issue_ids: next.map((i) => i.id), deal_ids: dealIds })}
        renderInput={(params) => <TextField {...params} label="Связанные задачи" size="small" />}
      />

      <Autocomplete
        multiple
        disabled={disabled}
        options={deals}
        value={deals.filter((deal) => dealIds.includes(deal.id))}
        getOptionLabel={(option) => option.title}
        isOptionEqualToValue={(option, value) => option.id === value.id}
        onChange={(_e, next) => onChange({ issue_ids: issueIds, deal_ids: next.map((d) => d.id) })}
        renderInput={(params) => <TextField {...params} label="Связанные сделки" size="small" />}
      />
    </Stack>
  )
}
