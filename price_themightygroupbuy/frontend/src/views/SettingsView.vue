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

      <!-- Locale -->
      <div class="card">
        <h3 class="card-title">Profile &amp; locale</h3>
        <div class="field" style="max-width:320px;margin-bottom:0">
          <label>Timezone</label>
          <select v-model="timezone" @change="saveTimezone">
            <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
          </select>
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
      </div>

      <!-- Data export -->
      <div class="card">
        <h3 class="card-title">Your data</h3>
        <p class="text-muted text-sm" style="margin-bottom:12px">Download everything tied to your account — profile, comparisons, feedback, referrals.</p>
        <button class="btn btn-ghost btn-sm" @click="exportData">Export my data</button>
      </div>

      <!-- Feedback -->
      <div class="card">
        <h3 class="card-title">Feedback</h3>
        <div class="pill-row">
          <button v-for="t in feedbackTypes" :key="t.value"
                  :class="['pill-btn', { active: feedback.type === t.value }]"
                  @click="feedback.type = t.value" type="button">
            {{ t.label }}
          </button>
        </div>
        <textarea v-model="feedback.message" rows="3" placeholder="What's on your mind?" style="margin:10px 0"></textarea>
        <p v-if="feedbackMsg" class="text-sm text-success" style="margin-bottom:8px">{{ feedbackMsg }}</p>
        <button class="btn btn-primary btn-sm" :disabled="feedbackSaving" @click="submitFeedback">
          {{ feedbackSaving ? 'Sending…' : 'Send feedback' }}
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
import { h, ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'
import { get, post, patch, del } from '@/utils/api.js'

const auth   = useAuthStore()
const router = useRouter()

const icon = (path) => () =>
  h('svg', { width: 16, height: 16, viewBox: '0 0 24 24', fill: 'none', stroke: 'currentColor', 'stroke-width': 1.75 }, path)

const themeOptions = [
  { value: 'system', label: 'System', icon: icon([h('rect', { x: 2, y: 3, width: 20, height: 14, rx: 2 }), h('path', { d: 'M8 21h8M12 17v4' })]) },
  { value: 'light',  label: 'Light',  icon: icon([h('circle', { cx: 12, cy: 12, r: 4 }), h('path', { d: 'M12 2v2M12 20v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M2 12h2M20 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42' })]) },
  { value: 'dark',   label: 'Dark',   icon: icon([h('path', { d: 'M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z' })]) },
]

// ── Locale ──────────────────────────────────────────────────────────
const timezones = typeof Intl.supportedValuesOf === 'function'
  ? Intl.supportedValuesOf('timeZone')
  : ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London']
const timezone = ref(auth.user?.timezone || 'UTC')
async function saveTimezone() {
  await patch('/api/me', { timezone: timezone.value })
  await auth.fetchMe()
}

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

// ── Data export ──────────────────────────────────────────────────
async function exportData() {
  const res = await get('/api/me/export')
  const blob = new Blob([JSON.stringify(res, null, 2)], { type: 'application/json' })
  const url  = URL.createObjectURL(blob)
  const a    = document.createElement('a')
  a.href = url; a.download = 'my-data-export.json'; a.click()
  URL.revokeObjectURL(url)
}

// ── Feedback ────────────────────────────────────────────────────────
const feedbackTypes = [
  { value: 'bug',     label: 'Bug' },
  { value: 'feature', label: 'Feature idea' },
  { value: 'other',   label: 'Other' },
]
const feedback        = reactive({ type: 'bug', message: '' })
const feedbackSaving  = ref(false)
const feedbackMsg     = ref('')
async function submitFeedback() {
  feedbackSaving.value = true; feedbackMsg.value = ''
  try {
    await post('/api/feedback', { type: feedback.type, message: feedback.message, url: window.location.pathname })
    feedbackMsg.value = 'Thanks — sent!'
    feedback.message = ''
  } catch { /* inline error not critical here */ } finally { feedbackSaving.value = false }
}

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
.settings-stack { display: flex; flex-direction: column; gap: 20px; max-width: 560px; }
.card-title { font-size: 15px; margin-bottom: 14px; }

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

.pill-row { display: flex; gap: 8px; flex-wrap: wrap; }
.pill-btn {
  padding: 6px 14px; border-radius: 99px; border: 1.5px solid var(--border);
  background: var(--surface); color: var(--text-secondary); cursor: pointer;
  font-size: 12.5px; font-weight: 500; transition: all var(--transition);
}
.pill-btn.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.text-danger  { color: var(--danger); }
.text-success { color: var(--success); }
</style>
