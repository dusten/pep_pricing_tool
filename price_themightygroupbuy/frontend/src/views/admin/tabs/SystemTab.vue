<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-ghost btn-sm" @click="refresh">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
      <label class="poll-select">
        Auto-refresh:
        <select v-model="pollInterval" @change="setupPolling">
          <option value="0">Off</option>
          <option value="60000">1m</option>
          <option value="180000">3m</option>
          <option value="300000">5m</option>
        </select>
      </label>
      <span v-if="lastRefreshed" class="text-muted text-sm">Last updated {{ lastRefreshed }}</span>
    </div>

    <div v-if="sys" class="stat-grid">
      <div class="stat-tile">
        <div class="stat-value">{{ sys.cache.available ? `${sys.cache.hit_rate_pct ?? '—'}%` : 'n/a' }}</div>
        <div class="stat-label">Cache hit rate</div>
      </div>
      <div class="stat-tile">
        <div class="stat-value">{{ sys.cache.available ? fmtBytes(sys.cache.bytes_used) : 'n/a' }}</div>
        <div class="stat-label">Cache memory used</div>
      </div>
      <div class="stat-tile"><div class="stat-value">{{ sys.database.connections }}</div><div class="stat-label">DB connections</div></div>
      <div class="stat-tile"><div class="stat-value">{{ sys.database.total_queries.toLocaleString() }}</div><div class="stat-label">Total queries</div></div>
      <div class="stat-tile"><div class="stat-value">{{ sys.database.slow_queries }}</div><div class="stat-label">Slow queries</div></div>
    </div>

    <h4 class="section-title">Slow queries (deduplicated, by avg time)</h4>
    <table v-if="sys" class="admin-table">
      <thead><tr><th>Query</th><th>Executions</th><th>Avg</th><th>Max</th></tr></thead>
      <tbody>
        <tr v-for="(q, i) in sys.slow_queries" :key="i">
          <td class="text-sm mono">{{ q.query }}</td>
          <td class="text-sm">{{ q.exec_count }}</td>
          <td class="text-sm">{{ q.avg_ms }}ms</td>
          <td class="text-sm">{{ q.max_ms }}ms</td>
        </tr>
      </tbody>
    </table>
    <p v-if="sys && !sys.slow_queries.length" class="text-muted text-sm">No slow-query data available.</p>

    <h4 class="section-title">Maintenance run history</h4>
    <table v-if="sys" class="admin-table">
      <thead><tr><th>Job</th><th>Status</th><th>When</th></tr></thead>
      <tbody>
        <tr v-for="(m, i) in sys.maintenance" :key="i">
          <td class="text-sm">{{ m.job }}</td>
          <td><span :class="['badge', m.status === 'ok' ? 'badge-pro' : 'badge-free']">{{ m.status }}</span></td>
          <td class="text-sm text-muted">{{ m.ran_at }}</td>
        </tr>
      </tbody>
    </table>

    <h4 class="section-title">Comparison query log</h4>
    <div class="toolbar">
      <label class="toggle-row"><input type="checkbox" v-model="slowOnly" @change="loadQueryLog" /> Slow only</label>
      <select v-model="qlRange" @change="loadQueryLog">
        <option value="24h">Last 24h</option>
        <option value="7d">Last 7 days</option>
        <option value="30d">Last 30 days</option>
      </select>
    </div>
    <table v-if="queries" class="admin-table">
      <thead><tr><th @click="sortByDuration" style="cursor:pointer">Duration ↕</th><th>User</th><th>Results</th><th>When</th><th></th></tr></thead>
      <tbody>
        <tr v-for="q in sortedQueries" :key="q.id">
          <td class="text-sm" :class="{ 'text-danger': q.slow_flag }">{{ q.duration_ms }}ms</td>
          <td class="text-sm">{{ q.email }}</td>
          <td class="text-sm">{{ q.result_count }}</td>
          <td class="text-sm text-muted">{{ q.created_at }}</td>
          <td><button class="btn btn-ghost btn-sm" @click="rerun(q)">Re-run</button></td>
        </tr>
      </tbody>
    </table>
    <div v-if="rerunResult" class="rerun-result">
      <strong>Re-run:</strong> {{ rerunResult.original_duration_ms }}ms → {{ rerunResult.new_duration_ms }}ms now, {{ rerunResult.result_count }} rows
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { get, post } from '@/utils/api.js'

const sys            = ref(null)
const loading         = ref(false)
const lastRefreshed   = ref('')
const pollInterval    = ref('0') // manual-refresh by default — no auto-poll on tab open
let pollTimer         = null

async function refresh() {
  loading.value = true
  try {
    sys.value = await get('/api/admin/system')
    lastRefreshed.value = new Date().toLocaleTimeString()
  } finally { loading.value = false }
}

function setupPolling() {
  if (pollTimer) clearInterval(pollTimer)
  const ms = Number(pollInterval.value)
  if (ms > 0) pollTimer = setInterval(refresh, ms)
}

onMounted(refresh)
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer) }) // never leave a poll timer running after navigating away

function fmtBytes(n) {
  if (!n) return '—'
  const mb = n / 1024 / 1024
  return mb >= 1 ? `${mb.toFixed(1)}MB` : `${(n / 1024).toFixed(0)}KB`
}

// ── Comparison query log ─────────────────────────────────────────
const queries  = ref(null)
const slowOnly = ref(false)
const qlRange  = ref('7d')
const sortDesc = ref(true)
const rerunResult = ref(null)

async function loadQueryLog() {
  const params = new URLSearchParams({ range: qlRange.value })
  if (slowOnly.value) params.set('slow', '1')
  const res = await get(`/api/admin/query-log?${params}`)
  queries.value = res.queries
}
onMounted(loadQueryLog)

const sortedQueries = ref([])
function applySort() {
  sortedQueries.value = [...(queries.value || [])].sort((a, b) =>
    sortDesc.value ? b.duration_ms - a.duration_ms : a.duration_ms - b.duration_ms)
}
function sortByDuration() { sortDesc.value = !sortDesc.value; applySort() }
watch(queries, applySort)

async function rerun(q) {
  rerunResult.value = await post(`/api/admin/query-log/${q.id}/rerun`)
}
</script>

<style scoped>
.toolbar { display: flex; align-items: center; gap: 12px; margin-bottom: 14px; flex-wrap: wrap; }
.poll-select { font-size: 13px; display: flex; align-items: center; gap: 6px; }
.poll-select select { padding: 4px 8px; }
.toggle-row { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.toggle-row input { width: auto; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 10px; }
.stat-tile { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; text-align: center; }
.stat-value { font-size: 20px; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; }

.section-title { margin: 24px 0 10px; font-size: 13.5px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 6px 8px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.mono { font-family: var(--font-mono); max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.text-danger { color: var(--danger); font-weight: 700; }

.rerun-result { margin-top: 12px; padding: 10px 14px; background: var(--surface-alt); border-radius: var(--radius); font-size: 13px; }
</style>
