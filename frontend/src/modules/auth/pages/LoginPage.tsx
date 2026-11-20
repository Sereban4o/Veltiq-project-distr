import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { Box, Button, Card, CardContent, Container, TextField, Typography, Alert } from '@mui/material'
import { useAuthContext } from '@/modules/auth/hooks/useAuth'
import BrandFooter from '@/modules/shared/components/BrandFooter'
import { useBrandingStore } from '@/modules/shared/branding/brandingStore'

export default function LoginPage() {
  const navigate = useNavigate()
  const { login, verifyTwoFactor, loading, error, clearError } = useAuthContext()
  const branding = useBrandingStore((state) => state.branding)
  const brandLogo = branding?.logo || '/veltiq-icon.svg'
  const brandName = branding?.appName || 'Veltiq project'
  const [formData, setFormData] = useState({ email: '', password: '' })
  const [challengeToken, setChallengeToken] = useState<string | null>(null)
  const [code, setCode] = useState('')

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault()
    clearError()
    try {
      const result = await login(formData.email, formData.password)
      if (result?.twoFactorRequired) {
        setChallengeToken(result.challengeToken ?? '')
        return
      }
      navigate('/')
    } catch {
      // Error handled by store
    }
  }

  const handleVerify = async (e: React.FormEvent) => {
    e.preventDefault()
    clearError()
    try {
      await verifyTwoFactor(challengeToken ?? '', code.trim())
      navigate('/')
    } catch {
      // Error handled by store
    }
  }

  const handleChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    setFormData({ ...formData, [e.target.name]: e.target.value })
  }

  return (
    <Container component="main" maxWidth="xs">
      <Box
        sx={{
          marginTop: 8,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
        }}
      >
        <Box component="img" src={brandLogo} alt={brandName} sx={{ width: 64, height: 64, mb: 1.5 }} />
        <Typography component="h1" variant="h4" sx={{ mb: 0.5, fontWeight: 700 }}>
          {brandName}
        </Typography>
        <Typography component="h2" variant="h6" color="text.secondary" sx={{ mb: 3 }}>
          Вход в систему
        </Typography>

        <Card sx={{ width: '100%' }}>
          <CardContent sx={{ p: 4 }}>
            {error && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {error}
              </Alert>
            )}

            {challengeToken !== null ? (
              <Box component="form" onSubmit={handleVerify} noValidate>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                  Введите код из приложения-аутентификатора или резервный код.
                </Typography>
                <TextField
                  margin="normal"
                  required
                  fullWidth
                  label="Код"
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  autoFocus
                  autoComplete="one-time-code"
                />
                <Button
                  type="submit"
                  fullWidth
                  variant="contained"
                  sx={{ mt: 3, mb: 1, py: 1.5 }}
                  disabled={loading || code.trim() === ''}
                >
                  {loading ? 'Проверка...' : 'Подтвердить'}
                </Button>
                <Button fullWidth onClick={() => setChallengeToken(null)} disabled={loading}>
                  Назад
                </Button>
              </Box>
            ) : (
              <Box component="form" onSubmit={handleSubmit} noValidate>
                <TextField
                  margin="normal"
                  required
                  fullWidth
                  id="email"
                  label="Email"
                  name="email"
                  autoComplete="email"
                  autoFocus
                  value={formData.email}
                  onChange={handleChange}
                />
                <TextField
                  margin="normal"
                  required
                  fullWidth
                  name="password"
                  label="Пароль"
                  type="password"
                  id="password"
                  autoComplete="current-password"
                  value={formData.password}
                  onChange={handleChange}
                />
                <Button type="submit" fullWidth variant="contained" sx={{ mt: 3, mb: 2, py: 1.5 }} disabled={loading}>
                  {loading ? 'Вход...' : 'Войти'}
                </Button>
              </Box>
            )}
          </CardContent>
        </Card>

        <Box sx={{ width: '100%', mt: 1 }}>
          <BrandFooter />
        </Box>
      </Box>
    </Container>
  )
}
