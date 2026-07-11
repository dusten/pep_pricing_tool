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
          <td class="text-muted text-sm notes-cell">
            <button v-if="f.processing_notes" class="notes-btn" @click="notesFile = f">{{ truncateNotes(f.processing_notes) }}</button>
            <span v-else>—</span>
          </td>
          <td class="actions">
            <button class="btn btn-ghost btn-sm" :disabled="f.processing_status === 'processing'" @click="process(f)">
              {{ f.processing_status === 'processing' ? 'Processing…' : 'Process' }}
            </button>
            <button v-if="f.file_type !== 'zip'" class="btn btn-ghost btn-sm" @click="viewFile(f)">View</button>
            <button class="btn btn-ghost btn-sm" @click="downloadFile(f)">Download</button>
            <button class="btn btn-ghost btn-sm" @click="openManual(f)">Manual JSON</button>
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
          <div v-else-if="viewing.file_type === 'pdf'" ref="pdfContainer" class="pdf-pages">
            <canvas v-for="p in pdfPageCount" :key="p" :ref="el => setPdfCanvasRef(el, p - 1)"></canvas>
          </div>
          <pre v-else-if="viewText !== null" class="view-text">{{ viewText }}</pre>
          <p v-else class="text-muted">No inline preview for {{ viewing.file_type }} files — use Download.</p>
        </div>
      </div>
    </div>

    <div v-if="manualFile" class="view-backdrop" @click.self="manualFile = null">
      <div class="view-card manual-card">
        <div class="view-header">
          <span class="text-sm">Manual JSON — {{ manualFile.original_filename }}</span>
          <button class="btn btn-ghost btn-sm" @click="manualFile = null">✕ Close</button>
        </div>
        <div class="view-body manual-body">
          <p class="text-muted text-sm">Paste an extraction result from another tool (Grok, hand-corrected JSON, etc.) — same shape as Claude's own output: an object with a <code>prices</code> array. This commits through the exact same logic a real "Process" click uses, without calling Claude.</p>
          <textarea v-model="manualJson" class="manual-textarea" placeholder='{"contact": {}, "warnings": [], "prices": [{"canonical_name":"","spec_label":"","numeric_value":0,"unit":"mg","price_usd":0,"kit_vial_count":10,"tier_kit_size":1,"vendor_sku":"","non_standard_kit":false,"is_raw_material":false}]}'></textarea>
          <div class="manual-actions">
            <button class="btn btn-primary btn-sm" :disabled="!manualJson.trim() || manualSubmitting" @click="submitManual">
              {{ manualSubmitting ? 'Submitting…' : 'Submit' }}
            </button>
            <span v-if="manualNote" class="text-sm">{{ manualNote }}</span>
          </div>
        </div>
      </div>
    </div>

    <div v-if="notesFile" class="view-backdrop" @click.self="notesFile = null">
      <div class="view-card notes-card">
        <div class="view-header">
          <span class="text-sm">Notes — {{ notesFile.original_filename }}</span>
          <button class="btn btn-ghost btn-sm" @click="notesFile = null">✕ Close</button>
        </div>
        <div class="view-body">
          <pre class="view-text">{{ notesFile.processing_notes }}</pre>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue'
import { get, post, del } from '@/utils/api.js'
import * as pdfjsLib from 'pdfjs-dist'

// Chrome's native PDF viewer (what an <iframe src="blob:..."> would use) has
// a long-documented history of sizing/resize bugs specifically with blob
// URLs — confirmed against this exact feature after three CSS-level fixes
// all failed to make it fill the card. Rendering with pdf.js to a plain
// <canvas> sidesteps the native viewer entirely; we fully control sizing.
//
// Pinned to 4.10.38, not latest: 5.x/6.x's internal "fingerprints" computation
// calls the native Uint8Array.prototype.toHex() directly with no feature
// detection (confirmed in both the normal and "legacy" builds of 6.1.200) —
// a JS engine method that only landed in Chromium 140+, so it throws
// "toHex is not a function" on any older browser. 4.10.38 still uses a
// feature-detected toHexUtil() wrapper for the same computation (falls back
// to a manual implementation when the native method isn't available) —
// confirmed via mozilla/pdf.js's own bug tracker, not assumed.
pdfjsLib.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

const files = ref([])
const batchRunning = ref(false)
const pendingCount = computed(() => files.value.filter(f => f.processing_status === 'pending').length)

const viewing  = ref(null)
const viewUrl  = ref(null)
const viewText = ref(null)
const pdfPageCount = ref(0)
const pdfContainer = ref(null)
let pdfDoc = null
let pdfCanvasEls = []

function setPdfCanvasRef(el, index) {
  if (el) pdfCanvasEls[index] = el
}

async function renderPdf(blob) {
  const arrayBuffer = await blob.arrayBuffer()
  pdfDoc = await pdfjsLib.getDocument({ data: arrayBuffer }).promise
  pdfCanvasEls = []
  pdfPageCount.value = pdfDoc.numPages
  await nextTick() // let Vue create the container + one canvas per page first

  const containerWidth = (pdfContainer.value?.clientWidth || 860) - 24 // minus .pdf-pages' own padding
  for (let i = 1; i <= pdfDoc.numPages; i++) {
    const page = await pdfDoc.getPage(i)
    const scale = containerWidth / page.getViewport({ scale: 1 }).width
    const viewport = page.getViewport({ scale })
    const canvas = pdfCanvasEls[i - 1]
    canvas.width = viewport.width
    canvas.height = viewport.height
    await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise
  }
}

async function load() {
  const res = await get('/api/admin/files')
  files.value = res.files
}
onMounted(load)

function onKeydown(e) { if (e.key === 'Escape' && viewing.value) closeView() }
onMounted(() => document.addEventListener('keydown', onKeydown))
onUnmounted(() => document.removeEventListener('keydown', onKeydown))

function statusBadge(status) {
  return { complete: 'badge-pro', failed: 'badge-free', processing: 'badge-advanced', pending: 'badge-free', skipped_duplicate: 'badge-pro' }[status] || 'badge-free'
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
const manualFile       = ref(null)
const manualJson       = ref('')
const manualSubmitting = ref(false)
const manualNote       = ref('')

const notesFile = ref(null)
function truncateNotes(notes) {
  return notes.length > 255 ? notes.slice(0, 255) + '…' : notes
}

function openManual(f) {
  manualFile.value = f
  manualJson.value = ''
  manualNote.value = ''
}
async function submitManual() {
  manualSubmitting.value = true
  manualNote.value = ''
  try {
    const res = await post(`/api/files/${manualFile.value.id}/manual-process`, { json: manualJson.value })
    manualNote.value = res.message
    await load()
  } catch (err) {
    manualNote.value = err.message
  } finally {
    manualSubmitting.value = false
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
    viewUrl.value      = null
    viewText.value     = null
    pdfPageCount.value = 0
    if (f.file_type === 'image') {
      viewUrl.value = URL.createObjectURL(blob)
    } else if (f.file_type === 'csv') {
      viewText.value = await blob.text()
    }
    viewing.value = f
    if (f.file_type === 'pdf') await renderPdf(blob)
  } catch (err) {
    alert(err.message)
  }
}

function closeView() {
  if (viewUrl.value) URL.revokeObjectURL(viewUrl.value)
  if (pdfDoc) { pdfDoc.destroy(); pdfDoc = null }
  pdfCanvasEls = []
  pdfPageCount.value = 0
  viewing.value  = null
  viewUrl.value  = null
  viewText.value = null
}
</script>

<style scoped>
.notes-cell { max-width: 260px; }
.notes-btn {
  background: none; border: none; padding: 0; font: inherit; color: inherit; text-align: left;
  cursor: pointer; text-decoration: underline; text-decoration-color: transparent; white-space: normal;
}
.notes-btn:hover { text-decoration-color: currentColor; }
.notes-card { height: min(70vh, 500px); }

/* Overrides the shared .view-body (main.css) — this one also centers an
   image/PDF preview and needs position:relative for the pdf-pages overlay. */
.view-body { flex: 1; overflow: auto; display: flex; align-items: center; justify-content: center; padding: 12px; position: relative; }
.view-body img { max-width: 100%; max-height: 100%; object-fit: contain; }
/* Same fill technique proven correct for the old iframe attempt (position:
   absolute + inset:0 against .view-body's own relatively-positioned box) —
   reused here since it empirically worked (1:1 fill ratio measured); the
   part that never worked was the native PDF viewer's own internal
   rendering, not this box-sizing approach, which is why PDF now renders via
   pdf.js canvases instead rather than yet another CSS attempt. */
.pdf-pages {
  position: absolute; inset: 0; overflow: auto; padding: 12px;
  display: flex; flex-direction: column; align-items: center; gap: 12px;
}
.pdf-pages canvas { max-width: 100%; box-shadow: 0 1px 4px rgba(0,0,0,0.25); }
.view-text { align-self: stretch; white-space: pre-wrap; font-size: 12px; font-family: monospace; padding: 8px; }

.manual-card { height: min(85vh, 700px); }
.manual-body { flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 10px; }
.manual-textarea { flex: 1; font-family: monospace; font-size: 12px; resize: none; min-height: 300px; }
.manual-actions { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
</style>
