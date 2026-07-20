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

    <h4 class="section-title">
      Activity
      <label class="include-internal">
        <input type="checkbox" v-model="includeInternal" @change="loadTrend" />
        Include admin/test activity
      </label>
    </h4>
    <template v-if="trend">
      <div class="metric-grid">
        <div v-for="m in METRICS" :key="m.key" class="card metric-chart-card">
          <div class="metric-chart-header">
            <h5 class="metric-chart-title">{{ m.label }}</h5>
            <div class="range-pills">
              <button v-for="r in ['day', 'week', 'month']" :key="r" type="button"
                      :class="['pill', { active: trendGranularity[m.key] === r }]" @click="trendGranularity[m.key] = r">{{ r }}</button>
            </div>
          </div>
          <div class="bar-rows">
            <div v-for="row in trend[trendGranularity[m.key]]" :key="row.label" class="bar-row">
              <span class="bar-label">{{ row.label }}</span>
              <div class="bar-track"><div class="bar-fill" :style="{ width: barPct(row[m.key], m.key) + '%' }"></div></div>
              <span class="bar-value">{{ row[m.key] }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>

    <h4 class="section-title">Referrals</h4>
    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.referrals.total_referrals }}</div><div class="stat-label">Total referrals</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.referrals.unique_referrers }}</div><div class="stat-label">Unique referrers</div></div>
      <div class="stat-tile"><div class="stat-value">{{ data.referrals.total_months_credited }}</div><div class="stat-label">Total months credited</div></div>
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
import { ref, reactive, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const data = ref(null)
onMounted(async () => { data.value = await get('/api/admin/overview') })

const trend = ref(null)
const includeInternal = ref(false)
async function loadTrend() {
  trend.value = await get('/api/admin/activity-trend' + (includeInternal.value ? '?include_internal=1' : ''))
}
onMounted(loadTrend)

const METRICS = [
  { key: 'signups', label: 'Signups' },
  { key: 'logins', label: 'Logins' },
  { key: 'searches', label: 'Searches' },
  { key: 'daily_active_users', label: 'Daily Active Users' },
  { key: 'whatsapp_clicks', label: 'WhatsApp Clicks' },
  { key: 'website_clicks', label: 'Website Clicks' },
  { key: 'cas_clicks', label: 'CAS Link Clicks' },
  { key: 'downloads', label: 'Downloads / Exports' },
]
// Each card picks its own Day/Week/Month independently.
const trendGranularity = reactive(Object.fromEntries(METRICS.map(m => [m.key, 'day'])))

// Bar width relative to that metric's own max across the currently-shown
// 13 periods (not a shared 0-100 scale across metrics) — matches how each
// chart in the reference design normalizes independently.
function barPct(value, metricKey) {
  const rows = trend.value?.[trendGranularity[metricKey]] || []
  const max = Math.max(1, ...rows.map(r => r[metricKey]))
  return (value / max) * 100
}
</script>

<style scoped>
.section-title { margin: 24px 0 10px; font-size: 13.5px; display: flex; align-items: center; gap: 14px; }
.include-internal { display: flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 400; color: var(--text-secondary); cursor: pointer; }
.mono { font-family: var(--font-mono); max-width: 360px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.range-pills { display: flex; gap: 6px; margin-bottom: 10px; }
.pill {
  padding: 4px 12px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface);
  cursor: pointer; font-size: 12px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition);
  text-transform: capitalize;
}
.pill:hover  { border-color: var(--accent); color: var(--accent); }
.pill.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.metric-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 16px; margin-bottom: 8px; }
.metric-chart-card { padding: 18px; }
.metric-chart-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.metric-chart-title { margin: 0; font-size: 14.5px; }

.bar-rows { display: flex; flex-direction: column; gap: 9px; }
.bar-row { display: flex; align-items: center; gap: 10px; font-size: 12px; }
.bar-label { min-width: 76px; color: var(--text-secondary); flex-shrink: 0; }
.bar-track { flex: 1; height: 10px; border-radius: 99px; background: var(--surface-alt); overflow: hidden; }
.bar-fill { height: 100%; border-radius: 99px; background: var(--accent); transition: width var(--transition); }
.bar-value { min-width: 22px; text-align: right; color: var(--text-secondary); font-weight: 600; flex-shrink: 0; }
</style>
