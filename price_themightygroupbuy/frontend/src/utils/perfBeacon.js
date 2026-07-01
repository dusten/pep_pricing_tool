// ponytail: one function, no library — Navigation Timing API is native and
// exactly matches what backend/api/perf.php already expects.
import { post } from './api.js'

export function sendPerfBeacon() {
  const send = () => {
    const [nav] = performance.getEntriesByType('navigation')
    if (!nav) return
    post('/api/perf', {
      page:        window.location.pathname,
      dns_ms:      Math.max(0, Math.round(nav.domainLookupEnd - nav.domainLookupStart)),
      connect_ms:  Math.max(0, Math.round(nav.connectEnd - nav.connectStart)),
      ttfb_ms:     Math.max(0, Math.round(nav.responseStart - nav.startTime)),
      dom_load_ms: Math.max(0, Math.round(nav.domContentLoadedEventEnd - nav.startTime)),
      load_ms:     Math.max(0, Math.round(nav.loadEventEnd - nav.startTime)),
    }).catch(() => {}) // best-effort — never surface a failure to the user
  }

  // loadEventEnd isn't finalized until just after 'load' fires; a 0ms
  // setTimeout lets the browser finish writing it before we read the entry.
  if (document.readyState === 'complete') {
    setTimeout(send, 0)
  } else {
    window.addEventListener('load', () => setTimeout(send, 0), { once: true })
  }
}
