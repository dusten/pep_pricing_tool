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
            <button v-if="f.file_type !== 'zip'" class="btn btn-ghost btn-sm" @click="viewFile(f)">View</button>
            <button class="btn btn-ghost btn-sm" @click="downloadFile(f)">Download</button>
            <button class="btn btn-ghost btn-sm" @click="remove(f)">Delete</button>
          </td>
        </tr>
      </tbody>
    </table>

    <div v-if="viewing" class="view-backdrop" @click.self="closeView">
      <div class="view-card">
        <div class="view-header">
          <span class="text-sm">{{ viewing.original_filename }}</span>
          <button class="btn btn-ghost btn-sm" @click="closeView">✕ Close</button>
        </div>
        <div class="view-body">
          <img v-if="viewing.file_type === 'image'" :src="viewUrl" />
          <iframe v-else-if="viewing.file_type === 'pdf'" :src="viewUrl + '#view=FitH'"></iframe>
          <pre v-else-if="viewText !== null" class="view-text">{{ viewText }}</pre>
          <p v-else class="text-muted">No inline preview for {{ viewing.file_type }} files — use Download.</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { get, post, del } from '@/utils/api.js'

const files = ref([])
const batchRunning = ref(false)
const pendingCount = computed(() => files.value.filter(f => f.processing_status === 'pending').length)

const viewing  = ref(null)
const viewUrl  = ref(null)
const viewText = ref(null)

async function load() {
  const res = await get('/api/admin/files')
  files.value = res.files
}
onMounted(load)

function onKeydown(e) { if (e.key === 'Escape' && viewing.value) closeView() }
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

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

// /api/files/{id}/download requires the same Bearer auth as every other
// endpoint — a plain <a href>/<img src> can't send that header, so both
// this and the old Download link (silently a 401 the whole time) need an
// authenticated fetch first, then work from the resulting blob.
async function fetchFileBlob(f) {
  const token = localStorage.getItem('pc_token')
  const res = await fetch(`/api/files/${f.id}/download`, { headers: { Authorization: 'Bearer ' + token } })
  if (!res.ok) throw new Error('Could not load the file.')
  return res.blob()
}

async function downloadFile(f) {
  try {
    const blob = await fetchFileBlob(f)
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = f.original_filename
    a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    alert(err.message)
  }
}

async function viewFile(f) {
  try {
    const blob = await fetchFileBlob(f)
    if (viewUrl.value) URL.revokeObjectURL(viewUrl.value)
    viewText.value = null
    if (f.file_type === 'image' || f.file_type === 'pdf') {
      viewUrl.value = URL.createObjectURL(blob)
    } else if (f.file_type === 'csv') {
      viewText.value = await blob.text()
    }
    viewing.value = f
  } catch (err) {
    alert(err.message)
  }
}

function closeView() {
  if (viewUrl.value) URL.revokeObjectURL(viewUrl.value)
  viewing.value  = null
  viewUrl.value  = null
  viewText.value = null
}
</script>

<style scoped>
.toolbar { margin-bottom: 14px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.notes-cell { max-width: 260px; }
.actions { display: flex; gap: 4px; white-space: nowrap; }

.view-backdrop {
  position: fixed; inset: 0; background: rgba(0, 0, 0, 0.6);
  display: flex; align-items: center; justify-content: center; z-index: 1000;
}
.view-card {
  background: var(--surface); border-radius: 8px; width: min(90vw, 900px); height: min(85vh, 900px);
  display: flex; flex-direction: column; overflow: hidden;
}
.view-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 10px 14px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.view-body { flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center; padding: 12px; position: relative; }
.view-body img { max-width: 100%; max-height: 100%; object-fit: contain; }
/* A flex item's height:100% doesn't reliably resolve against align-items:center
   (which opts it out of stretch) — the iframe fell back to its intrinsic
   ~150px default instead of filling the card. Absolute positioning against
   .view-body's own box sidesteps the flex percentage-height ambiguity — but
   iframe is a *replaced* element, so inset:0 alone still isn't enough; it
   keeps its own intrinsic 300x150 default unless width/height are also set
   explicitly (unlike a plain div, which would auto-size from the insets). */
.view-body iframe { position: absolute; inset: 0; width: 100%; height: 100%; border: none; }
.view-text { align-self: stretch; white-space: pre-wrap; font-size: 12px; font-family: monospace; padding: 8px; }
</style>
