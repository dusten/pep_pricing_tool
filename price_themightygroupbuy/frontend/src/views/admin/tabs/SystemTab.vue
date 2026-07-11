<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-ghost btn-sm" @click="refresh">{{ loading ? 'Refreshing…' : 'Refresh' }}</button>
      <div class="poll-pills">
        <button v-for="opt in pollOptions" :key="opt.value"
                :class="['poll-pill', { active: pollInterval === opt.value }]"
                type="button" @click="setPoll(opt.value)">
          {{ opt.label }}
        </button>
      </div>
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
      <div class="stat-tile">
        <div class="stat-value">{{ sys.cache.available ? (sys.cache.data_items ?? sys.cache.curr_items).toLocaleString() : 'n/a' }}</div>
        <div class="stat-label">Cached objects</div>
        <div class="stat-sublabel" v-if="sys.cache.available && sys.cache.housekeeping_items !== undefined">
          data entries ({{ sys.cache.housekeeping_items }} housekeeping keys not counted — version counters, rate limits)
        </div>
        <div class="stat-sublabel" v-else>live keys held right now</div>
      </div>
      <div class="stat-tile"><div class="stat-value">{{ sys.database.connections }}</div><div class="stat-label">DB connections</div></div>
      <div class="stat-tile">
        <div class="stat-value">{{ sys.database.total_queries === null ? 'n/a' : sys.database.total_queries.toLocaleString() }}</div>
        <div class="stat-label">App queries</div>
        <div class="stat-sublabel">this app only, since cache restart</div>
      </div>
      <div class="stat-tile"><div class="stat-value">{{ sys.database.slow_queries }}</div><div class="stat-label">Slow queries</div><div class="stat-sublabel">server-wide, since restart</div></div>
    </div>

    <h4 class="section-title">Slow queries — this database only</h4>
    <p class="text-muted text-sm" style="margin-bottom:10px">
      Fed hourly from mysql.slow_log (server-wide, shared with other DBs on this box — scoped to tmgb_price's own rows only).
      Includes queries that were slow <em>or</em> didn't use an index.
    </p>
    <div class="toolbar">
      <select v-model="sqStatus" @change="loadSlowQueries">
        <option value="">All statuses</option>
        <option value="new">New</option>
        <option value="acknowledged">Acknowledged</option>
        <option value="resolved">Resolved</option>
      </select>
      <button class="btn btn-ghost btn-sm" @click="exportSlowQueries">Export CSV</button>
    </div>
    <table v-if="slowQueries" class="admin-table">
      <thead><tr><th>Query</th><th>Time</th><th>Rows examined</th><th>Seen</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="q in slowQueries" :key="q.id">
          <td class="text-sm mono" :title="q.query_sql">{{ q.query_sql }}</td>
          <td class="text-sm">{{ q.query_time_secs }}s</td>
          <td class="text-sm">{{ q.rows_examined.toLocaleString() }}</td>
          <td class="text-sm text-muted">×{{ q.occurrence_count }}, last {{ q.last_seen_at }}</td>
          <td><span :class="['badge', statusBadge(q.status)]">{{ q.status }}</span></td>
          <td class="actions">
            <button v-if="q.status !== 'acknowledged'" class="btn btn-ghost btn-sm" @click="setStatus(q, 'acknowledged')">Acknowledge</button>
            <button v-if="q.status !== 'resolved'" class="btn btn-ghost btn-sm" @click="setStatus(q, 'resolved')">Resolve</button>
            <button v-if="q.status === 'resolved'" class="btn btn-ghost btn-sm" @click="setStatus(q, 'new')">Reopen</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="slowQueries && !slowQueries.length" class="text-muted text-sm">No slow-query data available.</p>

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
      <input v-model="userFilter" type="text" placeholder="Filter by email…" class="ql-input" />
      <input v-model.number="minMs" type="number" min="0" placeholder="Min ms" class="ql-input ql-num" />
      <span class="text-muted text-sm">{{ sortedQueries.length }} shown</span>
    </div>
    <table v-if="queries" class="admin-table">
      <thead><tr>
        <th @click="setSort('duration_ms')" style="cursor:pointer">Duration {{ sortArrow('duration_ms') }}</th>
        <th @click="setSort('email')" style="cursor:pointer">User {{ sortArrow('email') }}</th>
        <th @click="setSort('result_count')" style="cursor:pointer">Results {{ sortArrow('result_count') }}</th>
        <th @click="setSort('created_at')" style="cursor:pointer">When {{ sortArrow('created_at') }}</th>
        <th></th>
      </tr></thead>
      <tbody>
        <tr v-for="q in sortedQueries" :key="q.id">
          <td class="text-sm" :class="{ 'text-danger': q.slow_flag }">{{ q.duration_ms }}ms</td>
          <td class="text-sm">{{ q.email }}</td>
          <td class="text-sm">{{ q.result_count }}</td>
          <td class="text-sm text-muted">{{ q.created_at }}</td>
          <td class="actions">
            <button class="btn btn-ghost btn-sm" @click="openQuery(q)" title="Open this query as a live Comparison in a new tab">Open</button>
            <button class="btn btn-ghost btn-sm" @click="rerun(q)" title="Re-execute server-side and compare timing">Time</button>
          </td>
        </tr>
      </tbody>
    </table>
    <p v-if="queries && !sortedQueries.length" class="text-muted text-sm">No queries match.</p>
    <div v-if="rerunResult" class="rerun-result">
      <strong>Timed re-run:</strong> {{ rerunResult.original_duration_ms }}ms → {{ rerunResult.new_duration_ms }}ms now, {{ rerunResult.result_count }} rows
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { get, post, patch } from '@/utils/api.js'

const sys            = ref(null)
const loading         = ref(false)
const lastRefreshed   = ref('')
const pollInterval    = ref('0') // manual-refresh by default — no auto-poll on tab open
let pollTimer         = null

const pollOptions = [
  { value: '1000',   label: 'Live' },
  { value: '60000',  label: '1m' },
  { value: '180000', label: '3m' },
  { value: '300000', label: '5m' },
  { value: '0',      label: 'Manual' },
]
function setPoll(value) {
  pollInterval.value = value
  setupPolling()
}

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

// ── Slow queries (this db only, status-tracked) ──────────────────
const slowQueries = ref(null)
const sqStatus     = ref('')
async function loadSlowQueries() {
  const params = sqStatus.value ? `?status=${sqStatus.value}` : ''
  const res = await get(`/api/admin/slow-queries${params}`)
  slowQueries.value = res.queries
}
onMounted(loadSlowQueries)

function statusBadge(status) {
  return status === 'resolved' ? 'badge-pro' : status === 'acknowledged' ? 'badge-advanced' : 'badge-free'
}
async function setStatus(q, status) {
  await patch(`/api/admin/slow-queries/${q.id}`, { status })
  q.status = status
}
async function exportSlowQueries() {
  const params = sqStatus.value ? `?status=${sqStatus.value}` : ''
  const res = await fetch(`/api/admin/slow-queries/export${params}`, {
    headers: { Authorization: 'Bearer ' + localStorage.getItem('pc_token') },
  })
  const blob = await res.blob()
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'slow-queries.csv'; a.click()
  URL.revokeObjectURL(url)
}

// ── Comparison query log ─────────────────────────────────────────
const queries    = ref(null)
const slowOnly   = ref(false)
const qlRange    = ref('7d')
const sortKey    = ref('duration_ms')
const sortDesc   = ref(true)
const userFilter = ref('')
const minMs      = ref(0)
const rerunResult = ref(null)

async function loadQueryLog() {
  const params = new URLSearchParams({ range: qlRange.value })
  if (slowOnly.value) params.set('slow', '1')
  const res = await get(`/api/admin/query-log?${params}`)
  queries.value = res.queries
}
onMounted(loadQueryLog)

const sortedQueries = computed(() => {
  let list = queries.value || []
  const uf = userFilter.value.trim().toLowerCase()
  if (uf) list = list.filter(q => (q.email || '').toLowerCase().includes(uf))
  if (minMs.value > 0) list = list.filter(q => q.duration_ms >= minMs.value)
  const k = sortKey.value
  return [...list].sort((a, b) => {
    if (k === 'email' || k === 'created_at') {
      const av = String(a[k] || ''), bv = String(b[k] || '')
      return sortDesc.value ? bv.localeCompare(av) : av.localeCompare(bv)
    }
    return sortDesc.value ? b[k] - a[k] : a[k] - b[k]
  })
})
function setSort(key) {
  if (sortKey.value === key) sortDesc.value = !sortDesc.value
  else { sortKey.value = key; sortDesc.value = true }
}
function sortArrow(key) { return sortKey.value === key ? (sortDesc.value ? '↓' : '↑') : '↕' }

// Open the logged query as a live Comparison in a new tab (deep-linked filters)
// so its result and behavior can be inspected directly.
function openQuery(q) {
  const p = q.selection_params || {}
  const params = new URLSearchParams()
  ;(p.classificationIds || []).forEach(id => params.append('classification_ids', id))
  ;(p.vendorIds || []).forEach(id => params.append('vendors', id))
  if (p.tierKitSize) params.set('tier', p.tierKitSize)
  if (p.multiOnly)       params.set('multi_only', '1')
  if (p.verifiedOnly)    params.set('verified_only', '1')
  if (p.rawMaterialOnly) params.set('raw_material_only', '1')
  window.open(`/comparison?${params.toString()}`, '_blank')
}

async function rerun(q) {
  rerunResult.value = await post(`/api/admin/query-log/${q.id}/rerun`)
}
</script>

<style scoped>
.poll-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.poll-pill {
  padding: 5px 14px; border-radius: 99px; border: 1.5px solid var(--border);
  background: var(--surface); color: var(--text-secondary); cursor: pointer;
  font-size: 12.5px; font-weight: 500; transition: all var(--transition);
}
.poll-pill:hover  { border-color: var(--accent); color: var(--accent); }
.poll-pill.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }
.toggle-row { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.toggle-row input { width: auto; }

.section-title { margin: 24px 0 10px; font-size: 13.5px; }
.mono { font-family: var(--font-mono); max-width: 420px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: block; }
.text-danger { color: var(--danger); font-weight: 700; }

.rerun-result { margin-top: 12px; padding: 10px 14px; background: var(--surface-alt); border-radius: var(--radius); font-size: 13px; }
.ql-input { padding: 5px 9px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font-size: 12.5px; }
.ql-num { max-width: 90px; }
</style>
