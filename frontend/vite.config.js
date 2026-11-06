import { fileURLToPath, URL } from 'node:url'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

const appRoot = fileURLToPath(new URL('..', import.meta.url))

export default defineConfig(({ command }) => ({
  plugins: [vue()],
  base: command === 'serve' ? '/' : '/assets/dist/',
  publicDir: fileURLToPath(new URL('../public', import.meta.url)),
  build: {
    outDir: fileURLToPath(new URL('../public/assets/dist', import.meta.url)),
    emptyOutDir: true,
    copyPublicDir: false,
    manifest: true,
    rollupOptions: {
      input: {
        landingNomen: fileURLToPath(new URL('src/entries/landing-nomen.js', import.meta.url)),
        register: fileURLToPath(new URL('src/entries/register.js', import.meta.url)),
        login: fileURLToPath(new URL('src/entries/login.js', import.meta.url)),
        twofa: fileURLToPath(new URL('src/entries/twofa.js', import.meta.url)),
        authorize: fileURLToPath(new URL('src/entries/authorize.js', import.meta.url)),
        activate: fileURLToPath(new URL('src/entries/activate.js', import.meta.url)),
        registerVerify: fileURLToPath(new URL('src/entries/register-verify.js', import.meta.url)),
        recovery: fileURLToPath(new URL('src/entries/recovery.js', import.meta.url)),
        recoverySecure: fileURLToPath(new URL('src/entries/recovery-secure.js', import.meta.url)),
        family: fileURLToPath(new URL('src/entries/family.js', import.meta.url)),
        app: fileURLToPath(new URL('src/entries/app.js', import.meta.url)),
        admin: fileURLToPath(new URL('src/entries/admin.js', import.meta.url)),
      },
      output: {
        entryFileNames: 'js/[name]-[hash].js',
        chunkFileNames: 'js/[name]-[hash].js',
        assetFileNames: 'assets/[name]-[hash][extname]',
      },
    },
  },
  server: {
    fs: {
      allow: [appRoot],
    },
  },
}))
