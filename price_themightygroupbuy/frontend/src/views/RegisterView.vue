<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="auth-logo-label">TheMightyGroupBuy</div>
        <div class="auth-logo-title">Price Comparison</div>
      </div>

      <!-- Waitlist mode: no invite token -->
      <template v-if="settings.waitlistMode() && !inviteToken">
        <h2 class="auth-title">Join the waitlist</h2>
        <p class="auth-subtitle">We're in invite-only mode. Enter your email and we'll reach out when a spot opens.</p>
        <div v-if="waitlistSuccess" class="alert alert-success">You're on the list! We'll be in touch.</div>
        <form v-else @submit.prevent="joinWaitlist">
          <div v-if="error" class="alert alert-error">{{ error }}</div>
          <div class="field">
            <label for="wl-email">Email</label>
            <input id="wl-email" v-model="wlEmail" type="email" placeholder="you@example.com"
                   required :disabled="loading" />
          </div>
          <button class="btn btn-primary btn-block" type="submit" :disabled="loading">
            {{ loading ? 'Submitting…' : 'Join Waitlist' }}
          </button>
        </form>
        <p class="text-center text-sm mt-4" style="color:var(--text-secondary)">
          Have an invite? <RouterLink :to="'/register?invite=' + ''">Use your invite link</RouterLink>
        </p>
      </template>

      <!-- Registration form -->
      <template v-else>
        <h2 class="auth-title">Create account</h2>
        <p class="auth-subtitle">
          <template v-if="inviteToken">You've been invited — set up your account below.</template>
          <template v-else>Start comparing peptide vendor prices.</template>
        </p>

        <div v-if="success" class="alert alert-success">
          Account created! Check your email for a verification link.
        </div>
        <form v-else @submit.prevent="submit">
          <div v-if="error" class="alert alert-error">{{ error }}</div>

          <div class="field">
            <label for="name">Display name</label>
            <input id="name" v-model="form.display_name" type="text"
                   placeholder="Your name" required :disabled="loading"
                   :class="{ error: errors.display_name }" />
            <p v-if="errors.display_name" class="field-error">{{ errors.display_name }}</p>
          </div>
          <div class="field">
            <label for="reg-email">Email</label>
            <input id="reg-email" v-model="form.email" type="email"
                   placeholder="you@example.com" required :disabled="loading"
                   :class="{ error: errors.email }" />
            <p v-if="errors.email" class="field-error">{{ errors.email }}</p>
          </div>
          <div class="field">
            <label for="reg-password">Password <span class="text-muted">(min. 8 characters)</span></label>
            <input id="reg-password" v-model="form.password" type="password"
                   placeholder="••••••••" required :disabled="loading"
                   :class="{ error: errors.password }" />
            <p v-if="errors.password" class="field-error">{{ errors.password }}</p>
          </div>

          <button class="btn btn-primary btn-block btn-lg" type="submit" :disabled="loading">
            <span v-if="loading" class="spinner" style="width:16px;height:16px;border-width:2px"></span>
            {{ loading ? 'Creating account…' : 'Create account' }}
          </button>

          <p class="text-center text-sm mt-4" style="color:var(--text-muted);font-size:11.5px">
            By signing up you agree to our terms of service.
          </p>
        </form>
      </template>

      <p class="text-center text-sm mt-4" style="color:var(--text-secondary)">
        Already have an account? <RouterLink to="/login">Sign in</RouterLink>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { post } from '@/utils/api.js'
import { useSettingsStore } from '@/stores/settings.js'

const route    = useRoute()
const settings = useSettingsStore()

const inviteToken = ref(route.query.invite || '')
const refCode     = ref(route.query.ref    || localStorage.getItem('pc_ref') || '')

// Persist referral code from URL
onMounted(() => {
  if (route.query.ref) localStorage.setItem('pc_ref', route.query.ref)
})

const form    = reactive({ display_name: '', email: '', password: '' })
const errors  = reactive({})
const error   = ref('')
const success = ref(false)
const loading = ref(false)

// Waitlist
const wlEmail        = ref('')
const waitlistSuccess = ref(false)

async function submit() {
  error.value   = ''
  Object.keys(errors).forEach(k => delete errors[k])
  loading.value = true
  try {
    await post('/api/auth/register', {
      ...form,
      invite_token:  inviteToken.value || undefined,
      referral_code: refCode.value || undefined,
    })
    success.value = true
  } catch (err) {
    if (err.data?.errors) Object.assign(errors, err.data.errors)
    else if (err.data?.error === 'waitlist') error.value = err.message
    else error.value = err.message || 'Registration failed. Please try again.'
  } finally {
    loading.value = false
  }
}

async function joinWaitlist() {
  error.value   = ''
  loading.value = true
  try {
    await post('/api/waitlist/join', { email: wlEmail.value, referral_code: refCode.value || undefined })
    waitlistSuccess.value = true
  } catch (err) {
    error.value = err.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>
