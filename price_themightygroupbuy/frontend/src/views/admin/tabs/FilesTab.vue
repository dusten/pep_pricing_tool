<template>
  <div>
    <div class="toolbar">
      <button class="btn btn-accent btn-sm" :disabled="!pendingCount || batchRunning" @click="processAll">
        {{ batchRunning ? 'Processing…' : `Process All Pending (${pendingCount})` }}
      </button>
    </div>
    <table class="admin-table">
      <thead><tr><th>Vendor</th><th>File</th><th>Type</th><th>Status</th><th>Notes</th><th></th></tr></thead>
      <tbody>
        <tr v-for="f in files" :key="f.id">
          <td>{{ f.vendor_name }}</td>
          <td class="text-sm">{{ f.original_filename }}</td>
          <td>{{ f.file_type }}</td>
          <td><span :class="['badge', statusBadge(f.processing_status)]">{{ f.processing_status }}</span></td>
          <td class="text-muted text-sm notes-cell">{{ f.processing_notes || '—' }}</td>
          <td class="actions">
            <button class="btn btn-ghost btn-sm" :disabled="f.processing_status === 'processing'" @click="process(f)">
              {{ f.processing_status === 'processing' ? 'Processing…' : 'Process' }}
            </button>
            <a class="btn btn-ghost btn-sm" :href="`/api/files/${f.id}/download`" target="_blank">Download</a>
            <button class="btn btn-ghost btn-sm" @click="remove(f)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { get, post, del } from '@/utils/api.js'

const files = ref([])
const batchRunning = ref(false)
const pendingCount = computed(() => files.value.filter(f => f.processing_status === 'pending').length)

async function load() {
  const res = await get('/api/admin/files')
  files.value = res.files
}
onMounted(load)

function statusBadge(status) {
  return { complete: 'badge-pro', failed: 'badge-free', processing: 'badge-advanced', pending: 'badge-free' }[status] || 'badge-free'
}

async function processAll() {
  batchRunning.value = true
  try {
    const res = await post('/api/files/process-all', {})
    const imported = res.results.reduce((sum, r) => sum + (r.imported || 0), 0)
    const pending = res.results.reduce((sum, r) => sum + (r.pending || 0), 0)
    const failed = res.results.filter(r => r.error).length
    const queued = res.results.filter(r => r.queued).length
    alert(`${res.message}\n${imported} price row(s) imported, ${pending} sent to review, ${failed} failed, ${queued} queued for background processing.`)
  } catch (err) {
    alert(err.message)
  } finally {
    batchRunning.value = false
    await load()
  }
}

async function process(f) {
  f.processing_status = 'processing'
  try {
    const res = await post(`/api/files/${f.id}/process`, {})
    alert(res.message)
  } catch (err) {
    alert(err.message)
  } finally {
    await load()
  }
}
async function remove(f) {
  if (!confirm(`Remove the record for "${f.original_filename}"? The stored file stays on disk.`)) return
  await del(`/api/files/${f.id}`)
  await load()
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.notes-cell { max-width: 260px; }
.actions { display: flex; gap: 4px; white-space: nowrap; }
</style>
