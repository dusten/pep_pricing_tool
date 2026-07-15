<template>
  <div class="vc-backdrop" @click.self="$emit('close')">
    <div class="card vc-card">
      <button class="vc-close" @click="$emit('close')">&times;</button>

      <div v-if="loading" class="spinner" style="margin:32px auto"></div>

      <template v-else-if="vendor">
        <h3 class="vc-name">
          <span v-if="vendor.is_verified" class="badge badge-verified">✓ {{ vendor.display_name }}</span>
          <template v-else>{{ vendor.display_name }}</template>
        </h3>
        <p v-if="vendor.contact_name" class="text-muted text-sm" style="margin-bottom:14px">{{ vendor.contact_name }}</p>

        <div v-if="hasScorecard" class="vc-scorecard">
          <div v-if="vendor.scorecard.total_listings > 0" class="vc-score-row">
            <span class="vc-score-label">Competitiveness</span>
            <span>Cheapest on {{ vendor.scorecard.cheapest_count }} of {{ vendor.scorecard.total_listings }} listings ({{ vendor.scorecard.cheapest_pct }}%)</span>
          </div>
          <div v-if="vendor.scorecard.coa_submitted_count > 0" class="vc-score-row">
            <span class="vc-score-label">COAs</span>
            <span>{{ vendor.scorecard.coa_approved_count }} of {{ vendor.scorecard.coa_submitted_count }} submissions approved</span>
          </div>
          <div v-if="vendor.scorecard.price_change_count > 0" class="vc-score-row">
            <span class="vc-score-label">Price activity</span>
            <span>{{ vendor.scorecard.price_change_count }} change{{ vendor.scorecard.price_change_count !== 1 ? 's' : '' }} logged, last {{ formatDate(vendor.scorecard.last_price_change) }}</span>
          </div>
        </div>

        <div class="vc-rows">
          <a v-if="vendor.whatsapp" class="vc-row vc-link" :href="whatsappUrl" target="_blank" rel="noopener" @click="trackLinkClick('whatsapp')">
            <span class="vc-label">WhatsApp</span>
            <span>{{ vendor.whatsapp }}</span>
          </a>
          <div v-for="p in vendor.phones" :key="p" class="vc-row">
            <span class="vc-label">Phone</span><span>{{ p }}</span>
          </div>
          <a v-if="vendor.email" class="vc-row vc-link" :href="`mailto:${vendor.email}`">
            <span class="vc-label">Email</span><span>{{ vendor.email }}</span>
          </a>
          <div v-if="vendor.discord" class="vc-row">
            <span class="vc-label">Discord</span><span>{{ vendor.discord }}</span>
          </div>
          <div v-if="vendor.telegram" class="vc-row">
            <span class="vc-label">Telegram</span><span>{{ vendor.telegram }}</span>
          </div>
          <a v-if="vendor.website" class="vc-row vc-link" :href="vendor.website" target="_blank" rel="noopener" @click="trackLinkClick('website')">
            <span class="vc-label">Website</span><span>{{ vendor.website }}</span>
          </a>
        </div>

        <p v-if="vendor.shipping_note" class="text-muted text-sm vc-shipping">{{ vendor.shipping_note }}</p>
        <p v-if="!hasAnyContact" class="text-muted text-sm">No contact details on file for this vendor.</p>
      </template>

      <p v-else class="text-muted text-sm">Vendor not found.</p>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { get, post } from '@/utils/api.js'
import { useCartStore } from '@/stores/cart.js'

const props = defineProps({ vendorId: { type: Number, required: true } })
defineEmits(['close'])

const cart    = useCartStore()
const vendor  = ref(null)
const loading = ref(true)

onMounted(async () => {
  try {
    vendor.value = await get(`/api/vendors/${props.vendorId}/contact`)
  } finally {
    loading.value = false
  }
})

const hasAnyContact = computed(() => vendor.value && (
  vendor.value.whatsapp || vendor.value.phones?.length || vendor.value.email ||
  vendor.value.discord || vendor.value.telegram || vendor.value.website
))

// Backlog #51 — synthesizes bell-curve competitiveness + COA approval +
// price-history activity into one "should I trust/buy from this vendor" view.
const hasScorecard = computed(() => vendor.value?.scorecard && (
  vendor.value.scorecard.total_listings > 0 || vendor.value.scorecard.coa_submitted_count > 0 ||
  vendor.value.scorecard.price_change_count > 0
))
function formatDate(d) { return new Date(d).toLocaleDateString() }

// Best-effort click tracking for the admin activity dashboard — never
// blocks or delays the actual outbound navigation.
function trackLinkClick(linkType) {
  post('/api/track/link-click', { link_type: linkType, vendor_id: props.vendorId }).catch(() => {})
}

const whatsappUrl = computed(() => {
  if (!vendor.value?.whatsapp) return ''
  // Some vendors list more than one number ("+852 111/+852 222") — only the
  // first is dialable as a single wa.me number, so split before stripping
  // punctuation (stripping first would glue both numbers into one garbled string).
  const firstNumber = vendor.value.whatsapp.split(/[\/,]/)[0]
  const digits = firstNumber.replace(/\D+/g, '')
  let text = 'I found you on Price TheMightyGroupBuy, I would like to know more about: '
  const inCart = cart.vendors.find(v => v.vendor_id === props.vendorId)
  if (inCart?.covered_items?.length) {
    text += inCart.covered_items.map(it => it.sku).join(', ')
  }
  return `https://wa.me/${digits}?text=${encodeURIComponent(text)}`
})
</script>

<style scoped>
.vc-backdrop {
  position: fixed; inset: 0; background: rgba(0,0,0,.5); display: flex;
  align-items: center; justify-content: center; z-index: 100; padding: 20px;
}
.vc-card { position: relative; width: 100%; max-width: 380px; box-shadow: var(--shadow-lg); }
.vc-close {
  position: absolute; top: 10px; right: 12px; background: none; border: none;
  font-size: 22px; line-height: 1; color: var(--text-secondary); cursor: pointer;
}
.vc-name { font-size: 17px; margin-bottom: 4px; display: flex; align-items: center; gap: 8px; }
.vc-scorecard {
  background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius-sm);
  padding: 10px 12px; margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px;
}
.vc-score-row { display: flex; flex-direction: column; font-size: 12.5px; }
.vc-score-label { color: var(--text-secondary); font-weight: 600; font-size: 11px; text-transform: uppercase; }
.vc-rows { display: flex; flex-direction: column; gap: 2px; }
.vc-row {
  display: flex; justify-content: space-between; gap: 12px; padding: 9px 0;
  border-bottom: 1px solid var(--border); font-size: 13.5px; color: var(--text);
}
.vc-row:last-child { border-bottom: none; }
.vc-label { color: var(--text-secondary); font-weight: 600; }
.vc-link { text-decoration: none; }
.vc-link:hover .vc-label { color: var(--accent); }
.vc-shipping { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--border); }
</style>
