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

    <h4 class="section-title">Activity</h4>
    <div class="range-pills">
      <button v-for="r in ['day', 'week', 'month']" :key="r" type="button"
              :class="['pill', { active: activityRange === r }]" @click="activityRange = r; loadActivity()">{{ r }}</button>
    </div>
    <div v-if="activity" class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ activity.signups }}</div><div class="stat-label">Signups</div></div>
      <div class="stat-tile"><div class="stat-value">{{ activity.logins }}</div><div class="stat-label">Logins</div></div>
      <div class="stat-tile"><div class="stat-value">{{ activity.searches }}</div><div class="stat-label">Searches</div></div>
      <div class="stat-tile"><div class="stat-value">{{ activity.whatsapp_clicks }}</div><div class="stat-label">WhatsApp clicks</div></div>
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

const activity      = ref(null)
const activityRange = ref('day')
async function loadActivity() { activity.value = await get(`/api/admin/activity-stats?range=${activityRange.value}`) }
loadActivity()
</script>

<style scoped>
.section-title { margin: 24px 0 10px; font-size: 13.5px; }
.mono { font-family: var(--font-mono); max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.range-pills { display: flex; gap: 6px; margin-bottom: 10px; }
.pill {
  padding: 4px 12px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface);
  cursor: pointer; font-size: 12px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition);
  text-transform: capitalize;
}
.pill:hover  { border-color: var(--accent); color: var(--accent); }
.pill.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }
</style>
