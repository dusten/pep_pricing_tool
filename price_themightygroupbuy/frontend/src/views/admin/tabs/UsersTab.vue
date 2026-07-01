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
      <thead><tr><th>Email</th><th>Name</th><th>Tier</th><th>Status</th><th>Verified</th><th>Admin</th><th>Test</th><th>Joined</th><th></th></tr></thead>
      <tbody>
        <template v-for="u in users" :key="u.id">
          <tr>
            <td>{{ u.email }}</td>
            <td>{{ u.display_name }}</td>
            <td>
              <select :value="u.tier" @change="setField(u, 'tier', $event.target.value)">
                <option value="free">Free</option>
                <option value="advanced">Advanced</option>
                <option value="pro">Pro</option>
                <option value="expert">Expert</option>
              </select>
            </td>
            <td><span :class="['badge', u.tier_status === 'active' ? 'badge-pro' : 'badge-free']">{{ u.tier_status }}</span></td>
            <td><span :class="['badge', u.email_verified_at ? 'badge-pro' : 'badge-free']">{{ u.email_verified_at ? 'Verified' : 'Unverified' }}</span></td>
            <td>
              <label class="toggle-row">
                <input type="checkbox" :checked="u.is_admin" @change="setField(u, 'is_admin', $event.target.checked)" />
              </label>
            </td>
            <td>
              <label class="toggle-row" title="Excludes this account from real notifications and stats">
                <input type="checkbox" :checked="u.test_account" @change="setField(u, 'test_account', $event.target.checked)" />
              </label>
            </td>
            <td class="text-muted text-sm">{{ u.created_at }}</td>
            <td><button class="btn btn-ghost btn-sm" @click="toggleReferrals(u)">{{ expanded === u.id ? 'Hide' : 'Referrals' }}</button></td>
          </tr>
          <tr v-if="expanded === u.id">
            <td colspan="9">
              <div v-if="!referralData[u.id]" class="text-muted text-sm">Loading…</div>
              <div v-else class="referral-tree">
                <div class="text-sm">
                  <strong>Referred by:</strong>
                  {{ referralData[u.id].referred_by ? `${referralData[u.id].referred_by.display_name} (${referralData[u.id].referred_by.email})` : '—' }}
                </div>
                <div class="text-sm" style="margin-top:8px"><strong>Referred ({{ referralData[u.id].referred.length }}):</strong></div>
                <ul v-if="referralData[u.id].referred.length" class="referral-list">
                  <li v-for="r in referralData[u.id].referred" :key="r.id" class="text-sm">
                    {{ r.display_name }} ({{ r.email }}) — joined {{ r.created_at }}
                    <span v-if="r.granted_at" class="text-success"> · credited ${{ r.amount_usd }}</span>
                    <span v-else class="text-muted"> · not yet converted</span>
                  </li>
                </ul>
                <p v-else class="text-muted text-sm">No referrals yet.</p>
              </div>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { get, patch } from '@/utils/api.js'

const users      = ref([])
const tierFilter = ref('')
const expanded    = ref(null)
const referralData = ref({})

async function load() {
  const res = await get(`/api/admin/users${tierFilter.value ? '?tier=' + tierFilter.value : ''}`)
  users.value = res.users
}
onMounted(load)

async function setField(u, field, value) {
  await patch(`/api/admin/users/${u.id}`, { [field]: value })
  u[field] = value
}

async function toggleReferrals(u) {
  if (expanded.value === u.id) { expanded.value = null; return }
  expanded.value = u.id
  if (!referralData.value[u.id]) {
    const res = await get(`/api/admin/users/${u.id}/referrals`)
    referralData.value[u.id] = res
  }
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
.referral-tree { padding: 12px 4px; }
.referral-list { margin: 6px 0 0; padding-left: 18px; }
.text-success { color: var(--success); }
</style>
