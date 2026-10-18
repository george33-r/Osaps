import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { api, type CartItemInput, type Fabric, type Fulfilment, type PriceOffer, type SavedCartLine } from '../api'
import { useSession } from './session'

/**
 * The fabric cart. Kept in this browser, and also saved to the account once signed in, so it
 * follows the shopper between phone and laptop. On sign-in the two are merged (the larger
 * quantity wins). Prices here are for display only: the server re-prices every line (and any
 * agreed offer) before checkout, and the buyer pays the server's total.
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
  /** Destination country for international shipping (ISO code). */
  country: string | null
  setCountry: (c: string | null) => void
  /** Nigerian state for delivery pricing. */
  shipState: string
  setShipState: (s: string) => void
  /** Express delivery (delivery to a Nigerian address only). */
  express: boolean
  setExpress: (on: boolean) => void
  addFabric: (f: Fabric, quantity: number) => void
  addOffer: (o: PriceOffer) => void
  setQuantity: (fabricId: number, quantity: number) => void
  remove: (fabricId: number) => void
  clear: () => void
  items: CartItemInput[]
}

const KEY = 'osaps-cart-v1'
const Ctx = createContext<Cart | null>(null)

const FULFILMENTS: Fulfilment[] = ['delivery', 'pickup', 'arrival', 'international']

function load(): { lines: CartLine[]; fulfilment: Fulfilment; country: string | null; shipState: string; express: boolean } {
  try {
    const raw = localStorage.getItem(KEY)
    if (!raw) return { lines: [], fulfilment: 'delivery', country: null, shipState: '', express: false }
    const parsed = JSON.parse(raw) as { lines?: CartLine[]; fulfilment?: Fulfilment; country?: string | null; shipState?: string; express?: boolean }
    const lines = Array.isArray(parsed.lines) ? parsed.lines.filter((l) => typeof l?.fabric_id === 'number' && l.quantity > 0).slice(0, 30) : []
    const fulfilment = FULFILMENTS.includes(parsed.fulfilment as Fulfilment) ? (parsed.fulfilment as Fulfilment) : 'delivery'
    const country = typeof parsed.country === 'string' && /^[A-Z]{2}$/.test(parsed.country) ? parsed.country : null
    const shipState = typeof parsed.shipState === 'string' ? parsed.shipState.slice(0, 40) : ''
    return { lines, fulfilment, country, shipState, express: parsed.express === true }
  } catch {
    return { lines: [], fulfilment: 'delivery', country: null, shipState: '', express: false }
  }
}

function fromSaved(s: SavedCartLine): CartLine {
  return {
    fabric_id: s.fabric_id, slug: s.fabric.slug, name: s.fabric.name, unit: s.fabric.unit, colors: s.fabric.colors, pattern: s.fabric.pattern,
    image_url: s.fabric.image_url, vendor_name: s.fabric.vendor_name ?? '', list_minor: s.fabric.list_minor,
    offer_unit_minor: s.offer?.unit_minor ?? null, price_offer_id: s.price_offer_id, offer_quantity: s.offer?.quantity ?? null,
    quantity: s.quantity, min: s.fabric.min, max: s.offer ? s.offer.quantity : s.fabric.available,
  }
}

export function CartProvider({ children }: { children: ReactNode }) {
  const [state, setState] = useState(load)
  const { user } = useSession()
  const synced = useRef<number | null>(null) // the user id whose saved cart is merged in
  const lastSent = useRef('')

  // Signed in: merge the saved cart with this browser's, once per account.
  useEffect(() => {
    if (!user) {
      // Signed out: the cart is saved to the account, so clear it from this (maybe shared) browser.
      if (synced.current !== null) setState((s) => ({ ...s, lines: [] }))
      synced.current = null
      lastSent.current = ''
      return
    }
    if (synced.current === user.id) return
    // Switched straight to another account: never carry one person's cart into someone else's.
    if (synced.current !== null) { setState((s) => ({ ...s, lines: [] })); lastSent.current = '' }
    let live = true
    api.savedCart().then((saved) => {
      if (!live) return
      setState((s) => {
        const byId = new Map(s.lines.map((l) => [l.fabric_id, l]))
        for (const line of saved.map(fromSaved)) {
          const mine = byId.get(line.fabric_id)
          byId.set(line.fabric_id, mine ? { ...mine, quantity: Math.max(mine.quantity, line.quantity), price_offer_id: mine.price_offer_id ?? line.price_offer_id,
            offer_unit_minor: mine.offer_unit_minor ?? line.offer_unit_minor, offer_quantity: mine.offer_quantity ?? line.offer_quantity } : line)
        }
        return { ...s, lines: [...byId.values()].slice(-30) }
      })
      synced.current = user.id
    }).catch(() => { synced.current = user.id /* offline or not allowed: keep the local cart */ })
    return () => { live = false }
  }, [user])

  // Signed in and merged: save changes to the account (debounced).
  useEffect(() => {
    if (!user || synced.current !== user.id) return
    const items = state.lines.map((l) => ({ fabric_id: l.fabric_id, quantity: l.quantity, price_offer_id: l.price_offer_id }))
    const body = JSON.stringify(items)
    if (body === lastSent.current) return
    const t = window.setTimeout(() => { lastSent.current = body; api.saveCart(items).catch(() => { lastSent.current = '' }) }, 250)
    return () => window.clearTimeout(t)
  }, [state.lines, user])

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
    country: state.country,
    setCountry: (country) => setState((s) => ({ ...s, country })),
    shipState: state.shipState,
    setShipState: (shipState) => setState((s) => ({ ...s, shipState })),
    express: state.express && state.fulfilment === 'delivery',
    setExpress: (express) => setState((s) => ({ ...s, express })),
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
