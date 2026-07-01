<template>
  <AppLayout title="Price History">
    <div class="card">
      <div class="cal-header">
        <button class="btn btn-ghost btn-sm" @click="shiftMonth(-1)">&larr;</button>
        <h3>{{ monthLabel }}</h3>
        <button class="btn btn-ghost btn-sm" @click="shiftMonth(1)">&rarr;</button>
      </div>

      <div v-if="loading" class="spinner" style="margin:32px auto"></div>

      <template v-else>
        <div class="cal-grid">
          <div v-for="d in weekdayLabels" :key="d" class="cal-weekday">{{ d }}</div>
          <div v-for="n in leadingBlanks" :key="'b'+n" class="cal-cell blank"></div>
          <button v-for="day in daysInMonth" :key="day"
                  :class="['cal-cell', { active: selectedDay === day, 'has-changes': dayKey(day) in days }]"
                  @click="selectedDay = day">
            <span class="cal-day-num">{{ day }}</span>
            <span v-if="dayKey(day) in days" class="cal-dot">{{ days[dayKey(day)].length }}</span>
          </button>
        </div>

        <div v-if="selectedDay && dayKey(selectedDay) in days" class="day-detail">
          <h4>{{ monthLabel }} {{ selectedDay }} — {{ days[dayKey(selectedDay)].length }} price change(s)</h4>
          <table class="day-table">
            <thead><tr><th>Product</th><th>Spec</th><th>Vendor</th><th>Price</th><th>$/unit</th></tr></thead>
            <tbody>
              <tr v-for="(c, i) in days[dayKey(selectedDay)]" :key="i">
                <td>{{ c.product }}</td><td>{{ c.spec }}</td><td>{{ c.vendor }}</td>
                <td>${{ c.price.toFixed(2) }}</td><td>${{ c.price_per_unit.toFixed(2) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else-if="selectedDay" class="text-muted text-sm" style="margin-top:16px">No price changes that day.</p>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import AppLayout from '@/components/AppLayout.vue'
import { get } from '@/utils/api.js'

const today       = new Date()
const viewYear    = ref(today.getFullYear())
const viewMonth   = ref(today.getMonth()) // 0-indexed
const days        = ref({}) // { 'YYYY-MM-DD': [changes] }
const loading     = ref(false)
const selectedDay = ref(null)

const weekdayLabels = ['Su','Mo','Tu','We','Th','Fr','Sa']

const monthLabel = computed(() =>
  new Date(viewYear.value, viewMonth.value, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' }))

const daysInMonth    = computed(() => new Date(viewYear.value, viewMonth.value + 1, 0).getDate())
const leadingBlanks  = computed(() => new Date(viewYear.value, viewMonth.value, 1).getDay())

function dayKey(day) {
  return `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`
}

function shiftMonth(delta) {
  let m = viewMonth.value + delta
  let y = viewYear.value
  if (m < 0) { m = 11; y-- }
  if (m > 11) { m = 0; y++ }
  viewMonth.value = m
  viewYear.value  = y
  selectedDay.value = null
}

async function load() {
  loading.value = true
  const month = `${viewYear.value}-${String(viewMonth.value + 1).padStart(2, '0')}`
  try {
    const res = await get(`/api/calendar?month=${month}`)
    days.value = res.days
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch([viewYear, viewMonth], load)
</script>

<style scoped>
.cal-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.cal-header h3 { font-size: 15px; }

.cal-grid { display: grid; grid-template-columns: repeat(7, 1fr); gap: 4px; }
.cal-weekday { text-align: center; font-size: 11px; font-weight: 700; color: var(--text-muted); padding: 4px 0; text-transform: uppercase; }
.cal-cell {
  position: relative; aspect-ratio: 1; border: 1px solid var(--border); border-radius: var(--radius-sm);
  background: var(--surface); cursor: pointer; display: flex; flex-direction: column; align-items: center; justify-content: center;
  font-size: 13px; color: var(--text);
}
.cal-cell.blank { visibility: hidden; cursor: default; }
.cal-cell.has-changes { border-color: var(--accent); }
.cal-cell.active { background: var(--accent-subtle); border-color: var(--accent); }
.cal-dot {
  position: absolute; top: 2px; right: 3px; background: var(--accent); color: var(--text-on-accent);
  font-size: 9px; font-weight: 700; border-radius: 99px; min-width: 14px; height: 14px; display: flex; align-items: center; justify-content: center; padding: 0 3px;
}

.day-detail { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); }
.day-detail h4 { font-size: 13.5px; margin-bottom: 12px; }
.day-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.day-table th, .day-table td { padding: 6px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.day-table thead th { color: var(--text-secondary); font-weight: 600; text-transform: uppercase; font-size: 10.5px; }
</style>
