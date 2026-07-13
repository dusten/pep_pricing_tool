<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="showAdd = !showAdd">{{ showAdd ? 'Cancel' : '+ Add product' }}</button>
    </div>

    <div v-if="showAdd" class="card add-form">
      <div class="field-row">
        <input v-model="form.canonical_name" placeholder="Canonical name *" />
      </div>
      <div class="field-row">
        <span v-for="cid in form.classification_ids" :key="cid" class="chip">
          {{ classificationName(cid) }} <button class="chip-x" @click="form.classification_ids = form.classification_ids.filter(id => id !== cid)">×</button>
        </span>
        <select @change="form.classification_ids.push(+$event.target.value); $event.target.value=''">
          <option value="">+ classification…</option>
          <option v-for="c in classifications.filter(c => !form.classification_ids.includes(c.id))" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" @click="create">Create</button>
    </div>

    <table class="admin-table">
      <thead><tr><th>Name</th><th>CAS / g·mol⁻¹</th><th>Classifications</th><th>Aliases</th><th>Vendors</th><th>Merge into</th><th></th></tr></thead>
      <tbody>
        <template v-for="p in products" :key="p.id">
        <tr>
          <td>
            <input v-if="editingId === p.id" v-model="editForm.canonical_name" />
            <template v-else>{{ p.canonical_name }}</template>
          </td>
          <td>
            <template v-if="editingId === p.id">
              <input v-model="editForm.cas_number" placeholder="CAS #" style="width:90px" />
              <input v-model.number="editForm.molecular_weight" type="number" step="any" placeholder="g/mol" style="width:70px" />
            </template>
            <template v-else>
              <a v-if="p.cas_number" :href="pubchemUrl(p.cas_number)" target="_blank" rel="noopener">{{ p.cas_number }}</a>
              <span v-if="p.molecular_weight">{{ p.cas_number ? ' · ' : '' }}{{ p.molecular_weight }} g/mol</span>
            </template>
          </td>
          <td>
            <template v-if="editingId === p.id">
              <span v-for="cid in editForm.classification_ids" :key="cid" class="chip">
                {{ classificationName(cid) }} <button class="chip-x" @click="editForm.classification_ids = editForm.classification_ids.filter(id => id !== cid)">×</button>
              </span>
              <select @change="editForm.classification_ids.push(+$event.target.value); $event.target.value=''">
                <option value="">+ classification…</option>
                <option v-for="c in classifications.filter(c => !editForm.classification_ids.includes(c.id))" :key="c.id" :value="c.id">{{ c.name }}</option>
              </select>
              <button class="btn btn-ghost btn-sm" @click="addNewClassification">+ new tag</button>
            </template>
            <template v-else>
              <span v-for="c in classificationsFor(p)" :key="c.id" class="chip">{{ c.name }}</span>
            </template>
          </td>
          <td>
            <span v-for="a in aliasesFor(p)" :key="a.id" class="chip">
              {{ a.alias }} <button class="chip-x" @click="removeAlias(p, a)">×</button>
            </span>
            <template v-if="addingAliasFor === p.id">
              <input ref="aliasInputEl" v-model="newAliasText" placeholder="alias…" style="width:110px"
                     @keyup.enter="submitAlias(p)" @keyup.esc="cancelAlias" @blur="submitAlias(p)" />
            </template>
            <button v-else class="btn btn-ghost btn-sm" @click="startAlias(p)">+ alias</button>
          </td>
          <td>{{ p.vendor_count }}</td>
          <td>
            <select @change="merge(p, $event.target.value); $event.target.value=''">
              <option value="">Merge into…</option>
              <option v-for="o in products.filter(o => o.id !== p.id)" :key="o.id" :value="o.id">{{ o.canonical_name }}</option>
            </select>
          </td>
          <td class="actions">
            <template v-if="editingId === p.id">
              <button class="btn btn-primary btn-sm" @click="saveEdit(p)">Save</button>
              <button class="btn btn-ghost btn-sm" @click="cancelEdit">Cancel</button>
            </template>
            <button v-else class="btn btn-ghost btn-sm" @click="startEdit(p)">Edit</button>
          </td>
        </tr>
        <tr v-if="editingId === p.id" class="detail-row">
          <td colspan="7">
            <div class="label-sm">Versions / specs — edit the mg amount, or move one onto a different product if it doesn't actually belong here (e.g. a blend wrongly filed under a single-compound product). Price/vial-count edits per vendor live on the Inventory tab.</div>
            <div v-if="!specsFor(p).length" class="text-muted text-sm">No specs yet.</div>
            <div v-for="s in specsFor(p)" :key="s.id" class="spec-block">
              <input v-model.number="s.numeric_value" type="number" step="any" style="width:70px" @change="saveSpec(s)" />
              <select v-model="s.unit" style="width:70px" @change="saveSpec(s)">
                <option value="mg">mg</option>
                <option value="iu">iu</option>
                <option value="ml">ml</option>
                <option value="other">other</option>
              </select>
              <input v-model="s.spec_label" style="width:130px" placeholder="label" @change="saveSpec(s)" />
              <select style="width:160px" @change="moveSpec(s, $event.target.value); $event.target.value=''">
                <option value="">Move to…</option>
                <option v-for="o in products.filter(o => o.id !== p.id)" :key="o.id" :value="o.id">{{ o.canonical_name }}</option>
              </select>
              <select style="width:160px" @change="mergeSpec(s, $event.target.value); $event.target.value=''">
                <option value="">Merge into…</option>
                <option v-for="o in specsFor(p).filter(o => o.id !== s.id)" :key="o.id" :value="o.id">{{ o.spec_label }}</option>
              </select>
              <span class="text-muted text-sm">{{ s.prices?.length || 0 }} active vendor price(s)</span>
            </div>
          </td>
        </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, nextTick } from 'vue'
import { get, post, put, del } from '@/utils/api.js'
import { useToastStore } from '@/stores/toast.js'

const toast    = useToastStore()
const products = ref([])
const detail   = reactive({}) // productId -> { aliases, classifications, specifications }
const classifications = ref([]) // all available tags, for the pickers
const showAdd  = ref(false)
const form     = reactive({ canonical_name: '', classification_ids: [] })
const editingId = ref(null)
const editForm   = reactive({ canonical_name: '', classification_ids: [], cas_number: '', molecular_weight: null })

function pubchemUrl(cas) {
  return `https://pubchem.ncbi.nlm.nih.gov/#query=${encodeURIComponent(cas)}`
}

async function load() {
  const res = await get('/api/products')
  products.value = res.products
  for (const p of res.products) {
    detail[p.id] = { ...detail[p.id], aliases: p.aliases, classifications: p.classifications }
  }
}
async function loadSpecs(p) {
  const d = await get(`/api/products/${p.id}`)
  detail[p.id] = { ...detail[p.id], specifications: d.specifications }
}
async function loadClassifications() {
  const res = await get('/api/classifications')
  classifications.value = res.classifications
}
onMounted(() => { load(); loadClassifications() })

function aliasesFor(p) { return detail[p.id]?.aliases || [] }
function specsFor(p) { return detail[p.id]?.specifications || [] }
function classificationsFor(p) { return detail[p.id]?.classifications || [] }
function classificationName(id) { return classifications.value.find(c => c.id === id)?.name || '…' }

async function addNewClassification() {
  const name = prompt('New classification name:')
  if (!name) return
  const res = await post('/api/classifications', { name })
  await loadClassifications()
  editForm.classification_ids.push(res.id)
}

async function create() {
  if (!form.canonical_name.trim()) return
  await post('/api/products', { ...form })
  form.canonical_name = ''
  form.classification_ids = []
  showAdd.value = false
  await load()
}

function startEdit(p) {
  editingId.value = p.id
  editForm.canonical_name = p.canonical_name
  editForm.classification_ids = classificationsFor(p).map(c => c.id)
  editForm.cas_number = p.cas_number || ''
  editForm.molecular_weight = p.molecular_weight
  loadSpecs(p)
}
function cancelEdit() {
  editingId.value = null
}
async function saveEdit(p) {
  if (!editForm.canonical_name.trim()) return
  await put(`/api/products/${p.id}`, {
    canonical_name: editForm.canonical_name,
    classification_ids: editForm.classification_ids,
    cas_number: editForm.cas_number,
    molecular_weight: editForm.molecular_weight,
  })
  editingId.value = null
  await load()
}

// Spec edits only affect the one product currently expanded — refetch just its
// specs (loadSpecs) instead of the old approach of reloading every product's
// full detail via a 194-request loop just to refresh one row.
async function refreshEditingSpecs() {
  const p = products.value.find(x => x.id === editingId.value)
  if (p) await loadSpecs(p)
}

async function saveSpec(s) {
  if (!s.numeric_value || s.numeric_value <= 0 || !s.spec_label.trim()) return
  try {
    await put(`/api/products/specifications/${s.id}`, { spec_label: s.spec_label, numeric_value: s.numeric_value, unit: s.unit })
  } catch (err) {
    toast.error(err.message)
  }
  await load()
  await refreshEditingSpecs()
}

async function moveSpec(spec, targetProductId) {
  if (!targetProductId) return
  try {
    await post(`/api/products/specifications/${spec.id}/move`, { product_id: targetProductId })
  } catch (err) {
    toast.error(err.message)
    return
  }
  await load()
  await refreshEditingSpecs()
}

async function mergeSpec(spec, targetSpecId) {
  if (!targetSpecId) return
  if (!confirm(`Merge "${spec.spec_label}" into the selected spec? This cannot be undone.`)) return
  try {
    await post(`/api/products/specifications/${spec.id}/merge`, { into: targetSpecId })
  } catch (err) {
    toast.error(err.message)
    return
  }
  await load()
  await refreshEditingSpecs()
}

// Inline text input in place of the "+ alias" button, matching how every
// other field on this tab edits in-place — a native prompt() can't be
// styled, blocks the tab, and looks out of place next to the rest of the row.
const addingAliasFor = ref(null) // product id currently showing the input, or null
const newAliasText   = ref('')
const aliasInputEl    = ref(null)

async function startAlias(p) {
  addingAliasFor.value = p.id
  newAliasText.value = ''
  await nextTick()
  aliasInputEl.value?.[0]?.focus() // v-for + ref gives an array; only one is ever rendered at a time
}
function cancelAlias() {
  addingAliasFor.value = null
}
async function submitAlias(p) {
  if (addingAliasFor.value !== p.id) return // already closed via Escape or a prior Enter — avoid double-submit from the trailing blur
  const alias = newAliasText.value.trim()
  addingAliasFor.value = null
  if (!alias) return
  await post(`/api/products/${p.id}/aliases`, { alias })
  await load()
}
async function removeAlias(p, a) {
  await del(`/api/products/${p.id}/aliases/${a.id}`)
  await load()
}
async function merge(loser, winnerId) {
  if (!winnerId) return
  if (!confirm(`Merge "${loser.canonical_name}" into the selected product? This cannot be undone.`)) return
  await post(`/api/products/${winnerId}/merge`, { loser_id: loser.id })
  await load()
  await refreshEditingSpecs() // merge can move the loser's specs onto whichever product is currently expanded
}
</script>

<style scoped>
.add-form { margin-bottom: 16px; }
.field-row { display: flex; gap: 8px; margin-bottom: 12px; }
/* Only Name/Classifications/Aliases need to anchor to the top when Aliases wraps
   onto multiple lines — Vendors/Merge/Edit read better vertically centered
   in the row rather than pinned to the top with dead space underneath. */
.admin-table td:nth-child(-n+3) { vertical-align: top; }
.chip { display: inline-flex; align-items: center; gap: 3px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 99px; padding: 2px 8px; font-size: 11.5px; margin: 0 4px 4px 0; }
.chip-x { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 0; }
.spec-block { display: flex; align-items: center; gap: 6px; padding: 4px 0; }
</style>
