<template>
  <div>
    <p class="text-muted text-sm" style="margin-bottom:16px">
      Downloads a ZIP containing a full database dump (<code>database.sql</code>) and every uploaded vendor file.
    </p>
    <button class="btn btn-accent" :disabled="downloading" @click="download">
      {{ downloading ? 'Preparing backup…' : 'Download backup' }}
    </button>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useToastStore } from '@/stores/toast.js'

const toast       = useToastStore()
const downloading = ref(false)

async function download() {
  downloading.value = true
  try {
    const res = await fetch('/api/admin/backup', {
      headers: { Authorization: 'Bearer ' + localStorage.getItem('pc_token') },
    })
    if (!res.ok) throw new Error('Backup failed.')
    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href = url
    a.download = `price-backup-${new Date().toISOString().slice(0, 10)}.zip`
    a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    toast.error(err.message)
  } finally {
    downloading.value = false
  }
}
</script>
