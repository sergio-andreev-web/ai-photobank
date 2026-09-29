<template>
  <div class="admin-shell">
    <aside class="admin-sidebar" aria-label="Main navigation">
      <NuxtLink to="/dashboard" class="admin-brand">
        <span class="admin-brand-mark"><UIcon name="i-heroicons-photo" /></span>
        <span><strong>Photobank</strong><small>ADMIN STUDIO</small></span>
      </NuxtLink>

      <div class="admin-sidebar-label">WORKSPACE</div>
      <nav class="admin-nav" aria-label="Admin sections">
        <NuxtLink
          v-for="item in navigation"
          :key="item.to"
          :to="item.to"
          class="admin-nav-link"
          :class="{ 'is-active': isActive(item.to) }"
          :aria-current="isActive(item.to) ? 'page' : undefined"
        >
          <UIcon :name="item.icon" />
          <span>{{ item.label }}</span>
        </NuxtLink>
      </nav>

      <div class="admin-sidebar-footer">
        <span class="admin-avatar">{{ initial }}</span>
        <span class="admin-account"><strong>Administrator</strong><small>{{ auth.user?.email }}</small></span>
      </div>
    </aside>

    <div class="admin-workspace">
      <header class="admin-topbar">
        <div class="admin-topbar-heading">
          <span class="admin-eyebrow">PHOTOBANK / ADMIN</span>
          <span class="admin-topbar-title">Media workspace</span>
        </div>
        <UButton variant="ghost" color="neutral" icon="i-heroicons-arrow-right-on-rectangle" @click="handleLogout">
          Sign out
        </UButton>
      </header>

      <nav class="admin-mobile-nav" aria-label="Admin sections">
        <NuxtLink
          v-for="item in navigation"
          :key="item.to"
          :to="item.to"
          :class="{ 'is-active': isActive(item.to) }"
          :aria-current="isActive(item.to) ? 'page' : undefined"
        >{{ item.label }}</NuxtLink>
      </nav>

      <main class="admin-main"><slot /></main>
    </div>
  </div>
</template>

<script setup lang="ts">
const auth = useAuthStore()
const route = useRoute()

const navigation = [
  { to: '/dashboard', label: 'Overview', icon: 'i-heroicons-squares-2x2' },
  { to: '/gallery', label: 'Gallery', icon: 'i-heroicons-photo' },
  { to: '/uploader', label: 'Upload', icon: 'i-heroicons-arrow-up-tray' },
  { to: '/tags', label: 'Tags', icon: 'i-heroicons-tag' },
  { to: '/candidates', label: 'Moderation', icon: 'i-heroicons-inbox-stack' },
  { to: '/search', label: 'Search', icon: 'i-heroicons-magnifying-glass' },
]

const initial = computed(() => auth.user?.name?.charAt(0)?.toUpperCase() || 'A')
function isActive(path: string) {
  return route.path === path || (path === '/gallery' && route.path.startsWith('/gallery/'))
}

async function handleLogout() {
  await auth.logout()
  await navigateTo('/login')
}
</script>
