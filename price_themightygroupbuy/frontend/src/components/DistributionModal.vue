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
          active vendors ({{ data.coverage_pct }}%). This view needs at least 75% coverage to be a meaningful signal.
        </div>

        <div v-else-if="data">
          <h3 style="margin-bottom:2px">{{ data.product }} <span class="text-muted">{{ data.spec }}</span></h3>
          <p class="text-muted text-sm" style="margin-bottom:14px">
            {{ data.vendor_count }} of {{ data.total_active_vendors }} active vendors carry this item ({{ data.coverage_pct }}% coverage)
          </p>

          <BellCurveChart
            :mean="data.stats.unit_mean" :stdev="data.stats.unit_stdev || 0"
            :points="chartPoints" :unit="data.unit"
          />

          <table class="admin-table dist-table">
            <thead><tr><th>Vendor</th><th>$/{{ data.unit }}</th></tr></thead>
            <tbody>
              <tr v-for="v in sortedVendors" :key="v.vendor_id" :class="{ lowest: v.is_lowest }">
                <td>{{ v.name }}</td>
                <td>${{ v.price_per_unit.toFixed(2) }}</td>
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

const sortedVendors = computed(() => data.value?.qualifies
  ? [...data.value.vendors].sort((a, b) => a.price_per_unit - b.price_per_unit)
  : [])

const chartPoints = computed(() => sortedVendors.value.map(v => ({
  vendorId: v.vendor_id, name: v.name, value: v.price_per_unit, isLowest: v.is_lowest,
})))
</script>

<style scoped>
.dist-card { width: min(90vw, 640px); height: auto; max-height: 85vh; }
.dist-body { display: block; }
.dist-table { margin-top: 16px; }
.dist-table tr.lowest td { color: var(--success); font-weight: 600; }
</style>
