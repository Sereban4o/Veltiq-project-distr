import { useEffect, useState } from 'react'
import { Autocomplete, Box, TextField, Typography } from '@mui/material'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'
import type { AddressValue, MailContact, MailRecipient } from '../types'

export function emailOf(value: AddressValue): string {
  return typeof value === 'string' ? value : value.email
}

/** Normalise chips: split raw strings on commas/semicolons and dedupe by email. */
export function dedupeAddressValues(values: AddressValue[]): AddressValue[] {
  const out: AddressValue[] = []
  const seen = new Set<string>()

  const push = (value: AddressValue) => {
    const email = emailOf(value).trim()
    if (!email) return
    const key = email.toLowerCase()
    if (seen.has(key)) return
    seen.add(key)
    out.push(typeof value === 'string' ? email : value)
  }

  for (const value of values) {
    if (typeof value === 'string') {
      value.split(/[,;]/).forEach((part) => push(part.trim()))
    } else {
      push(value)
    }
  }

  return out
}

export function addressValuesToEmails(values: AddressValue[]): string[] {
  return dedupeAddressValues(values).map((value) => emailOf(value))
}

export function addressValuesFrom(list?: (string | MailRecipient)[] | null): AddressValue[] {
  if (!list) return []

  const out: AddressValue[] = []
  for (const item of list) {
    if (typeof item === 'string') {
      out.push(item)
    } else if (item && item.email) {
      out.push({ id: -1, email: item.email, name: item.name ?? null })
    }
  }

  return dedupeAddressValues(out)
}

interface RecipientFieldProps {
  label: string
  value: AddressValue[]
  onChange: (value: AddressValue[]) => void
  disabled?: boolean
}

export default function RecipientField({ label, value, onChange, disabled }: RecipientFieldProps) {
  const [input, setInput] = useState('')
  const [search, setSearch] = useState('')

  useEffect(() => {
    const timer = setTimeout(() => setSearch(input.trim()), 300)
    return () => clearTimeout(timer)
  }, [input])

  const { data: contacts = [] } = useQuery<MailContact[]>({
    queryKey: ['mail-contacts', search],
    queryFn: async () =>
      unwrapList<MailContact>(await apiClient.get('/v1/mail/contacts', { params: { search, per_page: 20 } })),
    staleTime: 60_000,
    enabled: !disabled,
  })

  return (
    <Autocomplete<AddressValue, true, false, true>
      multiple
      freeSolo
      size="small"
      disabled={disabled}
      options={contacts}
      value={value}
      onChange={(_, next) => onChange(dedupeAddressValues(next as AddressValue[]))}
      inputValue={input}
      onInputChange={(_, next, reason) => {
        if (reason !== 'reset') setInput(next)
      }}
      getOptionLabel={(option) => {
        if (typeof option === 'string') return option
        return option.name ? `${option.name} <${option.email}>` : option.email
      }}
      isOptionEqualToValue={(option, val) => emailOf(option).toLowerCase() === emailOf(val).toLowerCase()}
      filterOptions={(options) => options}
      noOptionsText="Совпадений нет — адрес можно ввести вручную"
      renderOption={(props, option) => {
        const contact = option as MailContact
        return (
          <Box component="li" {...props}>
            <Box>
              <Typography sx={{ fontSize: '0.88rem' }}>{contact.name || contact.email}</Typography>
              {contact.name && (
                <Typography sx={{ fontSize: '0.75rem', color: 'text.secondary' }}>{contact.email}</Typography>
              )}
            </Box>
          </Box>
        )
      }}
      renderInput={(params) => <TextField {...params} label={label} />}
    />
  )
}
