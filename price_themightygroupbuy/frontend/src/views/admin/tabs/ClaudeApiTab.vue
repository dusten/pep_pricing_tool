<template>
  <div>
    <h4 class="section-title">Extraction system prompt</h4>
    <p class="text-muted text-sm" style="margin-bottom:10px">Live — includes the current product catalog, so this reflects exactly what the next file sent to Claude will see.</p>
    <pre v-if="prompts" class="prompt-box">{{ prompts.extraction_prompt }}</pre>

    <h4 class="section-title">Vendor-contact-parse prompt</h4>
    <pre v-if="prompts" class="prompt-box">{{ prompts.vendor_contact_parse_prompt }}</pre>

    <h4 class="section-title">Call log ({{ calls.length }})</h4>
    <table class="admin-table">
      <thead><tr><th>When</th><th>Vendor / File</th><th>Type</th><th>Status</th><th>Tokens (in/out)</th><th>Parsed</th><th></th></tr></thead>
      <tbody>
        <tr v-for="c in calls" :key="c.id">
          <td class="text-sm text-muted">{{ c.created_at }}</td>
          <td class="text-sm">{{ c.vendor_name || '—' }}<br><span class="text-muted text-sm">{{ c.original_filename || '—' }}</span></td>
          <td class="text-sm">{{ c.call_type }}</td>
          <td class="text-sm">{{ c.http_status }} / {{ c.stop_reason || '—' }}</td>
          <td class="text-sm">{{ c.input_tokens ?? '—' }} / {{ c.output_tokens ?? '—' }}</td>
          <td><span :class="['badge', c.parsed_ok ? 'badge-pro' : 'badge-free']">{{ c.parsed_ok ? 'ok' : 'failed' }}</span></td>
          <td><button class="btn btn-ghost btn-sm" @click="view(c)">View</button></td>
        </tr>
      </tbody>
    </table>
    <p v-if="!calls.length" class="text-muted text-sm">No calls logged yet.</p>

    <div v-if="viewing" class="view-backdrop" @click.self="viewing = null">
      <div class="view-card">
        <div class="view-header">
          <span class="text-sm">Call #{{ viewing.id }} — {{ viewing.vendor_name || '—' }} / {{ viewing.original_filename || '—' }}</span>
          <button class="btn btn-ghost btn-sm" @click="viewing = null">✕ Close</button>
        </div>
        <div class="view-body">
          <p v-if="viewing.error_message" class="text-sm" style="color:var(--danger)">{{ viewing.error_message }}</p>
          <pre class="view-text">{{ viewing.raw_response_text }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get } from '@/utils/api.js'

const prompts = ref(null)
const calls   = ref([])
const viewing = ref(null)

async function load() {
  prompts.value = await get('/api/admin/claude-prompt')
  const res = await get('/api/admin/claude-log')
  calls.value = res.calls
}
onMounted(load)

async function view(c) {
  viewing.value = await get(`/api/admin/claude-log/${c.id}`)
}
</script>

<style scoped>
.section-title { margin: 20px 0 10px; font-size: 13.5px; }
.section-title:first-child { margin-top: 0; }
.prompt-box {
  background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 12px; font-size: 11.5px; font-family: monospace; white-space: pre-wrap;
  max-height: 300px; overflow: auto; margin-bottom: 8px;
}
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }

.view-backdrop {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6);
  display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.view-card {
  background: var(--surface); border-radius: 8px; width: min(90vw, 900px); height: min(85vh, 900px);
  display: flex; flex-direction: column; overflow: hidden;
}
.view-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.view-body { flex: 1; overflow: auto; padding: 12px; }
.view-text { white-space: pre-wrap; font-size: 12px; font-family: monospace; }
</style>
