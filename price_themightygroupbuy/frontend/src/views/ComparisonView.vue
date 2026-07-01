<template>
  <AppLayout title="Price Comparison">
    <!-- Filter bar -->
    <div class="card filter-bar">
      <div class="category-tabs">
        <button v-for="c in categories" :key="c.value"
                :class="['cat-tab', { active: category === c.value }]"
                @click="category = c.value">{{ c.label }}</button>
      </div>
      <div class="filter-row">
        <input v-model="search" type="text" placeholder="Search product…" class="search-input" />
        <label class="toggle-label">
          <input type="checkbox" v-model="multiOnly" />
          Only rows with multiple vendors
        </label>
        <label class="toggle-label">
          <input type="checkbox" v-model="verifiedOnly" />
          Verified vendors only
        </label>
      </div>
      <div v-if="comparison.vendors.length" class="vendor-checks">
        <label v-for="v in comparison.vendors" :key="v.id" class="vendor-check">
          <input type="checkbox" :value="v.id" v-model="selectedVendors" />
          {{ v.display_name }}
          <span v-if="v.is_verified" class="badge badge-pro">Verified</span>
        </label>
      </div>
    </div>

    <!-- Quota-blocked state -->
    <div v-if="comparison.quotaBlocked" class="card" style="text-align:center;padding:48px 32px">
      <h3 style="margin-bottom:8px">Comparison limit reached</h3>
      <p style="color:var(--text-secondary);margin-bottom:20px">{{ comparison.quotaBlocked.message }}</p>
      <RouterLink to="/pricing" class="btn btn-accent">View plans</RouterLink>
    </div>

    <div v-else-if="comparison.loading" class="card" style="text-align:center;padding:48px">
      <div class="spinner" style="margin:0 auto"></div>
    </div>

    <div v-else-if="!filteredRows.length" class="card" style="text-align:center;padding:48px 32px;color:var(--text-secondary)">
      No pricing data matches these filters yet.
    </div>

    <!-- Comparison table -->
    <div v-else class="card table-card">
      <div class="table-scroll">
        <table class="cmp-table">
          <thead>
            <tr>
              <th class="sticky-col col-product" rowspan="2">Product</th>
              <th class="sticky-col col-spec" rowspan="2">Spec</th>
              <th v-for="v in vendorColumns" :key="v.id" colspan="2" class="vendor-header">
                {{ v.name }}
                <span v-if="v.is_verified" class="badge badge-pro">✓</span>
              </th>
              <th rowspan="2" class="stat-header">Avg</th>
              <th rowspan="2" class="stat-header">Median</th>
            </tr>
            <tr>
              <template v-for="v in vendorColumns" :key="'sub'+v.id">
                <th class="sub-header">Price</th>
                <th class="sub-header">$/unit</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in filteredRows" :key="row.product_id + ':' + row.spec" :class="{ odd: i % 2 === 1 }">
              <td class="sticky-col col-product">{{ row.product }}</td>
              <td class="sticky-col col-spec">{{ row.spec }}</td>
              <template v-for="v in vendorColumns" :key="v.id">
                <template v-if="row.byVendor[v.id]">
                  <td :class="{ lowest: row.byVendor[v.id].is_lowest }">
                    ${{ row.byVendor[v.id].price.toFixed(2) }}
                    <span v-if="row.byVendor[v.id].non_standard_kit" class="warn-icon"
                          :title="`Listed as ${row.byVendor[v.id].kit_vial_count}-vial kit — \$/unit may not be comparable.`">⚠</span>
                  </td>
                  <td :class="{ lowest: row.byVendor[v.id].is_lowest }">${{ row.byVendor[v.id].price_per_unit.toFixed(2) }}</td>
                </template>
                <template v-else>
                  <td class="blank"></td><td class="blank"></td>
                </template>
              </template>
              <td class="stat-cell">${{ row.stats.avg.toFixed(2) }}</td>
              <td class="stat-cell">${{ row.stats.median.toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useComparisonStore } from '@/stores/comparison.js'

const comparison = useComparisonStore()

const categories = [
  { value: '',            label: 'All' },
  { value: 'glp1',        label: 'GLP-1' },
  { value: 'peptide',     label: 'Peptide' },
  { value: 'hormone',     label: 'Hormone' },
  { value: 'blend',       label: 'Blend' },
  { value: 'consumable',  label: 'Consumable' },
]

const category         = ref('')
const search            = ref('')
const multiOnly          = ref(false)
const verifiedOnly       = ref(false)
const selectedVendors    = ref([])

function runSearch() {
  comparison.search({
    category: category.value, vendors: selectedVendors.value,
    multiOnly: multiOnly.value, verifiedOnly: verifiedOnly.value,
  })
}

onMounted(async () => {
  await comparison.loadFilters()
  runSearch()
})
watch([category, multiOnly, verifiedOnly, selectedVendors], runSearch, { deep: true })

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return comparison.rows
    .filter(r => !q || r.product.toLowerCase().includes(q))
    .map(r => ({ ...r, byVendor: Object.fromEntries(r.vendors.map(v => [v.vendor_id, v])) }))
})

const vendorColumns = computed(() => {
  const map = new Map()
  for (const row of filteredRows.value) {
    for (const v of row.vendors) if (!map.has(v.vendor_id)) map.set(v.vendor_id, { name: v.name, is_verified: v.is_verified })
  }
  return [...map.entries()]
    .map(([id, v]) => ({ id, name: v.name, is_verified: v.is_verified }))
    .sort((a, b) => a.name.localeCompare(b.name))
})
</script>

<style scoped>
.filter-bar { margin-bottom: 20px; }
.category-tabs { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 14px; }
.cat-tab {
  padding: 6px 14px; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface);
  cursor: pointer; font-size: 12.5px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition);
}
.cat-tab:hover  { border-color: var(--accent); color: var(--accent); }
.cat-tab.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.filter-row { display: flex; gap: 16px; align-items: center; flex-wrap: wrap; margin-bottom: 12px; }
.search-input { max-width: 260px; }
.toggle-label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary); }
.toggle-label input { width: auto; }

.vendor-checks { display: flex; gap: 14px; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--border); }
.vendor-check { display: flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-secondary); }
.vendor-check input { width: auto; }

.table-card { padding: 0; overflow: hidden; }
.table-scroll { overflow-x: auto; }
.cmp-table { border-collapse: collapse; width: 100%; font-size: 13px; white-space: nowrap; }
.cmp-table th, .cmp-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: right; }
.cmp-table thead th { background: var(--primary); color: var(--text-on-primary); font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; text-align: center; }
.cmp-table thead tr:last-child th.sub-header { background: var(--info); font-size: 11px; }
.stat-header { background: var(--success) !important; }

.sticky-col { position: sticky; text-align: left !important; background: var(--surface); z-index: 2; }
.col-product { left: 0; min-width: 140px; font-weight: 600; }
.col-spec    { left: 140px; min-width: 80px; color: var(--text-secondary); }
thead .sticky-col { z-index: 3; }

tr.odd td:not(.sticky-col) { background: var(--surface-alt); }
td.lowest { background: var(--success-bg); color: var(--success); font-weight: 700; }
td.blank  { background: transparent; }
.warn-icon { color: var(--warning); margin-left: 3px; cursor: help; }
.stat-cell { color: var(--text-secondary); font-weight: 500; }
</style>
