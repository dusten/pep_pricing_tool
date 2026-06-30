<template>
  <button class="theme-toggle" :title="`Theme: ${label}`" @click="cycle">
    <!-- Sun (light) -->
    <svg v-if="current === 'light'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
      <circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42"/>
    </svg>
    <!-- Moon (dark) -->
    <svg v-else-if="current === 'dark'" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
      <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
    </svg>
    <!-- Monitor (system) -->
    <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
      <rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/>
    </svg>
  </button>
</template>

<script setup>
import { computed } from 'vue'
import { useAuthStore } from '@/stores/auth.js'

const auth  = useAuthStore()
const ORDER = ['system', 'light', 'dark']

const current = computed(() => auth.user?.theme ?? 'system')
const label   = computed(() => ({ system: 'System', light: 'Light', dark: 'Dark' }[current.value]))

function cycle() {
  const next = ORDER[(ORDER.indexOf(current.value) + 1) % ORDER.length]
  auth.updateTheme(next)
}
</script>

<style scoped>
.theme-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 32px;
  height: 32px;
  border-radius: var(--radius);
  background: var(--surface-alt);
  border: 1px solid var(--border);
  cursor: pointer;
  color: var(--text-secondary);
  transition: background var(--transition), color var(--transition);
}
.theme-toggle:hover {
  background: var(--accent-subtle);
  color: var(--accent);
}
</style>
