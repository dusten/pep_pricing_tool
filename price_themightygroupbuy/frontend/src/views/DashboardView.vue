<template>
  <AppLayout title="Dashboard">
    <div class="dashboard">
      <!-- Welcome -->
      <div class="welcome-card card">
        <div class="welcome-text">
          <h2>Welcome back, {{ auth.user?.display_name }}</h2>
          <p>Compare peptide vendor prices across {{ stats.vendors }} vendors and {{ stats.products }} products.</p>
        </div>
        <RouterLink to="/comparison" class="btn btn-accent">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75">
            <path d="M3 3h18v4H3z"/><path d="M3 11h18v2H3z"/><path d="M3 17h11v4H3z"/>
          </svg>
          Open Comparison
        </RouterLink>
      </div>

      <!-- Shopping cart -->
      <div class="card cart-card">
        <div class="cart-card-header">
          <h3>Shopping cart</h3>
          <RouterLink to="/cart" class="btn btn-ghost btn-sm">Open cart</RouterLink>
        </div>
        <p v-if="!cart.items.length" class="text-muted text-sm" style="margin:0">
          Your cart is empty. Add items from <RouterLink to="/comparison">Comparison</RouterLink>.
        </p>
        <p v-else-if="cart.cheapestFullCoverage" style="margin:0;font-size:13.5px">
          {{ cart.items.length }} item{{ cart.items.length !== 1 ? 's' : '' }} — cheapest is
          <strong>{{ cart.cheapestFullCoverage.vendor_name }}</strong> at
          <strong>${{ cart.cheapestFullCoverage.total_usd.toFixed(2) }}</strong>
        </p>
        <p v-else style="margin:0;font-size:13.5px;color:var(--text-secondary)">
          {{ cart.items.length }} item{{ cart.items.length !== 1 ? 's' : '' }} — no single vendor carries everything yet.
        </p>
      </div>

      <!-- Buy This Stack -->
      <div v-if="stacks.length" class="card">
        <h3 style="margin:0 0 10px;font-size:14px">Buy This Stack</h3>
        <div v-for="s in stacks" :key="s.id" class="stack-row">
          <div>
            <strong>{{ s.name }}</strong>
            <span class="text-muted text-sm">{{ s.item_count }} item{{ s.item_count !== 1 ? 's' : '' }}</span>
            <p v-if="s.description" class="text-muted text-sm" style="margin:2px 0 0">{{ s.description }}</p>
          </div>
          <button class="btn btn-ghost btn-sm" @click="addStackToCart(s.id)">Add to cart</button>
        </div>
      </div>

      <!-- Quota card (free tier only) -->
      <div v-if="quota.isFree && quota.data" class="quota-card card">
        <div class="quota-header">
          <h3>Query quota</h3>
          <span class="badge badge-free">Free plan</span>
        </div>
        <div class="quota-meter">
          <div class="meter-bar">
            <div class="meter-fill" :style="{ width: pct + '%' }" :class="pctClass"></div>
          </div>
          <div class="meter-labels">
            <span>{{ quota.used }} used</span>
            <span>{{ quota.limit }} total per 72h</span>
          </div>
        </div>
        <p v-if="quota.isExhausted" style="margin:12px 0 0;font-size:13px;color:var(--danger)">
          Limit reached. Resets {{ resetsIn }}.
          <RouterLink to="/pricing">Upgrade for unlimited access →</RouterLink>
        </p>
        <p v-else style="margin:12px 0 0;font-size:13px;color:var(--text-secondary)">
          {{ quota.remaining }} comparison{{ quota.remaining !== 1 ? 's' : '' }} remaining.
          <RouterLink to="/pricing">Upgrade for unlimited →</RouterLink>
        </p>
      </div>

      <!-- Stat tiles -->
      <div class="stat-grid">
        <div class="stat-tile card">
          <div class="stat-value">{{ stats.vendors ?? '—' }}</div>
          <div class="stat-label">Active vendors</div>
        </div>
        <div class="stat-tile card">
          <div class="stat-value">{{ stats.products ?? '—' }}</div>
          <div class="stat-label">Products tracked</div>
        </div>
        <div class="stat-tile card">
          <div class="stat-value">{{ stats.prices ?? '—' }}</div>
          <div class="stat-label">Price entries</div>
        </div>
      </div>

      <!-- Upgrade prompt for free/advanced users -->
      <div v-if="auth.tier === 'free' || auth.tier === 'advanced'" class="upgrade-strip card">
        <div>
          <strong>Want more?</strong>
          <span style="color:var(--text-secondary);margin-left:8px">
            {{ auth.tier === 'free'
                ? 'Upgrade to Advanced for unlimited comparisons, or Pro for export downloads.'
                : 'Upgrade to Pro to download filtered results as CSV/XLSX.' }}
          </span>
        </div>
        <RouterLink to="/pricing" class="btn btn-accent btn-sm">View plans</RouterLink>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore }  from '@/stores/auth.js'
import { useQuotaStore } from '@/stores/quota.js'
import { useCartStore }  from '@/stores/cart.js'
import { get, post } from '@/utils/api.js'

const auth  = useAuthStore()
const quota = useQuotaStore()
const cart  = useCartStore()

const stats  = ref({ vendors: null, products: null, prices: null })
const stacks = ref([])

onMounted(async () => {
  quota.fetch()
  cart.load()
  try {
    stats.value = await get('/api/stats')
  } catch { /* leave the '—' placeholders */ }
  try {
    stacks.value = (await get('/api/stacks')).stacks
  } catch { /* best effort */ }
})

async function addStackToCart(stackId) {
  const res = await post(`/api/cart/add-stack/${stackId}`)
  cart.items   = res.items
  cart.vendors = res.vendors
}

const pct = computed(() => {
  if (!quota.data || !quota.limit.value) return 0
  return Math.min(100, Math.round((quota.used.value / quota.limit.value) * 100))
})
const pctClass = computed(() => pct.value >= 100 ? 'fill-danger' : pct.value >= 67 ? 'fill-warn' : 'fill-ok')

const resetsIn = computed(() => {
  if (!quota.resetsAt) return 'soon'
  const diff = new Date(quota.resetsAt) - Date.now()
  if (diff <= 0) return 'now'
  return `in ${Math.ceil(diff / 3_600_000)}h`
})
</script>

<style scoped>
.dashboard { display: flex; flex-direction: column; gap: 20px; }

.welcome-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  background: var(--primary);
  border-color: transparent;
  color: var(--text-on-primary);
}
.welcome-card h2 { font-size: 18px; margin-bottom: 4px; }
.welcome-card p  { margin: 0; opacity: .75; font-size: 13.5px; }

.quota-card h3  { margin: 0; font-size: 14px; }
.quota-header   { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }

.cart-card-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
.cart-card-header h3 { margin: 0; font-size: 14px; }

.stack-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); font-size: 13.5px; }
.stack-row:last-child { border-bottom: none; }

.meter-bar { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; }
.meter-fill { height: 100%; border-radius: 99px; transition: width .4s; }
.fill-ok     { background: var(--success); }
.fill-warn   { background: var(--warning); }
.fill-danger { background: var(--danger); }
.meter-labels { display: flex; justify-content: space-between; font-size: 11.5px; color: var(--text-muted); margin-top: 5px; }

.stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; }
.stat-tile { text-align: center; }
.stat-value { font-size: 28px; font-weight: 700; color: var(--primary); }
.stat-label { font-size: 12px; color: var(--text-secondary); margin-top: 4px; text-transform: uppercase; letter-spacing: 0.5px; }

.upgrade-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  background: var(--accent-subtle);
  border-color: var(--accent);
}
</style>
