<template>
  <RouterLink v-if="quota.isFree" to="/pricing" class="quota-badge" :class="urgencyClass" :title="tooltip">
    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
      <circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/>
    </svg>
    <span v-if="!quota.isExhausted">{{ quota.remaining }} of {{ quota.limit }} left</span>
    <span v-else>0 left · resets {{ resetsIn }}</span>
  </RouterLink>
</template>

<script setup>
import { computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useQuotaStore } from '@/stores/quota.js'

const quota = useQuotaStore()
onMounted(() => quota.fetch())

const urgencyClass = computed(() => {
  if (quota.isExhausted) return 'quota-danger'
  if (quota.remaining <= 1) return 'quota-warn'
  return 'quota-ok'
})

const tooltip = computed(() =>
  quota.isExhausted
    ? `Query limit reached. Resets ${resetsIn.value}.`
    : `${quota.remaining} of ${quota.limit} comparison queries remaining this ${quota.data?.limit ? '72h window' : 'period'}.`
)

const resetsIn = computed(() => {
  if (!quota.resetsAt) return 'soon'
  const diff = new Date(quota.resetsAt) - Date.now()
  if (diff <= 0) return 'now'
  const h = Math.ceil(diff / 3_600_000)
  return `in ${h}h`
})
</script>

<style scoped>
.quota-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 99px;
  font-size: 12px;
  font-weight: 600;
  text-decoration: none;
  transition: opacity var(--transition);
  white-space: nowrap;
}
.quota-badge:hover { opacity: .8; text-decoration: none; }

.quota-ok     { background: var(--surface-alt); color: var(--text-secondary); border: 1px solid var(--border); }
.quota-warn   { background: var(--warning-bg);  color: var(--warning); }
.quota-danger { background: var(--danger-bg);   color: var(--danger); }
</style>
