<template>
  <AppLayout title="Submit a COA">
    <div class="card form-card">
      <p class="text-muted text-sm" style="margin-bottom:16px">
        Bought from a vendor and had it lab-tested? Share the COA link here — an admin reviews every
        submission before it counts toward that vendor's verification.
      </p>

      <div class="field-row">
        <select v-model="vendorId">
          <option :value="null">Select vendor…</option>
          <option v-for="v in vendors" :key="v.id" :value="v.id">{{ v.display_name }}</option>
        </select>
      </div>

      <div class="field-row">
        <label class="toggle-label">
          <input type="checkbox" v-model="customBlend" />
          This is a custom blend not on their price list
        </label>
      </div>

      <div class="field-row" v-if="!customBlend">
        <select v-model="productId" :disabled="!vendorId">
          <option :value="null">Select product…</option>
          <option v-for="p in products" :key="p.id" :value="p.id">{{ p.canonical_name }}</option>
        </select>
      </div>
      <div class="field-row" v-else>
        <input v-model="customProductName" placeholder="Custom product name *" />
      </div>

      <div class="field-row">
        <input v-model="coaUrl" placeholder="COA URL *" />
      </div>

      <button class="btn btn-primary btn-sm" :disabled="!canSubmit || submitting" @click="submit">
        {{ submitting ? 'Submitting…' : 'Submit for review' }}
      </button>
      <p v-if="message" class="text-sm" style="margin-top:12px">{{ message }}</p>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import AppLayout from '@/components/AppLayout.vue'
import { get, post } from '@/utils/api.js'

const vendors           = ref([])
const products           = ref([])
const vendorId           = ref(null)
const productId          = ref(null)
const customBlend        = ref(false)
const customProductName  = ref('')
const coaUrl             = ref('')
const submitting         = ref(false)
const message            = ref('')

async function loadVendors() {
  const res = await get('/api/comparison/filters')
  vendors.value = res.vendors
}
loadVendors()

watch(vendorId, async (id) => {
  productId.value = null
  products.value = []
  if (!id) return
  const res = await get(`/api/coa/vendor-products?vendor_id=${id}`)
  products.value = res.products
})

const canSubmit = computed(() =>
  vendorId.value && coaUrl.value.trim() &&
  (customBlend.value ? customProductName.value.trim() : productId.value)
)

async function submit() {
  submitting.value = true
  message.value = ''
  try {
    await post('/api/coa/submit', {
      vendor_id: vendorId.value,
      product_id: customBlend.value ? null : productId.value,
      custom_product_name: customBlend.value ? customProductName.value.trim() : null,
      coa_url: coaUrl.value.trim(),
    })
    message.value = 'Thanks — your COA submission is pending admin review.'
    productId.value = null; customProductName.value = ''; coaUrl.value = ''; customBlend.value = false
  } catch (err) {
    message.value = err.message || 'Submission failed.'
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.form-card { max-width: 480px; }
.field-row { margin-bottom: 14px; }
.field-row select, .field-row input { width: 100%; }
.toggle-label { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--text-secondary); }
.toggle-label input { width: auto; }
</style>
