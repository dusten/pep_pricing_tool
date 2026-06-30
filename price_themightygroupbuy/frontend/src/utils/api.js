// ponytail: thin fetch wrapper — no axios, no interceptor stack

let _getToken = () => null

export function setTokenGetter(fn) {
  _getToken = fn
}

export async function api(method, path, body = null) {
  const headers = {}
  const token = _getToken()
  if (token) headers['Authorization'] = `Bearer ${token}`
  if (body !== null) headers['Content-Type'] = 'application/json'

  const res = await fetch(path, {
    method,
    headers,
    body: body !== null ? JSON.stringify(body) : undefined,
  })

  let data
  try { data = await res.json() } catch { data = {} }

  if (!res.ok) {
    const err = new Error(data.message || data.error || `HTTP ${res.status}`)
    err.status = res.status
    err.data   = data
    throw err
  }

  return data
}

export const get    = (path)        => api('GET',    path)
export const post   = (path, body)  => api('POST',   path, body)
export const patch  = (path, body)  => api('PATCH',  path, body)
export const put    = (path, body)  => api('PUT',    path, body)
export const del    = (path)        => api('DELETE', path)
