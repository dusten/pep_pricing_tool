<template>
  <RouterView />
</template>

<script setup>
import { watch, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

function applyTheme(theme) {
  // 'system' = remove attribute → CSS prefers-color-scheme takes over
  if (!theme || theme === 'system') {
    document.documentElement.removeAttribute('data-theme')
  } else {
    document.documentElement.setAttribute('data-theme', theme)
  }
}

onMounted(() => applyTheme(auth.user?.theme))
watch(() => auth.user?.theme, applyTheme)
</script>
