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
      <OverviewTab      v-if="activeTab === 'overview'" />
      <UsersTab         v-if="activeTab === 'users'" />
      <WaitlistTab      v-if="activeTab === 'waitlist'" />
      <SubscriptionsTab v-if="activeTab === 'subscriptions'" />
      <VendorsTab       v-if="activeTab === 'vendors'" />
      <ReviewQueueTab   v-if="activeTab === 'review-queue'" />
      <ProductsTab      v-if="activeTab === 'products'" />
      <StacksTab        v-if="activeTab === 'stacks'" />
      <InventoryTab     v-if="activeTab === 'inventory'" />
      <FilesTab         v-if="activeTab === 'files'" />
      <ClaudeApiTab     v-if="activeTab === 'claude-api'" />
      <CalendarTab      v-if="activeTab === 'calendar'" />
      <SettingsTab      v-if="activeTab === 'settings'" />
      <PerformanceTab   v-if="activeTab === 'performance'" />
      <SystemTab        v-if="activeTab === 'system'" />
      <FeedbackTab      v-if="activeTab === 'feedback'" />
      <BackupTab        v-if="activeTab === 'backup'" />
    </div>
  </AppLayout>
</template>

<script setup>
import { ref } from 'vue'
import AppLayout from '@/components/AppLayout.vue'

import OverviewTab      from './tabs/OverviewTab.vue'
import UsersTab         from './tabs/UsersTab.vue'
import WaitlistTab      from './tabs/WaitlistTab.vue'
import SubscriptionsTab from './tabs/SubscriptionsTab.vue'
import VendorsTab       from './tabs/VendorsTab.vue'
import ReviewQueueTab   from './tabs/ReviewQueueTab.vue'
import ProductsTab      from './tabs/ProductsTab.vue'
import StacksTab        from './tabs/StacksTab.vue'
import InventoryTab     from './tabs/InventoryTab.vue'
import FilesTab         from './tabs/FilesTab.vue'
import ClaudeApiTab     from './tabs/ClaudeApiTab.vue'
import CalendarTab      from './tabs/CalendarTab.vue'
import SettingsTab      from './tabs/SettingsTab.vue'
import PerformanceTab   from './tabs/PerformanceTab.vue'
import SystemTab        from './tabs/SystemTab.vue'
import FeedbackTab      from './tabs/FeedbackTab.vue'
import BackupTab        from './tabs/BackupTab.vue'

const activeTab = ref('overview')

const tabs = [
  { id: 'overview',      label: 'Overview' },
  { id: 'users',         label: 'Users' },
  { id: 'waitlist',      label: 'Waitlist' },
  { id: 'subscriptions', label: 'Subscriptions' },
  { id: 'vendors',       label: 'Vendors' },
  { id: 'review-queue',  label: 'Review Queue' },
  { id: 'products',      label: 'Products' },
  { id: 'stacks',        label: 'Stacks' },
  { id: 'inventory',     label: 'Inventory' },
  { id: 'files',         label: 'Files' },
  { id: 'claude-api',    label: 'Claude API' },
  { id: 'calendar',      label: 'Calendar' },
  { id: 'settings',      label: 'Settings' },
  { id: 'performance',   label: 'Performance' },
  { id: 'system',        label: 'System' },
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
</style>
