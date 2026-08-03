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
      <button v-if="selectedVendorId" class="btn btn-accent btn-sm" @click="showAddForm = !showAddForm">
        {{ showAddForm ? 'Cancel' : '+ Add product' }}
      </button>
    </div>

    <div v-if="selectedVendorId && showAddForm" class="card add-form">
      <div class="field-row">
        <span class="label-sm">Product</span>
        <input type="text" v-model="newRow.canonical_name" placeholder="Product name…" style="width:200px" />
        <select v-model="newRow.product_id" style="width:220px">
          <option value="">— Create new product "{{ newRow.canonical_name || '…' }}" —</option>
          <option v-for="p in filteredProducts" :key="p.id" :value="p.id">{{ p.canonical_name }}</option>
        </select>
      </div>
      <div class="field-row">
        <span class="label-sm">Spec</span>
        <input v-model.number="newRow.numeric_value" type="number" step="any" placeholder="Value" style="width:70px" />
        <input v-model="newRow.unit" placeholder="mg" style="width:55px" />
        <span class="text-muted text-sm">label</span>
        <input v-model="newRow.spec_label" placeholder="e.g. 10mg" style="width:100px" />
      </div>
      <div class="field-row">
        <span class="label-sm">Price / tier</span>
        $<input v-model.number="newRow.price_usd" type="number" step="any" min="0.01" style="width:80px" />
        — tier <input v-model.number="newRow.tier_kit_size" type="number" min="1" style="width:55px" />-kit
        — vials/kit <input v-model.number="newRow.kit_vial_count" type="number" min="1" style="width:55px" />
        — SKU <input v-model="newRow.vendor_sku" placeholder="—" style="width:90px" />
      </div>
      <div class="field-row">
        <label class="toggle-label"><input type="checkbox" v-model="newRow.non_standard_kit" /> Non-standard kit size</label>
        <label class="toggle-label"><input type="checkbox" v-model="newRow.is_raw_material" /> Raw/bulk powder</label>
        <label class="toggle-label"><input type="checkbox" v-model="newRow.is_tablet" /> Oral tablet</label>
      </div>
      <button class="btn btn-primary btn-sm" :disabled="adding" @click="addPrice">{{ adding ? 'Adding…' : 'Add price' }}</button>
    </div>

    <div v-if="!selectedVendorId" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
      Select a vendor to view and edit their price lines.
    </div>
    <div v-else-if="!prices.length" class="card" style="text-align:center;padding:32px;color:var(--text-secondary)">
      No prices for this vendor.
    </div>
    <table v-else class="admin-table">
      <thead>
        <tr>
          <th>Product</th><th>Spec</th><th>Tier</th><th>Price</th><th>$/unit</th><th>Vials/kit</th><th>SKU</th><th>Non-standard</th><th>Active</th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="pr in prices" :key="pr.id" :class="{ 'hidden-row': !pr.is_active }">
          <td>{{ pr.canonical_name }}</td>
          <td>{{ pr.spec_label }}</td>
          <td><input v-model.number="pr.tier_kit_size" type="number" min="1" max="255" style="width:55px" @change="save(pr)" /></td>
          <td>$<input v-model.number="pr.price_usd" type="number" step="any" min="0.01" style="width:75px" @change="save(pr)" /></td>
          <td class="text-muted text-sm">${{ pr.price_per_unit.toFixed(2) }}</td>
          <td><input v-model.number="pr.kit_vial_count" type="number" min="1" max="255" style="width:55px" @change="save(pr)" /></td>
          <td><input v-model="pr.vendor_sku" placeholder="—" style="width:90px" @change="save(pr)" /></td>
          <td><input type="checkbox" v-model="pr.non_standard_kit" @change="save(pr)" /></td>
          <td><input type="checkbox" v-model="pr.is_active" title="Uncheck to hide this line from every calculation (comparison, cart, stacks) without deleting it" @change="save(pr)" /></td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
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
    const res = await put(`/api/prices/${pr.id}`, {
      price_usd: pr.price_usd, kit_vial_count: pr.kit_vial_count,
      vendor_sku: pr.vendor_sku, tier_kit_size: pr.tier_kit_size, non_standard_kit: pr.non_standard_kit,
      is_active: pr.is_active,
    })
    pr.price_per_unit = res.price_per_unit // price/kit-count edits recompute this server-side
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

// "+ Add product" — a brand-new price line for the selected vendor, bypassing
// the file-upload/Claude pipeline. Product picker mirrors the Review Queue's
// "map to product" control: search the full catalog or create new, defaulting
// safely to create-new rather than guessing a match.
const showAddForm = ref(false)
const allProducts = ref([])
const filteredProducts = computed(() => {
  const q = (newRow.value.canonical_name || '').trim().toLowerCase()
  const matches = q ? allProducts.value.filter(p => p.canonical_name.toLowerCase().includes(q)) : allProducts.value
  return matches.slice().sort((a, b) => a.canonical_name.localeCompare(b.canonical_name))
})
function emptyNewRow() {
  return {
    canonical_name: '', product_id: '', spec_label: '', numeric_value: null, unit: 'mg',
    price_usd: null, tier_kit_size: 1, kit_vial_count: 10, vendor_sku: '',
    non_standard_kit: false, is_raw_material: false, is_tablet: false,
  }
}
const newRow = ref(emptyNewRow())
const adding = ref(false)

async function loadProducts() {
  const res = await get('/api/products')
  allProducts.value = res.products
}

async function addPrice() {
  adding.value = true
  try {
    const body = { ...newRow.value }
    if (body.product_id) delete body.canonical_name
    else body.create_new = true
    await post(`/api/vendors/${selectedVendorId.value}/prices`, body)
    toast.success('Price added.')
    newRow.value = emptyNewRow()
    showAddForm.value = false
    await loadVendor()
  } catch (err) {
    toast.error(err.message)
  } finally {
    adding.value = false
  }
}

onMounted(() => { loadVendors(); loadProducts() })
</script>

<style scoped>
.hidden-row { opacity: 0.5; }
.add-form { display: flex; flex-direction: column; gap: 10px; margin-bottom: 16px; }
.add-form .field-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.label-sm { min-width: 90px; color: var(--text-muted); font-size: 11px; text-transform: uppercase; }
.toggle-label { display: flex; align-items: center; gap: 6px; font-size: 12.5px; color: var(--text-secondary); }
.toggle-label input { width: auto; }
</style>
