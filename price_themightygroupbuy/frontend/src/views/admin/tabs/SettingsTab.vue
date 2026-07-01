<template>
  <div>
    <div v-if="saved" class="alert alert-success">Settings saved.</div>
    <div class="settings-grid">
      <label class="toggle-row">
        <input type="checkbox" v-model="form.waitlist_mode" true-value="1" false-value="0" />
        Waitlist mode (invite-only registration)
      </label>
      <label class="toggle-row">
        <input type="checkbox" v-model="form.maintenance_mode" true-value="1" false-value="0" />
        Maintenance mode
      </label>

      <div class="field">
        <label>Referral credit (USD)</label>
        <input v-model="form.referral_credit_usd" type="number" step="0.01" min="0" />
      </div>
      <div class="field">
        <label>Free tier query limit</label>
        <input v-model="form.free_tier_query_limit" type="number" min="0" />
      </div>
      <div class="field">
        <label>Free tier window (hours)</label>
        <input v-model="form.free_tier_window_hours" type="number" min="1" />
      </div>
      <div class="field">
        <label>Annual discount (months free)</label>
        <input v-model="form.annual_discount_months_free" type="number" min="0" />
      </div>
      <div class="field">
        <label>Session lifetime (days)</label>
        <input v-model="form.session_lifetime_days" type="number" min="1" />
      </div>
    </div>
    <button class="btn btn-primary" :disabled="saving" @click="save">{{ saving ? 'Saving…' : 'Save settings' }}</button>
  </div>
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue'
import { get, post } from '@/utils/api.js'

const form   = reactive({
  waitlist_mode: '0', maintenance_mode: '0', referral_credit_usd: '5.00',
  free_tier_query_limit: '3', free_tier_window_hours: '72',
  annual_discount_months_free: '2', session_lifetime_days: '30',
})
const saving = ref(false)
const saved  = ref(false)

onMounted(async () => {
  const res = await get('/api/app-settings')
  Object.assign(form, res)
})

async function save() {
  saving.value = true
  saved.value  = false
  try {
    await post('/api/app-settings', { ...form })
    saved.value = true
    setTimeout(() => { saved.value = false }, 2500)
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.settings-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px 24px; margin-bottom: 20px; max-width: 640px; }
.toggle-row { display: flex; align-items: center; gap: 8px; font-size: 13.5px; grid-column: 1 / -1; }
.toggle-row input { width: auto; }
</style>
