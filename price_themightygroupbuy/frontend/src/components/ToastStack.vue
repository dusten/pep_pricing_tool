<template>
  <div class="toast-stack">
    <div v-for="t in toast.toasts" :key="t.id" :class="['toast', `toast-${t.type}`]" @click="toast.remove(t.id)">
      {{ t.message }}
    </div>
  </div>
</template>

<script setup>
import { useToastStore } from '@/stores/toast.js'

const toast = useToastStore()
</script>

<style scoped>
.toast-stack {
  position: fixed; top: 16px; right: 16px; z-index: 2000;
  display: flex; flex-direction: column; gap: 8px; max-width: min(90vw, 380px);
}
.toast {
  padding: 12px 16px; border-radius: var(--radius-sm); font-size: 13.5px; line-height: 1.4;
  box-shadow: 0 4px 16px rgba(0,0,0,0.15); cursor: pointer; word-break: break-word;
}
.toast-error   { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger); }
.toast-success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }
.toast-info    { background: var(--surface-alt); color: var(--text); border: 1px solid var(--border); }
</style>
