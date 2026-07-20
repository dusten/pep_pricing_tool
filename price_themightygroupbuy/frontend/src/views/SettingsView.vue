<template>
  <AppLayout title="Settings">
    <div class="settings-stack">

      <!-- Appearance — most-used toggle, kept at the top -->
      <div class="card">
        <h3 class="card-title">Appearance</h3>
        <div class="theme-options">
          <button
            v-for="opt in themeOptions"
            :key="opt.value"
            :class="['theme-option', { active: auth.user?.theme === opt.value }]"
            @click="auth.updateTheme(opt.value)"
          >
            <component :is="opt.icon" />
            {{ opt.label }}
          </button>
        </div>
      </div>

      <!-- Profile & locale -->
      <div class="card">
        <h3 class="card-title">Profile &amp; locale</h3>
        <div class="field">
          <label>Display name</label>
          <input v-model="displayName" type="text" :disabled="nameSaving" />
        </div>
        <div class="field">
          <label>Email</label>
          <input :value="auth.user?.email" type="email" disabled style="opacity:.6" />
        </div>
        <div class="field" style="max-width:320px;margin-bottom:0">
          <label>Timezone</label>
          <select v-model="timezone" @change="saveTimezone">
            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
          </select>
        </div>
        <div style="margin-top:16px;display:flex;align-items:center;gap:10px">
          <button class="btn btn-primary btn-sm" @click="saveName" :disabled="nameSaving">
            {{ nameSaving ? 'Saving…' : 'Save name' }}
          </button>
          <span v-if="nameSaved" style="font-size:12.5px;color:var(--success)">Saved!</span>
        </div>
      </div>

      <!-- Subscription -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
          <h3 class="card-title" style="margin:0">Subscription</h3>
          <span :class="['badge', `badge-${auth.tier}`]">
            {{ auth.tier.charAt(0).toUpperCase() + auth.tier.slice(1) }}
          </span>
        </div>
        <p class="text-muted text-sm" style="margin-bottom:16px">
          <template v-if="auth.tier === 'free'">
            You're on the free plan — 3 comparison queries per 72 hours.
          </template>
          <template v-else>
            {{ auth.user?.tier_status === 'active' ? 'Active' : auth.user?.tier_status }}
            <span v-if="auth.user?.tier_renews_at">· renews {{ fmtDate(auth.user.tier_renews_at) }}</span>
          </template>
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <RouterLink to="/pricing" class="btn btn-accent btn-sm">
            {{ auth.tier === 'free' ? 'Upgrade plan' : 'Change plan' }}
          </RouterLink>
          <button v-if="auth.tier !== 'free'" class="btn btn-ghost btn-sm" disabled title="Coming in Phase 2">
            Manage billing
          </button>
        </div>
      </div>

      <!-- Referral -->
      <div class="card">
        <div class="card-header-icon">
          <span class="icon-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 10.5l6.8-3.9M8.6 13.5l6.8 3.9"/></svg>
          </span>
          <h3 class="card-title" style="margin:0">Refer a Friend</h3>
        </div>
        <p class="text-muted text-sm" style="margin:10px 0 14px">
          Share your link. When someone signs up and subscribes, you earn free months added to your subscription.
        </p>
        <div class="ref-row">
          <input :value="referralUrl" readonly class="ref-input" @focus="$event.target.select()" />
          <button class="btn btn-accent btn-sm" @click="copyRef">{{ copied ? 'Copied!' : 'Copy' }}</button>
        </div>
        <div class="text-muted text-sm" style="margin:10px 0 16px">Code: <span class="mono">{{ auth.user?.referral_code }}</span></div>
        <div class="stat-tiles">
          <div class="stat-tile"><div class="stat-value">{{ referralStats.joined }}</div><div class="stat-label">Joined</div></div>
          <div class="stat-tile"><div class="stat-value accent">{{ referralStats.converted }}</div><div class="stat-label">Converted</div></div>
          <div class="stat-tile"><div class="stat-value accent">{{ referralStats.months_earned }}</div><div class="stat-label">Months Earned</div></div>
        </div>
      </div>

      <!-- Notifications -->
      <div class="card">
        <h3 class="card-title">Notifications</h3>
        <label class="toggle-row">
          <input type="checkbox" v-model="pushEnabled" @change="savePush" />
          Push notifications
        </label>
        <div v-if="pushEnabled && installPromptEvent" class="install-card">
          <div>
            <div style="font-weight:600;font-size:13.5px">Add to home screen</div>
            <div class="text-muted text-sm">Install the app for faster access and push delivery.</div>
          </div>
          <button class="btn btn-accent btn-sm" @click="promptInstall">Install</button>
        </div>
      </div>

      <!-- Security -->
      <div class="card">
        <h3 class="card-title">Security</h3>

        <form class="security-block" @submit.prevent="changePassword">
          <div class="field"><label>Current password</label><input v-model="pwd.current" type="password" autocomplete="current-password" /></div>
          <div class="field" style="margin-bottom:8px"><label>New password</label><input v-model="pwd.next" type="password" autocomplete="new-password" /></div>
          <p v-if="pwdMsg" :class="['text-sm', pwdErr ? 'text-danger' : 'text-success']" style="margin-bottom:8px">{{ pwdMsg }}</p>
          <button class="btn btn-primary btn-sm" :disabled="pwdSaving">{{ pwdSaving ? 'Saving…' : 'Change password' }}</button>
        </form>

        <hr class="divider" />

        <form class="security-block" @submit.prevent="changeEmail">
          <div class="field"><label>New email address</label><input v-model="emailForm.new_email" type="email" /></div>
          <div class="field" style="margin-bottom:8px"><label>Password</label><input v-model="emailForm.password" type="password" /></div>
          <p v-if="emailMsg" :class="['text-sm', emailErr ? 'text-danger' : 'text-success']" style="margin-bottom:8px">{{ emailMsg }}</p>
          <button class="btn btn-ghost btn-sm" :disabled="emailSaving">{{ emailSaving ? 'Sending…' : 'Change email' }}</button>
          <span v-if="auth.user?.pending_email" class="text-muted text-sm" style="margin-left:10px">
            Confirmation pending for {{ auth.user.pending_email }}
          </span>
        </form>

        <hr class="divider" />

        <div>
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:13.5px;font-weight:600">Login history</span>
            <button class="btn btn-ghost btn-sm" @click="loadLoginHistory">{{ logins ? 'Refresh' : 'Show' }}</button>
          </div>
          <table v-if="logins" class="admin-table">
            <thead><tr><th>When</th><th>IP</th><th>Device</th></tr></thead>
            <tbody>
              <tr v-for="(l, i) in logins" :key="i">
                <td class="text-sm">{{ l.created_at }}</td>
                <td class="text-sm">{{ l.ip || '—' }}</td>
                <td class="text-sm text-muted" style="max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">{{ l.user_agent || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>

        <hr class="divider" />

        <div>
          <div style="font-size:13.5px;font-weight:600;margin-bottom:4px">Sign out other sessions</div>
          <p class="text-muted text-sm" style="margin-bottom:10px">Invalidates every other active session. This device stays signed in.</p>
          <button class="btn btn-ghost btn-sm" :disabled="revoking" @click="revokeOthers">
            {{ revoking ? 'Signing out…' : 'Sign out other sessions' }}
          </button>
          <span v-if="revoked" style="font-size:12.5px;color:var(--success);margin-left:10px">Done!</span>
        </div>
      </div>

      <!-- Data export -->
      <div class="card">
        <h3 class="card-title">Your data</h3>
        <p class="text-muted text-sm" style="margin-bottom:12px">Download everything tied to your account — profile, comparisons, feedback, referrals.</p>
        <button class="btn btn-ghost btn-sm" @click="exportData">Export my data</button>
      </div>

      <!-- Full dataset export (Expert tier) -->
      <div v-if="auth.isAdmin || (auth.tier === 'expert' && auth.tierActive)" class="card">
        <h3 class="card-title">Full dataset export</h3>
        <p class="text-muted text-sm" style="margin-bottom:12px">Download every active product, vendor, spec, and price row — the whole live dataset, not filtered by the Comparison view.</p>
        <button class="btn btn-ghost btn-sm" @click="exportFullDataset">Download full dataset (JSON)</button>
      </div>

      <!-- Feedback -->
      <div class="card" ref="feedbackCard">
        <div class="card-header-icon">
          <span class="icon-badge">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
          </span>
          <h3 class="card-title" style="margin:0">Share Feedback</h3>
        </div>
        <p class="text-muted text-sm" style="margin:10px 0 14px">Tell us what you'd like to see improved.</p>

        <label class="field-label">Category</label>
        <div class="pill-row">
          <button v-for="t in feedbackTypes" :key="t.value"
                  :class="['pill-btn', { active: feedback.type === t.value }]"
                  @click="feedback.type = t.value" type="button">
            <component :is="t.icon" />
            {{ t.label }}
          </button>
        </div>
        <textarea v-model="feedback.message" rows="4" placeholder="Your suggestion…" style="margin:12px 0"></textarea>
        <p v-if="feedbackMsg" class="text-sm text-success" style="margin-bottom:8px">{{ feedbackMsg }}</p>
        <button class="btn btn-primary btn-block" :disabled="feedbackSaving" @click="submitFeedback">
          {{ feedbackSaving ? 'Sending…' : 'Submit Feedback' }}
        </button>
      </div>

      <!-- Danger zone -->
      <div class="card" style="border-color:var(--danger)">
        <h3 class="card-title" style="color:var(--danger)">Delete account</h3>
        <p class="text-muted text-sm" style="margin-bottom:12px">This permanently deletes your account and everything above. It can't be undone.</p>
        <div v-if="!showDelete">
          <button class="btn btn-danger btn-sm" @click="showDelete = true">Delete my account</button>
        </div>
        <form v-else class="security-block" @submit.prevent="deleteAccount">
          <div class="field" style="margin-bottom:8px"><label>Confirm your password</label><input v-model="deletePassword" type="password" /></div>
          <p v-if="deleteMsg" class="text-sm text-danger" style="margin-bottom:8px">{{ deleteMsg }}</p>
          <button class="btn btn-danger btn-sm" :disabled="deleting">{{ deleting ? 'Deleting…' : 'Permanently delete' }}</button>
          <button type="button" class="btn btn-ghost btn-sm" @click="showDelete = false">Cancel</button>
        </form>
      </div>

    </div>
  </AppLayout>
</template>

<script setup>
import { h, ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'
import { get, post, patch, del } from '@/utils/api.js'

const auth   = useAuthStore()
const router = useRouter()
const route  = useRoute()

const icon = (path) => () =>
  h('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.75 }, path)

const themeOptions = [
  { value: 'system', label: 'System', icon: icon([h('rect', { x: 2, y: 3, width: 20, height: 14, rx: 2 }), h('path', { d: 'M8 21h8M12 17v4' })]) },
  { value: 'light',  label: 'Light',  icon: icon([h('circle', { cx: 12, cy: 12, r: 4 }), h('path', { d: 'M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42' })]) },
  { value: 'dark',   label: 'Dark',   icon: icon([h('path', { d: 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z' })]) },
]

// ── Profile ──────────────────────────────────────────────────────
const displayName = ref(auth.user?.display_name ?? '')
const nameSaving   = ref(false)
const nameSaved    = ref(false)
async function saveName() {
  nameSaving.value = true
  try {
    await patch('/api/me', { display_name: displayName.value })
    await auth.fetchMe()
    nameSaved.value = true
    setTimeout(() => { nameSaved.value = false }, 2000)
  } catch { /* */ } finally { nameSaving.value = false }
}
function fmtDate(d) {
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}

// ── Locale ──────────────────────────────────────────────────────────
const timezones = typeof Intl.supportedValuesOf === 'function'
  ? Intl.supportedValuesOf('timeZone')
  : ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London']
const timezone = ref(auth.user?.timezone || 'UTC')
async function saveTimezone() {
  await patch('/api/me', { timezone: timezone.value })
  await auth.fetchMe()
}

// ── Referral ────────────────────────────────────────────────────────
const referralUrl = computed(() =>
  `${window.location.origin}/register?ref=${auth.user?.referral_code ?? ''}`)
const copied = ref(false)
async function copyRef() {
  await navigator.clipboard.writeText(referralUrl.value).catch(() => {})
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}
const referralStats = ref({ joined: 0, converted: 0, months_earned: 0 })
onMounted(async () => { referralStats.value = await get('/api/me/referral-stats') })

// ── Notifications ─────────────────────────────────────────────────
const pushEnabled = ref(!!auth.user?.push_enabled)
const installPromptEvent = ref(null)
window.addEventListener('beforeinstallprompt', (e) => {
  e.preventDefault()
  installPromptEvent.value = e
})
async function savePush() {
  await patch('/api/me', { push_enabled: pushEnabled.value })
  await auth.fetchMe()
}
async function promptInstall() {
  if (!installPromptEvent.value) return
  installPromptEvent.value.prompt()
  await installPromptEvent.value.userChoice
  installPromptEvent.value = null
}

// ── Security: password ──────────────────────────────────────────────
const pwd       = reactive({ current: '', next: '' })
const pwdSaving = ref(false)
const pwdMsg    = ref('')
const pwdErr    = ref(false)
async function changePassword() {
  pwdSaving.value = true; pwdMsg.value = ''
  try {
    const res = await post('/api/me/password', { current_password: pwd.current, new_password: pwd.next })
    pwdMsg.value = res.message; pwdErr.value = false
    pwd.current = ''; pwd.next = ''
  } catch (e) {
    pwdMsg.value = e.message; pwdErr.value = true
  } finally { pwdSaving.value = false }
}

// ── Security: email ──────────────────────────────────────────────────
const emailForm   = reactive({ new_email: '', password: '' })
const emailSaving = ref(false)
const emailMsg    = ref('')
const emailErr    = ref(false)
async function changeEmail() {
  emailSaving.value = true; emailMsg.value = ''
  try {
    const res = await post('/api/me/email', emailForm)
    emailMsg.value = res.message; emailErr.value = false
    emailForm.new_email = ''; emailForm.password = ''
    await auth.fetchMe()
  } catch (e) {
    emailMsg.value = e.message; emailErr.value = true
  } finally { emailSaving.value = false }
}

// ── Security: login history ───────────────────────────────────────
const logins = ref(null)
async function loadLoginHistory() {
  const res = await get('/api/me/login-history')
  logins.value = res.logins
}

// ── Security: sign out other sessions ────────────────────────────
const revoking = ref(false)
const revoked  = ref(false)
async function revokeOthers() {
  revoking.value = true; revoked.value = false
  try {
    await post('/api/me/sessions/revoke-all')
    revoked.value = true
    setTimeout(() => { revoked.value = false }, 2500)
  } finally { revoking.value = false }
}

// ── Data export ──────────────────────────────────────────────────
async function exportData() {
  const res = await get('/api/me/export')
  const blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'my-data-export.json'; a.click()
  URL.revokeObjectURL(url)
}

async function exportFullDataset() {
  const res = await get('/api/export/full')
  const blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'full-export.json'; a.click()
  URL.revokeObjectURL(url)
}

// ── Feedback ────────────────────────────────────────────────────────
const feedbackTypes = [
  { value: 'general',     label: 'General',     icon: icon([h('circle', { cx: 12, cy: 12, r: 10 }), h('path', { d: 'M12 16v-4M12 8h.01' })]) },
  { value: 'ui_ux',       label: 'UI / UX',      icon: icon([h('rect', { x: 3, y: 3, width: 18, height: 18, rx: 2 }), h('path', { d: 'M3 9h18M9 21V9' })]) },
  { value: 'feature',     label: 'Feature',      icon: icon([h('path', { d: 'M12 2l1.9 4.9L19 8l-4.9 1.9L12 15l-1.9-5.1L5 8l5.1-1.1z' }), h('path', { d: 'M19 15l.9 2.4L22 18l-2.1.9L19 21l-.9-2.1L16 18l2.1-.6z' })]) },
  { value: 'bug',         label: 'Bug',          icon: icon([h('rect', { x: 7, y: 8, width: 10, height: 11, rx: 5 }), h('path', { d: 'M7 12H3M21 12h-4M12 3v3M9 5l1.5 2M15 5l-1.5 2M4 7l3 2.5M20 7l-3 2.5' })]) },
  { value: 'performance', label: 'Performance',  icon: icon([h('path', { d: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z' }), h('path', { d: 'M12 6v6l4 2' })]) },
  { value: 'product',     label: 'Product',      icon: icon([h('path', { d: 'M20.59 13.41 11 3.83A2 2 0 0 0 9.59 3.24L3 3v6.59a2 2 0 0 0 .59 1.41l9.59 9.59a2 2 0 0 0 2.82 0l4.59-4.59a2 2 0 0 0 0-2.82z' }), h('circle', { cx: 7.5, cy: 7.5, r: 1.5 })]) },
]
const feedback        = reactive({ type: 'general', message: '' })
const feedbackSaving  = ref(false)
const feedbackMsg     = ref('')
const feedbackCard    = ref(null)
async function submitFeedback() {
  feedbackSaving.value = true; feedbackMsg.value = ''
  try {
    await post('/api/feedback', { type: feedback.type, message: feedback.message, url: window.location.pathname })
    feedbackMsg.value = 'Thanks — sent!'
    feedback.message = ''
  } catch { /* inline error not critical here */ } finally { feedbackSaving.value = false }
}

// Deep link from other pages, e.g. Comparison's "Product feedback" pill
// (/settings?feedback_type=product) — pre-select the category and scroll
// the card into view so it's not just silently pre-filled off-screen.
onMounted(() => {
  const t = route.query.feedback_type
  if (feedbackTypes.some(ft => ft.value === t)) {
    feedback.type = t
    feedbackCard.value?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  }
})

// ── Account deletion ─────────────────────────────────────────────
const showDelete    = ref(false)
const deletePassword = ref('')
const deleting        = ref(false)
const deleteMsg       = ref('')
async function deleteAccount() {
  deleting.value = true; deleteMsg.value = ''
  try {
    await del('/api/me', { password: deletePassword.value })
    await auth.logout()
    router.push('/login')
  } catch (e) {
    deleteMsg.value = e.message
  } finally { deleting.value = false }
}
</script>

<style scoped>
.settings-stack { display: flex; flex-direction: column; gap: 20px; max-width: 560px; margin: 0 auto; }
.card-title { font-size: 15px; margin-bottom: 14px; }
.field-label { display: block; font-size: 13px; font-weight: 600; margin-bottom: 8px; color: var(--text-secondary); }

.card-header-icon { display: flex; align-items: center; gap: 10px; }
.icon-badge {
  display: flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: var(--radius);
  background: var(--primary); color: var(--accent); flex-shrink: 0;
}

.theme-options { display: flex; gap: 10px; }
.theme-option {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 16px 10px;
  border-radius: var(--radius);
  border: 1.5px solid var(--border);
  background: var(--surface-alt);
  color: var(--text-secondary);
  cursor: pointer;
  font-size: 12.5px;
  font-weight: 500;
  transition: all var(--transition);
}
.theme-option:hover  { border-color: var(--accent); color: var(--accent); }
.theme-option.active { border-color: var(--accent); background: var(--accent-subtle); color: var(--accent); font-weight: 700; }

.toggle-row { display: flex; align-items: center; gap: 8px; font-size: 13.5px; }
.toggle-row input { width: auto; }

.install-card {
  margin-top: 12px; padding: 12px 14px; border-radius: var(--radius);
  background: var(--surface-alt); border: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
}

.security-block { margin: 0; }
.divider { border: none; border-top: 1px solid var(--border); margin: 18px 0; }

.ref-row   { display: flex; gap: 8px; }
.ref-input { flex: 1; font-size: 12.5px; font-family: var(--font-mono); }
.mono      { font-family: var(--font-mono); }

.stat-tiles { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
.stat-tile { background: var(--surface-alt); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 8px; text-align: center; }
.stat-value { font-size: 19px; font-weight: 700; color: var(--text); }
.stat-value.accent { color: var(--accent); }
.stat-label { font-size: 10.5px; color: var(--text-secondary); text-transform: uppercase; margin-top: 3px; }

.pill-row { display: flex; gap: 8px; flex-wrap: wrap; }
.pill-btn {
  display: flex; align-items: center; gap: 6px;
  padding: 6px 14px; border-radius: 99px; border: 1.5px solid var(--border);
  background: var(--surface); color: var(--text-secondary); cursor: pointer;
  font-size: 12.5px; font-weight: 500; transition: all var(--transition);
}
.pill-btn.active { background: var(--primary); border-color: var(--primary); color: var(--accent); font-weight: 700; }

.text-danger  { color: var(--danger); }
.text-success { color: var(--success); }
</style>
