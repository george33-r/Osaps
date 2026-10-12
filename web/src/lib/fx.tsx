import { useCallback, useEffect, useState } from 'react'
import { useSession } from './session'

const KEY = 'osaps-currency'
const SYMBOL: Record<string, string> = { GBP: '£', USD: '$', EUR: '€', CAD: 'CA$' }
const listeners = new Set<(c: string) => void>()

function stored(): string {
  try { return localStorage.getItem(KEY) ?? 'NGN' } catch { return 'NGN' }
}

/**
 * The shopper's display currency. Everything is charged in naira; foreign amounts are approximate
 * conversions at rates OSAPS sets, and are always shown with "≈".
 */
export function useFx() {
  const { meta, user } = useSession()
  const [currency, setLocal] = useState<string>(() => user?.currency ?? stored())
  useEffect(() => { if (user?.currency) setLocal(user.currency) }, [user?.currency])
  useEffect(() => {
    const on = (c: string) => setLocal(c)
    listeners.add(on)
    return () => { listeners.delete(on) }
  }, [])
  const rates = meta?.diaspora?.rates ?? {}
  const setCurrency = useCallback((c: string) => {
    try { localStorage.setItem(KEY, c) } catch { /* ignore */ }
    listeners.forEach((l) => l(c))
  }, [])
  const approx = useCallback((nairaMinor: number): string | null => {
    const rate = rates[currency]
    if (currency === 'NGN' || !rate) return null
    const v = nairaMinor / 100 / rate
    return `≈ ${SYMBOL[currency] ?? currency + ' '}${v >= 100 ? Math.round(v).toLocaleString('en-GB') : v.toFixed(2)}`
  }, [currency, rates])
  return { currency, setCurrency, approx, available: Object.keys(rates) }
}

export function Approx({ minor, className = '' }: { minor: number; className?: string }) {
  const { approx } = useFx()
  const text = approx(minor)
  return text ? <span className={`approx ${className}`} title="Approximate. You are charged in naira.">{text}</span> : null
}

export function CurrencyPicker({ compact = false }: { compact?: boolean }) {
  const { currency, setCurrency, available } = useFx()
  if (available.length === 0) return null
  return (
    <label className={`currency-picker ${compact ? 'currency-picker--compact' : ''}`}>
      <span className={compact ? 'sr-only' : 'label'}>Show prices in</span>
      <select value={currency} onChange={(e) => setCurrency(e.target.value)} className="input">
        <option value="NGN">₦ Naira</option>
        {available.map((c) => <option key={c} value={c}>{SYMBOL[c] ?? ''} {c} (approx.)</option>)}
      </select>
    </label>
  )
}
