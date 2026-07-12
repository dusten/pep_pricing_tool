import { defineStore } from 'pinia'
import { ref } from 'vue'

let nextId = 0

// ponytail: in-memory queue, no persistence — a toast that hasn't rendered
// yet by the time of a refresh wasn't worth keeping anyway.
export const useToastStore = defineStore('toast', () => {
  const toasts = ref([]) // { id, message, type }

  function remove(id) {
    const i = toasts.value.findIndex(t => t.id === id)
    if (i !== -1) toasts.value.splice(i, 1)
  }

  function push(message, type, duration) {
    const id = nextId++
    toasts.value.push({ id, message, type })
    setTimeout(() => remove(id), duration)
  }

  return {
    toasts,
    remove,
    error:   (message) => push(message, 'error', 3500),
    success: (message) => push(message, 'success', 3500),
    info:    (message) => push(message, 'info', 3500),
  }
})
