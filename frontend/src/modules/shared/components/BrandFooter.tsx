import { Box, Link, Typography } from '@mui/material'

/**
 * Developer attribution shown at the bottom of the SPA and on the login page.
 */
export default function BrandFooter() {
  const year = new Date().getFullYear()

  return (
    <Box
      component="footer"
      sx={{
        mt: 4,
        pt: 2,
        pb: 1,
        borderTop: 1,
        borderColor: 'divider',
        textAlign: 'center',
      }}
    >
      <Typography variant="caption" color="text.secondary">
        Разработано{' '}
        <Link
          href="https://veltiq.ru"
          target="_blank"
          rel="noopener noreferrer"
          underline="hover"
          color="inherit"
          sx={{ fontWeight: 600 }}
        >
          Veltiq · veltiq.ru
        </Link>{' '}
        · {year}
      </Typography>
    </Box>
  )
}
