<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="showAdd = !showAdd">{{ showAdd ? 'Cancel' : '+ Add product' }}</button>
    </div>

    <div v-if="showAdd" class="card add-form">
      <div class="field-row">
        <input v-model="form.canonical_name" placeholder="Canonical name *" />
        <select v-model="form.category">
          <option value="peptide">Peptide</option>
          <option value="glp1">GLP-1</option>
          <option value="hormone">Hormone</option>
          <option value="blend">Blend</option>
          <option value="consumable">Consumable</option>
          <option value="other">Other</option>
        </select>
      </div>
      <button class="btn btn-primary btn-sm" @click="create">Create</button>
    </div>

    <table class="admin-table">
      <thead><tr><th>Name</th><th>Category</th><th>Aliases</th><th>Vendors</th><th>Merge into</th><th></th></tr></thead>
      <tbody>
        <template v-for="p in products" :key="p.id">
        <tr>
          <td>
            <input v-if="editingId === p.id" v-model="editForm.canonical_name" />
            <template v-else>{{ p.canonical_name }}</template>
          </td>
          <td>
            <select v-if="editingId === p.id" v-model="editForm.category">
              <option value="peptide">Peptide</option>
              <option value="glp1">GLP-1</option>
              <option value="hormone">Hormone</option>
              <option value="blend">Blend</option>
              <option value="consumable">Consumable</option>
              <option value="other">Other</option>
            </select>
            <template v-else>{{ p.category }}</template>
          </td>
          <td>
            <span v-for="a in aliasesFor(p)" :key="a.id" class="chip">
              {{ a.alias }} <button class="chip-x" @click="removeAlias(p, a)">×</button>
            </span>
            <button class="btn btn-ghost btn-sm" @click="addAlias(p)">+ alias</button>
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
        <tr v-if="editingId === p.id" class="specs-row">
          <td colspan="6">
            <div class="label-sm">Versions / specs — move one onto a different product if it doesn't actually belong here (e.g. a blend wrongly filed under a single-compound product)</div>
            <div v-if="!specsFor(p).length" class="text-muted text-sm">No specs yet.</div>
            <span v-for="s in specsFor(p)" :key="s.id" class="chip spec-chip">
              {{ s.spec_label }}
              <select @change="moveSpec(s, $event.target.value); $event.target.value=''">
                <option value="">Move to…</option>
                <option v-for="o in products.filter(o => o.id !== p.id)" :key="o.id" :value="o.id">{{ o.canonical_name }}</option>
              </select>
            </span>
          </td>
        </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { get, post, put, del } from '@/utils/api.js'

const products = ref([])
const detail   = reactive({}) // productId -> { aliases }
const showAdd  = ref(false)
const form     = reactive({ canonical_name: '', category: 'peptide' })
const editingId = ref(null)
const editForm   = reactive({ canonical_name: '', category: '' })

async function load() {
  const res = await get('/api/products')
  products.value = res.products
  for (const p of res.products) {
    const d = await get(`/api/products/${p.id}`)
    detail[p.id] = d
  }
}
onMounted(load)

function aliasesFor(p) { return detail[p.id]?.aliases || [] }
function specsFor(p) { return detail[p.id]?.specifications || [] }

async function create() {
  if (!form.canonical_name.trim()) return
  await post('/api/products', { ...form })
  form.canonical_name = ''
  showAdd.value = false
  await load()
}

function startEdit(p) {
  editingId.value = p.id
  editForm.canonical_name = p.canonical_name
  editForm.category = p.category
}
function cancelEdit() {
  editingId.value = null
}
async function saveEdit(p) {
  if (!editForm.canonical_name.trim()) return
  await put(`/api/products/${p.id}`, { canonical_name: editForm.canonical_name, category: editForm.category })
  editingId.value = null
  await load()
}

async function moveSpec(spec, targetProductId) {
  if (!targetProductId) return
  try {
    await post(`/api/products/specifications/${spec.id}/move`, { product_id: targetProductId })
  } catch (err) {
    alert(err.message)
    return
  }
  await load()
}

async function addAlias(p) {
  const alias = prompt('New alias for ' + p.canonical_name + ':')
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
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.add-form { margin-bottom: 16px; }
.field-row { display: flex; gap: 8px; margin-bottom: 12px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.chip { display: inline-flex; align-items: center; gap: 3px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 99px; padding: 2px 8px; font-size: 11.5px; margin: 0 4px 4px 0; }
.chip-x { background: none; border: none; cursor: pointer; color: var(--text-muted); font-size: 13px; padding: 0; }
.actions { display: flex; gap: 4px; white-space: nowrap; }
.specs-row td { background: var(--surface-alt); }
.specs-row .label-sm { color: var(--text-muted); font-size: 11px; text-transform: uppercase; margin-bottom: 8px; }
.spec-chip select { border: none; background: none; font-size: 11px; color: var(--text-secondary); padding: 0 0 0 4px; }
</style>
