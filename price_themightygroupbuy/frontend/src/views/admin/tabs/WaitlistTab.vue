<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" :disabled="!selected.length" @click="invite">
        Invite selected ({{ selected.length }})
      </button>
      <button class="btn btn-danger btn-sm" :disabled="!selected.length" @click="bulkDelete">
        Delete selected ({{ selected.length }})
      </button>
      <button class="btn btn-ghost btn-sm" :disabled="!selected.length" @click="exportCsv(true)">
        Export selected
      </button>
      <button class="btn btn-ghost btn-sm" @click="exportCsv(false)">Export all</button>
    </div>
    <table class="admin-table">
      <thead><tr><th></th><th>Email</th><th>Name</th><th>Status</th><th></th></tr></thead>
      <tbody>
        <tr v-for="w in entries" :key="w.id">
          <td><input type="checkbox" v-model="selected" :value="w.id" :disabled="!!w.joined_at" /></td>
          <td>{{ w.email }}</td>
          <td>{{ w.name || '—' }}</td>
          <td><span :class="['badge', statusBadge(w)]">{{ statusLabel(w) }}</span></td>
          <td><button class="btn btn-ghost btn-sm" @click="remove(w)">Remove</button></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, post, del } from '@/utils/api.js'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

const entries  = ref([])
const selected = ref([])

async function load() {
  const res = await get('/api/admin/waitlist')
  entries.value = res.waitlist
  selected.value = []
}
onMounted(load)

function statusLabel(w) {
  return w.joined_at ? 'confirmed' : w.invited_at ? 'invited' : 'pending'
}
function statusBadge(w) {
  return w.joined_at ? 'badge-pro' : w.invited_at ? 'badge-advanced' : 'badge-free'
}

async function invite() {
  await post('/api/admin/waitlist', { ids: selected.value })
  await load()
}
async function bulkDelete() {
  await del('/api/admin/waitlist', { ids: selected.value })
  await load()
}
async function remove(w) {
  await del(`/api/admin/waitlist/${w.id}`)
  entries.value = entries.value.filter(e => e.id !== w.id)
}
async function exportCsv(selectedOnly) {
  const ids = selectedOnly ? selected.value.join(',') : ''
  const res = await fetch(`/api/admin/waitlist/export${ids ? '?ids=' + ids : ''}`, {
    headers: { Authorization: `Bearer ${auth.token}` },
  })
  const blob = await res.blob()
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'waitlist.csv'; a.click()
  URL.revokeObjectURL(url)
}
</script>

