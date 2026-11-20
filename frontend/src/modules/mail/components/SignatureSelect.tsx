import { FormControl, InputLabel, MenuItem, Select } from '@mui/material'
import type { MailSignature } from '../types'

interface SignatureSelectProps {
  signatures: MailSignature[]
  value: number
  onChange: (value: number) => void
  disabled?: boolean
}

export default function SignatureSelect({ signatures, value, onChange, disabled }: SignatureSelectProps) {
  return (
    <FormControl size="small" sx={{ minWidth: 220 }} disabled={disabled || signatures.length === 0}>
      <InputLabel id="mail-signature-label">Подпись</InputLabel>
      <Select
        labelId="mail-signature-label"
        label="Подпись"
        value={value}
        onChange={(e) => onChange(Number(e.target.value))}
      >
        <MenuItem value={0}>Без подписи</MenuItem>
        {signatures.map((signature) => (
          <MenuItem key={signature.id} value={signature.id}>
            {signature.name}
            {signature.is_default ? ' (по умолчанию)' : ''}
          </MenuItem>
        ))}
      </Select>
    </FormControl>
  )
}
