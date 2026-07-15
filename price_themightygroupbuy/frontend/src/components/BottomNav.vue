<template>
  <nav class="bottom-nav">
    <RouterLink to="/dashboard" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      <span>Dashboard</span>
    </RouterLink>
    <RouterLink to="/calendar" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      <span>Calendar</span>
    </RouterLink>
    <RouterLink to="/comparison" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 3h18v4H3z"/><path d="M3 11h18v2H3z"/><path d="M3 17h11v4H3z"/><path d="M17 17l2 2 4-4"/></svg>
      <span>Comparison</span>
    </RouterLink>
    <RouterLink to="/cart" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>
      <span>Cart</span>
    </RouterLink>
    <RouterLink to="/pricing" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M12 6v6l4 2"/></svg>
      <span>Pricing</span>
    </RouterLink>
    <RouterLink to="/submit-coa" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/><path d="M9 15l2 2 4-4"/></svg>
      <span>Submit COA</span>
    </RouterLink>
    <!-- ponytail: build-phase gate (backlog #69), delete v-if at launch (Phase 3) -->
    <RouterLink v-if="auth.isTestAccount || auth.isAdmin" to="/suggest-vendor" class="bn-item" active-class="active">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2l3 6.5 7 1-5 5 1.5 7L12 18l-6.5 3.5 1.5-7-5-5 7-1z"/></svg>
      <span>Suggest Vendor</span>
    </RouterLink>
  </nav>
</template>

<script setup>
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()
</script>

<style scoped>
.bottom-nav {
  display: flex;
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  height: var(--bottomnav-height, 58px);
  background: var(--topbar-bg);
  border-top: 1px solid var(--topbar-border);
  z-index: 100;
  padding-bottom: env(safe-area-inset-bottom, 0);
  max-width: 1400px;
  margin: 0 auto;
}

.bn-item {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  color: var(--text-muted);
  text-decoration: none;
  font-size: 10.5px;
  font-weight: 600;
}
.bn-item.active { color: var(--accent); }
</style>
