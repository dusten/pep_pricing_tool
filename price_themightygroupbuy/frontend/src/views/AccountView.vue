<template>
  <AppLayout title="Account">
    <div style="display:flex;flex-direction:column;gap:20px;max-width:640px">
      <!-- Profile -->
      <div class="card">
        <h3 style="margin-bottom:16px;font-size:15px">Profile</h3>
        <div class="field">
          <label>Display name</label>
          <input v-model="displayName" type="text" :disabled="saving" />
        </div>
        <div class="field" style="margin-bottom:0">
          <label>Email</label>
          <input :value="auth.user?.email" type="email" disabled style="opacity:.6" />
        </div>
        <div style="margin-top:16px;display:flex;align-items:center;gap:10px">
          <button class="btn btn-primary btn-sm" @click="saveName" :disabled="saving">
            {{ saving ? 'Saving…' : 'Save name' }}
          </button>
          <span v-if="saved" style="font-size:12.5px;color:var(--success)">Saved!</span>
        </div>
      </div>

      <!-- Subscription -->
      <div class="card">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
          <h3 style="font-size:15px;margin:0">Subscription</h3>
          <span :class="['badge', `badge-${auth.tier}`]">
            {{ auth.tier.charAt(0).toUpperCase() + auth.tier.slice(1) }}
          </span>
        </div>
        <p style="font-size:13.5px;color:var(--text-secondary);margin-bottom:16px">
          <template v-if="auth.tier === 'free'">
            You're on the free plan — 3 comparison queries per 72 hours.
          </template>
          <template v-else>
            {{ auth.user?.tier_status === 'active' ? 'Active' : auth.user?.tier_status }}
            <span v-if="auth.user?.tier_renews_at">
              · renews {{ fmtDate(auth.user.tier_renews_at) }}
            </span>
          </template>
        </p>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <RouterLink to="/pricing" class="btn btn-accent btn-sm">
            {{ auth.tier === 'free' ? 'Upgrade plan' : 'Change plan' }}
          </RouterLink>
          <!-- Billing portal: wired in Phase 2 (Stripe) -->
          <button v-if="auth.tier !== 'free'" class="btn btn-ghost btn-sm" disabled title="Coming in Phase 2">
            Manage billing
          </button>
        </div>
      </div>

      <!-- Referral -->
      <div class="card">
        <h3 style="margin-bottom:8px;font-size:15px">Refer a friend</h3>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px">
          Share your link. When they subscribe, you'll receive account credit.
        </p>
        <div class="ref-row">
          <input :value="referralUrl" readonly class="ref-input" @focus="$event.target.select()" />
          <button class="btn btn-ghost btn-sm" @click="copyRef">{{ copied ? 'Copied!' : 'Copy' }}</button>
        </div>
      </div>

      <!-- Danger zone -->
      <div class="card" style="border-color:var(--danger)">
        <h3 style="font-size:15px;margin-bottom:8px;color:var(--danger)">Sign out other sessions</h3>
        <p style="font-size:13px;color:var(--text-secondary);margin-bottom:14px">
          Invalidates every other active session. This device stays signed in.
        </p>
        <button class="btn btn-danger btn-sm" :disabled="revoking" @click="revokeOthers">
          {{ revoking ? 'Signing out…' : 'Sign out other sessions' }}
        </button>
        <span v-if="revoked" style="font-size:12.5px;color:var(--success);margin-left:10px">Done!</span>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRouter, RouterLink } from 'vue-router'
import AppLayout from '@/components/AppLayout.vue'
import { useAuthStore } from '@/stores/auth.js'
import { patch, post } from '@/utils/api.js'

const auth   = useAuthStore()
const router = useRouter()

const displayName = ref(auth.user?.display_name ?? '')
const saving      = ref(false)
const saved       = ref(false)
const copied      = ref(false)
const revoking     = ref(false)
const revoked      = ref(false)

async function saveName() {
  saving.value = true
  try {
    await patch('/api/me', { display_name: displayName.value })
    await auth.fetchMe()
    saved.value = true
    setTimeout(() => { saved.value = false }, 2000)
  } catch { /* */ } finally { saving.value = false }
}

const referralUrl = computed(() =>
  `${window.location.origin}/register?ref=${auth.user?.referral_code ?? ''}`)

async function copyRef() {
  await navigator.clipboard.writeText(referralUrl.value).catch(() => {})
  copied.value = true
  setTimeout(() => { copied.value = false }, 2000)
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}

async function revokeOthers() {
  revoking.value = true; revoked.value = false
  try {
    await post('/api/me/sessions/revoke-all')
    revoked.value = true
    setTimeout(() => { revoked.value = false }, 2500)
  } finally { revoking.value = false }
}

function fmtDate(d) {
  return new Date(d).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
}
</script>

<style scoped>
.ref-row   { display: flex; gap: 8px; }
.ref-input { flex: 1; font-size: 12.5px; font-family: var(--font-mono); }
</style>
