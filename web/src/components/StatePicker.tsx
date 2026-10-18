import type { SelectHTMLAttributes } from 'react'
import { useSession } from '../lib/session'
import { formatNaira } from '../lib/format'

/** The 36 states and FCT, with the delivery price and time beside each choice. */
export function StatePicker({ value, onChange, name = 'state', required = true, ...rest }: { value: string; onChange: (s: string) => void } & Omit<SelectHTMLAttributes<HTMLSelectElement>, 'onChange' | 'value'>) {
  const { meta } = useSession()
  const d = meta?.shop?.delivery
  const zoneOf = (s: string) => d?.zones.find((z) => z.states.includes(s)) ?? d?.zones.find((z) => z.key === 'other')
  return (
    <select {...rest} name={name} className="input input--select" value={value} required={required} onChange={(e) => onChange(e.target.value)} autoComplete="address-level1">
      <option value="">Choose your state</option>
      {(d?.states ?? []).map((s) => {
        const z = zoneOf(s)
        return <option key={s} value={s}>{s}{z ? ` · ${formatNaira(z.fee_minor)}, ${z.days}` : ''}</option>
      })}
    </select>
  )
}

/** "Delivery to Kano: ₦6,000, 3–5 working days" for a chosen state, or the price range before one is chosen. */
export function useDeliveryInfo(state: string | null) {
  const { meta } = useSession()
  const d = meta?.shop?.delivery
  if (!d || d.zones.length === 0) return null
  if (state) {
    const z = d.zones.find((x) => x.states.includes(state)) ?? d.zones.find((x) => x.key === 'other')
    return z ? { text: `Delivery to ${state}: ${formatNaira(z.fee_minor)}, ${z.days}`, fee: z.fee_minor, days: z.days } : null
  }
  const fees = d.zones.map((z) => z.fee_minor)
  const lagos = d.zones.find((z) => z.key === 'lagos')
  return { text: `Delivery from ${formatNaira(Math.min(...fees))}${lagos ? ` (Lagos, ${lagos.days})` : ''} to ${formatNaira(Math.max(...fees))} across Nigeria`, fee: null, days: null }
}

/**
 * Express delivery for a state: { fee, days }, or null where it is not offered.
 * Without a state: the cheapest express anywhere, for "Need it fast? Express from …".
 */
export function useExpressInfo(state: string | null) {
  const { meta } = useSession()
  const zones = (meta?.shop?.delivery?.zones ?? []).filter((z) => (z.express_fee_minor ?? 0) > 0)
  if (zones.length === 0) return null
  if (state) {
    const all = meta?.shop?.delivery?.zones ?? []
    const z = all.find((x) => x.states.includes(state)) ?? all.find((x) => x.key === 'other')
    return z && (z.express_fee_minor ?? 0) > 0 ? { fee: z.express_fee_minor!, days: z.express_days ?? '', from: false } : null
  }
  const cheapest = zones.reduce((a, b) => (b.express_fee_minor! < a.express_fee_minor! ? b : a))
  return { fee: cheapest.express_fee_minor!, days: cheapest.express_days ?? '', from: true, label: cheapest.label }
}
