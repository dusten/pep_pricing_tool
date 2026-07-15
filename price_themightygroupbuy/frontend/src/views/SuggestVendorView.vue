<template>
  <AppLayout title="Suggest a Vendor">
    <div class="card form-card">
      <p class="text-muted text-sm" style="margin-bottom:16px">
        Know a vendor that's not in the catalog? Share their contact info and a pricing file —
        we'll score how their prices compare to the current market. An admin reviews every
        suggestion before it's added to the catalog.
      </p>
      <p class="text-muted text-sm disclaimer">
        Only submit contact info the vendor shares publicly. Suggestions are never publicly visible —
        only you and admins can see them.
      </p>

      <div class="field-row">
        <label class="label-sm">Your relationship to this vendor</label>
        <div class="radio-row">
          <label><input type="radio" v-model="relationship" value="vendor_rep" /> I'm the vendor / a rep</label>
          <label><input type="radio" v-model="relationship" value="customer" /> I'm a customer</label>
          <label><input type="radio" v-model="relationship" value="other" /> Other</label>
        </div>
      </div>

      <div class="field-row"><input v-model="displayName" placeholder="Vendor name *" /></div>
      <div class="field-row"><input v-model="contactName" placeholder="Contact name" /></div>
      <div class="field-row"><input v-model="email" placeholder="Email" /></div>
      <div class="field-row"><input v-model="whatsapp" placeholder="WhatsApp" /></div>
      <div class="field-row"><input v-model="discord" placeholder="Discord" /></div>
      <div class="field-row"><input v-model="telegram" placeholder="Telegram" /></div>
      <div class="field-row"><input v-model="website" placeholder="Website" /></div>
      <div class="field-row"><textarea v-model="notes" placeholder="Notes (optional)" rows="2"></textarea></div>

      <div class="field-row">
        <label class="label-sm">Pricing file *</label>
        <input type="file" ref="fileInput" accept=".pdf,.xlsx,.csv,.jpg,.jpeg,.png,.zip" @change="onFileChange" />
        <button class="btn btn-ghost btn-sm" style="margin-top:6px" @click="downloadTemplate">Download CSV template</button>
        <p class="text-muted text-sm" style="margin-top:4px">
          Using the CSV template gets you an instant score. Other formats (PDF/XLSX/screenshot/ZIP) are
          queued for processing.
        </p>
      </div>

      <button class="btn btn-primary btn-sm" :disabled="!canSubmit || submitting" @click="submit">
        {{ submitting ? 'Submitting…' : 'Submit suggestion' }}
      </button>
    </div>

    <div class="card" style="margin-top:20px" v-if="suggestions.length">
      <h3 style="margin:0 0 12px">My Suggestions</h3>
      <div v-for="s in suggestions" :key="s.id" class="suggestion-row">
        <div class="suggestion-head" @click="toggle(s.id)">
          <span class="suggestion-name">{{ s.display_name }}</span>
          <span class="badge" :class="'badge-vs-' + s.status">{{ statusLabel(s.status) }}</span>
          <span v-if="s.is_duplicate" class="badge badge-vs-dup">possible duplicate</span>
          <span class="text-muted text-sm">{{ s.created_at }}</span>
        </div>
        <div v-if="expanded === s.id" class="suggestion-body">
          <template v-if="s.score_json">
            <p v-if="s.score_json.vendor_score !== null" class="text-sm">
              Vendor score: <strong>{{ s.score_json.vendor_score }}/100</strong>
              ({{ s.score_json.matched_rows }} of {{ s.score_json.total_rows }} rows matched)
            </p>
            <p v-else class="text-muted text-sm">{{ s.score_json.note || 'Not enough catalog overlap to score yet.' }}</p>
            <p v-if="s.score_json.unmatched_names?.length" class="text-muted text-sm">
              Unmatched: {{ s.score_json.unmatched_names.join(', ') }}
            </p>
          </template>
          <p v-else-if="s.status === 'pending_parse'" class="text-muted text-sm">Queued for processing.</p>
          <p v-if="s.admin_note" class="text-muted text-sm">Admin note: {{ s.admin_note }}</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AppLayout from '@/components/AppLayout.vue'
import { get } from '@/utils/api.js'
import { useToastStore } from '@/stores/toast.js'

const toast = useToastStore()

const relationship = ref('customer')
const displayName   = ref('')
const contactName   = ref('')
const email         = ref('')
const whatsapp      = ref('')
const discord       = ref('')
const telegram      = ref('')
const website       = ref('')
const notes         = ref('')
const file          = ref(null)
const submitting    = ref(false)
const fileInput     = ref(null)

const suggestions = ref([])
const expanded     = ref(null)

const canSubmit = computed(() =>
  displayName.value.trim() && file.value &&
  (email.value.trim() || whatsapp.value.trim() || discord.value.trim() || telegram.value.trim() || website.value.trim())
)

function onFileChange(e) {
  file.value = e.target.files[0] || null
}

function statusLabel(status) {
  return { pending_parse: 'Queued', processing: 'Processing', scored: 'Scored', parse_failed: 'Parse failed',
           virus_detected: 'Rejected (file flagged)', accepted: 'Accepted', rejected: 'Rejected' }[status] || status
}

function toggle(id) {
  expanded.value = expanded.value === id ? null : id
}

function downloadTemplate() {
  const csv = 'product_name,spec,price_usd,kit_vial_count,tier_kit_size,vendor_sku\nBPC-157,5mg,25.00,1,1,\n'
  const blob = new Blob([csv], { type: 'text/csv' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = 'vendor-price-template.csv'
  a.click()
  URL.revokeObjectURL(url)
}

async function loadSuggestions() {
  const res = await get('/api/vendor-suggestions')
  suggestions.value = res.suggestions
}
loadSuggestions()

async function submit() {
  submitting.value = true
  try {
    const body = new FormData()
    body.append('relationship', relationship.value)
    body.append('display_name', displayName.value.trim())
    body.append('contact_name', contactName.value.trim())
    body.append('email', email.value.trim())
    body.append('whatsapp', whatsapp.value.trim())
    body.append('discord', discord.value.trim())
    body.append('telegram', telegram.value.trim())
    body.append('website', website.value.trim())
    body.append('notes', notes.value.trim())
    body.append('file', file.value, file.value.name)

    const res = await fetch('/api/vendor-suggestions', {
      method: 'POST', body, headers: { Authorization: 'Bearer ' + localStorage.getItem('pc_token') },
    })
    const data = await res.json().catch(() => ({}))
    if (!res.ok) throw new Error(data.error || 'Submission failed.')

    toast.success(data.message || 'Suggestion submitted.')
    displayName.value = ''; contactName.value = ''; email.value = ''; whatsapp.value = ''
    discord.value = ''; telegram.value = ''; website.value = ''; notes.value = ''
    file.value = null
    if (fileInput.value) fileInput.value.value = ''
    await loadSuggestions()
  } catch (err) {
    toast.error(err.message || 'Submission failed.')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.form-card { max-width: 560px; }
.disclaimer { font-style: italic; }
.field-row { margin-bottom: 14px; }
.field-row input, .field-row textarea { width: 100%; }
.label-sm { display: block; font-size: 12.5px; color: var(--text-secondary); margin-bottom: 4px; }
.radio-row { display: flex; gap: 16px; flex-wrap: wrap; font-size: 13px; }
.radio-row label { display: flex; align-items: center; gap: 4px; }

.suggestion-row { border-bottom: 1px solid var(--border); padding: 10px 0; }
.suggestion-row:last-child { border-bottom: none; }
.suggestion-head { display: flex; align-items: center; gap: 10px; cursor: pointer; }
.suggestion-name { font-weight: 600; }
.suggestion-body { margin-top: 8px; padding-left: 4px; }

.badge-vs-scored, .badge-vs-accepted { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }
.badge-vs-pending_parse, .badge-vs-processing { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning); }
.badge-vs-parse_failed, .badge-vs-virus_detected, .badge-vs-rejected { background: var(--danger-bg); color: var(--danger); border: 1px solid var(--danger); }
.badge-vs-dup { background: var(--surface-alt); color: var(--text-secondary); border: 1px solid var(--border); }
</style>
