<template>
  <RouterView />
  <ToastStack />
</template>

<script setup>
import { watch, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth.js'
import { sendPerfBeacon } from '@/utils/perfBeacon.js'
import ToastStack from '@/components/ToastStack.vue'

const auth = useAuthStore()

function applyTheme(theme) {
  // 'system' = remove attribute → CSS prefers-color-scheme takes over
  if (!theme || theme === 'system') {
    document.documentElement.removeAttribute('data-theme')
  } else {
    document.documentElement.setAttribute('data-theme', theme)
  }
}

onMounted(() => {
  applyTheme(auth.user?.theme)
  sendPerfBeacon()
})
watch(() => auth.user?.theme, applyTheme)
</script>
