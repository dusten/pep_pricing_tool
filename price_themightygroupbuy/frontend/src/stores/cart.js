import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { get, post, del } from '@/utils/api.js'

export const useCartStore = defineStore('cart', () => {
  const items    = ref([])
  const vendors  = ref([]) // ranked vendor coverage/total breakdown, from the server
  const cheapestByItem = ref([]) // per-item cheapest vendor (mix-and-match)
  const cheapestTotal  = ref(0)  // sum of the per-item cheapest available lines
  const loading  = ref(false)

  const cheapestFullCoverage = computed(() => vendors.value.find(v => v.full_coverage) || null)

  function _apply(res) {
    items.value          = res.items
    vendors.value        = res.vendors
    cheapestByItem.value = res.cheapest_by_item || []
    cheapestTotal.value  = res.cheapest_total || 0
  }

  async function load() {
    loading.value = true
    try {
      _apply(await get('/api/cart'))
    } finally {
      loading.value = false
    }
  }

  async function add(productId, specificationId) {
    _apply(await post('/api/cart', { product_id: productId, specification_id: specificationId }))
  }

  async function remove(itemId) {
    await del(`/api/cart/${itemId}`)
    await load()
  }

  async function clear() {
    await Promise.all(items.value.map(it => del(`/api/cart/${it.id}`)))
    await load()
  }

  return { items, vendors, cheapestByItem, cheapestTotal, loading, cheapestFullCoverage, load, add, remove, clear }
})
