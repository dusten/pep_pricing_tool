<template>
  <div>
    <div class="filter-row">
      <select v-model="tierFilter" @change="load">
        <option value="">All tiers</option>
        <option value="free">Free</option>
        <option value="advanced">Advanced</option>
        <option value="pro">Pro</option>
        <option value="expert">Expert</option>
      </select>
    </div>
    <table class="admin-table">
      <thead><tr><th>Email</th><th>Name</th><th>Tier</th><th>Status</th><th>Admin</th><th>Joined</th></tr></thead>
      <tbody>
        <tr v-for="u in users" :key="u.id">
          <td>{{ u.email }}</td>
          <td>{{ u.display_name }}</td>
          <td>
            <select :value="u.tier" @change="setTier(u, $event.target.value)">
              <option value="free">Free</option>
              <option value="advanced">Advanced</option>
              <option value="pro">Pro</option>
              <option value="expert">Expert</option>
            </select>
          </td>
          <td><span :class="['badge', u.tier_status === 'active' ? 'badge-pro' : 'badge-free']">{{ u.tier_status }}</span></td>
          <td>
            <label class="toggle-row">
              <input type="checkbox" :checked="u.is_admin" @change="setAdmin(u, $event.target.checked)" />
            </label>
          </td>
          <td class="text-muted text-sm">{{ u.created_at }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, patch } from '@/utils/api.js'

const users      = ref([])
const tierFilter = ref('')

async function load() {
  const res = await get(`/api/admin/users${tierFilter.value ? '?tier=' + tierFilter.value : ''}`)
  users.value = res.users
}
onMounted(load)

async function setTier(u, tier) {
  await patch(`/api/admin/users/${u.id}`, { tier })
  u.tier = tier
}
async function setAdmin(u, isAdmin) {
  await patch(`/api/admin/users/${u.id}`, { is_admin: isAdmin })
  u.is_admin = isAdmin
}
</script>

<style scoped>
.filter-row { margin-bottom: 14px; }
.filter-row select { max-width: 200px; }
.admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.admin-table th, .admin-table td { padding: 8px 10px; border-bottom: 1px solid var(--border); text-align: left; }
.admin-table thead th { color: var(--text-secondary); font-size: 11px; text-transform: uppercase; }
.admin-table select { padding: 4px 8px; font-size: 12.5px; }
.toggle-row input { width: auto; }
</style>
