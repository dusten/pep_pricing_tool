<template>
  <div v-if="data">
    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.users.total }}</div><div class="stat-label">Total users</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.verified }}</div><div class="stat-label">Verified</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.active_subs }}</div><div class="stat-label">Active subscriptions</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.waitlist_pending }}</div><div class="stat-label">Waitlist pending</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.test_accounts }}</div><div class="stat-label">Test accounts</div></div>
    </div>

    <h4 class="section-title">Subscriptions by tier</h4>
    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.users.free_tier }}</div><div class="stat-label">Free</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.advanced_tier }}</div><div class="stat-label">Advanced</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.pro_tier }}</div><div class="stat-label">Pro</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.users.expert_tier }}</div><div class="stat-label">Expert</div></div>
    </div>

    <h4 class="section-title">Referrals</h4>
    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.referrals.total_referrals }}</div><div class="stat-label">Total referrals</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.referrals.unique_referrers }}</div><div class="stat-label">Unique referrers</div></div>
      <div class="stat-tile"><div class="stat-value">${{ data.referrals.total_credited_usd.toFixed(2) }}</div><div class="stat-label">Total credited</div></div>
    </div>

    <h4 class="section-title">Recent admin activity</h4>
    <table class="admin-table">
      <thead><tr><th>Admin</th><th>Action</th><th>Details</th><th>When</th></tr></thead>
      <tbody>
        <tr v-for="(a, i) in data.recent_activity" :key="i">
          <td class="text-sm">{{ a.admin_email }}</td>
          <td class="text-sm">{{ a.action }}</td>
          <td class="text-sm text-muted mono">{{ a.details }}</td>
          <td class="text-sm text-muted">{{ a.created_at }}</td>
        </tr>
      </tbody>
    </table>
    <p v-if="!data.recent_activity.length" class="text-muted text-sm">No admin activity yet.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const data = ref(null)
onMounted(async () => { data.value = await get('/api/admin/overview') })
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 10px; }
.stat-tile { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; text-align: center; }
.stat-value { font-size: 22px; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; }

.section-title { margin: 24px 0 10px; font-size: 13.5px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.mono { font-family: var(--font-mono); max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
