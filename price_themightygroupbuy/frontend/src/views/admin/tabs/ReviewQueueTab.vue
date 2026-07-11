<template>
  <div>
    <div class="queue-toggle">
      <button :class="['admin-tab', { active: mode === 'imports' }]" @click="mode = 'imports'">Pending imports</button>
      <button :class="['admin-tab', { active: mode === 'coa' }]" @click="mode = 'coa'">COA submissions</button>
    </div>

    <!-- Pending price-import review (single-card, approve/reject, auto-advance) -->
    <div v-if="mode === 'imports'">
      <div v-if="!importRow" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
        Nothing pending review.
      </div>
      <div v-else class="card review-card">
        <div class="review-row"><span class="label-sm">Remaining</span> {{ importRow.remaining }} pending</div>
        <div class="review-row"><span class="label-sm">Vendor</span> {{ importRow.vendor_name }}</div>
        <div class="review-row"><span class="label-sm">Source file</span> {{ importRow.original_filename }}</div>
        <div class="review-row"><span class="label-sm">Reason</span> <span class="badge">{{ matchTypeLabel(importRow.match_type) }}</span></div>
        <div class="review-row" v-if="importRow.candidate_name">
          <span class="label-sm">Closest existing match</span> {{ importRow.candidate_name }}
        </div>

        <p class="text-muted text-sm hint">Values below are exactly what Claude extracted. A red border means it came back empty — everything else only needs a look, not an edit.</p>

        <div class="review-row"><span class="label-sm">Name</span> <input :class="{ 'needs-review': !importRow.raw_json.canonical_name }" v-model="importRow.raw_json.canonical_name" /></div>
        <div class="review-row"><span class="label-sm">Vendor SKU / Cat No.</span> <input v-model="importRow.raw_json.vendor_sku" placeholder="— (vendor may not use one)" /></div>
        <div class="review-row">
          <span class="label-sm">Spec</span>
          <input :class="{ 'needs-review': !importRow.raw_json.numeric_value }" v-model.number="importRow.raw_json.numeric_value" type="number" step="any" style="width:80px" />
          <input :class="{ 'needs-review': !importRow.raw_json.unit }" v-model="importRow.raw_json.unit" style="width:55px" />
          <span class="text-muted text-sm">label</span>
          <input :class="{ 'needs-review': !importRow.raw_json.spec_label }" v-model="importRow.raw_json.spec_label" style="width:110px" />
        </div>
        <div class="review-row">
          <span class="label-sm">Price / tier</span>
          $<input :class="{ 'needs-review': !importRow.raw_json.price_usd }" v-model.number="importRow.raw_json.price_usd" type="number" step="any" style="width:80px" />
          — tier <input v-model.number="importRow.raw_json.tier_kit_size" type="number" min="1" style="width:55px" />-kit
        </div>
        <div class="review-row">
          <label class="toggle-label"><input type="checkbox" v-model="importRow.raw_json.non_standard_kit" /> Non-standard kit size</label>
          <label class="toggle-label"><input type="checkbox" v-model="importRow.raw_json.is_raw_material" /> Raw/bulk powder (priced by weight, not a finished vial)</label>
        </div>

        <div class="review-actions">
          <label v-if="importRow.candidate_product_id" class="toggle-label">
            <input type="checkbox" v-model="mapToCandidate" /> Map onto "{{ importRow.candidate_name }}" instead of creating a new product
          </label>
          <button class="btn btn-primary btn-sm" @click="approveImport">Approve</button>
          <button class="btn btn-ghost btn-sm" @click="rejectImport">Reject</button>
        </div>
      </div>
      <p v-if="approveMsg" class="text-sm text-success approve-msg">{{ approveMsg }}</p>
    </div>

    <!-- COA submission review (single-card, approve/reject, auto-advance) -->
    <div v-else>
      <div v-if="!coaRow" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
        Nothing pending review.
      </div>
      <div v-else class="card review-card">
        <div class="review-row"><span class="label-sm">Submitted by</span> {{ coaRow.submitted_by }}</div>
        <div class="review-row"><span class="label-sm">Vendor</span> {{ coaRow.vendor_name }}</div>
        <div class="review-row"><span class="label-sm">Product</span> {{ coaRow.product_name || coaRow.custom_product_name + ' (custom blend)' }}</div>
        <div class="review-row"><span class="label-sm">COA</span> <a :href="coaRow.coa_url" target="_blank" rel="noopener">{{ coaRow.coa_url }}</a></div>

        <div class="review-actions">
          <button class="btn btn-primary btn-sm" @click="approveCoa">Approve</button>
          <button class="btn btn-ghost btn-sm" @click="rejectCoa">Reject</button>
        </div>
      </div>

      <div class="card coa-list-card">
        <div class="coa-list-header">
          <h4 style="margin:0">All COA submissions</h4>
          <select v-model="coaStatusFilter" @change="loadCoaList">
            <option value="">All statuses</option>
            <option value="pending">Pending</option>
            <option value="approved">Approved</option>
            <option value="rejected">Rejected</option>
          </select>
        </div>
        <table v-if="coaList.length" class="admin-table">
          <thead>
            <tr><th>Submitted</th><th>Vendor</th><th>Product</th><th>COA</th><th>Status</th><th></th></tr>
          </thead>
          <tbody>
            <tr v-for="s in coaList" :key="s.id">
              <td>{{ s.submitted_by }}<br><span class="text-muted text-sm">{{ new Date(s.created_at).toLocaleDateString() }}</span></td>
              <td>{{ s.vendor_name }}</td>
              <td>{{ s.product_name || (s.custom_product_name + ' (custom)') }}</td>
              <td><a :href="s.coa_url" target="_blank" rel="noopener">View</a></td>
              <td><span class="badge" :class="'badge-coa-' + s.status">{{ s.status }}</span></td>
              <td class="actions">
                <button v-if="s.status !== 'approved'" class="btn btn-ghost btn-sm" @click="setCoaStatus(s, 'approve')">Approve</button>
                <button v-if="s.status !== 'rejected'" class="btn btn-ghost btn-sm" @click="setCoaStatus(s, 'reject')">Reject</button>
                <button v-if="s.status !== 'pending'" class="btn btn-ghost btn-sm" @click="setCoaStatus(s, 'revoke')">Revoke</button>
              </td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-muted text-sm" style="padding:12px 0">No submissions.</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { get, post } from '@/utils/api.js'

const mode            = ref('imports')
const importRow       = ref(null)
const coaRow          = ref(null)
const mapToCandidate  = ref(true)
const approveMsg      = ref('')
const coaList         = ref([])
const coaStatusFilter = ref('')

async function loadImport() {
  const res = await get('/api/vendors/pending-imports')
  importRow.value = res.done ? null : res
  mapToCandidate.value = true
}
async function loadCoa() {
  const res = await get('/api/admin/coa-queue')
  coaRow.value = res.done ? null : res
  await loadCoaList()
}
async function loadCoaList() {
  const params = new URLSearchParams({ list: '1' })
  if (coaStatusFilter.value) params.set('status', coaStatusFilter.value)
  const res = await get(`/api/admin/coa-queue?${params.toString()}`)
  coaList.value = res.rows
}
async function setCoaStatus(s, action) {
  try {
    await post(`/api/admin/coa-queue/${s.id}/${action}`, {})
  } catch (err) {
    alert(err.message)
    return
  }
  await loadCoa()
}

function matchTypeLabel(t) {
  return { new_product: 'New product', new_spec: 'New spec on existing product', name_mismatch: 'Name close to an existing product' }[t] || t
}

async function approveImport() {
  const productId = mapToCandidate.value ? importRow.value.candidate_product_id : null
  const r = importRow.value.raw_json
  const body = {
    canonical_name: r.canonical_name, spec_label: r.spec_label, numeric_value: r.numeric_value,
    unit: r.unit, price_usd: r.price_usd, tier_kit_size: r.tier_kit_size,
    vendor_sku: r.vendor_sku, non_standard_kit: r.non_standard_kit, is_raw_material: r.is_raw_material,
  }
  if (productId) body.product_id = productId
  try {
    const res = await post(`/api/vendors/pending-imports/${importRow.value.id}/approve`, body)
    if (res.auto_approved_matches) {
      approveMsg.value = `Also auto-approved ${res.auto_approved_matches} other vendor(s) with the exact same item.`
      setTimeout(() => { approveMsg.value = '' }, 6000)
    }
  } catch (err) {
    alert(err.message)
    return
  }
  await loadImport()
}
async function rejectImport() {
  try {
    await post(`/api/vendors/pending-imports/${importRow.value.id}/reject`, {})
  } catch (err) {
    alert(err.message)
    return
  }
  await loadImport()
}
async function approveCoa() {
  try {
    await post(`/api/admin/coa-queue/${coaRow.value.id}/approve`, {})
  } catch (err) {
    alert(err.message)
    return
  }
  await loadCoa()
}
async function rejectCoa() {
  try {
    await post(`/api/admin/coa-queue/${coaRow.value.id}/reject`, {})
  } catch (err) {
    alert(err.message)
    return
  }
  await loadCoa()
}

watch(mode, (m) => { if (m === 'imports') loadImport(); else loadCoa() })
onMounted(loadImport)
</script>

<style scoped>
.queue-toggle { display: flex; gap: 6px; margin-bottom: 16px; }
.admin-tab {
  padding: 6px 16px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface);
  cursor: pointer; font-size: 13px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition);
}
.admin-tab:hover  { border-color: var(--accent); color: var(--accent); }
.admin-tab.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.review-card { max-width: 560px; }
.review-row { display: flex; gap: 8px; padding: 6px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.label-sm { min-width: 160px; color: var(--text-muted); font-size: 11px; text-transform: uppercase; }
.review-actions { display: flex; align-items: center; gap: 10px; margin-top: 16px; flex-wrap: wrap; }
.toggle-label { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary); }
.toggle-label input { width: auto; }
.hint { margin: 10px 0; }
.needs-review { border-color: var(--warning) !important; background: var(--warning-bg); }
.approve-msg { margin-top: 12px; }

.coa-list-card { margin-top: 20px; }
.coa-list-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.badge-coa-pending  { background: var(--warning-bg); color: var(--warning); border: 1px solid var(--warning); }
.badge-coa-approved { background: var(--success-bg); color: var(--success); border: 1px solid var(--success); }
.badge-coa-rejected { background: var(--danger-bg);  color: var(--danger);  border: 1px solid var(--danger); }
</style>
