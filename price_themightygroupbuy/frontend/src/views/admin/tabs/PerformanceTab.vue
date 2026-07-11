<template>
  <div v-if="data">
    <div class="filter-row">
      <select v-model="range" @change="load">
        <option value="24h">Last 24h</option>
        <option value="7d">Last 7 days</option>
        <option value="30d">Last 30 days</option>
      </select>
      <select v-model="device" @change="refreshAll">
        <option value="">All devices</option>
        <option value="desktop">Desktop</option>
        <option value="mobile">Mobile</option>
        <option value="tablet">Tablet</option>
        <option value="other">Other</option>
      </select>
      <input v-model="path" @change="refreshAll" placeholder="Filter by path…" style="max-width:200px" />
    </div>

    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.overall.samples }}</div><div class="stat-label">Samples</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.ttfb_ms) }}</div><div class="stat-label">Avg TTFB</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.dom_load_ms) }}</div><div class="stat-label">Avg DOM Load</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.load_ms) }}</div><div class="stat-label">Avg Full Load</div></div>
    </div>

    <h4 class="section-title">Daily avg load time</h4>
    <div class="range-pills">
      <button v-for="r in ['24h', '7d', '30d']" :key="r" type="button"
              :class="['pill', { active: dailyRange === r }]" @click="dailyRange = r; loadDaily()">{{ r }}</button>
    </div>
    <div v-if="dailyData" class="hbar-chart">
      <div v-for="d in dailyData" :key="d.day" class="hbar-row">
        <span class="hbar-label">{{ d.day }}</span>
        <div class="hbar-track"><div class="hbar-fill" :style="{ width: barWidth(d.avg_load_ms) + '%' }" :title="`${d.avg_load_ms}ms (${d.samples} samples)`"></div></div>
        <span class="hbar-value">{{ d.avg_load_ms }}ms</span>
      </div>
      <p v-if="!dailyData.length" class="text-muted text-sm">No performance samples yet.</p>
    </div>

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

const data       = ref(null)
const range      = ref('7d')
const device     = ref('')
const path       = ref('')
const dailyRange = ref('7d') // independent of `range` above — its own pills
const dailyData  = ref(null)

async function load() {
  const params = new URLSearchParams({ range: range.value })
  if (device.value) params.set('device', device.value)
  if (path.value)   params.set('path', path.value)
  data.value = await get(`/api/admin/performance?${params}`)
}

async function loadDaily() {
  const params = new URLSearchParams({ range: dailyRange.value })
  if (device.value) params.set('device', device.value)
  if (path.value)   params.set('path', path.value)
  const res = await get(`/api/admin/performance?${params}`)
  dailyData.value = res.daily
}

function refreshAll() { load(); loadDaily() }

onMounted(refreshAll)

function fmt(v) { return v === null ? '—' : `${v}ms` }
function barWidth(ms) {
  const max = Math.max(...dailyData.value.map(d => d.avg_load_ms), 1)
  return Math.max(2, Math.round((ms / max) * 100))
}
</script>

<style scoped>
.filter-row { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filter-row select, .filter-row input { max-width: 160px; }

.section-title { margin: 20px 0 10px; font-size: 13.5px; }

.range-pills { display: flex; gap: 6px; margin-bottom: 10px; }
.pill {
  padding: 4px 12px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface);
  cursor: pointer; font-size: 12px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition);
}
.pill:hover  { border-color: var(--accent); color: var(--accent); }
.pill.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.hbar-chart { display: flex; flex-direction: column; gap: 6px; }
.hbar-row { display: flex; align-items: center; gap: 10px; }
.hbar-label { width: 90px; flex-shrink: 0; font-size: 12px; color: var(--text-secondary); }
.hbar-track { flex: 1; background: var(--surface-alt); border-radius: 2px; height: 14px; }
.hbar-fill { height: 100%; background: var(--accent); border-radius: 2px; min-width: 2px; }
.hbar-value { width: 60px; flex-shrink: 0; font-size: 12px; color: var(--text-secondary); text-align: right; }

.breakdown-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }

@media (max-width: 720px) { .breakdown-grid { grid-template-columns: 1fr; } }
</style>
