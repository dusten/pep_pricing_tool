<template>
  <div v-if="data">
    <div class="stat-grid">
      <div class="stat-tile"><div class="stat-value">{{ data.overall.samples }}</div><div class="stat-label">Samples (90d)</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.ttfb_ms) }}</div><div class="stat-label">Avg TTFB</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.dom_load_ms) }}</div><div class="stat-label">Avg DOM Load</div></div>
      <div class="stat-tile"><div class="stat-value">{{ fmt(data.overall.load_ms) }}</div><div class="stat-label">Avg Full Load</div></div>
    </div>

    <h4 style="margin:20px 0 10px;font-size:13.5px">Daily avg load time (30d)</h4>
    <div class="sparkline">
      <div v-for="d in data.daily" :key="d.day" class="bar" :style="{ height: barHeight(d.avg_load_ms) + '%' }" :title="`${d.day}: ${d.avg_load_ms}ms (${d.samples} samples)`"></div>
    </div>
    <p v-if="!data.daily.length" class="text-muted text-sm">No performance samples yet.</p>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const data = ref(null)

onMounted(async () => { data.value = await get('/api/admin/performance') })

function fmt(v) { return v === null ? '—' : `${v}ms` }
function barHeight(ms) {
  const max = Math.max(...data.value.daily.map(d => d.avg_load_ms), 1)
  return Math.max(4, Math.round((ms / max) * 100))
}
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 14px; margin-bottom: 10px; }
.stat-tile { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; text-align: center; }
.stat-value { font-size: 22px; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 11px; color: var(--text-secondary); text-transform: uppercase; margin-top: 4px; }

.sparkline { display: flex; align-items: flex-end; gap: 2px; height: 80px; border-bottom: 1px solid var(--border); }
.bar { flex: 1; background: var(--accent); border-radius: 2px 2px 0 0; min-height: 2px; }
</style>
