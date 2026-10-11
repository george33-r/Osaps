import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Built into Laravel's public/spa; Laravel's web route serves spa/index.html for every
// non-API path. In development, /api and /sanctum are proxied to `php artisan serve`
// so the browser sees one origin and Sanctum cookie sessions work.
export default defineConfig(({ mode }) => ({
  plugins: [react()],
  base: mode === 'preview' ? '/' : '/spa/',
  build: {
    outDir: '../public/spa',
    emptyOutDir: true,
    sourcemap: false,
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://127.0.0.1:8000',
      '/sanctum': 'http://127.0.0.1:8000',
    },
  },
}))
