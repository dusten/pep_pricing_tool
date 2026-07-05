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
                 :class="['vendor-row', { full: v.full_coverage }]">
              <div>
                <button class="vendor-name-btn" @click="openVendorId = v.vendor_id">{{ v.vendor_name }}</button>
                <span v-if="v.full_coverage" class="badge badge-pro">Covers all {{ v.total_items }}</span>
                <span v-else class="text-muted text-sm">{{ v.items_covered }} of {{ v.total_items }} items — missing {{ v.missing.join(', ') }}</span>
              </div>
              <span class="vendor-total">${{ v.total_usd.toFixed(2) }}</span>
            </div>
          </template>
        </div>
      </template>
    </template>

    <VendorCard v-if="openVendorId" :vendor-id="openVendorId" @close="openVendorId = null" />
  </AppLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import VendorCard from '@/components/VendorCard.vue'
import { useCartStore } from '@/stores/cart.js'

const cart = useCartStore()
const openVendorId = ref(null)
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
.vendor-row.full { background: var(--success-bg); margin: 0 -20px; padding: 10px 20px; }
.vendor-total { font-weight: 700; font-size: 15px; }
.vendor-name-btn {
  background: none; border: none; padding: 0; font: inherit; font-weight: 700; color: inherit;
  cursor: pointer; text-decoration: underline; text-decoration-color: transparent;
}
.vendor-name-btn:hover { text-decoration-color: currentColor; }
</style>
