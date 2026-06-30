import { defineStore } from 'pinia'
import { ref } from 'vue'
import { get } from '@/utils/api.js'

export const useSettingsStore = defineStore('settings', () => {
  const settings    = ref({})
  const loaded      = ref(false)

  async function load() {
    if (loaded.value) return
    try {
      settings.value = await get('/api/app-settings')
      loaded.value = true
    } catch {
      // non-fatal; defaults apply
    }
  }

  const waitlistMode   = () => settings.value.waitlist_mode      === '1'
  const maintenanceMode = () => settings.value.maintenance_mode  === '1'
  const queryLimit     = () => parseInt(settings.value.free_tier_query_limit  ?? '3')
  const queryWindowHrs = () => parseInt(settings.value.free_tier_window_hours ?? '72')

  return { settings, loaded, load, waitlistMode, maintenanceMode, queryLimit, queryWindowHrs }
})
