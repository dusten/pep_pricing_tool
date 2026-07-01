<template>
  <AppLayout title="Pricing">
  <div class="pricing-page">
    <div class="pricing-hero">
      <div class="pricing-eyebrow">Pricing</div>
      <h1 class="pricing-title">Simple, transparent pricing</h1>
      <p class="pricing-sub">Access detailed vendor price comparisons. Upgrade or cancel any time.</p>

      <!-- Billing interval toggle -->
      <div class="interval-toggle">
        <button :class="['toggle-btn', { active: interval === 'monthly' }]" @click="interval = 'monthly'">Monthly</button>
        <button :class="['toggle-btn', { active: interval === 'annual' }]"  @click="interval = 'annual'">
          Annual
          <span class="save-pill">2 months free</span>
        </button>
      </div>
    </div>

    <div class="pricing-grid">
      <div v-for="plan in plans" :key="plan.id" :class="['pricing-card', { featured: plan.featured }]">
        <div v-if="plan.featured" class="popular-badge">Most popular</div>
        <div class="plan-name">{{ plan.name }}</div>
        <div class="plan-price">
          <span class="price-amount">{{ interval === 'annual' ? plan.annualMonthly : plan.monthly }}</span>
          <span class="price-period">/mo</span>
        </div>
        <div v-if="plan.id !== 'free' && interval === 'annual'" class="price-annual">
          Billed {{ plan.annualTotal }}/yr · save {{ plan.annualSavings }}
        </div>
        <p class="plan-promise">{{ plan.promise }}</p>

        <ul class="plan-features">
          <li v-for="f in plan.features" :key="f.text" :class="{ disabled: !f.included }">
            <span class="feature-icon">
              <svg v-if="f.included" width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2.5"><path d="M5 12l5 5L20 7"/></svg>
              <svg v-else width="14" height="14" viewBox="0 0 24 24" fill="none"
                   stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
            </span>
            {{ f.text }}
          </li>
        </ul>

        <button :class="['btn btn-block', plan.featured ? 'btn-accent' : 'btn-primary']"
                @click="selectPlan(plan)">
          {{ plan.id === 'free' ? 'Start free' : `Upgrade to ${plan.name}` }}
        </button>
      </div>
    </div>

    <!-- Feature matrix -->
    <div class="feature-matrix">
      <h2 class="matrix-title">What's included</h2>
      <table class="matrix-table">
        <thead>
          <tr>
            <th>Feature</th>
            <th>Free</th>
            <th>Advanced</th>
            <th>Pro</th>
            <th>Expert</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in matrix" :key="row.feature">
            <td class="matrix-feature">
              <div>{{ row.feature }}</div>
              <div v-if="row.note" class="matrix-note">{{ row.note }}</div>
            </td>
            <td v-for="tier in ['free','advanced','pro','expert']" :key="tier" class="matrix-cell">
              <span v-if="row[tier] === true" class="check">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12l5 5L20 7"/></svg>
              </span>
              <span v-else-if="row[tier] === false" class="cross">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
              </span>
              <span v-else class="matrix-text">{{ row[tier] }}</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'

const interval = ref('monthly')
const auth     = useAuthStore()
const router   = useRouter()

const plans = [
  {
    id: 'free', name: 'Free', monthly: '$0', annualMonthly: '$0',
    annualTotal: '$0', annualSavings: '$0',
    promise: 'Get started with basic price comparisons.',
    featured: false,
    features: [
      { included: true,  text: '3 comparison queries per 72 hours' },
      { included: false, text: 'Download filtered results' },
      { included: false, text: 'Full raw data export' },
    ],
  },
  {
    id: 'advanced', name: 'Advanced', monthly: '$5', annualMonthly: '$4.17',
    annualTotal: '$50', annualSavings: '$10',
    promise: 'Unlimited comparisons, no throttling.',
    featured: false,
    features: [
      { included: true,  text: 'Unlimited comparison queries' },
      { included: false, text: 'Download filtered results' },
      { included: false, text: 'Full raw data export' },
    ],
  },
  {
    id: 'pro', name: 'Pro', monthly: '$14', annualMonthly: '$11.67',
    annualTotal: '$140', annualSavings: '$28',
    promise: 'Export exactly what you see, when you need it.',
    featured: true,
    features: [
      { included: true,  text: 'Unlimited comparison queries' },
      { included: true,  text: 'Download filtered results (CSV/XLSX)' },
      { included: false, text: 'Full raw data export' },
    ],
  },
  {
    id: 'expert', name: 'Expert', monthly: '$34', annualMonthly: '$28.33',
    annualTotal: '$340', annualSavings: '$68',
    promise: 'The complete dataset, every vendor, every price.',
    featured: false,
    features: [
      { included: true,  text: 'Unlimited comparison queries' },
      { included: true,  text: 'Download filtered results (CSV/XLSX)' },
      { included: true,  text: 'Full raw data export — entire dataset' },
    ],
  },
]

const matrix = [
  {
    feature: 'Comparison queries',
    note: 'Each unique filter set = 1 query',
    free: '3 / 72h', advanced: 'Unlimited', pro: 'Unlimited', expert: 'Unlimited',
  },
  {
    feature: 'Download filtered results',
    note: 'Export the table exactly as you have it filtered',
    free: false, advanced: false, pro: true, expert: true,
  },
  {
    feature: 'Full raw data export',
    note: 'Every vendor × product × spec in one formatted XLSX',
    free: false, advanced: false, pro: false, expert: true,
  },
]

function selectPlan(plan) {
  if (plan.id === 'free') {
    auth.isAuthenticated ? router.push('/dashboard') : router.push('/register')
    return
  }
  // ponytail: Stripe checkout wired in Phase 2; for now redirect to account
  if (!auth.isAuthenticated) { router.push('/register'); return }
  router.push('/account')
}
</script>

<style scoped>
.pricing-page { max-width: 1100px; margin: 0 auto; padding: 48px 24px; }

.pricing-hero { text-align: center; margin-bottom: 48px; }
.pricing-eyebrow { font-size: 11px; letter-spacing: 3px; text-transform: uppercase; color: var(--accent); font-weight: 700; margin-bottom: 10px; }
.pricing-title { font-size: 32px; font-weight: 800; margin-bottom: 10px; }
.pricing-sub   { color: var(--text-secondary); font-size: 15px; margin-bottom: 28px; }

.interval-toggle { display: inline-flex; background: var(--surface-alt); border: 1px solid var(--border); border-radius: 99px; padding: 3px; gap: 2px; }
.toggle-btn { padding: 7px 18px; border-radius: 99px; border: none; background: none; cursor: pointer; font-size: 13.5px; font-weight: 500; color: var(--text-secondary); transition: all var(--transition); display: flex; align-items: center; gap: 6px; }
.toggle-btn.active { background: var(--surface); color: var(--text); box-shadow: var(--shadow-sm); }
.save-pill { background: var(--success-bg); color: var(--success); font-size: 11px; padding: 2px 6px; border-radius: 99px; font-weight: 600; }

.pricing-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px; margin-bottom: 60px; }

.pricing-card {
  background: var(--surface);
  border: 1.5px solid var(--border);
  border-radius: var(--radius-lg);
  padding: 28px 24px;
  position: relative;
  transition: box-shadow var(--transition);
}
.pricing-card:hover    { box-shadow: var(--shadow); }
.pricing-card.featured { border-color: var(--accent); box-shadow: 0 0 0 1px var(--accent), var(--shadow); }

.popular-badge { position: absolute; top: -11px; left: 50%; transform: translateX(-50%); background: var(--accent); color: var(--text-on-accent); font-size: 11px; font-weight: 700; padding: 2px 12px; border-radius: 99px; white-space: nowrap; }
.plan-name     { font-size: 13px; font-weight: 700; letter-spacing: 1px; text-transform: uppercase; color: var(--text-secondary); margin-bottom: 10px; }
.plan-price    { display: flex; align-items: baseline; gap: 2px; margin-bottom: 2px; }
.price-amount  { font-size: 36px; font-weight: 800; letter-spacing: -1px; color: var(--text); }
.price-period  { font-size: 14px; color: var(--text-muted); }
.price-annual  { font-size: 12px; color: var(--success); margin-bottom: 12px; }
.plan-promise  { font-size: 13px; color: var(--text-secondary); margin: 12px 0 18px; min-height: 38px; }

.plan-features { list-style: none; padding: 0; margin: 0 0 24px; display: flex; flex-direction: column; gap: 10px; }
.plan-features li { display: flex; align-items: flex-start; gap: 8px; font-size: 13px; }
.plan-features li.disabled { color: var(--text-muted); }
.feature-icon { flex-shrink: 0; margin-top: 1px; }
.plan-features li:not(.disabled) .feature-icon { color: var(--success); }
.plan-features li.disabled .feature-icon { color: var(--text-muted); }

/* Matrix */
.feature-matrix { margin-top: 20px; }
.matrix-title   { font-size: 20px; font-weight: 700; margin-bottom: 20px; }
.matrix-table   { width: 100%; border-collapse: collapse; font-size: 13.5px; }
.matrix-table th, .matrix-table td { padding: 12px 16px; border-bottom: 1px solid var(--border); text-align: center; }
.matrix-table th:first-child, .matrix-table td:first-child { text-align: left; }
.matrix-table thead th { font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-secondary); background: var(--surface-alt); }
.matrix-feature { font-weight: 500; }
.matrix-note    { font-size: 11.5px; color: var(--text-muted); margin-top: 3px; font-weight: 400; }
.matrix-text    { color: var(--text-secondary); }
.check          { color: var(--success); display: flex; justify-content: center; }
.cross          { color: var(--border); display: flex; justify-content: center; }

@media (max-width: 680px) {
  .pricing-grid { grid-template-columns: 1fr; }
  .matrix-table { font-size: 12px; }
  .matrix-table th, .matrix-table td { padding: 8px 10px; }
}
</style>
