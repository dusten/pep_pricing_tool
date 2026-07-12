import { defineStore } from 'pinia'
import { ref } from 'vue'
import { get } from '@/utils/api.js'

export const useComparisonStore = defineStore('comparison', () => {
  const rows       = ref([])
  const vendors     = ref([])
  const products    = ref([])
  const classifications = ref([])
  const tiers       = ref([])
  const loading     = ref(false)
  const error       = ref(null)
  const quotaBlocked = ref(null) // { message, resets_at } when free-tier limit hit
  const totalActiveVendors = ref(0) // for the price-distribution feature's per-row coverage check

  async function loadFilters() {
    if (vendors.value.length || products.value.length) return
    try {
      const res = await get('/api/comparison/filters')
      vendors.value  = res.vendors
      products.value = res.products
      classifications.value = res.classifications
      tiers.value = res.tiers
    } catch { /* best effort */ }
  }

  function buildParams(filters) {
    const params = new URLSearchParams()
    ;(filters.vendors  || []).forEach(v => params.append('vendors[]', v))
    ;(filters.products || []).forEach(p => params.append('products[]', p))
    ;(filters.classificationIds || []).forEach(c => params.append('classification_ids[]', c))
    if (filters.multiOnly)       params.set('multi_only', '1')
    if (filters.verifiedOnly)    params.set('verified_only', '1')
    if (filters.rawMaterialOnly) params.set('raw_material_only', '1')
    if (filters.tier)            params.set('tier', filters.tier)
    return params
  }

  async function search(filters) {
    loading.value     = true
    error.value        = null
    quotaBlocked.value = null
    const params = buildParams(filters)

    try {
      const res = await get(`/api/comparison?${params.toString()}`)
      rows.value = res.rows
      totalActiveVendors.value = res.total_active_vendors || 0
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

  return { rows, vendors, products, classifications, tiers, loading, error, quotaBlocked, totalActiveVendors, loadFilters, search, buildParams }
})
