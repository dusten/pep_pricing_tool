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
        <div class="review-row"><span class="label-sm">Vendor</span> {{ importRow.vendor_name }}</div>
        <div class="review-row"><span class="label-sm">Source file</span> {{ importRow.original_filename }}</div>
        <div class="review-row"><span class="label-sm">Reason</span> <span class="badge">{{ matchTypeLabel(importRow.match_type) }}</span></div>
        <div class="review-row"><span class="label-sm">Extracted name</span> {{ importRow.raw_json.canonical_name }}</div>
        <div class="review-row" v-if="importRow.candidate_name">
          <span class="label-sm">Closest existing match</span> {{ importRow.candidate_name }}
        </div>
        <div class="review-row"><span class="label-sm">Spec</span> {{ importRow.raw_json.spec_label }} ({{ importRow.raw_json.numeric_value }}{{ importRow.raw_json.unit }})</div>
        <div class="review-row"><span class="label-sm">Price</span> ${{ importRow.raw_json.price_usd }} — tier {{ importRow.raw_json.tier_kit_size || 1 }}-kit</div>

        <div class="review-actions">
          <label v-if="importRow.candidate_product_id" class="toggle-label">
            <input type="checkbox" v-model="mapToCandidate" /> Map onto "{{ importRow.candidate_name }}" instead of creating a new product
          </label>
          <button class="btn btn-primary btn-sm" @click="approveImport">Approve</button>
          <button class="btn btn-ghost btn-sm" @click="rejectImport">Reject</button>
        </div>
      </div>
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
    </div>
  </div>
</template>

<script setup>
import { ref, watch, onMounted } from 'vue'
import { get, post } from '@/utils/api.js'

const mode          = ref('imports')
const importRow      = ref(null)
const coaRow          = ref(null)
const mapToCandidate  = ref(true)

async function loadImport() {
  const res = await get('/api/vendors/pending-imports')
  importRow.value = res.done ? null : res
  mapToCandidate.value = true
}
async function loadCoa() {
  const res = await get('/api/admin/coa-queue')
  coaRow.value = res.done ? null : res
}

function matchTypeLabel(t) {
  return { new_product: 'New product', new_spec: 'New spec on existing product', name_mismatch: 'Name close to an existing product' }[t] || t
}

async function approveImport() {
  const productId = mapToCandidate.value ? importRow.value.candidate_product_id : null
  try {
    await post(`/api/vendors/pending-imports/${importRow.value.id}/approve`, productId ? { product_id: productId } : {})
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
</style>
