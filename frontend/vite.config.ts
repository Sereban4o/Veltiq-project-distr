import { defineConfig } from 'vitest/config'
import react from '@vitejs/plugin-react'
import path from 'path'

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  test: {
    environment: 'jsdom',
    globals: false,
    include: ['src/**/*.test.{ts,tsx}'],
    restoreMocks: true,
  },
  optimizeDeps: {
    force: true,
    include: [
      'react',
      'react-dom',
      'react-dom/client',
      'react-router-dom',
      '@tanstack/react-query',
      '@mui/material',
      '@mui/icons-material',
      'axios',
      '@tiptap/react',
      '@tiptap/starter-kit',
      'zustand',
      'react-hook-form',
      '@emotion/react',
      '@emotion/styled',
      'react-dnd',
      'react-dnd-html5-backend',
    ],
  },
  esbuild: {
    jsx: 'automatic',
    jsxImportSource: 'react',
    // Drop license banners/comments from the shipped bundle.
    legalComments: 'none',
  },
  server: {
    port: 5173,
    host: '0.0.0.0',
    cors: true,
    watch: {
      usePolling: true,
      interval: 300,
    },
    proxy: {
      '/api': {
        target: 'http://nginx',
      },
      '/broadcasting': {
        target: 'http://nginx',
      },
      '/storage': {
        target: 'http://nginx',
      },
      '/admin': {
        target: 'http://nginx',
      },
      '/sanctum': {
        target: 'http://nginx',
      },
      '/updates': {
        target: 'http://nginx',
      },
    },
  },
  build: {
    // Never ship source maps: they would hand the original sources back through
    // DevTools. Kept explicit so a stray env change cannot turn them on.
    sourcemap: false,
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined
          if (id.includes('node_modules/@tiptap/')) return 'tiptap-vendor'
          if (id.includes('node_modules/@mui/x-data-grid')) return 'mui-grid'
          if (id.includes('node_modules/@mui/icons-material')) return 'mui-icons'
          if (id.includes('node_modules/@mui/')) return 'mui-vendor'
          if (id.includes('node_modules/@tanstack/react-query')) return 'query-vendor'
          if (
            id.includes('node_modules/react-dom') ||
            id.includes('node_modules/react-router') ||
            id.includes('node_modules/react/')
          ) {
            return 'react-vendor'
          }
          return undefined
        },
      },
    },
  },
})
