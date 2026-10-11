import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

// Der Server liefert die gebaute Oberfläche unter /app/ aus (PHP, mit allen Sicherheits-Headern).
export default defineConfig({
  base: '/app/',
  plugins: [react()],
  build: {
    outDir: 'dist',
    emptyOutDir: true,
    sourcemap: false,
    // Nichts als data:-URI einbetten: die CSP erlaubt Schriften und Skripte nur von der eigenen Quelle.
    assetsInlineLimit: 0,
    modulePreload: { polyfill: false },
  },
  server: {
    port: 5173,
    // Entwicklung: API des lokal laufenden Meridian (MERIDIAN_ENV=dev, php -S 127.0.0.1:8080 -t public)
    proxy: { '/api': 'http://127.0.0.1:8080' },
  },
});
