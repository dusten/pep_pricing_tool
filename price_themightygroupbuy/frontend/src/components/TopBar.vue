<template>
  <header class="topbar">
    <h1 class="topbar-title">{{ title }}</h1>
    <div class="topbar-right">
      <QuotaBadge v-if="auth.tier === 'free'" />
      <ThemeToggle />
      <div class="topbar-user">
        <span class="user-name">{{ auth.user?.display_name }}</span>
      </div>
    </div>
  </header>
</template>

<script setup>
import QuotaBadge  from './QuotaBadge.vue'
import ThemeToggle from './ThemeToggle.vue'
import { useAuthStore } from '@/stores/auth.js'

defineProps({ title: { type: String, default: '' } })
const auth = useAuthStore()
</script>

<style scoped>
.topbar {
  position: sticky;
  top: 0;
  z-index: 50;
  height: var(--topbar-height);
  background: var(--topbar-bg);
  border-bottom: 1px solid var(--topbar-border);
  display: flex;
  align-items: center;
  padding: 0 32px;
  gap: 16px;
  box-shadow: var(--shadow-sm);
}

.topbar-title {
  font-size: 16px;
  font-weight: 700;
  color: var(--text);
  flex: 1;
  letter-spacing: -0.2px;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 12px;
}

.topbar-user {
  display: flex;
  align-items: center;
  gap: 8px;
}
.user-name {
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary);
}

@media (max-width: 768px) {
  .topbar { padding: 0 16px; }
  .user-name { display: none; }
}
</style>
