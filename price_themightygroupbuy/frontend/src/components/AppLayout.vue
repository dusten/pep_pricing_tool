<template>
  <div class="app-shell">
    <TopBar :title="title" />
    <main class="app-content" :class="{ wide }">
      <slot />
    </main>
    <BottomNav />
  </div>
</template>

<script setup>
import TopBar    from './TopBar.vue'
import BottomNav from './BottomNav.vue'

defineProps({
  title: { type: String, default: '' },
  // Drop the centered max-width cap so content fills a very wide window
  // (the Comparison table wants every pixel when there are many vendors).
  wide: { type: Boolean, default: false },
})
</script>

<style scoped>
.app-shell {
  min-height: 100vh;
  background: var(--background);
}

.app-content {
  padding: 28px 32px calc(var(--bottomnav-height, 58px) + 28px);
  max-width: 1400px;
  margin: 0 auto;
  width: 100%;
}
.app-content.wide { max-width: none; }

@media (max-width: 768px) {
  .app-content { padding: 16px calc(16px) calc(var(--bottomnav-height, 58px) + 16px); }
}
</style>
