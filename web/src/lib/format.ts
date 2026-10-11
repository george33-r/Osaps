/** Naira input (whole naira typed by a person) → integer kobo for the API. Returns null for empty or invalid input. */
export function nairaToMinor(input: string): number | null {
  const clean = input.replace(/[₦,\s]/g, '')
  if (!clean || !/^\d+(\.\d{1,2})?$/.test(clean)) return null
  const [whole = '0', frac = ''] = clean.split('.')
  return Number(whole) * 100 + Number(frac.padEnd(2, '0'))
}

export function formatNaira(minor: number): string {
  return '₦' + Math.floor(minor / 100).toLocaleString('en-NG') + (minor % 100 ? '.' + String(minor % 100).padStart(2, '0') : '')
}

/** Live digit grouping while typing an amount: "45000" → "45,000". */
export function groupDigits(input: string): string {
  const digits = input.replace(/[^\d]/g, '')
  return digits ? Number(digits).toLocaleString('en-NG') : ''
}

export function formatDate(iso: string, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'short', year: 'numeric' }): string {
  return new Date(iso.length === 10 ? iso + 'T12:00:00' : iso).toLocaleDateString('en-NG', opts)
}

/** "in 2 days", "in 5 hours", "expired" — for offer and request deadlines. */
export function timeLeft(iso: string, now = Date.now()): string {
  const ms = new Date(iso).getTime() - now
  if (ms <= 0) return 'expired'
  const h = Math.floor(ms / 3_600_000)
  if (h >= 48) return `${Math.floor(h / 24)} days left`
  if (h >= 1) return `${h} hour${h === 1 ? '' : 's'} left`
  return `${Math.max(1, Math.floor(ms / 60_000))} min left`
}

export function plural(n: number, one: string, many = one + 's'): string {
  return `${n} ${n === 1 ? one : many}`
}

export function humanise(key: string): string {
  return key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase())
}
