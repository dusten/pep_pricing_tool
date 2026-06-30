<template>
  <div class="auth-page">
    <div class="auth-card">
      <div class="auth-logo">
        <div class="auth-logo-label">TheMightyGroupBuy</div>
        <div class="auth-logo-title">Price Comparison</div>
      </div>
      <h2 class="auth-title">Set new password</h2>

      <div v-if="success" class="alert alert-success" style="text-align:center">
        Password updated! <RouterLink to="/login">Sign in</RouterLink>
      </div>

      <template v-else>
        <div v-if="error" class="alert alert-error">{{ error }}</div>
        <form @submit.prevent="submit">
          <div class="field">
            <label for="np">New password <span class="text-muted">(min. 8 characters)</span></label>
            <input id="np" v-model="password" type="password" placeholder="••••••••"
                   required minlength="8" :disabled="loading" />
          </div>
          <div class="field">
            <label for="np2">Confirm password</label>
            <input id="np2" v-model="confirm" type="password" placeholder="••••••••"
                   required :disabled="loading" :class="{ error: mismatch }" />
            <p v-if="mismatch" class="field-error">Passwords do not match.</p>
          </div>
          <button class="btn btn-primary btn-block" type="submit"
                  :disabled="loading || mismatch">
            {{ loading ? 'Saving…' : 'Set new password' }}
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { ref, computed } from 'vue'
import { useRoute, RouterLink } from 'vue-router'
import { post } from '@/utils/api.js'

const route    = useRoute()
const password = ref('')
const confirm  = ref('')
const error    = ref('')
const success  = ref(false)
const loading  = ref(false)

const mismatch = computed(() => confirm.value && confirm.value !== password.value)

async function submit() {
  if (mismatch.value) return
  error.value   = ''
  loading.value = true
  try {
    await post('/api/auth/reset-password', {
      token:    route.query.token,
      password: password.value,
    })
    success.value = true
  } catch (err) {
    error.value = err.message || 'This link may have expired. Request a new one.'
  } finally {
    loading.value = false
  }
}
</script>
