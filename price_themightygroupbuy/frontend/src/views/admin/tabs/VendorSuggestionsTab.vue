<template>
  <div>
    <div class="field-row">
      <select v-model="statusFilter" @change="load">
        <option value="">All statuses</option>
        <option v-for="s in statuses" :key="s" :value="s">{{ s }}</option>
      </select>
    </div>

    <div v-if="!items.length" class="text-muted text-sm">No vendor suggestions.</div>
    <table v-else class="admin-table">
      <thead><tr><th>Vendor</th><th>Relationship</th><th>Submitted by</th><th>Status</th><th>Score</th><th>Dup?</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <template v-for="s in items" :key="s.id">
          <tr :class="{ dup: s.duplicate_of_vendor_id }">
            <td>{{ s.display_name }}</td>
            <td><span class="badge badge-free">{{ s.relationship }}</span></td>
            <td>{{ s.user_display_name || s.user_email }}</td>
            <td><span class="badge" :class="'badge-vs-' + s.status">{{ s.status }}</span></td>
            <td>{{ s.score_json?.vendor_score ?? '—' }}</td>
            <td class="text-muted text-sm">{{ s.duplicate_vendor_name || '—' }}</td>
            <td class="text-muted text-sm">{{ s.created_at }}</td>
            <td><button class="btn btn-ghost btn-sm" @click="toggle(s.id)">{{ expanded === s.id ? 'Hide' : 'Details' }}</button></td>
          </tr>
          <tr v-if="expanded === s.id">
            <td colspan="8" class="detail-cell">
              <div class="detail-grid">
                <div><strong>Contact:</strong> {{ s.contact_name || '—' }} · {{ s.email || '—' }} · {{ s.whatsapp || '—' }} · {{ s.discord || '—' }} · {{ s.telegram || '—' }}</div>
                <div v-if="s.website"><strong>Website:</strong> <a :href="s.website" target="_blank" rel="noopener">{{ s.website }}</a></div>
                <div v-if="s.notes"><strong>Notes:</strong> {{ s.notes }}</div>
                <div><strong>File:</strong> {{ s.original_filename }} ({{ s.file_type }}{{ s.is_template_csv ? ', template CSV' : '' }})</div>
                <div v-if="s.duplicate_vendor_name" class="warn">⚠ Possible duplicate of existing vendor: {{ s.duplicate_vendor_name }}</div>
                <div v-if="s.admin_note"><strong>Admin note:</strong> {{ s.admin_note }}</div>

                <template v-if="s.score_json">
                  <div v-if="s.score_json.vendor_score !== null">
                    <strong>Score:</strong> {{ s.score_json.vendor_score }}/100 —
                    {{ s.score_json.matched_rows }}/{{ s.score_json.total_rows }} rows matched,
                    {{ s.score_json.would_be_cheapest_pct }}% would be cheapest,
                    {{ s.score_json.below_median_pct }}% below median
                  </div>
                  <div v-else class="text-muted">{{ s.score_json.note }}</div>
                  <div v-if="s.score_json.unmatched_names?.length" class="text-muted">
                    Unmatched: {{ s.score_json.unmatched_names.join(', ') }}
                  </div>
                </template>

                <div v-if="s.extracted_json?.prices?.length" class="price-preview">
                  <strong>Extracted prices ({{ s.extracted_json.prices.length }}):</strong>
                  <table class="admin-table">
                    <thead><tr><th>Product</th><th>Spec</th><th>Price</th><th>Tier</th></tr></thead>
                    <tbody>
                      <tr v-for="(p, i) in s.extracted_json.prices.slice(0, 20)" :key="i">
                        <td>{{ p.canonical_name }}</td>
                        <td>{{ p.spec_label }}</td>
                        <td>${{ p.price_usd }}</td>
                        <td>{{ p.tier_kit_size }}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <div v-if="s.status === 'awaiting_approval'" class="actions-row">
                  <input v-model="adminNotes[s.id]" placeholder="Admin note (optional)" />
                  <button class="btn btn-primary btn-sm" @click="queue(s)">Send to Claude</button>
                  <button class="btn btn-danger btn-sm" @click="reject(s)">Reject</button>
                </div>
                <div v-if="['scored', 'parse_failed'].includes(s.status)" class="actions-row">
                  <input v-model="adminNotes[s.id]" placeholder="Admin note (optional)" />
                  <button class="btn btn-primary btn-sm" @click="accept(s)">Accept</button>
                  <button class="btn btn-danger btn-sm" @click="reject(s)">Reject</button>
                </div>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { get, post } from '@/utils/api.js'
import { useToastStore } from '@/stores/toast.js'

const toast = useToastStore()
const statuses = ['pending_parse', 'awaiting_approval', 'processing', 'scored', 'parse_failed', 'virus_detected', 'accepted', 'rejected']

const items = ref([])
const statusFilter = ref('')
const expanded = ref(null)
const adminNotes = reactive({})

async function load() {
  const qs = statusFilter.value ? `?status=${statusFilter.value}` : ''
  const res = await get(`/api/admin/vendor-suggestions${qs}`)
  items.value = res.suggestions
}
onMounted(load)

function toggle(id) {
  expanded.value = expanded.value === id ? null : id
}

async function accept(s) {
  if (!confirm(`Accept "${s.display_name}" as a new catalog vendor?`)) return
  try {
    const res = await post(`/api/admin/vendor-suggestions/${s.id}/accept`, { admin_note: adminNotes[s.id] || '' })
    toast.success(`Vendor created (#${res.vendor_id}). Imported ${res.import.imported}, ${res.import.pending} pending review.`)
    await load()
  } catch (err) {
    toast.error(err.message || 'Accept failed.')
  }
}

async function reject(s) {
  try {
    await post(`/api/admin/vendor-suggestions/${s.id}/reject`, { admin_note: adminNotes[s.id] || '' })
    toast.success('Suggestion rejected.')
    await load()
  } catch (err) {
    toast.error(err.message || 'Reject failed.')
  }
}

async function queue(s) {
  try {
    await post(`/api/admin/vendor-suggestions/${s.id}/queue`, {})
    toast.success('Sent to Claude — the cron will process it shortly.')
    await load()
  } catch (err) {
    toast.error(err.message || 'Queue failed.')
  }
}
</script>

<style scoped>
.field-row { margin-bottom: 12px; }
.field-row select { max-width: 200px; }
tr.dup { background: var(--warning-bg); }
.detail-cell { background: var(--surface-alt); }
.detail-grid { display: flex; flex-direction: column; gap: 8px; padding: 12px; font-size: 13px; }
.warn { color: var(--warning); }
.price-preview { margin-top: 6px; }
.actions-row { display: flex; gap: 8px; align-items: center; margin-top: 8px; }
.actions-row input { flex: 1; max-width: 300px; }

.badge-vs-scored, .badge-vs-accepted { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }
.badge-vs-pending_parse, .badge-vs-awaiting_approval, .badge-vs-processing { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning); }
.badge-vs-parse_failed, .badge-vs-virus_detected, .badge-vs-rejected { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
</style>
