import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Autocomplete, Box, Button, Chip, Stack, TextField } from '@mui/material'
import apiClient from '@/modules/shared/api/apiClient'

interface UserOption {
  id: number
  first_name: string
  last_name: string
  email: string
}

interface Props {
  userValues: number[]
  emailValues: string[]
  onChange: (next: { user_ids: number[]; emails: string[] }) => void
  disabled?: boolean
}

export default function ParticipantsEditor({ userValues, emailValues, onChange, disabled }: Props) {
  const [emailInput, setEmailInput] = useState('')

  const { data: users = [] } = useQuery({
    queryKey: ['all-users'],
    queryFn: async () => {
      const data = await apiClient.get('/v1/users/all').then((r) => r.data.data || r.data)
      return (data as UserOption[]) || []
    },
  })

  const selectedUsers = users.filter((user) => userValues.includes(user.id))

  const addEmail = () => {
    const email = emailInput.trim()
    if (!email || emailValues.includes(email)) return
    onChange({ user_ids: userValues, emails: [...emailValues, email] })
    setEmailInput('')
  }

  return (
    <Stack spacing={1.5}>
      <Autocomplete
        multiple
        disabled={disabled}
        options={users}
        value={selectedUsers}
        getOptionLabel={(option) => `${option.first_name} ${option.last_name}`.trim() || option.email}
        isOptionEqualToValue={(option, value) => option.id === value.id}
        onChange={(_e, next) => onChange({ user_ids: next.map((u) => u.id), emails: emailValues })}
        renderInput={(params) => <TextField {...params} label="Участники" size="small" />}
      />

      <Box sx={{ display: 'flex', gap: 1 }}>
        <TextField
          size="small"
          fullWidth
          label="Внешний email"
          value={emailInput}
          disabled={disabled}
          onChange={(e) => setEmailInput(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter') {
              e.preventDefault()
              addEmail()
            }
          }}
        />
        <Button variant="outlined" onClick={addEmail} disabled={disabled || !emailInput.trim()}>
          Добавить
        </Button>
      </Box>

      {emailValues.length > 0 && (
        <Stack direction="row" spacing={0.5} flexWrap="wrap" useFlexGap>
          {emailValues.map((email) => (
            <Chip
              key={email}
              label={email}
              size="small"
              onDelete={
                disabled
                  ? undefined
                  : () => onChange({ user_ids: userValues, emails: emailValues.filter((e) => e !== email) })
              }
            />
          ))}
        </Stack>
      )}
    </Stack>
  )
}
