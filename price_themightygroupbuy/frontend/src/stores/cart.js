import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { get, post, del } from '@/utils/api.js'

export const useCartStore = defineStore('cart', () => {
  const items    = ref([])
  const vendors  = ref([]) // ranked vendor coverage/total breakdown, from the server
  const loading  = ref(false)

  const cheapestFullCoverage = computed(() => vendors.value.find(v => v.full_coverage) || null)

  async function load() {
    loading.value = true
    try {
      const res = await get('/api/cart')
      items.value   = res.items
      vendors.value = res.vendors
    } finally {
      loading.value = false
    }
  }

  async function add(productId, specificationId) {
    const res = await post('/api/cart', { product_id: productId, specification_id: specificationId })
    items.value   = res.items
    vendors.value = res.vendors
  }

  async function remove(itemId) {
    await del(`/api/cart/${itemId}`)
    await load()
  }

  async function clear() {
    await Promise.all(items.value.map(it => del(`/api/cart/${it.id}`)))
    await load()
  }

  return { items, vendors, loading, cheapestFullCoverage, load, add, remove, clear }
})
