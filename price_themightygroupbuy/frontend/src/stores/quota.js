import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { get } from '@/utils/api.js'
import { useAuthStore } from '@/stores/auth.js'

export const useQuotaStore = defineStore('quota', () => {
  const data     = ref(null)   // raw API response
  const loading  = ref(false)

  const isFree      = computed(() => data.value && !data.value.unlimited)
  const remaining   = computed(() => data.value?.remaining ?? null)
  const used        = computed(() => data.value?.used ?? 0)
  const limit       = computed(() => data.value?.limit ?? 3)
  const resetsAt    = computed(() => data.value?.resets_at ?? null)
  const isExhausted = computed(() => isFree.value && remaining.value === 0)

  async function fetch() {
    const auth = useAuthStore()
    if (!auth.isAuthenticated) return
    loading.value = true
    try {
      data.value = await get('/api/me/quota')
    } catch { /* best effort */ }
    finally { loading.value = false }
  }

  function invalidate() { data.value = null }

  return { data, loading, isFree, remaining, used, limit, resetsAt, isExhausted, fetch, invalidate }
})
