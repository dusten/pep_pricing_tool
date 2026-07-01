<template>
  <div>
    <div v-if="!items.length" class="text-muted text-sm">No feedback submitted yet.</div>
    <table v-else class="admin-table">
      <thead><tr><th>Type</th><th>Message</th><th>From</th><th>Page</th><th>Date</th><th></th></tr></thead>
      <tbody>
        <tr v-for="f in items" :key="f.id" :class="{ unread: !f.is_read }">
          <td><span class="badge badge-free">{{ f.type }}</span></td>
          <td class="msg-cell">{{ f.message }}</td>
          <td>{{ f.display_name || f.user_email || '—' }}</td>
          <td class="text-muted text-sm">{{ f.url || '—' }}</td>
          <td class="text-muted text-sm">{{ f.created_at }}</td>
          <td>
            <button class="btn btn-ghost btn-sm" @click="toggleRead(f)">{{ f.is_read ? 'Mark unread' : 'Mark read' }}</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, patch } from '@/utils/api.js'

const items = ref([])

async function load() {
  const res = await get('/api/admin/feedback')
  items.value = res.feedback
}
onMounted(load)

async function toggleRead(f) {
  await patch(`/api/admin/feedback/${f.id}`, { is_read: !f.is_read })
  f.is_read = !f.is_read
}
</script>

<style scoped>
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; vertical-align: top; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
tr.unread { background: var(--accent-subtle); }
.msg-cell { max-width: 360px; }
</style>
