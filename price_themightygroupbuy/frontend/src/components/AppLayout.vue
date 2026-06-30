<template>
  <div class="app-shell">
    <Sidebar />
    <div class="app-main">
      <TopBar :title="title" />
      <main class="app-content">
        <slot />
      </main>
    </div>
  </div>
</template>

<script setup>
import Sidebar from './Sidebar.vue'
import TopBar  from './TopBar.vue'

defineProps({
  title: { type: String, default: '' },
})
</script>

<style scoped>
.app-shell {
  display: flex;
  min-height: 100vh;
  background: var(--background);
}

.app-main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  margin-left: var(--sidebar-width);
}

.app-content {
  flex: 1;
  padding: 28px 32px;
  max-width: 1400px;
  width: 100%;
}

@media (max-width: 768px) {
  .app-main { margin-left: 0; }
  .app-content { padding: 16px; }
}
</style>
