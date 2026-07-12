<template>
  <div ref="root" class="history-popover" :style="{ top: y + 'px', left: x + 'px' }" @click.stop>
    <div v-if="loading" class="text-muted text-sm">Loading…</div>
    <template v-else-if="changes.length">
      <div class="history-title">Price history</div>
      <ul class="history-list">
        <li v-for="(c, i) in changes" :key="i">
          <span class="history-date">{{ formatDate(c.changed_at) }}</span>
          <span>
            <template v-if="c.old_price !== null">${{ c.old_price.toFixed(2) }} →</template>
            ${{ c.new_price.toFixed(2) }}
            <span v-if="c.old_price !== null" :class="c.new_price > c.old_price ? 'up' : 'down'">
              {{ c.new_price > c.old_price ? '▲' : '▼' }}
            </span>
          </span>
          <span class="text-muted text-sm">{{ c.source === 'manual_edit' ? 'manual' : 'import' }}</span>
        </li>
      </ul>
    </template>
    <p v-else class="text-muted text-sm">No price changes on file.</p>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { get } from '@/utils/api.js'

const props = defineProps({
  vendorId: { type: Number, required: true },
  productId: { type: Number, required: true },
  specificationId: { type: Number, required: true },
  tierKitSize: { type: Number, default: 1 },
  x: { type: Number, default: 0 },
  y: { type: Number, default: 0 },
})
const emit = defineEmits(['close'])

const root    = ref(null)
const loading = ref(true)
const changes = ref([])

function formatDate(d) { return new Date(d).toLocaleDateString() }

function onDocClick(e) {
  if (root.value && !root.value.contains(e.target)) emit('close')
}
function onEscape(e) { if (e.key === 'Escape') emit('close') }

onMounted(async () => {
  document.addEventListener('click', onDocClick)
  document.addEventListener('keydown', onEscape)
  try {
    const res = await get(`/api/comparison/price-history?vendor_id=${props.vendorId}&product_id=${props.productId}&specification_id=${props.specificationId}&tier_kit_size=${props.tierKitSize}`)
    changes.value = res.changes
  } finally {
    loading.value = false
  }
})
onUnmounted(() => {
  document.removeEventListener('click', onDocClick)
  document.removeEventListener('keydown', onEscape)
})
</script>

<style scoped>
.history-popover {
  position: fixed; z-index: 500; background: var(--surface); border: 1px solid var(--border);
  border-radius: var(--radius-sm); box-shadow: 0 4px 16px rgba(0,0,0,0.2); padding: 12px 14px;
  min-width: 220px; max-width: 320px; font-size: 12.5px;
}
.history-title { font-weight: 600; margin-bottom: 8px; }
.history-list { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 6px; }
.history-list li { display: flex; flex-direction: column; gap: 1px; padding-bottom: 6px; border-bottom: 1px solid var(--border); }
.history-list li:last-child { border-bottom: none; padding-bottom: 0; }
.history-date { color: var(--text-muted); font-size: 11px; }
.up   { color: var(--danger); }
.down { color: var(--success); }
</style>
