<template>
  <AppLayout title="Settings">
    <div class="card" style="max-width:480px">
      <h3 style="margin-bottom:14px;font-size:15px">Appearance</h3>
      <div class="theme-options">
        <button
          v-for="opt in themeOptions"
          :key="opt.value"
          :class="['theme-option', { active: auth.user?.theme === opt.value }]"
          @click="auth.updateTheme(opt.value)"
        >
          <component :is="opt.icon" />
          {{ opt.label }}
        </button>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { h } from 'vue'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

const icon = (path) => () =>
  h('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.75 }, path)

const themeOptions = [
  { value: 'system', label: 'System', icon: icon([h('rect', { x: 2, y: 3, width: 20, height: 14, rx: 2 }), h('path', { d: 'M8 21h8M12 17v4' })]) },
  { value: 'light',  label: 'Light',  icon: icon([h('circle', { cx: 12, cy: 12, r: 4 }), h('path', { d: 'M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42' })]) },
  { value: 'dark',   label: 'Dark',   icon: icon([h('path', { d: 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z' })]) },
]
</script>

<style scoped>
.theme-options { display: flex; gap: 10px; }
.theme-option {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 16px 10px;
  border-radius: var(--radius);
  border: 1.5px solid var(--border);
  background: var(--surface-alt);
  color: var(--text-secondary);
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 500;
  transition: all var(--transition);
}
.theme-option:hover  { border-color: var(--accent); color: var(--accent); }
.theme-option.active { border-color: var(--accent); background: var(--accent-subtle); color: var(--accent); font-weight: 700; }
</style>
