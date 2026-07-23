<template>
  <AppLayout title="Shopping Cart">
    <div v-if="cart.loading" class="spinner" style="margin:48px auto"></div>

    <template v-else>
      <div v-if="!cart.items.length" class="card" style="text-align:center;padding:48px 32px;color:var(--text-secondary)">
        Your cart is empty. Add items from the <RouterLink to="/comparison">Comparison</RouterLink> page.
      </div>

      <template v-else>
        <div class="card items-card">
          <div class="items-header">
            <h3>{{ cart.items.length }} item{{ cart.items.length !== 1 ? 's' : '' }} in cart</h3>
            <button class="btn btn-ghost btn-sm" @click="cart.clear()">Clear cart</button>
          </div>
          <div v-for="it in cart.items" :key="it.id" class="cart-item">
            <span>{{ it.product }} — {{ it.spec }}</span>
            <button class="btn btn-ghost btn-sm" @click="cart.remove(it.id)">Remove</button>
          </div>
        </div>

        <div class="card">
          <h3 style="margin-bottom:14px">Cheapest vendor to buy this cart from</h3>
          <div v-if="!cart.vendors.length" class="text-muted text-sm">
            No vendor carries any of these items right now.
          </div>
          <template v-else>
            <div v-for="v in cart.vendors" :key="v.vendor_id"
                 :class="['vendor-row', { cheapest: v.vendor_id === cart.cheapestFullCoverage?.vendor_id }]">
              <div>
                <button class="vendor-name-btn" @click="openVendorId = v.vendor_id">{{ v.vendor_name }}</button>
                <span v-if="v.full_coverage" class="badge badge-pro">Covers all {{ v.total_items }}</span>
                <span v-else class="text-muted text-sm">{{ v.items_covered }} of {{ v.total_items }} items — missing {{ v.missing.join(', ') }}</span>
                <span v-if="v.vendor_id === cart.cheapestFullCoverage?.vendor_id" class="best-price-badge">Best price</span>
              </div>
              <span class="vendor-total">${{ v.total_usd.toFixed(2) }}</span>
            </div>
          </template>
        </div>

        <div class="card">
          <div class="items-header">
            <h3>Cheapest per item (mix &amp; match)</h3>
            <span v-if="allItemsAvailable" class="vendor-total">${{ cart.cheapestTotal.toFixed(2) }}</span>
          </div>
          <p class="text-muted text-sm" style="margin:-6px 0 14px">
            The lowest price for each item, buying each from whichever vendor is cheapest.
          </p>
          <div v-for="c in cart.cheapestByItem" :key="c.product_id + ':' + c.specification_id" class="split-row">
            <div>
              <div class="split-item">{{ c.product }} — {{ c.spec }}</div>
              <button v-if="c.vendor_id" class="vendor-name-btn text-sm" @click="openVendorId = c.vendor_id">{{ c.vendor_name }}</button>
              <span v-else class="text-muted text-sm">No vendor carries this</span>
            </div>
            <span v-if="c.price !== null" class="split-price">${{ c.price.toFixed(2) }}</span>
            <span v-else class="text-muted text-sm">—</span>
          </div>
          <div v-if="!allItemsAvailable" class="text-muted text-sm" style="margin-top:12px">
            Total shown once every item has at least one vendor. Available items so far: <strong>${{ cart.cheapestTotal.toFixed(2) }}</strong>.
          </div>
        </div>
      </template>
    </template>

    <VendorCard v-if="openVendorId" :vendor-id="openVendorId" @close="openVendorId = null" />
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import VendorCard from '@/components/VendorCard.vue'
import { useCartStore } from '@/stores/cart.js'

const cart = useCartStore()
const openVendorId = ref(null)
const allItemsAvailable = computed(() =>
  cart.cheapestByItem.length > 0 && cart.cheapestByItem.every(c => c.vendor_id !== null))
onMounted(() => cart.load())
</script>

<style scoped>
.items-card { margin-bottom: 20px; }
.items-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
.items-header h3 { font-size: 15px; margin: 0; }
.cart-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.cart-item:last-child { border-bottom: none; }

.vendor-row { display: flex; align-items: center; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border); }
.vendor-row:last-child { border-bottom: none; }
.vendor-row.cheapest {
  background: var(--accent-subtle); margin: 0 -20px; padding: 10px 20px;
  border-left: 3px solid var(--accent); border-bottom-color: transparent;
}
.vendor-total { font-weight: 700; font-size: 15px; }
.best-price-badge { background: var(--accent); color: var(--text-on-accent); font-size: 10.5px; font-weight: 700; letter-spacing: 0.3px; padding: 2px 7px; border-radius: 99px; margin-left: 8px; }
.vendor-name-btn {
  background: none; border: none; padding: 0; font: inherit; font-weight: 700; color: inherit;
  cursor: pointer; text-decoration: underline; text-decoration-color: transparent;
}
.vendor-name-btn:hover { text-decoration-color: currentColor; }

.split-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.split-row:last-child { border-bottom: none; }
.split-item { font-size: 13.5px; margin-bottom: 2px; }
.split-price { font-weight: 700; font-size: 14px; white-space: nowrap; }
</style>
