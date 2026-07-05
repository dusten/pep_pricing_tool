import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'
import { useSettingsStore } from '@/stores/settings.js'

const router = createRouter({
  history: createWebHistory(),
  scrollBehavior: () => ({ top: 0 }),
  routes: [
    // ── Public / auth ─────────────────────────────────────────
    {
      path: '/login',
      component: () => import('@/views/LoginView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/register',
      component: () => import('@/views/RegisterView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/verify-email',
      component: () => import('@/views/VerifyEmailView.vue'),
    },
    {
      path: '/verify-email-change',
      component: () => import('@/views/VerifyEmailView.vue'),
      props: {
        apiPath: 'auth/verify-email-change',
        successTitle: 'Email updated!',
        successBody: 'Your account email address has been changed.',
        successLink: '/settings',
        successLinkLabel: 'Back to Settings',
      },
    },
    {
      path: '/forgot-password',
      component: () => import('@/views/ForgotPasswordView.vue'),
      meta: { guestOnly: true },
    },
    {
      path: '/reset-password',
      component: () => import('@/views/ResetPasswordView.vue'),
      meta: { guestOnly: true },
    },

    // ── Authenticated app ──────────────────────────────────────
    {
      path: '/',
      redirect: '/dashboard',
    },
    {
      path: '/dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/comparison',
      component: () => import('@/views/ComparisonView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/cart',
      component: () => import('@/views/CartView.vue'),
      meta: { requiresAuth: true },
    },
    {
      // Public — CalendarView itself branches on auth state: a teaser
      // summary (counts/categories/product names, no $ or vendor) for
      // anonymous visitors, the full ledger for logged-in users.
      path: '/calendar',
      component: () => import('@/views/CalendarView.vue'),
    },
    {
      path: '/pricing',
      component: () => import('@/views/PricingView.vue'),
    },
    {
      path: '/submit-coa',
      component: () => import('@/views/SubmitCoaView.vue'),
      meta: { requiresAuth: true },
    },
    {
      path: '/account',
      redirect: '/settings',
    },
    {
      path: '/settings',
      component: () => import('@/views/SettingsView.vue'),
      meta: { requiresAuth: true },
    },

    // ── Admin ─────────────────────────────────────────────────
    {
      path: '/admin',
      component: () => import('@/views/admin/AdminView.vue'),
      meta: { requiresAuth: true, requiresAdmin: true },
    },

    // ── Catch-all ─────────────────────────────────────────────
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

router.beforeEach(async (to) => {
  const auth     = useAuthStore()
  const settings = useSettingsStore()

  // Load public settings once
  await settings.load()

  // Refresh user on first navigation if token exists
  if (auth.token && !auth.user) {
    await auth.fetchMe()
  }

  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return { path: '/login', query: { redirect: to.fullPath } }
  }

  if (to.meta.requiresAdmin && !auth.isAdmin) {
    return { path: '/dashboard' }
  }

  if (to.meta.guestOnly && auth.isAuthenticated) {
    return { path: '/dashboard' }
  }
})

export default router
