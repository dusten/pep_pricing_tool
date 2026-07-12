<template>
  <div class="bell-curve" :class="{ compact }">
    <svg v-if="hasSpread" :viewBox="`0 0 ${width} ${height}`" class="bell-curve-svg">
      <path :d="curvePath" class="curve-path" />
      <g v-for="p in plottedPoints" :key="p.vendorId">
        <circle :cx="p.x" :cy="p.y" :r="p.isLowest ? 5 : 3.5" :class="['point', { lowest: p.isLowest }]">
          <title>{{ p.name }}: ${{ p.value.toFixed(2) }}/{{ unit }}</title>
        </circle>
      </g>
    </svg>
    <!-- Degenerate case: every vendor at (or near) the same price — no real
         spread to draw a curve over, so show a single marker line instead
         of a division-by-zero curve. -->
    <div v-else class="no-spread">
      Every vendor is at the same price — ${{ mean.toFixed(2) }}/{{ unit }}
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  mean:    { type: Number, required: true },
  stdev:   { type: Number, required: true },
  points:  { type: Array, required: true }, // [{ vendorId, name, value, isLowest }]
  unit:    { type: String, default: 'unit' },
  compact: { type: Boolean, default: false },
})

const width  = 400
const height = computed(() => props.compact ? 100 : 160)
const topPad = 10

const hasSpread = computed(() => props.stdev > 0.0001 && Number.isFinite(props.stdev))

const domainMin = computed(() => props.mean - 3 * props.stdev)
const domainMax = computed(() => props.mean + 3 * props.stdev)

function pdf(x) {
  const s = props.stdev
  return (1 / (s * Math.sqrt(2 * Math.PI))) * Math.exp(-0.5 * ((x - props.mean) / s) ** 2)
}
const peakPdf = computed(() => pdf(props.mean))

function toX(value) {
  const span = domainMax.value - domainMin.value
  return ((value - domainMin.value) / span) * width
}
function toY(value) {
  const ratio = pdf(value) / peakPdf.value // 0..1, 1 at the peak
  return height.value - topPad - ratio * (height.value - topPad * 2)
}

const curvePath = computed(() => {
  if (!hasSpread.value) return ''
  const steps = 60
  let d = ''
  for (let i = 0; i <= steps; i++) {
    const x = domainMin.value + (i / steps) * (domainMax.value - domainMin.value)
    const px = toX(x)
    const py = toY(x)
    d += (i === 0 ? 'M' : 'L') + px.toFixed(1) + ' ' + py.toFixed(1) + ' '
  }
  return d
})

const plottedPoints = computed(() => {
  if (!hasSpread.value) return []
  return props.points.map(p => ({
    ...p,
    x: toX(p.value),
    y: toY(p.value),
  }))
})
</script>

<style scoped>
.bell-curve { width: 100%; }
.bell-curve-svg { width: 100%; height: auto; display: block; }
.curve-path { fill: none; stroke: var(--accent); stroke-width: 2; }
.point { fill: var(--primary); stroke: var(--surface); stroke-width: 1.5; cursor: help; }
.point.lowest { fill: var(--success); }
.no-spread { padding: 20px; text-align: center; color: var(--text-secondary); font-size: 13px; }
</style>
