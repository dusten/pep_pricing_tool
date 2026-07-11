<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="auth-logo-label">TheMightyGroupBuy</div>
        <div class="auth-logo-title">Price Comparison</div>
      </div>

      <h2 class="auth-title">Sign in</h2>
      <p class="auth-subtitle">Access peptide vendor price data</p>

      <div v-if="error" class="alert alert-error">{{ error }}</div>
      <div v-if="unverified" class="alert alert-warning">
        Please verify your email before logging in.
        <button class="resend-link" @click="resendVerify">Resend email</button>
      </div>

      <form @submit.prevent="submit">
        <div class="field">
          <label for="email">Email</label>
          <input id="email" v-model="form.email" type="email" autocomplete="email"
                 placeholder="you@example.com" required :disabled="loading" />
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" v-model="form.password" type="password" autocomplete="current-password"
                 placeholder="••••••••" required :disabled="loading" />
        </div>

        <div class="field-row">
          <RouterLink to="/forgot-password" class="forgot-link">Forgot password?</RouterLink>
        </div>

        <button class="btn btn-primary btn-block btn-lg" type="submit" :disabled="loading">
          <span v-if="loading" class="spinner" style="width:16px;height:16px;border-width:2px"></span>
          <span>{{ loading ? 'Signing in…' : 'Sign in' }}</span>
        </button>
      </form>

      <p class="text-center text-sm mt-4" style="color:var(--text-secondary)">
        Don't have an account?
        <RouterLink to="/register">Sign up</RouterLink>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { useRouter, useRoute, RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useToastStore } from '@/stores/toast.js'

const auth    = useAuthStore()
const toast   = useToastStore()
const router  = useRouter()
const route   = useRoute()

const form       = reactive({ email: '', password: '' })
const error      = ref('')
const unverified = ref(false)
const loading    = ref(false)

async function submit() {
  error.value      = ''
  unverified.value = false
  loading.value    = true
  try {
    await auth.login(form.email, form.password)
    const dest = route.query.redirect || '/dashboard'
    router.push(dest)
  } catch (err) {
    if (err.data?.code === 'email_unverified') {
      unverified.value = true
    } else {
      error.value = err.message || 'Login failed. Please try again.'
    }
  } finally {
    loading.value = false
  }
}

async function resendVerify() {
  // ponytail: stub — wire up when /api/auth/resend-verify is built
  toast.info('Check your original verification email, or contact support.')
}
</script>

<style scoped>
.field-row {
  display: flex;
  justify-content: flex-end;
  margin: -8px 0 18px;
}
.forgot-link { font-size: 12.5px; color: var(--text-secondary); }
.forgot-link:hover { color: var(--accent); }
.resend-link {
  background: none; border: none; cursor: pointer;
  color: var(--warning); font-size: inherit;
  text-decoration: underline; padding: 0; margin-left: 4px;
}
</style>
