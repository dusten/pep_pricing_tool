<template>
  <div v-if="data">
    <div class="filter-row">
      <select v-model="range" @change="load">
        <option value="24h">Last 24h</option>
        <option value="7d">Last 7 days</option>
        <option value="30d">Last 30 days</option>
      </select>
      <select v-model="device" @change="load">
        <option value="">All devices</option>
        <option value="desktop">Desktop</option>
        <option value="mobile">Mobile</option>
        <option value="tablet">Tablet</option>
        <option value="other">Other</option>
      </select>
      <input v-model="path" @change="load" placeholder="Filter by path…" style="max-width:200px" />
    </div>

    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.overall.samples }}</div><div class="stat-label">Samples</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.ttfb_ms) }}</div><div class="stat-label">Avg TTFB</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.dom_load_ms) }}</div><div class="stat-label">Avg DOM Load</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.load_ms) }}</div><div class="stat-label">Avg Full Load</div></div>
    </div>

    <h4 class="section-title">Daily avg load time</h4>
    <div class="sparkline">
      <div v-for="d in data.daily" :key="d.day" class="bar" :style="{ height: barHeight(d.avg_load_ms) + '%' }" :title="`${d.day}: ${d.avg_load_ms}ms (${d.samples} samples)`"></div>
    </div>
    <p v-if="!data.daily.length" class="text-muted text-sm">No performance samples yet.</p>

    <div class="breakdown-grid">
      <div>
        <h4 class="section-title">Top pages</h4>
        <table class="admin-table">
          <thead><tr><th>Page</th><th>Samples</th><th>Avg load</th></tr></thead>
          <tbody>
            <tr v-for="p in data.top_pages" :key="p.page">
              <td class="text-sm">{{ p.page }}</td>
              <td class="text-sm">{{ p.samples }}</td>
              <td class="text-sm">{{ p.avg_load_ms }}ms</td>
            </tr>
          </tbody>
        </table>
      </div>
      <div>
        <h4 class="section-title">Device split</h4>
        <table class="admin-table">
          <thead><tr><th>Device</th><th>Samples</th><th>Avg load</th></tr></thead>
          <tbody>
            <tr v-for="s in data.device_split" :key="s.device_type">
              <td class="text-sm">{{ s.device_type }}</td>
              <td class="text-sm">{{ s.samples }}</td>
              <td class="text-sm">{{ s.avg_load_ms }}ms</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <h4 class="section-title">Recent requests</h4>
    <table class="admin-table">
      <thead><tr><th>When</th><th>Page</th><th>Device</th><th>TTFB</th><th>DOM</th><th>Load</th></tr></thead>
      <tbody>
        <tr v-for="(r, i) in data.recent" :key="i">
          <td class="text-sm text-muted">{{ r.created_at }}</td>
          <td class="text-sm">{{ r.page || '—' }}</td>
          <td class="text-sm">{{ r.device_type }}</td>
          <td class="text-sm">{{ r.ttfb_ms ?? '—' }}</td>
          <td class="text-sm">{{ r.dom_load_ms ?? '—' }}</td>
          <td class="text-sm">{{ r.load_ms ?? '—' }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const data   = ref(null)
const range  = ref('7d')
const device = ref('')
const path   = ref('')

async function load() {
  const params = new URLSearchParams({ range: range.value })
  if (device.value) params.set('device', device.value)
  if (path.value)   params.set('path', path.value)
  data.value = await get(`/api/admin/performance?${params}`)
}
onMounted(load)

function fmt(v) { return v === null ? '—' : `${v}ms` }
function barHeight(ms) {
  const max = Math.max(...data.value.daily.map(d => d.avg_load_ms), 1)
  return Math.max(4, Math.round((ms / max) * 100))
}
</script>

<style scoped>
.filter-row { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-row select, .filter-row input { max-width: 160px; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 10px; }
.stat-tile { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; text-align: center; }
.stat-value { font-size: 22px; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; }

.section-title { margin: 20px 0 10px; font-size: 13.5px; }
.sparkline { display: flex; align-items: flex-end; gap: 2px; height: 80px; border-bottom: 1px solid var(--border); }
.bar { flex: 1; background: var(--accent); border-radius: 2px 2px 0 0; min-height: 2px; }

.breakdown-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }

@media (max-width: 720px) { .breakdown-grid { grid-template-columns: 1fr; } }
</style>
