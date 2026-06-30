<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="auth-logo-label">TheMightyGroupBuy</div>
        <div class="auth-logo-title">Price Comparison</div>
      </div>

      <h2 class="auth-title">Reset password</h2>

      <div v-if="sent" class="alert alert-success" style="text-align:center">
        If that email is registered, you'll receive a reset link shortly. Check your spam folder too.
      </div>

      <template v-else>
        <p class="auth-subtitle">Enter your email and we'll send a reset link.</p>
        <div v-if="error" class="alert alert-error">{{ error }}</div>
        <form @submit.prevent="submit">
          <div class="field">
            <label for="fp-email">Email</label>
            <input id="fp-email" v-model="email" type="email" placeholder="you@example.com"
                   required :disabled="loading" />
          </div>
          <button class="btn btn-primary btn-block" type="submit" :disabled="loading">
            {{ loading ? 'Sending…' : 'Send reset link' }}
          </button>
        </form>
      </template>

      <p class="text-center text-sm mt-4" style="color:var(--text-secondary)">
        <RouterLink to="/login">Back to Sign In</RouterLink>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { RouterLink } from 'vue-router'
import { post } from '@/utils/api.js'

const email   = ref('')
const error   = ref('')
const sent    = ref(false)
const loading = ref(false)

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    await post('/api/auth/forgot-password', { email: email.value })
    sent.value = true
  } catch (err) {
    error.value = err.message || 'Something went wrong. Please try again.'
  } finally {
    loading.value = false
  }
}
</script>
