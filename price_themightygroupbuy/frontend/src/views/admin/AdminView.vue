<template>
  <AppLayout title="Admin Panel">
    <!-- Pill tabs -->
    <div class="admin-tabs">
      <button v-for="tab in tabs" :key="tab.id"
              :class="['admin-tab', { active: activeTab === tab.id }]"
              @click="activeTab = tab.id">
        {{ tab.label }}
      </button>
    </div>

    <div class="admin-body">
      <!-- Users -->
      <div v-if="activeTab === 'users'">
        <div class="tab-placeholder">
          <p>Users table — lists all registered users with tier, status, and admin controls.</p>
          <p class="text-muted text-sm">Built in Phase 2 alongside subscription management.</p>
        </div>
      </div>

      <!-- Waitlist -->
      <div v-if="activeTab === 'waitlist'">
        <div class="tab-placeholder">
          <p>Waitlist management — invite individuals or in bulk, toggle waitlist mode.</p>
          <p class="text-muted text-sm">Built in Phase 2.</p>
        </div>
      </div>

      <!-- Vendors -->
      <div v-if="activeTab === 'vendors'">
        <div class="tab-placeholder">
          <p>Vendor management — add/edit vendors, upload price lists.</p>
          <p class="text-muted text-sm">Built in Phase 3 (Claude pipeline phase).</p>
        </div>
      </div>

      <!-- Products -->
      <div v-if="activeTab === 'products'">
        <div class="tab-placeholder">
          <p>Product catalogue — canonical names, aliases, merge tool.</p>
          <p class="text-muted text-sm">Built in Phase 3.</p>
        </div>
      </div>

      <!-- Files -->
      <div v-if="activeTab === 'files'">
        <div class="tab-placeholder">
          <p>File processing queue — upload vendor price lists, trigger Claude extraction.</p>
          <p class="text-muted text-sm">Built in Phase 3 (Claude pipeline phase).</p>
        </div>
      </div>

      <!-- Subscriptions -->
      <div v-if="activeTab === 'subscriptions'">
        <div class="tab-placeholder">
          <p>Subscriber list — tier, status, Stripe links, manual credit.</p>
          <p class="text-muted text-sm">Built in Phase 2 (Stripe phase).</p>
        </div>
      </div>

      <!-- Settings -->
      <div v-if="activeTab === 'settings'">
        <SettingsTab />
      </div>

      <!-- Performance -->
      <div v-if="activeTab === 'performance'">
        <div class="tab-placeholder">
          <p>Navigation Timing metrics, 90-day retention sparklines.</p>
          <p class="text-muted text-sm">Built in Phase 2.</p>
        </div>
      </div>

      <!-- Feedback -->
      <div v-if="activeTab === 'feedback'">
        <FeedbackTab />
      </div>

      <!-- Backup -->
      <div v-if="activeTab === 'backup'">
        <div class="tab-placeholder">
          <p>Download ZIP of database + vendor_files, restore with SQL validation.</p>
          <p class="text-muted text-sm">Built in Phase 2.</p>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

<script setup>
import { ref, defineAsyncComponent } from 'vue'
import AppLayout from '@/components/AppLayout.vue'

// Only wire up tabs that are built; stubs for the rest
const SettingsTab = defineAsyncComponent(() => import('./tabs/SettingsTab.vue').catch(() => ({ template: '<div class="tab-placeholder"><p>Settings tab loading…</p></div>' })))
const FeedbackTab = defineAsyncComponent(() => import('./tabs/FeedbackTab.vue').catch(() => ({ template: '<div class="tab-placeholder"><p>Feedback tab loading…</p></div>' })))

const activeTab = ref('settings')

const tabs = [
  { id: 'users',         label: 'Users' },
  { id: 'waitlist',      label: 'Waitlist' },
  { id: 'subscriptions', label: 'Subscriptions' },
  { id: 'vendors',       label: 'Vendors' },
  { id: 'products',      label: 'Products' },
  { id: 'files',         label: 'Files' },
  { id: 'settings',      label: 'Settings' },
  { id: 'performance',   label: 'Performance' },
  { id: 'feedback',      label: 'Feedback' },
  { id: 'backup',        label: 'Backup' },
]
</script>

<style scoped>
.admin-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--border);
}
.admin-tab {
  padding: 6px 16px;
  border-radius: 99px;
  border: 1.5px solid var(--border);
  background: var(--surface);
  cursor: pointer;
  font-size: 13px;
  font-weight: 500;
  color: var(--text-secondary);
  transition: all var(--transition);
}
.admin-tab:hover  { border-color: var(--accent); color: var(--accent); }
.admin-tab.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

.admin-body { }
.tab-placeholder {
  background: var(--surface);
  border: 1px dashed var(--border);
  border-radius: var(--radius-lg);
  padding: 40px;
  text-align: center;
  color: var(--text-secondary);
}
.tab-placeholder p { margin: 0 0 8px; }
</style>
