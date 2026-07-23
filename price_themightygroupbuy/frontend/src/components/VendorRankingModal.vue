<template>
  <div class="vc-backdrop" @click.self="$emit('close')">
    <div class="card vc-card vrm-card">
      <button class="vc-close" @click="$emit('close')">&times;</button>
      <h3 class="vc-name">Vendor ranking</h3>

      <div v-if="loading" class="spinner" style="margin:32px auto"></div>
      <ol v-else class="vrm-list">
        <li v-for="(v, i) in vendors" :key="v.id" class="vrm-row">
          <span class="vrm-rank">{{ i + 1 }}</span>
          <button class="vrm-body vrm-body-btn" @click="openVendorId = v.id">
            <div class="vrm-name-line">
              <span v-if="v.is_verified" class="badge badge-verified">✓ {{ v.display_name }}</span>
              <template v-else>{{ v.display_name }}</template>
              <span class="vrm-score">{{ v.score }}</span>
            </div>
            <p class="text-muted text-sm vrm-breakdown">
              Cheapest {{ v.cheapest_pct }}% · Carries {{ v.coverage_pct }}% of catalog · Payment: {{ v.payment_tier_label || 'None on file' }} (+{{ v.payment_bonus }})
            </p>
          </button>
        </li>
      </ol>
    </div>
    <VendorCard v-if="openVendorId" :vendor-id="openVendorId" @close="openVendorId = null" />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { get } from '@/utils/api.js'
import VendorCard from '@/components/VendorCard.vue'

const emit = defineEmits(['close'])

const vendors = ref([])
const loading = ref(true)
const openVendorId = ref(null)

function onEscape(e) { if (e.key === 'Escape') emit('close') }

onMounted(async () => {
  document.addEventListener('keydown', onEscape)
  try {
    vendors.value = (await get('/api/vendors/ranked')).vendors
  } finally {
    loading.value = false
  }
})
onUnmounted(() => document.removeEventListener('keydown', onEscape))
</script>

<style scoped>
/* Same popup pattern as VendorCard.vue's .vc-* classes — scoped styles
   don't cross components, so those rules are copied here rather than
   just reusing the class names. */
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
.vrm-card { max-width: 480px; max-height: 80vh; overflow-y: auto; }
.vrm-list { list-style: none; display: flex; flex-direction: column; gap: 2px; }
.vrm-row { display: flex; gap: 12px; padding: 9px 0; border-bottom: 1px solid var(--border); }
.vrm-row:last-child { border-bottom: none; }
.vrm-rank { font-weight: 700; color: var(--text-secondary); min-width: 18px; }
.vrm-body { flex: 1; }
.vrm-body-btn {
  background: none; border: none; padding: 0; font: inherit; color: inherit;
  text-align: left; cursor: pointer; width: 100%;
}
.vrm-body-btn:hover .vrm-name-line { text-decoration: underline; text-decoration-color: currentColor; }
.vrm-name-line { display: flex; align-items: center; justify-content: space-between; gap: 8px; font-size: 13.5px; }
.vrm-score { font-weight: 700; color: var(--primary); }
.vrm-breakdown { margin: 3px 0 0; }
</style>
