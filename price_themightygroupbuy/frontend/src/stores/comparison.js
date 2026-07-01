import { defineStore } from 'pinia'
import { ref } from 'vue'
import { get } from '@/utils/api.js'

export const useComparisonStore = defineStore('comparison', () => {
  const rows       = ref([])
  const vendors     = ref([])
  const products    = ref([])
  const loading     = ref(false)
  const error       = ref(null)
  const quotaBlocked = ref(null) // { message, resets_at } when free-tier limit hit

  async function loadFilters() {
    if (vendors.value.length || products.value.length) return
    try {
      const res = await get('/api/comparison/filters')
      vendors.value  = res.vendors
      products.value = res.products
    } catch { /* best effort */ }
  }

  async function search(filters) {
    loading.value     = true
    error.value        = null
    quotaBlocked.value = null
    const params = new URLSearchParams()
    ;(filters.vendors  || []).forEach(v => params.append('vendors[]', v))
    ;(filters.products || []).forEach(p => params.append('products[]', p))
    if (filters.category)     params.set('category', filters.category)
    if (filters.multiOnly)    params.set('multi_only', '1')
    if (filters.verifiedOnly) params.set('verified_only', '1')

    try {
      const res = await get(`/api/comparison?${params.toString()}`)
      rows.value = res.rows
    } catch (err) {
      if (err.status === 402) {
        quotaBlocked.value = { message: err.data.message, resets_at: err.data.resets_at }
        rows.value = []
      } else {
        error.value = err.message || 'Failed to load comparison data.'
      }
    } finally {
      loading.value = false
    }
  }

  return { rows, vendors, products, loading, error, quotaBlocked, loadFilters, search }
})
