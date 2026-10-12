import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import type { CartItemInput, Fabric, Fulfilment, PriceOffer } from '../api'

/**
 * The fabric cart, kept in this browser. Prices here are for display only: the server re-prices
 * every line (and any agreed offer) before checkout, and the buyer pays the server's total.
 */
export interface CartLine {
  fabric_id: number
  slug: string
  name: string
  unit: string
  colors: string[]
  pattern: string | null
  image_url: string | null
  vendor_name: string
  list_minor: number
  /** Agreed price per unit, if this line uses an accepted offer. */
  offer_unit_minor: number | null
  price_offer_id: number | null
  offer_quantity: number | null
  quantity: number
  min: number
  max: number
}

interface Cart {
  lines: CartLine[]
  count: number
  fulfilment: Fulfilment
  setFulfilment: (f: Fulfilment) => void
  addFabric: (f: Fabric, quantity: number) => void
  addOffer: (o: PriceOffer) => void
  setQuantity: (fabricId: number, quantity: number) => void
  remove: (fabricId: number) => void
  clear: () => void
  items: CartItemInput[]
}

const KEY = 'osaps-cart-v1'
const Ctx = createContext<Cart | null>(null)

function load(): { lines: CartLine[]; fulfilment: Fulfilment } {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return { lines: [], fulfilment: 'delivery' }
    const parsed = JSON.parse(raw) as { lines?: CartLine[]; fulfilment?: Fulfilment }
    const lines = Array.isArray(parsed.lines) ? parsed.lines.filter((l) => typeof l?.fabric_id === 'number' && l.quantity > 0).slice(0, 30) : []
    return { lines, fulfilment: parsed.fulfilment === 'pickup' ? 'pickup' : 'delivery' }
  } catch {
    return { lines: [], fulfilment: 'delivery' }
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState(load)

  useEffect(() => {
    try {
      localStorage.setItem(KEY, JSON.stringify(state))
    } catch {
      /* storage unavailable: the cart still works for this visit */
    }
  }, [state])

  // Keep tabs in step.
  useEffect(() => {
    const onStorage = (e: StorageEvent) => e.key === KEY && setState(load())
    window.addEventListener('storage', onStorage)
    return () => window.removeEventListener('storage', onStorage)
  }, [])

  const upsert = useCallback((line: CartLine) => {
    setState((s) => {
      const rest = s.lines.filter((l) => l.fabric_id !== line.fabric_id)
      return { ...s, lines: [...rest, line].slice(-30) }
    })
  }, [])

  const value = useMemo<Cart>(() => ({
    lines: state.lines,
    count: state.lines.length,
    fulfilment: state.fulfilment,
    setFulfilment: (fulfilment) => setState((s) => ({ ...s, fulfilment })),
    addFabric: (f, quantity) => {
      const existing = state.lines.find((l) => l.fabric_id === f.id)
      upsert({
        fabric_id: f.id, slug: f.slug, name: f.name, unit: f.unit, colors: f.colors, pattern: f.pattern, image_url: f.image_url,
        vendor_name: f.vendor?.business_name ?? '', list_minor: f.price.amount_minor,
        offer_unit_minor: existing?.offer_unit_minor ?? null, price_offer_id: existing?.price_offer_id ?? null, offer_quantity: existing?.offer_quantity ?? null,
        quantity: Math.max(f.min_order_quantity || 1, quantity), min: f.min_order_quantity || 1, max: f.available_quantity,
      })
    },
    addOffer: (o) => {
      if (!o.fabric) return
      upsert({
        fabric_id: o.fabric.id, slug: o.fabric.slug, name: o.fabric.name, unit: o.fabric.unit, colors: o.fabric.colors, pattern: o.fabric.pattern,
        image_url: o.fabric.image_url, vendor_name: o.fabric.vendor?.business_name ?? '', list_minor: o.list_unit.amount_minor,
        offer_unit_minor: o.unit.amount_minor, price_offer_id: o.id, offer_quantity: o.quantity,
        quantity: o.quantity, min: 1, max: o.quantity,
      })
    },
    setQuantity: (fabricId, quantity) =>
      setState((s) => ({ ...s, lines: s.lines.map((l) => (l.fabric_id === fabricId ? { ...l, quantity: Math.max(1, Math.min(10_000, Math.round(quantity))) } : l)) })),
    remove: (fabricId) => setState((s) => ({ ...s, lines: s.lines.filter((l) => l.fabric_id !== fabricId) })),
    clear: () => setState((s) => ({ ...s, lines: [] })),
    items: state.lines.map((l) => ({ fabric_id: l.fabric_id, quantity: l.quantity, price_offer_id: l.price_offer_id })),
  }), [state, upsert])

  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useCart(): Cart {
  const c = useContext(Ctx)
  if (!c) throw new Error('useCart outside CartProvider')
  return c
}

/** Display estimate for a line before the server quote arrives. */
export function lineEstimate(l: CartLine): number {
  const useOffer = l.offer_unit_minor !== null && (l.offer_quantity === null || l.quantity <= l.offer_quantity)
  return (useOffer ? Math.min(l.offer_unit_minor!, l.list_minor) : l.list_minor) * l.quantity
}
