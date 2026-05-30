const BASE = import.meta.env.VITE_API_URL ?? ''

async function get(path) {
  const r = await fetch(BASE + path)
  if (!r.ok) throw new Error(`${r.status} ${r.statusText}`)
  return r.json()
}

export const fetchSummary = () => get('/api/summary')

export const fetchStats = (days = 90, view = 'clean') =>
  days === 0
    ? get(`/api/stats?all=1&view=${view}`)
    : get(`/api/stats?days=${days}&view=${view}`)

export const fetchChanges = (days = 30, kind = 'all', view = 'clean') =>
  get(`/api/changes?days=${days}&kind=${kind}&view=${view}`)

export const fetchSearch = (q) =>
  get(`/api/search?q=${encodeURIComponent(q)}`)
