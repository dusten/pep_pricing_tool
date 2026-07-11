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
  position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); z-index: 2000;
  display: flex; flex-direction: column; gap: 8px; max-width: min(90vw, 380px); align-items: stretch;
}
.toast {
  padding: 14px 18px; border-radius: var(--radius-sm); font-size: 14px; line-height: 1.4; text-align: center;
  box-shadow: 0 8px 28px rgba(0,0,0,0.25); cursor: pointer; word-break: break-word;
}
.toast-error   { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger); }
.toast-success { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }
.toast-info    { background: var(--surface-alt); color: var(--text); border: 1px solid var(--border); }
</style>
