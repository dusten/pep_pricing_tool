import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { post, get, patch, setTokenGetter } from '@/utils/api.js'

const TOKEN_KEY = 'pc_token'
const USER_KEY  = 'pc_user'

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem(TOKEN_KEY))
  const user  = ref(JSON.parse(localStorage.getItem(USER_KEY) || 'null'))

  // Wire token getter into the API utility
  setTokenGetter(() => token.value)

  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const isAdmin         = computed(() => !!user.value?.is_admin)
  const tier            = computed(() => user.value?.tier ?? 'free')
  const tierActive      = computed(() =>
    ['active','trialing'].includes(user.value?.tier_status))

  async function login(email, password) {
    const res = await post('/api/auth/login', { email, password })
    _persist(res.token, res.user)
    return res
  }

  async function logout() {
    try { await post('/api/auth/logout') } catch { /* best effort */ }
    _clear()
  }

  async function fetchMe() {
    if (!token.value) return
    try {
      const res = await get('/api/me')
      user.value = res
      localStorage.setItem(USER_KEY, JSON.stringify(res))
    } catch (err) {
      if (err.status === 401) _clear()
    }
  }

  async function updateTheme(theme) {
    const res = await patch('/api/me', { theme })
    user.value = { ...user.value, theme: res.theme ?? theme }
    localStorage.setItem(USER_KEY, JSON.stringify(user.value))
  }

  function _persist(tok, usr) {
    token.value = tok
    user.value  = usr
    localStorage.setItem(TOKEN_KEY, tok)
    localStorage.setItem(USER_KEY, JSON.stringify(usr))
  }

  function _clear() {
    token.value = null
    user.value  = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(USER_KEY)
  }

  return {
    token, user,
    isAuthenticated, isAdmin, tier, tierActive,
    login, logout, fetchMe, updateTheme,
  }
})
