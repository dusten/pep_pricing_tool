<template>
  <AppLayout title="Price Comparison">
    <div class="card" style="text-align:center;padding:60px 32px">
      <svg style="margin:0 auto 20px;opacity:.3" width="52" height="52" viewBox="0 0 24 24"
           fill="none" stroke="currentColor" stroke-width="1.75">
        <path d="M3 3h18v4H3z"/><path d="M3 11h18v2H3z"/><path d="M3 17h11v4H3z"/>
      </svg>
      <h2 style="margin-bottom:8px">Comparison table</h2>
      <p style="color:var(--text-secondary);max-width:380px;margin:0 auto 24px">
        The price comparison table is coming in Phase 3. It will show side-by-side
        pricing across all vendors with $/unit highlighting and export options.
      </p>
      <RouterLink to="/pricing" class="btn btn-accent">View plans</RouterLink>
    </div>
  </AppLayout>
</template>

<script setup>
import AppLayout from '@/components/AppLayout.vue'
import { RouterLink } from 'vue-router'
</script>
