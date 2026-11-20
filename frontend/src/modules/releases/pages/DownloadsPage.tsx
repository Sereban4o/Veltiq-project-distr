import { Box, Button, Card, CardContent, Chip, CircularProgress, Stack, Typography, Alert } from '@mui/material'
import { Android as AndroidIcon, DesktopWindows as WindowsIcon, Download as DownloadIcon } from '@mui/icons-material'
import { useQuery } from '@tanstack/react-query'
import apiClient from '@/modules/shared/api/apiClient'
import { unwrapList } from '@/modules/shared/utils/apiResponse'

interface ReleaseItem {
  platform: string
  version: string
  versionCode: number | null
  path: string
  notes: string
  sha256: string
  size: number | null
  size_human: string | null
  uploaded_at: string | null
}

const PLATFORMS: Record<string, { title: string; subtitle: string; hint: string }> = {
  android: {
    title: 'Android · Veltiq chat',
    subtitle: 'Смартфоны и планшеты',
    hint: 'Скачайте APK и откройте его на устройстве. Для установки разрешите установку из этого источника.',
  },
  windows: {
    title: 'Windows · Veltiq chat',
    subtitle: 'Настольное приложение',
    hint: 'Скачайте установщик MSI и запустите его. Приложение установится для текущего пользователя.',
  },
}

function PlatformIcon({ platform }: { platform: string }) {
  const sx = { fontSize: 40 }
  if (platform === 'android') return <AndroidIcon sx={{ ...sx, color: '#3ddc84' }} />
  if (platform === 'windows') return <WindowsIcon sx={{ ...sx, color: '#0078d4' }} />
  return <DownloadIcon sx={sx} />
}

export default function DownloadsPage() {
  const {
    data = [],
    isLoading,
    isError,
  } = useQuery<ReleaseItem[]>({
    queryKey: ['app-releases'],
    queryFn: async () => unwrapList<ReleaseItem>(await apiClient.get('/v1/releases')),
    staleTime: 60_000,
  })

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto' }}>
      <Typography variant="h5" sx={{ fontWeight: 700, mb: 0.5 }}>
        Скачать Veltiq chat
      </Typography>
      <Typography sx={{ color: 'text.secondary', mb: 3 }}>
        Последние версии клиента Veltiq chat для Android и Windows.
      </Typography>

      {isLoading && (
        <Box sx={{ display: 'flex', justifyContent: 'center', py: 6 }}>
          <CircularProgress />
        </Box>
      )}

      {isError && (
        <Alert severity="error" sx={{ mb: 2 }}>
          Не удалось загрузить список релизов. Попробуйте обновить страницу.
        </Alert>
      )}

      {!isLoading && !isError && (
        <Stack spacing={2}>
          {Object.keys(PLATFORMS).map((platform) => {
            const meta = PLATFORMS[platform]
            const release = data.find((item) => item.platform === platform)

            return (
              <Card key={platform} variant="outlined" sx={{ borderRadius: 3 }}>
                <CardContent>
                  <Box sx={{ display: 'flex', alignItems: 'flex-start', gap: 2 }}>
                    <Box sx={{ pt: 0.5 }}>
                      <PlatformIcon platform={platform} />
                    </Box>

                    <Box sx={{ flex: 1, minWidth: 0 }}>
                      <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                        <Typography sx={{ fontSize: '1.1rem', fontWeight: 700 }}>{meta.title}</Typography>
                        {release?.version && (
                          <Chip size="small" label={`версия ${release.version}`} color="primary" variant="outlined" />
                        )}
                        {release?.size_human && (
                          <Typography sx={{ fontSize: '0.8rem', color: 'text.secondary' }}>
                            {release.size_human}
                          </Typography>
                        )}
                      </Box>

                      <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 0.25 }}>
                        {meta.subtitle}
                      </Typography>

                      {release?.notes && (
                        <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 1 }}>
                          {release.notes}
                        </Typography>
                      )}

                      {!release && (
                        <Typography sx={{ fontSize: '0.85rem', color: 'text.secondary', mt: 1 }}>
                          Релиз пока не опубликован.
                        </Typography>
                      )}

                      <Typography sx={{ fontSize: '0.78rem', color: 'text.secondary', mt: 1 }}>{meta.hint}</Typography>
                    </Box>

                    <Box sx={{ display: 'flex', alignItems: 'center' }}>
                      <Button
                        variant="contained"
                        disabled={!release?.path}
                        href={release?.path || undefined}
                        startIcon={<DownloadIcon />}
                        sx={{ textTransform: 'none', borderRadius: 2, whiteSpace: 'nowrap' }}
                      >
                        Скачать
                      </Button>
                    </Box>
                  </Box>
                </CardContent>
              </Card>
            )
          })}
        </Stack>
      )}
    </Box>
  )
}
