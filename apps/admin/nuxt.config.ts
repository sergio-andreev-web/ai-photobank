export default defineNuxtConfig({
  srcDir: '.',
  compatibilityDate: '2026-09-25',
  devtools: { enabled: false },
  modules: ['@nuxt/ui', '@pinia/nuxt'],
  // Nuxt UI v3 styles its components only when Tailwind and its theme are imported.
  css: ['~/assets/css/main.css'],
  // Admin-only SPA behind a Sanctum session cookie: no SEO/first-paint need for SSR,
  // and SSR would try to call the relative /api base URL from the Node process itself,
  // which has no browser origin to resolve against (auth middleware would always redirect).
  ssr: false,

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
