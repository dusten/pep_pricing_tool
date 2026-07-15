<template>
  <div class="view-backdrop" @click.self="$emit('close')">
    <div class="view-card dist-card">
      <div class="view-header">
        <strong>Price distribution</strong>
        <button class="btn btn-ghost btn-sm" @click="$emit('close')">Close</button>
      </div>
      <div class="view-body dist-body">
        <div v-if="loading" class="spinner" style="margin:32px auto"></div>

        <div v-else-if="upsell" class="card" style="text-align:center;padding:40px 24px">
          <h3 style="margin-bottom:8px">Price distribution is a Pro+ feature</h3>
          <p style="color:var(--text-secondary);margin-bottom:20px">{{ upsell }}</p>
          <RouterLink to="/pricing" class="btn btn-accent" @click="$emit('close')">View plans</RouterLink>
        </div>

        <div v-else-if="data && !data.qualifies" class="card" style="text-align:center;padding:40px 24px;color:var(--text-secondary)">
          Not enough vendor coverage for this item yet — {{ data.vendor_count }} of {{ data.total_active_vendors }}
          active vendors carry it. This view needs at least {{ data.min_vendors }} vendors to be a meaningful signal.
        </div>

        <div v-else-if="data">
          <h3 style="margin-bottom:2px">{{ data.product }} <span class="text-muted">{{ data.spec }}</span></h3>
          <p class="text-muted text-sm" style="margin-bottom:14px">
            {{ data.vendor_count }} of {{ data.total_active_vendors }} active vendors carry this item
          </p>

          <label class="toggle-label" style="margin-bottom:10px">
            <input type="checkbox" v-model="showUnitPrice" />
            Show $/{{ data.unit }} instead of kit price
          </label>

          <BellCurveChart
            :mean="basis.mean" :stdev="basis.stdev || 0"
            :points="chartPoints" :unit="basis.label"
          />

          <table class="admin-table dist-table">
            <thead><tr><th>Vendor</th><th>{{ showUnitPrice ? `$/${data.unit}` : 'Kit price' }}</th></tr></thead>
            <tbody>
              <tr v-for="v in sortedVendors" :key="v.vendor_id" :class="{ lowest: v.is_lowest }">
                <td>{{ v.name }}</td>
                <td>${{ (showUnitPrice ? v.price_per_unit : v.price).toFixed(2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <p v-else-if="error" class="text-muted text-sm">{{ error }}</p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { get } from '@/utils/api.js'
import BellCurveChart from '@/components/BellCurveChart.vue'

const props = defineProps({
  productId: { type: Number, required: true },
  specificationId: { type: Number, required: true },
})
defineEmits(['close'])

const loading = ref(true)
const data    = ref(null)
const upsell  = ref('')
const error   = ref('')

onMounted(async () => {
  try {
    data.value = await get(`/api/comparison/distribution?product_id=${props.productId}&specification_id=${props.specificationId}`)
  } catch (err) {
    if (err.status === 402) upsell.value = err.data?.message || 'Upgrade to see the price-distribution chart for this item.'
    else error.value = err.message || 'Could not load the distribution.'
  } finally {
    loading.value = false
  }
})

// Kit price is the default basis (matches the Avg/Median columns already
// shown next to each vendor's kit Price on the Comparison table); $/unit is
// the opt-in view for comparing across different kit sizes.
const showUnitPrice = ref(false)

const sortedVendors = computed(() => data.value?.qualifies
  ? [...data.value.vendors].sort((a, b) => a.price_per_unit - b.price_per_unit)
  : [])

const basis = computed(() => showUnitPrice.value
  ? { mean: data.value.stats.unit_mean, stdev: data.value.stats.unit_stdev, label: data.value.unit }
  : { mean: data.value.stats.kit_mean, stdev: data.value.stats.kit_stdev, label: 'kit' })

const chartPoints = computed(() => sortedVendors.value.map(v => ({
  vendorId: v.vendor_id, name: v.name,
  value: showUnitPrice.value ? v.price_per_unit : v.price,
  isLowest: v.is_lowest,
})))
</script>

<style scoped>
.dist-card { width: min(90vw, 640px); height: auto; max-height: 85vh; }
.dist-body { display: block; }
.dist-table { margin-top: 16px; }
.dist-table tr.lowest td { color: var(--success); font-weight: 600; }
</style>
