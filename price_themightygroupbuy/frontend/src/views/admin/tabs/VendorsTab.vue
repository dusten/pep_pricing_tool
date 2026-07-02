<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="startNew">+ New vendor</button>
    </div>

    <div class="card intake-form">
      <div class="field-row">
        <select v-model="selectedVendorId" @change="onSelectVendor">
          <option :value="null">— New vendor —</option>
          <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.display_name }}</option>
        </select>
        <label class="verified-toggle" v-if="selectedVendorId">
          <input type="checkbox" v-model="form.is_verified" /> Verified vendor
        </label>
      </div>

      <div class="field-row">
        <input v-model="form.display_name" placeholder="Vendor name *" />
        <input v-model="form.contact_name" placeholder="Contact name" />
        <input v-model="form.email" placeholder="Email" />
      </div>
      <div class="field-row">
        <input v-model="form.whatsapp" placeholder="WhatsApp" />
        <input v-model="form.discord" placeholder="Discord" />
        <input v-model="form.telegram" placeholder="Telegram" />
        <input v-model="form.website" placeholder="Website" />
      </div>

      <div class="field-row">
        <textarea v-model="form.shipping_note" placeholder="Shipping (carrier, timeframe, cost tiers — free text)" rows="8" class="note-box"></textarea>
      </div>
      <div class="field-row">
        <textarea v-model="form.notes" placeholder="Notes (payment addresses, anything else worth keeping)" rows="6" class="note-box"></textarea>
      </div>

      <div class="field-row">
        <div class="phones-editor">
          <span class="label-sm">Phone numbers</span>
          <span v-for="(p, i) in form.phones" :key="i" class="chip">
            {{ p }} <button class="chip-x" @click="form.phones.splice(i, 1)">×</button>
          </span>
          <input class="phone-input" v-model="newPhone" placeholder="+1 555…" @keyup.enter="addPhone" />
          <button class="btn btn-ghost btn-sm" @click="addPhone">+ add</button>
        </div>
      </div>

      <div class="field-row">
        <span class="label-sm">Payment methods</span>
      </div>
      <div class="payment-methods-grid">
        <label v-for="m in PAYMENT_METHODS" :key="m.value" class="pm-check">
          <input type="checkbox" :value="m.value" v-model="form.payment_methods" /> {{ m.label }}
        </label>
      </div>

      <div class="field-row">
        <textarea v-model="pasteText" :placeholder="INTAKE_TEMPLATE" rows="12" class="paste-box"></textarea>
      </div>
      <div class="field-row">
        <button class="btn btn-ghost btn-sm" @click="copyTemplate">
          {{ copyNote || 'Copy template to send to vendor' }}
        </button>
        <button class="btn btn-ghost btn-sm" :disabled="!pasteText.trim() || parsing" @click="parseIntake">
          {{ parsing ? 'Parsing…' : 'Parse reply' }}
        </button>
        <span v-if="parseNote" class="text-muted text-sm">{{ parseNote }}</span>
      </div>

      <div class="field-row">
        <button class="btn btn-primary btn-sm" @click="save">{{ selectedVendorId ? 'Save changes' : 'Create vendor' }}</button>
        <button v-if="selectedVendorId" class="btn btn-ghost btn-sm" @click="startNew">Cancel</button>
      </div>

      <div v-if="selectedVendorId" class="upload-row">
        <select v-model="uploadCategory">
          <option value="price_list">Price list</option>
          <option value="coa">COA</option>
          <option value="other">Other</option>
        </select>
        <input type="file" ref="fileInput" accept=".pdf,.xlsx,.csv,.jpg,.jpeg,.png" @change="upload" />
      </div>

      <div v-if="selectedVendorId && files.length" class="file-repo">
        <span class="label-sm">File repository</span>
        <table class="admin-table">
          <thead><tr><th>File</th><th>Category</th><th>Uploaded</th><th>Status</th></tr></thead>
          <tbody>
            <tr v-for="f in files" :key="f.id">
              <td class="text-sm">{{ f.original_filename }}</td>
              <td>{{ f.category }}</td>
              <td class="text-muted text-sm">{{ f.uploaded_at }}</td>
              <td><span class="badge">{{ f.processing_status }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <table class="admin-table">
      <thead><tr><th>Name</th><th>Contact</th><th>Prices</th><th>Last upload</th><th>Active</th><th>Merge into</th><th></th></tr></thead>
      <tbody>
        <tr v-for="v in vendors" :key="v.id">
          <td>{{ v.display_name }} <span v-if="v.is_verified" class="badge badge-pro">Verified</span></td>
          <td class="text-muted text-sm">{{ v.contact_name || v.email || '—' }}</td>
          <td>{{ v.price_count }}</td>
          <td class="text-muted text-sm">{{ v.last_upload || 'never' }}</td>
          <td>
            <input type="checkbox" :checked="v.is_active" @change="toggleActive(v, $event.target.checked)" />
          </td>
          <td>
            <select @change="mergeVendor(v, $event.target.value); $event.target.value=''">
              <option value="">Merge into…</option>
              <option v-for="o in vendors.filter(o => o.id !== v.id)" :key="o.id" :value="o.id">{{ o.display_name }}</option>
            </select>
          </td>
          <td>
            <button class="btn btn-ghost btn-sm" @click="selectedVendorId = v.id; onSelectVendor()">Manage</button>
            <button class="btn btn-ghost btn-sm" @click="deleteVendor(v)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { get, post, put, del } from '@/utils/api.js'

const PAYMENT_METHODS = [
  { value: 'usdt_sol', label: 'USDT (Solana)' }, { value: 'usdc_sol', label: 'USDC (Solana)' },
  { value: 'usdt_trc20', label: 'USDT (Tron)' }, { value: 'usdc_trc20', label: 'USDC (Tron)' },
  { value: 'usdt_erc20', label: 'USDT (ERC20)' }, { value: 'usdc_erc20', label: 'USDC (ERC20)' },
  { value: 'btc', label: 'BTC' }, { value: 'eth', label: 'ETH' }, { value: 'sol', label: 'SOL' },
  { value: 'paypal', label: 'PayPal' }, { value: 'wise', label: 'Wise' }, { value: 'alipay', label: 'Alipay' },
  { value: 'alibaba', label: 'Alibaba' }, { value: 'wire', label: 'Wire transfer' },
  { value: 'western_union', label: 'Western Union' }, { value: 'zelle', label: 'Zelle' },
  { value: 'cashapp', label: 'CashApp' }, { value: 'credit_card', label: 'Credit card' },
]

const INTAKE_TEMPLATE = `Vendor Name:
Contact Name:
Email:
WhatsApp:
Discord:
Telegram:
Website:
Phone Number(s):
Payment Methods (list all that apply — USDT/USDC Solana, USDT/USDC Tron, USDT/USDC ERC20,
  BTC, ETH, SOL, PayPal, Wise, Alipay, Alibaba, Wire Transfer, Western Union, Zelle, CashApp, Credit Card):
Shipping Note (carrier, timeframe, cost tiers):`

function emptyForm() {
  return {
    display_name: '', contact_name: '', email: '', whatsapp: '', discord: '', telegram: '',
    website: '', shipping_note: '', notes: '', is_verified: false, phones: [], payment_methods: [],
  }
}

const vendors          = ref([])
const selectedVendorId  = ref(null)
const form              = reactive(emptyForm())
const newPhone           = ref('')
const pasteText          = ref('')
const parsing            = ref(false)
const parseNote          = ref('')
const copyNote           = ref('')
const uploadCategory     = ref('price_list')
const fileInput          = ref(null)
const files              = ref([])

async function load() {
  const res = await get('/api/vendors')
  vendors.value = res.vendors
}
load()

function startNew() {
  selectedVendorId.value = null
  Object.assign(form, emptyForm())
  pasteText.value = ''
  parseNote.value = ''
  files.value = []
}

async function onSelectVendor() {
  if (!selectedVendorId.value) { startNew(); return }
  const v = await get(`/api/vendors/${selectedVendorId.value}`)
  Object.assign(form, {
    display_name: v.display_name || '', contact_name: v.contact_name || '', email: v.email || '',
    whatsapp: v.whatsapp || '', discord: v.discord || '', telegram: v.telegram || '',
    website: v.website || '', shipping_note: v.shipping_note || '', notes: v.notes || '', is_verified: v.is_verified,
    phones: v.phones || [], payment_methods: v.payment_methods || [],
  })
  files.value = v.files || []
  pasteText.value = ''
  parseNote.value = ''
}

async function copyTemplate() {
  await navigator.clipboard.writeText(INTAKE_TEMPLATE)
  copyNote.value = 'Copied!'
  setTimeout(() => { copyNote.value = '' }, 2000)
}

function addPhone() {
  const p = newPhone.value.trim()
  if (!p) return
  form.phones.push(p)
  newPhone.value = ''
}

async function parseIntake() {
  parsing.value = true
  parseNote.value = ''
  try {
    const res = await post('/api/vendors/parse-intake', { text: pasteText.value })
    const f = res.fields
    for (const key of ['display_name', 'contact_name', 'email', 'whatsapp', 'discord', 'telegram', 'website']) {
      if (f[key]) form[key] = f[key]
    }
    if (f.phones?.length) form.phones = f.phones
    if (f.payment_methods?.length) form.payment_methods = f.payment_methods
    if (f.shipping_note) form.shipping_note = f.shipping_note
    if (f.notes_append) form.notes = form.notes ? `${form.notes}\n${f.notes_append}` : f.notes_append
    parseNote.value = res.used_ai_fallback ? 'Parsed via AI fallback — review carefully before saving.' : 'Parsed. Review before saving.'
  } catch (err) {
    parseNote.value = 'Could not parse that reply: ' + err.message
  } finally {
    parsing.value = false
  }
}

async function save() {
  if (!form.display_name.trim() && pasteText.value.trim()) {
    await parseIntake()
  }
  if (!form.display_name.trim()) {
    alert('Vendor name is required — click "Parse reply" first, or fill in Vendor Name manually.')
    return
  }
  const body = { ...form }
  if (selectedVendorId.value) {
    await put(`/api/vendors/${selectedVendorId.value}`, body)
  } else {
    const res = await post('/api/vendors', body)
    selectedVendorId.value = res.id
    if (res.updated_existing) parseNote.value = 'A vendor named this already existed — updated it instead of creating a duplicate.'
  }
  await load()
  await onSelectVendor()
}

async function toggleActive(v, active) {
  await put(`/api/vendors/${v.id}`, { is_active: active })
  v.is_active = active
}

async function mergeVendor(loser, winnerId) {
  if (!winnerId) return
  const winner = vendors.value.find(o => o.id === Number(winnerId))
  if (!confirm(`Merge "${loser.display_name}" into "${winner.display_name}"? Prices, files, and payment info move to "${winner.display_name}"; "${loser.display_name}" is deleted. This cannot be undone.`)) return
  await post(`/api/vendors/${winnerId}/merge`, { loser_id: loser.id })
  if (selectedVendorId.value === loser.id) startNew()
  await load()
}

async function deleteVendor(v) {
  if (!confirm(`Delete "${v.display_name}"? Vendors with file history are deactivated instead of deleted.`)) return
  const res = await del(`/api/vendors/${v.id}`)
  if (selectedVendorId.value === v.id) startNew()
  await load()
  alert(res.message)
}

async function upload(event) {
  const file = event.target.files[0]
  if (!file || !selectedVendorId.value) return
  const body = new FormData()
  body.append('file', file)
  body.append('category', uploadCategory.value)
  const res = await fetch(`/api/vendors/${selectedVendorId.value}/files`, {
    method: 'POST', body, headers: { Authorization: 'Bearer ' + localStorage.getItem('pc_token') },
  })
  if (res.ok) { await onSelectVendor(); await load() }
  else { const data = await res.json().catch(() => ({})); alert(data.error || 'Upload failed.') }
  event.target.value = ''
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.intake-form { margin-bottom: 16px; }
.field-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; align-items: center; }
.field-row input, .field-row select { flex: 1; min-width: 140px; }
.paste-box { width: 100%; font-family: inherit; }
.note-box { width: 100%; font-family: inherit; resize: vertical; }
.label-sm { font-size: 11px; text-transform: uppercase; color: var(--text-secondary); margin-right: 8px; }
.verified-toggle { display: flex; align-items: center; gap: 6px; font-size: 13px; }
.verified-toggle input { width: auto; }
.phones-editor { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }
.phone-input { max-width: 140px; }
.payment-methods-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 8px 12px;
  margin-bottom: 12px;
}
.pm-check { display: flex; align-items: center; gap: 6px; font-size: 12px; }
.pm-check input { width: auto; margin: 0; }
.upload-row { display: flex; gap: 8px; margin-bottom: 12px; }
.upload-row select { max-width: 140px; }
.file-repo { margin-top: 8px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.chip { display: inline-flex; align-items: center; gap: 3px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 99px; padding: 2px 8px; font-size: 11.5px; }
.chip-x { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 0; }
.badge-pro { margin-left: 6px; }
</style>
