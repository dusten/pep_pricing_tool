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
        <!-- Public teaser summary — logged-out visitors only -->
        <div v-if="!auth.isAuthenticated && summary" class="public-summary">
          <p><strong>{{ summary.total_changes }}</strong> price change{{ summary.total_changes !== 1 ? 's' : '' }} tracked
             across <strong>{{ summary.vendor_count }}</strong> vendor{{ summary.vendor_count !== 1 ? 's' : '' }} this month.</p>
          <div v-if="summary.by_classification.length" class="cat-chips">
            <span v-for="c in summary.by_classification" :key="c.name" class="chip">{{ c.name }}: {{ c.count }}</span>
          </div>
        </div>

        <div class="cal-grid">
          <div v-for="d in weekdayLabels" :key="d" class="cal-weekday">{{ d }}</div>
          <div v-for="n in leadingBlanks" :key="'b'+n" class="cal-cell blank"></div>
          <button v-for="day in daysInMonth" :key="day"
                  :class="['cal-cell', { active: selectedDay === day, 'has-changes': dayHasContent(day) }]"
                  @click="selectedDay = day">
            <span class="cal-day-num">{{ day }}</span>
            <span v-if="dayKey(day) in days" class="cal-dot">{{ days[dayKey(day)].length }}</span>
            <span v-if="dayKey(day) in approved" class="cal-dot approved-dot">{{ approved[dayKey(day)].length }}</span>
            <span v-if="dayKey(day) in featured" class="cal-dot featured-dot" title="Featured product">★</span>
          </button>
        </div>

        <div v-if="selectedDay && dayHasContent(selectedDay)" class="day-detail">
          <!-- Featured product — admin-picked, fully revealed to anonymous visitors (backlog #18) -->
          <div v-if="!auth.isAuthenticated && dayKey(selectedDay) in featured" class="featured-card">
            <div class="featured-tag">★ Featured</div>
            <div class="featured-name">
              {{ featured[dayKey(selectedDay)].product }}
              <span class="text-muted">{{ featured[dayKey(selectedDay)].spec }}</span>
            </div>
            <div class="featured-price">
              <strong>${{ featured[dayKey(selectedDay)].price.toFixed(2) }}</strong>
              <span v-if="featured[dayKey(selectedDay)].old_price != null"
                    :class="['price-change', featured[dayKey(selectedDay)].price < featured[dayKey(selectedDay)].old_price ? 'down' : featured[dayKey(selectedDay)].price > featured[dayKey(selectedDay)].old_price ? 'up' : '']">
                was ${{ featured[dayKey(selectedDay)].old_price.toFixed(2) }}
              </span>
              <span class="text-muted">— {{ featured[dayKey(selectedDay)].vendor }}</span>
            </div>
            <p v-if="featured[dayKey(selectedDay)].note" class="featured-note">{{ featured[dayKey(selectedDay)].note }}</p>
          </div>

          <!-- All-time-low milestones — name-only teaser (backlog #19) -->
          <div v-if="!auth.isAuthenticated && dayKey(selectedDay) in milestones" class="milestones">
            <div v-for="(m, i) in milestones[dayKey(selectedDay)]" :key="i" class="milestone">
              🏆 <strong>All-time low</strong> — {{ m.product }} <span class="text-muted">{{ m.spec }}</span>
            </div>
          </div>

          <template v-if="dayKey(selectedDay) in days">
            <h4>{{ monthLabel }} {{ selectedDay }} — {{ days[dayKey(selectedDay)].length }} price change(s)</h4>

            <!-- Authenticated: full ledger detail -->
            <table v-if="auth.isAuthenticated" class="day-table">
              <thead><tr><th>Product</th><th>Spec</th><th>Vendor</th><th>Change</th><th>Source</th></tr></thead>
              <tbody>
                <tr v-for="(c, i) in days[dayKey(selectedDay)]" :key="i">
                  <td>{{ c.product }}</td><td>{{ c.spec }}</td><td>{{ c.vendor }}</td>
                  <td>
                    <span v-if="c.is_new" class="badge badge-pro">New: ${{ c.new_price.toFixed(2) }}</span>
                    <span v-else :class="['price-change', c.new_price > c.old_price ? 'up' : c.new_price < c.old_price ? 'down' : '']">
                      ${{ c.old_price.toFixed(2) }} → ${{ c.new_price.toFixed(2) }}
                    </span>
                  </td>
                  <td class="text-muted text-sm">{{ c.source === 'manual_edit' ? 'Manual edit' : 'Vendor import' }}</td>
                </tr>
              </tbody>
            </table>

            <!-- Public: teased product/spec only, no vendor or price -->
            <template v-else>
              <table class="day-table">
                <thead><tr><th>Product</th><th>Spec</th><th></th></tr></thead>
                <tbody>
                  <tr v-for="(c, i) in days[dayKey(selectedDay)]" :key="i">
                    <td>{{ c.product }}</td><td>{{ c.spec }}</td>
                    <td>{{ c.is_new ? 'New listing' : 'Price changed' }}</td>
                  </tr>
                </tbody>
              </table>
              <p class="text-muted text-sm" style="margin-top:10px">
                <RouterLink to="/login">Sign in</RouterLink> to see vendor names and exact prices.
              </p>
            </template>
          </template>

          <template v-if="dayKey(selectedDay) in approved">
            <h4 :style="dayKey(selectedDay) in days ? 'margin-top:20px' : ''">
              {{ monthLabel }} {{ selectedDay }} — {{ approved[dayKey(selectedDay)].length }} item(s) approved from review queue
            </h4>
            <table class="day-table">
              <thead><tr><th>Product</th><th>Spec</th><th>Vendor</th><th>Price</th></tr></thead>
              <tbody>
                <tr v-for="(a, i) in approved[dayKey(selectedDay)]" :key="i">
                  <td>{{ a.product }}</td><td>{{ a.spec }}</td><td>{{ a.vendor }}</td>
                  <td>{{ a.price !== null ? '$' + a.price.toFixed(2) : '—' }}</td>
                </tr>
              </tbody>
            </table>
          </template>
        </div>
        <p v-else-if="selectedDay" class="text-muted text-sm" style="margin-top:16px">Nothing happened that day.</p>
      </template>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'
import { get } from '@/utils/api.js'

const auth = useAuthStore()

const today       = new Date()
const viewYear    = ref(today.getFullYear())
const viewMonth   = ref(today.getMonth()) // 0-indexed
const days        = ref({}) // { 'YYYY-MM-DD': [changes] } — shape differs by auth state, see template
const approved    = ref({}) // { 'YYYY-MM-DD': [review-queue approvals] } — authenticated only
const summary     = ref(null) // { total_changes, vendor_count, by_classification } — public only
const featured    = ref({}) // { 'YYYY-MM-DD': {product,spec,vendor,price,old_price,note} } — public only
const milestones  = ref({}) // { 'YYYY-MM-DD': [{product,spec}] } all-time lows — public only
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

function dayHasContent(day) {
  const k = dayKey(day)
  return k in days.value || k in approved.value || k in featured.value || k in milestones.value
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
    if (auth.isAuthenticated) {
      const res = await get(`/api/calendar?month=${month}`)
      days.value = res.days
      approved.value = res.approved
      featured.value = {}
      milestones.value = {}
    } else {
      const res = await get(`/api/calendar/public?month=${month}`)
      days.value = res.days
      approved.value = {}
      summary.value = res.summary
      featured.value = res.featured || {}
      milestones.value = res.milestones || {}
    }
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

.public-summary { margin-bottom: 16px; padding-bottom: 14px; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.public-summary p { margin: 0 0 8px; }
.cat-chips { display: flex; gap: 6px; flex-wrap: wrap; }
.cat-chips .chip { background: var(--surface-alt); border: 1px solid var(--border); border-radius: 99px; padding: 3px 10px; font-size: 11.5px; color: var(--text-secondary); }

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
.cal-dot.approved-dot { right: auto; left: 3px; background: var(--success); }
.cal-dot.featured-dot { bottom: 2px; top: auto; right: 3px; background: var(--accent); min-width: 0; }

.featured-card {
  border: 1.5px solid var(--accent); border-radius: var(--radius-sm);
  background: var(--accent-subtle); padding: 12px 14px; margin-bottom: 14px;
}
.featured-tag { font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--accent); margin-bottom: 4px; }
.featured-name { font-size: 14px; font-weight: 600; margin-bottom: 4px; }
.featured-price { font-size: 13px; display: flex; gap: 8px; align-items: baseline; flex-wrap: wrap; }
.featured-price strong { font-size: 15px; }
.featured-note { font-size: 12px; color: var(--text-secondary); margin: 8px 0 0; }

.milestones { margin-bottom: 14px; display: flex; flex-direction: column; gap: 6px; }
.milestone { font-size: 12.5px; background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 6px 10px; }

.day-detail { margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--border); }
.day-detail h4 { font-size: 13.5px; margin-bottom: 12px; }
.day-table { width: 100%; border-collapse: collapse; font-size: 12.5px; }
.day-table th, .day-table td { padding: 6px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.day-table thead th { color: var(--text-secondary); font-weight: 600; text-transform: uppercase; font-size: 10.5px; }
.price-change.up   { color: var(--danger); font-weight: 600; }
.price-change.down { color: var(--success); font-weight: 600; }
</style>
