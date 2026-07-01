<template>
  <div>
    <p class="text-muted text-sm" style="margin-bottom:14px">
      Read-only until billing is built. Tier changes happen in the Users tab.
    </p>
    <table class="admin-table">
      <thead><tr><th>Email</th><th>Tier</th><th>Status</th><th>Renews</th><th>Credit</th></tr></thead>
      <tbody>
        <tr v-for="u in paidUsers" :key="u.id">
          <td>{{ u.email }}</td>
          <td><span :class="['badge', `badge-${u.tier}`]">{{ u.tier }}</span></td>
          <td>{{ u.tier_status }}</td>
          <td class="text-muted text-sm">{{ u.tier_renews_at || '—' }}</td>
          <td>${{ u.account_credit_usd.toFixed(2) }}</td>
        </tr>
      </tbody>
    </table>
    <p v-if="!paidUsers.length" class="text-muted text-sm">No paid subscribers yet.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const paidUsers = ref([])

onMounted(async () => {
  const res = await get('/api/admin/users')
  paidUsers.value = res.users.filter(u => u.tier !== 'free')
})
</script>

<style scoped>
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
</style>
