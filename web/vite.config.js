import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// In development the API runs on its own port; proxying keeps the browser
// on one origin, so no CORS configuration is needed locally.
const API_TARGET = process.env.KEELWATCH_API_URL || 'http://127.0.0.1:8080';
const proxy = {
  '/api': { target: API_TARGET, changeOrigin: false },
  '/healthz': { target: API_TARGET, changeOrigin: false },
  '/readyz': { target: API_TARGET, changeOrigin: false },
};

export default defineConfig({
  plugins: [react()],
  server: { port: 5173, strictPort: true, proxy },
  preview: { proxy },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.js'],
    include: ['src/**/*.test.{js,jsx}'],
    css: false,
  },
});
