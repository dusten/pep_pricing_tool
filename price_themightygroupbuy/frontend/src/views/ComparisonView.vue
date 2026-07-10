<template>
  <AppLayout title="Price Comparison" wide>
    <!-- Filter bar -->
    <div class="card filter-bar">
      <div v-if="comparison.tiers.length > 1" class="tier-tabs">
        <span class="tier-label">Kit size:</span>
        <button v-for="t in comparison.tiers" :key="t"
                :class="['cat-tab', { active: selectedTier === t }]"
                @click="selectedTier = t">{{ t }}-kit</button>
      </div>
      <div class="category-tabs">
        <button :class="['cat-tab', { active: !selectedClassifications.length }]"
                @click="selectedClassifications = []">All</button>
        <button v-for="c in comparison.classifications" :key="c.id"
                :class="['cat-tab', { active: selectedClassifications.includes(c.id) }]"
                @click="toggleClassification(c.id)">{{ c.name }}</button>
      </div>
      <div class="filter-row">
        <input v-model="search" type="text" placeholder="Search product or Cat No.…" class="search-input" />
        <label class="toggle-label">
          <input type="checkbox" v-model="multiOnly" />
          Only rows with multiple vendors
        </label>
        <label class="toggle-label">
          <input type="checkbox" v-model="verifiedOnly" />
          Verified vendors only
        </label>
        <label class="toggle-label">
          <input type="checkbox" v-model="rawMaterialOnly" />
          Raw/bulk powder only
        </label>
        <label class="toggle-label">
          <input type="checkbox" v-model="showUnitPricing" />
          Show $/unit
        </label>
        <div class="view-toggle">
          <button :class="['view-btn', { active: viewMode === 'table' }]" @click="viewMode = 'table'">Table</button>
          <button :class="['view-btn', { active: viewMode === 'list' }]" @click="viewMode = 'list'">List</button>
        </div>
        <template v-if="canExport">
          <button class="btn btn-ghost btn-sm" :disabled="exporting" @click="exportComparison('csv')">Export CSV</button>
          <button class="btn btn-ghost btn-sm" :disabled="exporting" @click="exportComparison('xlsx')">Export Excel</button>
        </template>
        <RouterLink v-else to="/pricing" class="feedback-pill">Export (Pro+)</RouterLink>
        <RouterLink to="/settings?feedback_type=product" class="feedback-pill">Product feedback</RouterLink>
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
    <div v-else-if="viewMode === 'table'" class="card table-card">
      <div class="table-scroll">
        <table class="cmp-table">
          <thead>
            <tr>
              <th class="sticky-col col-cart" rowspan="2"></th>
              <th class="sticky-col col-product" rowspan="2">Product</th>
              <th class="sticky-col col-spec" rowspan="2">Spec</th>
              <th v-for="v in vendorColumns" :key="v.id" :colspan="showUnitPricing ? 2 : 1" class="vendor-header vendor-divider">
                <button class="vendor-name-btn" :title="v.name" @click="openVendorCard(v.id)">{{ v.name }}</button>
                <span v-if="v.is_verified" class="badge badge-pro">✓</span>
              </th>
              <th rowspan="2" class="stat-header col-avg">Avg</th>
              <th rowspan="2" class="stat-header col-median">Median</th>
            </tr>
            <tr>
              <template v-for="v in vendorColumns" :key="'sub'+v.id">
                <th class="sub-header vendor-divider">Price</th>
                <th v-if="showUnitPricing" class="sub-header">$/unit</th>
              </template>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(row, i) in filteredRows" :key="row.product_id + ':' + row.spec" :class="{ odd: i % 2 === 1 }">
              <td class="sticky-col col-cart">
                <button class="btn btn-ghost btn-sm" :disabled="cartKeys.has(row.product_id + ':' + row.specification_id)"
                        @click="addToCart(row)">
                  {{ cartKeys.has(row.product_id + ':' + row.specification_id) ? 'Added' : '+ Cart' }}
                </button>
              </td>
              <td class="sticky-col col-product">{{ row.product }}</td>
              <td class="sticky-col col-spec">{{ row.spec }} <span v-if="row.is_raw_material" class="badge badge-free" title="Raw/bulk powder, not a finished vial">Raw</span></td>
              <template v-for="v in vendorColumns" :key="v.id">
                <template v-if="row.byVendor[v.id]">
                  <td class="vendor-divider" :class="{ lowest: row.byVendor[v.id].is_lowest }" :title="row.byVendor[v.id].vendor_sku ? `Cat No.: ${row.byVendor[v.id].vendor_sku}` : ''">
                    ${{ row.byVendor[v.id].price.toFixed(2) }}
                    <span v-if="row.byVendor[v.id].non_standard_kit" class="warn-icon"
                          :title="`Listed as ${row.byVendor[v.id].kit_vial_count}-vial kit — \$/unit may not be comparable.`">⚠</span>
                  </td>
                  <td v-if="showUnitPricing" :class="{ lowest: row.byVendor[v.id].is_lowest }">${{ row.byVendor[v.id].price_per_unit.toFixed(2) }}</td>
                </template>
                <template v-else>
                  <td class="blank vendor-divider"></td><td v-if="showUnitPricing" class="blank"></td>
                </template>
              </template>
              <td class="stat-cell col-avg">${{ row.stats.avg.toFixed(2) }}</td>
              <td class="stat-cell col-median">{{ row.stats.median === null ? '—' : '$' + row.stats.median.toFixed(2) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Compact list view — each row shows only the vendors that carry it,
         cheapest first. Far more readable on a phone and for sparse rows than
         the wide matrix. -->
    <div v-else class="list-view">
      <div v-for="row in filteredRows" :key="row.product_id + ':' + row.spec" class="card list-row">
        <div class="list-head">
          <div class="list-title">
            {{ row.product }} <span class="list-spec">{{ row.spec }}</span>
            <span v-if="row.is_raw_material" class="badge badge-free" title="Raw/bulk powder, not a finished vial">Raw</span>
          </div>
          <button class="btn btn-ghost btn-sm" :disabled="cartKeys.has(row.product_id + ':' + row.specification_id)" @click="addToCart(row)">
            {{ cartKeys.has(row.product_id + ':' + row.specification_id) ? 'Added' : '+ Cart' }}
          </button>
        </div>
        <div class="list-summary">
          Avg <strong>${{ row.stats.avg.toFixed(2) }}</strong>
          &nbsp;·&nbsp; Median <strong>{{ row.stats.median === null ? '—' : '$' + row.stats.median.toFixed(2) }}</strong>
          <span class="list-count">{{ row.vendors.length }} vendor{{ row.vendors.length !== 1 ? 's' : '' }}</span>
        </div>
        <div class="list-vendors">
          <div v-for="v in sortedVendors(row)" :key="v.vendor_id" :class="['list-vendor', { lowest: v.is_lowest }]">
            <button class="vendor-name-btn list-vendor-name" :title="v.name" @click="openVendorCard(v.vendor_id)">{{ v.name }}</button>
            <span class="list-vendor-price">
              ${{ v.price.toFixed(2) }}
              <span v-if="v.non_standard_kit" class="warn-icon" :title="`Listed as ${v.kit_vial_count}-vial kit — $/unit may not be comparable.`">⚠</span>
              <span v-if="showUnitPricing" class="list-ppu">${{ v.price_per_unit.toFixed(2) }}/unit</span>
            </span>
          </div>
        </div>
      </div>
    </div>

    <VendorCard v-if="openVendorId" :vendor-id="openVendorId" @close="openVendorId = null" />
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import VendorCard from '@/components/VendorCard.vue'
import { useComparisonStore } from '@/stores/comparison.js'
import { useCartStore } from '@/stores/cart.js'
import { useAuthStore } from '@/stores/auth.js'

const comparison = useComparisonStore()
const cart       = useCartStore()
const auth       = useAuthStore()
const cartKeys   = computed(() => new Set(cart.items.map(it => it.product_id + ':' + it.specification_id)))

const openVendorId = ref(null)
function openVendorCard(id) { openVendorId.value = id }

function addToCart(row) {
  cart.add(row.product_id, row.specification_id)
}

const canExport = computed(() => auth.isAdmin || (auth.tierActive && ['pro', 'expert'].includes(auth.tier)))
const exporting = ref(false)
async function exportComparison(format) {
  exporting.value = true
  try {
    const params = comparison.buildParams({
      vendors: selectedVendors.value, products: [], classificationIds: selectedClassifications.value,
      multiOnly: multiOnly.value, verifiedOnly: verifiedOnly.value, rawMaterialOnly: rawMaterialOnly.value,
      tier: selectedTier.value,
    })
    const res = await fetch(`/api/comparison/export/${format}?${params.toString()}`, {
      headers: { Authorization: `Bearer ${auth.token}` },
    })
    if (!res.ok) throw new Error('Export failed.')
    const blob = await res.blob()
    const url  = URL.createObjectURL(blob)
    const a    = document.createElement('a')
    a.href = url; a.download = `comparison.${format}`; a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    alert(err.message)
  } finally {
    exporting.value = false
  }
}

const selectedClassifications = ref([])
const selectedTier       = ref(1)
const search            = ref('')
const multiOnly          = ref(false)
const verifiedOnly       = ref(false)
const rawMaterialOnly    = ref(false)
const selectedVendors    = ref([])
// Hide the $/unit columns for a narrower, small-screen-friendly table.
// Persisted so a mobile user sets it once. Pure display — no re-query.
const showUnitPricing    = ref(localStorage.getItem('cmp_show_unit') !== '0')
watch(showUnitPricing, v => localStorage.setItem('cmp_show_unit', v ? '1' : '0'))

// Table (wide matrix) vs. List (compact per-row) view. Default to List on a
// phone-width screen, Table on desktop; persisted once the user picks.
const viewMode = ref(localStorage.getItem('cmp_view') || (window.innerWidth < 768 ? 'list' : 'table'))
watch(viewMode, v => localStorage.setItem('cmp_view', v))

// List view shows a row's vendors cheapest-first (by $/unit, matching the
// "lowest" highlight the server computes).
function sortedVendors(row) {
  return [...row.vendors].sort((a, b) => a.price_per_unit - b.price_per_unit)
}

function toggleClassification(id) {
  const i = selectedClassifications.value.indexOf(id)
  if (i === -1) selectedClassifications.value.push(id)
  else selectedClassifications.value.splice(i, 1)
}

function runSearch() {
  comparison.search({
    classificationIds: selectedClassifications.value, vendors: selectedVendors.value,
    multiOnly: multiOnly.value, verifiedOnly: verifiedOnly.value, rawMaterialOnly: rawMaterialOnly.value,
    tier: selectedTier.value,
  })
}

onMounted(async () => {
  await comparison.loadFilters()
  cart.load()
  runSearch()
})
watch([selectedClassifications, multiOnly, verifiedOnly, rawMaterialOnly, selectedVendors, selectedTier], runSearch, { deep: true })

const filteredRows = computed(() => {
  const q = search.value.trim().toLowerCase()
  return comparison.rows
    .filter(r => !q || r.product.toLowerCase().includes(q) || r.vendors.some(v => v.vendor_sku?.toLowerCase().includes(q)))
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
.tier-tabs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
.tier-label { font-size: 12.5px; color: var(--text-secondary); font-weight: 600; margin-right: 4px; }
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
.feedback-pill {
  margin-left: auto; padding: 6px 14px; border-radius: 99px; border: 1.5px solid var(--border);
  font-size: 12.5px; font-weight: 500; color: var(--text-secondary); text-decoration: none; transition: all var(--transition);
}
.feedback-pill:hover { border-color: var(--accent); color: var(--accent); }

.vendor-checks { display: flex; gap: 14px; flex-wrap: wrap; padding-top: 10px; border-top: 1px solid var(--border); }
.vendor-check { display: flex; align-items: center; gap: 5px; font-size: 12.5px; color: var(--text-secondary); }
.vendor-check input { width: auto; }

.table-card { padding: 0; overflow: hidden; }
/* Bounded to the viewport with its own scroll (both axes) so the horizontal
   scrollbar sits just below the visible rows, not at the bottom of however
   many rows the filters return — no more scrolling the whole page down to
   reach it. Header rows below are sticky within this box to match. */
.table-scroll { overflow: auto; max-height: 70vh; }
.cmp-table { border-collapse: collapse; width: 100%; font-size: 13px; white-space: nowrap; }
.cmp-table th, .cmp-table td { padding: 8px 12px; border-bottom: 1px solid var(--border); text-align: center; }
.cmp-table thead th { background: var(--primary); color: var(--text-on-primary); font-weight: 700; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.4px; text-align: center; }
.cmp-table thead tr:first-child th { position: sticky; top: 0; z-index: 3; }
.cmp-table thead tr:last-child th.sub-header { background: var(--info); font-size: 11px; position: sticky; top: 40px; z-index: 3; }
.stat-header { background: var(--success) !important; }
.vendor-name-btn {
  background: none; border: none; padding: 0; font: inherit; color: inherit;
  cursor: pointer; text-decoration: underline; text-decoration-color: transparent;
  display: inline-block; max-width: 80px; overflow: hidden; text-overflow: ellipsis;
  vertical-align: bottom;
}
.vendor-name-btn:hover { text-decoration-color: currentColor; }
/* Separates each vendor's Price/$-per-unit pair from the next vendor — long
   display names used to stretch the whole 2-column group to fit, leaving a
   lot of empty space in the narrow numeric cells below; truncating the name
   (title attr shows the full name on hover, and the vendor card on click)
   fixed that, this just makes the boundary between vendors visible too. */
.vendor-divider { border-left: 2px solid var(--border); }

.sticky-col { position: sticky; text-align: left !important; background: var(--surface); z-index: 2; }
.col-cart    { left: 0; width: 90px; min-width: 90px; max-width: 90px; text-align: center !important; }
.col-product { left: 90px; width: 170px; min-width: 170px; max-width: 170px; white-space: normal; word-break: break-word; font-weight: 600; }
.col-spec    { left: 260px; width: 80px; min-width: 80px; max-width: 80px; white-space: normal; word-break: break-word; color: var(--text-secondary); }
/* Must out-specificity ".cmp-table thead tr:first-child th" (which also sets
   z-index) or this loses to it — the corner cells (rowspan=2, sticky on both
   axes) need to beat every other sticky cell, header or body. */
.cmp-table thead tr th.sticky-col { z-index: 4; }

tr.odd td:not(.sticky-col) { background: var(--surface-alt); }
td.lowest { background: var(--success-bg); color: var(--success); font-weight: 700; }
td.blank  { background: transparent; }
.warn-icon { color: var(--warning); margin-left: 3px; cursor: help; }
.stat-cell { color: var(--text-secondary); font-weight: 500; }

/* Pin Avg + Median to the right edge so a row's summary stays visible while
   you scroll its (potentially many) vendor price columns — the fix for
   "the average doesn't match the one price I can see". */
.col-median { position: sticky; right: 0;    width: 76px; min-width: 76px; }
.col-avg    { position: sticky; right: 76px; width: 76px; min-width: 76px; border-left: 2px solid var(--border); }
td.col-avg, td.col-median { z-index: 2; background: var(--surface); }
tr.odd td.col-avg, tr.odd td.col-median { background: var(--surface-alt); }
.cmp-table thead th.col-avg, .cmp-table thead th.col-median { z-index: 5; } /* beat vendor sub-headers when scrolled */

/* View toggle */
.view-toggle { display: inline-flex; border: 1.5px solid var(--border); border-radius: 99px; overflow: hidden; }
.view-btn { padding: 5px 14px; border: none; background: var(--surface); cursor: pointer; font-size: 12.5px; font-weight: 500; color: var(--text-secondary); }
.view-btn.active { background: var(--primary); color: var(--text-on-primary); }

/* Compact list view */
.list-view { display: flex; flex-direction: column; gap: 12px; }
.list-row { padding: 14px 16px; }
.list-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 12px; margin-bottom: 8px; }
.list-title { font-size: 14.5px; font-weight: 600; }
.list-spec { color: var(--text-secondary); font-weight: 500; margin-left: 4px; }
.list-summary { font-size: 12.5px; color: var(--text-secondary); margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px solid var(--border); }
.list-count { float: right; }
.list-vendors { display: flex; flex-direction: column; gap: 6px; }
.list-vendor { display: flex; align-items: center; justify-content: space-between; gap: 12px; font-size: 13px; padding: 4px 8px; border-radius: var(--radius-sm); }
.list-vendor.lowest { background: var(--success-bg); }
.list-vendor.lowest .list-vendor-price { color: var(--success); font-weight: 700; }
.list-vendor-name { max-width: 60%; }
.list-vendor-price { white-space: nowrap; font-weight: 600; }
.list-ppu { color: var(--text-secondary); font-weight: 400; margin-left: 8px; font-size: 12px; }
</style>
