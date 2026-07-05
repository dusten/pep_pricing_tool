<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="showAdd = !showAdd">{{ showAdd ? 'Cancel' : '+ Add stack' }}</button>
    </div>

    <div v-if="showAdd" class="card add-form">
      <div class="field-row">
        <input v-model="form.name" placeholder="Stack name *" />
        <input v-model="form.description" placeholder="Description" style="flex:1" />
      </div>
      <button class="btn btn-primary btn-sm" @click="create">Create</button>
    </div>

    <table class="admin-table">
      <thead><tr><th>Name</th><th>Description</th><th>Components</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <template v-for="s in stacks" :key="s.id">
        <tr>
          <td>
            <input v-if="editingId === s.id" v-model="editForm.name" />
            <template v-else>{{ s.name }}</template>
          </td>
          <td>
            <input v-if="editingId === s.id" v-model="editForm.description" style="width:100%" />
            <template v-else>{{ s.description }}</template>
          </td>
          <td>{{ s.item_count }}</td>
          <td>
            <input v-if="editingId === s.id" type="checkbox" v-model="editForm.is_active" style="width:auto" />
            <span v-else :class="['badge', s.is_active ? 'badge-pro' : 'badge-free']">{{ s.is_active ? 'Active' : 'Inactive' }}</span>
          </td>
          <td class="actions">
            <template v-if="editingId === s.id">
              <button class="btn btn-primary btn-sm" @click="saveEdit(s)">Save</button>
              <button class="btn btn-ghost btn-sm" @click="cancelEdit">Cancel</button>
            </template>
            <template v-else>
              <button class="btn btn-ghost btn-sm" @click="startEdit(s)">Edit</button>
              <button class="btn btn-ghost btn-sm" @click="remove(s)">Delete</button>
            </template>
          </td>
        </tr>
        <tr v-if="editingId === s.id" class="items-row">
          <td colspan="5">
            <div class="label-sm">Components — the (product, spec) pairs this stack bulk-adds to a user's cart.</div>
            <div v-if="!itemsFor(s).length" class="text-muted text-sm">No components yet.</div>
            <div v-for="it in itemsFor(s)" :key="it.id" class="chip">
              {{ it.product }} — {{ it.spec }} <button class="chip-x" @click="removeItem(s, it)">×</button>
            </div>
            <div class="field-row" style="margin-top:10px">
              <select v-model="picker.productId" @change="onPickProduct(s)">
                <option value="">Product…</option>
                <option v-for="p in products" :key="p.id" :value="p.id">{{ p.canonical_name }}</option>
              </select>
              <select v-model="picker.specId">
                <option value="">Spec…</option>
                <option v-for="sp in picker.specs" :key="sp.id" :value="sp.id">{{ sp.spec_label }}</option>
              </select>
              <button class="btn btn-ghost btn-sm" @click="addItem(s)">+ Add component</button>
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
import { get, post, put, del } from '@/utils/api.js'

const stacks   = ref([])
const detail   = reactive({}) // stackId -> { items }
const products = ref([])
const showAdd  = ref(false)
const form     = reactive({ name: '', description: '' })
const editingId = ref(null)
const editForm   = reactive({ name: '', description: '', is_active: true })
const picker     = reactive({ productId: '', specId: '', specs: [] })

async function load() {
  const res = await get('/api/admin/stacks')
  stacks.value = res.stacks
  for (const s of res.stacks) {
    detail[s.id] = await get(`/api/admin/stacks/${s.id}`)
  }
}
onMounted(async () => {
  load()
  products.value = (await get('/api/products')).products
})

function itemsFor(s) { return detail[s.id]?.items || [] }

async function create() {
  if (!form.name.trim()) return
  await post('/api/admin/stacks', { ...form })
  form.name = ''; form.description = ''
  showAdd.value = false
  await load()
}

function startEdit(s) {
  editingId.value = s.id
  editForm.name = s.name
  editForm.description = s.description || ''
  editForm.is_active = s.is_active
  picker.productId = ''; picker.specId = ''; picker.specs = []
}
function cancelEdit() {
  editingId.value = null
}
async function saveEdit(s) {
  if (!editForm.name.trim()) return
  await put(`/api/admin/stacks/${s.id}`, { ...editForm })
  editingId.value = null
  await load()
}
async function remove(s) {
  if (!confirm(`Delete stack "${s.name}"? This cannot be undone.`)) return
  await del(`/api/admin/stacks/${s.id}`)
  await load()
}

async function onPickProduct(s) {
  picker.specId = ''
  picker.specs  = []
  if (!picker.productId) return
  const d = await get(`/api/products/${picker.productId}`)
  picker.specs = d.specifications || []
}
async function addItem(s) {
  if (!picker.productId || !picker.specId) return
  await post(`/api/admin/stacks/${s.id}/items`, { product_id: picker.productId, specification_id: picker.specId })
  picker.productId = ''; picker.specId = ''; picker.specs = []
  await load()
}
async function removeItem(s, it) {
  await del(`/api/admin/stacks/${s.id}/items/${it.id}`)
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
.actions { white-space: nowrap; }
.actions button + button { margin-left: 4px; }
.items-row td { background: var(--surface-alt); }
.items-row .label-sm { color: var(--text-muted); font-size: 11px; text-transform: uppercase; margin-bottom: 8px; }
</style>
