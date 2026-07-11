<template>
  <div>
    <div class="toolbar">
      <select v-model="selectedVendorId" @change="loadVendor">
        <option value="">Select a vendor…</option>
        <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.display_name }}</option>
      </select>
      <button v-if="selectedVendorId" :disabled="recalcing" @click="recalc" title="Recompute $/unit for every price line using the current price, vials/kit and spec — fixes rows imported before the kit-count fix">
        {{ recalcing ? 'Recalculating…' : 'Recalculate $/unit' }}
      </button>
    </div>

    <div v-if="!selectedVendorId" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
      Select a vendor to view and edit their price lines.
    </div>
    <div v-else-if="!prices.length" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
      No active prices for this vendor.
    </div>
    <table v-else class="admin-table">
      <thead>
        <tr>
          <th>Product</th><th>Spec</th><th>Tier</th><th>Price</th><th>Vials/kit</th><th>SKU</th><th>Non-standard</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pr in prices" :key="pr.id">
          <td>{{ pr.canonical_name }}</td>
          <td>{{ pr.spec_label }}</td>
          <td><input v-model.number="pr.tier_kit_size" type="number" min="1" max="255" style="width:55px" @change="save(pr)" /></td>
          <td>$<input v-model.number="pr.price_usd" type="number" step="any" min="0.01" style="width:75px" @change="save(pr)" /></td>
          <td><input v-model.number="pr.kit_vial_count" type="number" min="1" max="255" style="width:55px" @change="save(pr)" /></td>
          <td><input v-model="pr.vendor_sku" placeholder="—" style="width:90px" @change="save(pr)" /></td>
          <td><input type="checkbox" v-model="pr.non_standard_kit" @change="save(pr)" /></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, put, post } from '@/utils/api.js'
import { useToastStore } from '@/stores/toast.js'

const toast             = useToastStore()
const vendors          = ref([])
const selectedVendorId  = ref('')
const prices            = ref([])
const recalcing         = ref(false)

async function loadVendors() {
  const res = await get('/api/vendors')
  vendors.value = res.vendors
}
async function loadVendor() {
  if (!selectedVendorId.value) { prices.value = []; return }
  const res = await get(`/api/vendors/${selectedVendorId.value}`)
  prices.value = res.prices
}
async function save(pr) {
  try {
    await put(`/api/prices/${pr.id}`, {
      price_usd: pr.price_usd, kit_vial_count: pr.kit_vial_count,
      vendor_sku: pr.vendor_sku, tier_kit_size: pr.tier_kit_size, non_standard_kit: pr.non_standard_kit,
    })
  } catch (err) {
    toast.error(err.message)
    await loadVendor() // revert the edited field back to server state on failure
  }
}

async function recalc() {
  recalcing.value = true
  try {
    const res = await post(`/api/vendors/${selectedVendorId.value}/recalc-prices`)
    toast.success(res.message)
  } catch (err) {
    toast.error(err.message)
  } finally {
    recalcing.value = false
  }
}

onMounted(loadVendors)
</script>
