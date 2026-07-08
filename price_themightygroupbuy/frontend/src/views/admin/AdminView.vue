<template>
  <AppLayout title="Admin Panel">
    <!-- Primary groups -->
    <div class="admin-groups">
      <button v-for="g in groups" :key="g.id"
              :class="['admin-group-tab', { active: activeGroup === g.id }]"
              @click="selectGroup(g.id)">
        {{ g.label }}
      </button>
    </div>

    <!-- Sub-tabs for the active group -->
    <div class="admin-tabs">
      <button v-for="tab in currentTabs" :key="tab.id"
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
import { ref, computed } from 'vue'
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

// Two primary groups, each fanning out into its own sub-tabs — keeps the
// admin nav from being a single 17-wide pill row.
const groups = [
  { id: 'catalog', label: 'Vendor / Product Management', tabs: [
    { id: 'vendors',      label: 'Vendors' },
    { id: 'review-queue', label: 'Review Queue' },
    { id: 'products',     label: 'Products' },
    { id: 'inventory',    label: 'Inventory' },
    { id: 'stacks',       label: 'Stacks' },
    { id: 'files',        label: 'Files' },
    { id: 'claude-api',   label: 'Claude API' },
    { id: 'calendar',     label: 'Calendar' },
  ] },
  { id: 'system', label: 'System / User Management', tabs: [
    { id: 'overview',      label: 'Overview' },
    { id: 'users',         label: 'Users' },
    { id: 'waitlist',      label: 'Waitlist' },
    { id: 'subscriptions', label: 'Subscriptions' },
    { id: 'feedback',      label: 'Feedback' },
    { id: 'performance',   label: 'Performance' },
    { id: 'system',        label: 'System' },
    { id: 'backup',        label: 'Backup' },
    { id: 'settings',      label: 'Settings' },
  ] },
]

const activeGroup = ref('system')          // land on Overview, as before
const activeTab   = ref('overview')

const currentTabs = computed(() => groups.find(g => g.id === activeGroup.value).tabs)

function selectGroup(id) {
  activeGroup.value = id
  activeTab.value   = groups.find(g => g.id === id).tabs[0].id
}
</script>

<style scoped>
.admin-groups {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
}
.admin-group-tab {
  padding: 9px 20px;
  border-radius: var(--radius-sm);
  border: 1.5px solid var(--border);
  background: var(--surface);
  cursor: pointer;
  font-size: 14px;
  font-weight: 600;
  color: var(--text-secondary);
  transition: all var(--transition);
}
.admin-group-tab:hover  { border-color: var(--accent); color: var(--accent); }
.admin-group-tab.active { background: var(--primary); border-color: var(--primary); color: var(--text-on-primary); }

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
.admin-tab.active { background: var(--accent); border-color: var(--accent); color: var(--text-on-accent); }

.admin-body { }
</style>
