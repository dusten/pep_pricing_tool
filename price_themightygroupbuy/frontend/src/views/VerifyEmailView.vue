<template>
  <div class="auth-page">
    <div class="auth-card" style="text-align:center">
      <div class="auth-logo">
        <div class="auth-logo-label">TheMightyGroupBuy</div>
        <div class="auth-logo-title">Price Comparison</div>
      </div>

      <div v-if="loading" style="display:flex;flex-direction:column;align-items:center;gap:16px;padding:24px 0">
        <div class="spinner" style="width:28px;height:28px;border-width:3px"></div>
        <p style="color:var(--text-secondary);margin:0">Verifying your email…</p>
      </div>

      <template v-else-if="success">
        <svg style="margin:0 auto 16px" width="48" height="48" viewBox="0 0 24 24" fill="none"
             stroke="var(--success)" stroke-width="1.75">
          <circle cx="12" cy="12" r="10"/>
          <path d="M8 12l3 3 5-5"/>
        </svg>
        <h2 class="auth-title">Email verified!</h2>
        <p style="color:var(--text-secondary);margin:0 0 24px">Your account is ready. You can now sign in.</p>
        <RouterLink to="/login" class="btn btn-primary btn-block">Go to Sign In</RouterLink>
      </template>

      <template v-else>
        <svg style="margin:0 auto 16px" width="48" height="48" viewBox="0 0 24 24" fill="none"
             stroke="var(--danger)" stroke-width="1.75">
          <circle cx="12" cy="12" r="10"/>
          <path d="M12 8v4M12 16h.01"/>
        </svg>
        <h2 class="auth-title">Verification failed</h2>
        <p style="color:var(--text-secondary);margin:0 0 24px">{{ error }}</p>
        <RouterLink to="/login" class="btn btn-ghost btn-block">Back to Sign In</RouterLink>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { get } from '@/utils/api.js'

const route   = useRoute()
const loading = ref(true)
const success = ref(false)
const error   = ref('')

onMounted(async () => {
  const token = route.query.token
  if (!token) {
    error.value = 'No verification token found in the link.'
    loading.value = false
    return
  }
  try {
    await get(`/api/auth/verify-email?token=${encodeURIComponent(token)}`)
    success.value = true
  } catch (err) {
    error.value = err.message || 'This link is invalid or has expired. Request a new one by signing in.'
  } finally {
    loading.value = false
  }
})
</script>
