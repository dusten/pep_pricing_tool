<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" @click="showAdd = !showAdd">{{ showAdd ? 'Cancel' : '+ Add vendor' }}</button>
    </div>

    <div v-if="showAdd" class="card add-form">
      <div class="field-row">
        <input v-model="form.display_name" placeholder="Display name *" />
        <input v-model="form.contact_name" placeholder="Contact name" />
        <input v-model="form.email" placeholder="Email" />
        <input v-model="form.whatsapp" placeholder="WhatsApp" />
        <input v-model="form.website" placeholder="Website" />
      </div>
      <button class="btn btn-primary btn-sm" @click="create">Create</button>
    </div>

    <table class="admin-table">
      <thead><tr><th>Name</th><th>Contact</th><th>Prices</th><th>Last upload</th><th>Active</th><th></th></tr></thead>
      <tbody>
        <tr v-for="v in vendors" :key="v.id">
          <td>{{ v.display_name }}</td>
          <td class="text-muted text-sm">{{ v.contact_name || v.email || '—' }}</td>
          <td>{{ v.price_count }}</td>
          <td class="text-muted text-sm">{{ v.last_upload || 'never' }}</td>
          <td>
            <input type="checkbox" :checked="v.is_active" @change="toggleActive(v, $event.target.checked)" />
          </td>
          <td>
            <input type="file" :ref="el => fileInputs[v.id] = el" style="display:none" accept=".pdf,.xlsx,.csv" @change="upload(v, $event)" />
            <button class="btn btn-ghost btn-sm" @click="fileInputs[v.id].click()">Upload file</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { get, post, patch } from '@/utils/api.js'

const vendors    = ref([])
const showAdd    = ref(false)
const fileInputs = reactive({})
const form       = reactive({ display_name: '', contact_name: '', email: '', whatsapp: '', website: '' })

async function load() {
  const res = await get('/api/vendors')
  vendors.value = res.vendors
}
onMounted(load)

async function create() {
  if (!form.display_name.trim()) return
  await post('/api/vendors', { ...form })
  Object.keys(form).forEach(k => form[k] = '')
  showAdd.value = false
  await load()
}

async function toggleActive(v, active) {
  await patch(`/api/vendors/${v.id}`, { is_active: active })
  v.is_active = active
}

async function upload(v, event) {
  const file = event.target.files[0]
  if (!file) return
  const body = new FormData()
  body.append('file', file)
  const res = await fetch(`/api/vendors/${v.id}/files`, {
    method: 'POST', body, headers: { Authorization: 'Bearer ' + localStorage.getItem('pc_token') },
  })
  if (res.ok) { alert('Uploaded. Process it from the Files tab.'); await load() }
  else alert('Upload failed.')
  event.target.value = ''
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.add-form { margin-bottom: 16px; }
.field-row { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }
.field-row input { flex: 1; min-width: 140px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
</style>
