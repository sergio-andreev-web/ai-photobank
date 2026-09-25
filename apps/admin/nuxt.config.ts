export default defineNuxtConfig({
  srcDir: '.',
  compatibilityDate: '2026-09-25',
  devtools: { enabled: false },
  modules: ['@nuxt/ui', '@pinia/nuxt'],

  runtimeConfig: {
    public: {
      apiBaseUrl: process.env.NUXT_PUBLIC_API_BASE || '/api',
      mockMode: process.env.NUXT_PUBLIC_MOCK_MODE === 'true',
    },
  },

  ui: {
    prefix: 'U',
  },

  app: {
    head: {
      title: 'AI Photobank Admin',
      meta: [
        { charset: 'utf-8' },
        { name: 'viewport', content: 'width=device-width, initial-scale=1' },
      ],
    },
  },

  typescript: {
    strict: true,
    shim: false,
  },

  nitro: {
    prerender: {
      crawlLinks: false,
      routes: ['/'],
    },
  },
})
