<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="showAdd = !showAdd">{{ showAdd ? 'Cancel' : '+ Add product' }}</button>
    </div>

    <div v-if="showAdd" class="card add-form">
      <div class="field-row">
        <input v-model="form.canonical_name" placeholder="Canonical name *" />
        <input v-model="form.abbreviation" placeholder="Abbreviation" />
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
      <thead><tr><th>Name</th><th>Abbreviation</th><th>Category</th><th>Aliases</th><th>Vendors</th><th>Merge into</th></tr></thead>
      <tbody>
        <tr v-for="p in products" :key="p.id">
          <td>{{ p.canonical_name }}</td>
          <td class="text-muted text-sm">
            {{ p.abbreviation || '—' }}
            <button class="btn btn-ghost btn-sm" @click="editAbbreviation(p)">edit</button>
          </td>
          <td>{{ p.category }}</td>
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
        </tr>
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
const form     = reactive({ canonical_name: '', abbreviation: '', category: 'peptide' })

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

async function create() {
  if (!form.canonical_name.trim()) return
  await post('/api/products', { ...form })
  form.canonical_name = ''
  showAdd.value = false
  await load()
}

async function editAbbreviation(p) {
  const abbreviation = prompt('Abbreviation for ' + p.canonical_name + ':', p.abbreviation || '')
  if (abbreviation === null) return
  await put(`/api/products/${p.id}`, { abbreviation })
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
</style>
