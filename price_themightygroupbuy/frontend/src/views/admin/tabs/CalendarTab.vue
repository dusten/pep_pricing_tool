<template>
  <div class="card">
    <h3 style="margin-bottom:4px">Featured products</h3>
    <p class="text-muted text-sm" style="margin-bottom:18px">
      Pick one product to fully reveal (vendor, price, delta) to logged-out visitors on the public
      calendar for a given day. Everything else stays teased. All-time-low callouts are automatic.
    </p>

    <div class="feature-form">
      <label>
        <span>Date</span>
        <input type="date" v-model="form.feature_date" />
      </label>
      <label>
        <span>Product</span>
        <select v-model="form.product_id" @change="onProductChange">
          <option value="">Select a product…</option>
          <option v-for="p in products" :key="p.id" :value="p.id">{{ p.canonical_name }}</option>
        </select>
      </label>
      <label>
        <span>Spec (optional)</span>
        <select v-model="form.specification_id">
          <option value="">Cheapest across specs</option>
          <option v-for="s in specs" :key="s.id" :value="s.id">{{ s.spec_label }}</option>
        </select>
      </label>
      <label class="wide">
        <span>Note (optional)</span>
        <input type="text" v-model="form.note" maxlength="200" placeholder="e.g. Lowest we've seen this year" />
      </label>
      <button class="btn btn-primary btn-sm" :disabled="!form.feature_date || !form.product_id || saving" @click="save">
        {{ saving ? 'Saving…' : 'Set featured' }}
      </button>
    </div>

    <div class="cal-nav">
      <button class="btn btn-ghost btn-sm" @click="shiftMonth(-1)">&larr;</button>
      <strong>{{ month }}</strong>
      <button class="btn btn-ghost btn-sm" @click="shiftMonth(1)">&rarr;</button>
    </div>

    <table class="admin-table" v-if="features.length">
      <thead><tr><th>Date</th><th>Product</th><th>Spec</th><th>Note</th><th></th></tr></thead>
      <tbody>
        <tr v-for="f in features" :key="f.feature_date">
          <td>{{ f.feature_date }}</td>
          <td>{{ f.product }}</td>
          <td class="text-muted">{{ f.spec || 'cheapest' }}</td>
          <td class="text-muted text-sm">{{ f.note || '—' }}</td>
          <td><button class="btn btn-ghost btn-sm" @click="clear(f.feature_date)">Clear</button></td>
        </tr>
      </tbody>
    </table>
    <p v-else class="text-muted text-sm">No featured products set for {{ month }}.</p>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { get, post, del } from '@/utils/api.js'

const today = new Date()
const month = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const products = ref([])
const specs    = ref([])
const features = ref([])
const saving   = ref(false)

const form = reactive({
  feature_date: today.toISOString().slice(0, 10),
  product_id: '',
  specification_id: '',
  note: '',
})

async function loadProducts() {
  const res = await get('/api/products')
  products.value = res.products ?? res
}

async function loadFeatures() {
  const res = await get(`/api/admin/calendar-features?month=${month.value}`)
  features.value = res.features
}

async function onProductChange() {
  form.specification_id = ''
  specs.value = []
  if (!form.product_id) return
  const res = await get(`/api/products/${form.product_id}`)
  specs.value = res.specifications ?? []
}

async function save() {
  saving.value = true
  try {
    await post('/api/admin/calendar-features', {
      feature_date: form.feature_date,
      product_id: Number(form.product_id),
      specification_id: form.specification_id ? Number(form.specification_id) : null,
      note: form.note,
    })
    form.note = ''
    await loadFeatures()
  } finally {
    saving.value = false
  }
}

async function clear(date) {
  if (!confirm(`Clear the featured product for ${date}?`)) return
  await del(`/api/admin/calendar-features/${date}`)
  await loadFeatures()
}

function shiftMonth(delta) {
  const [y, m] = month.value.split('-').map(Number)
  const d = new Date(y, m - 1 + delta, 1)
  month.value = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
  loadFeatures()
}

loadProducts()
loadFeatures()
</script>

<style scoped>
.feature-form { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; align-items: end; margin-bottom: 22px; }
.feature-form label { display: flex; flex-direction: column; gap: 4px; font-size: 12px; color: var(--text-secondary); }
.feature-form label.wide { grid-column: span 2; }
.feature-form input, .feature-form select { padding: 7px 9px; border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); color: var(--text); font-size: 13px; }
.cal-nav { display: flex; align-items: center; gap: 12px; margin-bottom: 12px; }
</style>
