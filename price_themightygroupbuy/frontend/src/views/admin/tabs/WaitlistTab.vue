<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" :disabled="!selected.length" @click="invite">
        Invite selected ({{ selected.length }})
      </button>
    </div>
    <table class="admin-table">
      <thead><tr><th></th><th>Email</th><th>Name</th><th>Invited</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <tr v-for="w in entries" :key="w.id">
          <td><input type="checkbox" v-model="selected" :value="w.id" :disabled="!!w.joined_at" /></td>
          <td>{{ w.email }}</td>
          <td>{{ w.name || '—' }}</td>
          <td class="text-muted text-sm">{{ w.invited_at || 'Not invited' }}</td>
          <td class="text-muted text-sm">{{ w.joined_at || '—' }}</td>
          <td><button class="btn btn-ghost btn-sm" @click="remove(w)">Remove</button></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, post, del } from '@/utils/api.js'

const entries  = ref([])
const selected = ref([])

async function load() {
  const res = await get('/api/admin/waitlist')
  entries.value = res.waitlist
  selected.value = []
}
onMounted(load)

async function invite() {
  await post('/api/admin/waitlist', { ids: selected.value })
  await load()
}
async function remove(w) {
  await del(`/api/admin/waitlist/${w.id}`)
  entries.value = entries.value.filter(e => e.id !== w.id)
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
</style>
