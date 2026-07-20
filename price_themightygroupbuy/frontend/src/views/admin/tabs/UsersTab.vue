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
            <td>
              <select :value="u.tier_status" @change="setField(u, 'tier_status', $event.target.value)">
                <option value="active">active</option>
                <option value="trialing">trialing</option>
                <option value="past_due">past_due</option>
                <option value="canceled">canceled</option>
                <option value="none">none</option>
              </select>
            </td>
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
            <td class="actions">
              <button class="btn btn-ghost btn-sm" @click="toggle(u, 'activity')">{{ isOpen(u, 'activity') ? 'Hide' : 'Activity' }}</button>
              <button class="btn btn-ghost btn-sm" @click="toggle(u, 'referrals')">{{ isOpen(u, 'referrals') ? 'Hide' : 'Referrals' }}</button>
            </td>
          </tr>
          <tr v-if="isOpen(u, 'activity')">
            <td colspan="9">
              <div v-if="!activityData[u.id]" class="text-muted text-sm">Loading…</div>
              <div v-else class="activity-panel">
                <div class="text-sm"><strong>Recent activity</strong> (exports &amp; logged actions)</div>
                <table v-if="activityData[u.id].audit.length" class="admin-table sub-table">
                  <thead><tr><th>Action</th><th>Details</th><th>IP</th><th>When</th></tr></thead>
                  <tbody>
                    <tr v-for="(a, i) in activityData[u.id].audit" :key="i">
                      <td class="text-sm">{{ a.action }}</td>
                      <td class="text-sm text-muted">{{ fmtDetails(a.details) }}</td>
                      <td class="text-sm text-muted">{{ a.ip || '—' }}</td>
                      <td class="text-sm text-muted">{{ a.created_at }}</td>
                    </tr>
                  </tbody>
                </table>
                <p v-else class="text-muted text-sm">No recorded activity yet.</p>
                <div class="text-sm" style="margin-top:12px"><strong>Recent logins</strong></div>
                <ul v-if="activityData[u.id].logins.length" class="referral-list">
                  <li v-for="(l, i) in activityData[u.id].logins" :key="i" class="text-sm text-muted">{{ l.created_at }} — {{ l.ip || '—' }}</li>
                </ul>
                <p v-else class="text-muted text-sm">No login history.</p>
              </div>
            </td>
          </tr>
          <tr v-if="isOpen(u, 'referrals')">
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
                    <span v-if="r.granted_at" class="text-success"> · credited {{ r.months_granted }} mo</span>
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
const expanded     = ref(null) // { id, type } — one open panel at a time
const referralData = ref({})
const activityData = ref({})

async function load() {
  const res = await get(`/api/admin/users${tierFilter.value ? '?tier=' + tierFilter.value : ''}`)
  users.value = res.users
}
onMounted(load)

async function setField(u, field, value) {
  await patch(`/api/admin/users/${u.id}`, { [field]: value })
  u[field] = value
}

function isOpen(u, type) { return expanded.value?.id === u.id && expanded.value?.type === type }

async function toggle(u, type) {
  if (isOpen(u, type)) { expanded.value = null; return }
  expanded.value = { id: u.id, type }
  if (type === 'referrals' && !referralData.value[u.id]) {
    referralData.value[u.id] = await get(`/api/admin/users/${u.id}/referrals`)
  }
  if (type === 'activity' && !activityData.value[u.id]) {
    activityData.value[u.id] = await get(`/api/admin/users/${u.id}/activity`)
  }
}

function fmtDetails(d) {
  if (!d || typeof d !== 'object') return '—'
  return Object.entries(d).map(([k, v]) => `${k}: ${v}`).join(', ')
}
</script>

<style scoped>
.filter-row { margin-bottom: 14px; }
.filter-row select { max-width: 200px; }
.admin-table select { padding: 4px 8px; font-size: 12.5px; }
.toggle-row input { width: auto; }
.referral-tree { padding: 12px 4px; }
.referral-list { margin: 6px 0 0; padding-left: 18px; }
.text-success { color: var(--success); }
.activity-panel { padding: 12px 4px; }
.sub-table { margin-top: 6px; background: var(--surface); }
.sub-table th, .sub-table td { padding: 5px 8px; }
</style>
