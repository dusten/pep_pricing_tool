<template>
  <aside class="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
      <div class="brand-eyebrow">TheMightyGroupBuy</div>
      <div class="brand-title">Price Comparison</div>
    </div>

    <!-- Main nav -->
    <nav class="sidebar-nav">
      <NavItem to="/dashboard" label="Dashboard">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/></svg>
      </NavItem>
      <NavItem to="/calendar" label="Calendar">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
      </NavItem>
      <NavItem to="/comparison" label="Comparison">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M3 3h18v4H3z"/><path d="M3 11h18v2H3z"/><path d="M3 17h11v4H3z"/><path d="M17 17l2 2 4-4"/></svg>
      </NavItem>
      <NavItem to="/pricing" label="Pricing">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/><path d="M12 6v6l4 2"/></svg>
      </NavItem>
      <NavItem to="/account" label="Account">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><circle cx="12" cy="8" r="4"/><path d="M4 20c0-4 3.6-7 8-7s8 3 8 7"/></svg>
      </NavItem>
    </nav>

    <!-- Bottom: tier badge -->
    <div class="sidebar-footer">
      <span :class="['badge', `badge-${auth.tier}`]">
        {{ auth.tier.charAt(0).toUpperCase() + auth.tier.slice(1) }}
      </span>
    </div>
  </aside>
</template>

<script setup>
import { defineComponent, h } from 'vue'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth.js'

const auth = useAuthStore()

// Inline NavItem to avoid extra file — ponytail: fewest files
const NavItem = defineComponent({
  props: { to: String, label: String },
  setup(props, { slots }) {
    return () => h(RouterLink, { to: props.to, class: 'nav-item', activeClass: 'active' },
      () => [h('span', { class: 'nav-icon' }, slots.default?.()), h('span', props.label)])
  },
})
</script>

<style scoped>
.sidebar {
  position: fixed;
  top: 0;
  left: 0;
  width: var(--sidebar-width);
  height: 100vh;
  background: var(--sidebar-bg);
  display: flex;
  flex-direction: column;
  z-index: 100;
  border-right: 1px solid rgba(255,255,255,.06);
}

.sidebar-brand {
  padding: 22px 18px 16px;
  border-bottom: 1px solid rgba(255,255,255,.06);
}
.brand-eyebrow {
  font-size: 9px;
  letter-spacing: 2.5px;
  text-transform: uppercase;
  color: var(--accent);
  font-weight: 700;
  margin-bottom: 3px;
}
.brand-title {
  font-size: 13.5px;
  font-weight: 700;
  color: #fff;
  letter-spacing: -0.2px;
}

.sidebar-nav {
  flex: 1;
  overflow-y: auto;
  padding: 10px 10px;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

:deep(.nav-item) {
  display: flex;
  align-items: center;
  gap: 9px;
  padding: 8px 10px;
  border-radius: var(--radius);
  color: var(--sidebar-text);
  font-size: 13.5px;
  font-weight: 500;
  text-decoration: none;
  transition: background var(--transition), color var(--transition);
}
:deep(.nav-item:hover) {
  background: var(--sidebar-hover);
  color: #fff;
}
:deep(.nav-item.active) {
  background: var(--sidebar-active-bg);
  color: var(--sidebar-active);
  font-weight: 600;
}
.nav-icon {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}

.sidebar-footer {
  padding: 12px 14px 18px;
  border-top: 1px solid rgba(255,255,255,.06);
}

@media (max-width: 768px) {
  .sidebar { display: none; }
}
</style>
