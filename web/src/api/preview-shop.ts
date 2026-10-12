/**
 * DESIGN-REVIEW ADAPTER — fabric shop part (preview builds only; production never includes it).
 * Mirrors app/Domain/Shop and app/Services/{ShopOfferService,CheckoutService} closely enough to
 * exercise every screen. Paystack is simulated by an in-app page clearly labelled as a preview.
 */
import type { Api } from './client'
import type {
  AsoEbiGroup, CartItemInput, Checkout, Fabric, FabricOrder, FabricOrderStatus, Fulfilment, MoneyValue, PriceOffer, PriceOfferStatus,
  ShopQuote, User, VendorListing, VendorProfile,
} from './types'

interface Ctx {
  notify: (userId: number, title: string, body: string | null, link: string) => void
  fabrics: Fabric[]
  vendorSlugOf: (u: User) => string | null
  user: () => User | null
  money: (minor: number) => MoneyValue
  delay: <T>(v: T) => Promise<T>
  fail: (message: string, status: number, code: string, fieldErrors?: Record<string, string[]>) => never
}

export const SHOP = {
  service_fee_bps: 250, service_fee_cap_minor: 500_000, delivery_fee_per_vendor_minor: 300_000, checkout_hold_minutes: 60,
  offer_floor_bps: 7000, offer_response_hours: 24, offer_hold_hours: 48, offer_max_rounds: 3,
}
const R = 5_000 // ₦50 rounding
/** Demo international shipping per vendor parcel (kobo). Real values are set by OSAPS in .env. */
export const PREVIEW_SHIP: Record<string, number> = { GB: 2_800_000, IE: 3_000_000, US: 3_500_000, CA: 3_600_000, DE: 3_000_000, FR: 3_000_000, NL: 3_000_000, GH: 1_500_000 }
export const PREVIEW_FX: Record<string, number> = { GBP: 2050, USD: 1550, EUR: 1780, CAD: 1120 }
const bps = (m: number, b: number) => Math.floor((m * b + 5_000) / 10_000)
export const offerFloor = (list: number) => Math.ceil(bps(list, SHOP.offer_floor_bps) / R) * R
export const offerSuggestions = (list: number) => {
  const out: number[] = []
  for (const b of [9_500, 9_000, 8_500]) {
    const p = Math.max(offerFloor(list), Math.floor(bps(list, b) / R) * R)
    if (p < list && !out.includes(p)) out.push(p)
  }
  return out
}
const hours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString()
const rid = (n: number) => Array.from({ length: n }, () => 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'[Math.floor(Math.random() * 32)]).join('')

interface OfferRow {
  id: number; fabricId: number; buyerId: number; buyerName: string; vendorSlug: string; quantity: number
  list: number; unit: number; buyerUnit: number; vendorUnit: number | null; status: PriceOfferStatus
  buyerRounds: number; vendorRounds: number; note: string | null; respondBy: string | null; holdUntil: string | null
  createdAt: string; history: { by: 'buyer' | 'vendor' | 'system'; action: string; unit: number | null; at: string }[]
}
interface OrderRow {
  groupCode: string | null; payout: 'held' | 'releasable' | 'paid' | 'withheld'
  reference: string; checkoutRef: string; customerId: number; vendorSlug: string; status: FabricOrderStatus; fulfilment: Fulfilment
  subtotal: number; delivery: number; fee: number; commission: number; createdAt: string
  items: { fabricId: number; name: string; unitName: string; quantity: number; list: number; price: number; offerId: number | null }[]
  events: { to: FabricOrderStatus; by: string; note: string | null; at: string }[]
}
interface CheckoutRow {
  reference: string; customerId: number; fulfilment: Fulfilment; subtotal: number; delivery: number; fee: number; total: number
  status: Checkout['payment_status']; expiresAt: string; paidAt: string | null; simulatedPaid: boolean
  contact: { name: string; phone: string }; address: Record<string, string> | null
  country: string | null; arrivalDate: string | null; groupCode: string | null
}
interface GroupRow {
  code: string; organizerId: number; organizerName: string; organizerPhone: string; fabricId: number; title: string; message: string | null
  eventDate: string; deadline: string; yards: number; target: number; fulfilment: AsoEbiGroup['fulfilment']
  dealUnit: number | null; dealQty: number | null; status: 'open' | 'closed' | 'cancelled'
  shares: { guestId: number; guestName: string; yards: number; showName: boolean; checkoutRef: string; status: 'pending' | 'paid' | 'cancelled' }[]
}

const LABELS: Record<PriceOfferStatus, string> = {
  awaiting_vendor: 'Waiting for the vendor', awaiting_buyer: 'Vendor countered', accepted: 'Price agreed',
  declined: 'Declined', withdrawn: 'Withdrawn', expired: 'Expired', used: 'Bought',
}
const ORDER_LABELS: Record<FabricOrderStatus, string> = {
  pending_payment: 'Awaiting payment', paid: 'Paid', packed: 'Packed', out_for_delivery: 'Out for delivery',
  ready_for_pickup: 'Ready for pickup', delivered: 'Received', completed: 'Completed', cancelled: 'Cancelled',
}
const NEXT: Record<FabricOrderStatus, Partial<Record<'vendor' | 'customer', FabricOrderStatus[]>>> = {
  pending_payment: {}, paid: { vendor: ['packed'] }, packed: { vendor: ['out_for_delivery', 'ready_for_pickup'] },
  out_for_delivery: { vendor: ['delivered'], customer: ['delivered'] }, ready_for_pickup: { vendor: ['delivered'], customer: ['delivered'] },
  delivered: { customer: ['completed'] }, completed: {}, cancelled: {},
}
const dispatch = (f: Fulfilment): FabricOrderStatus => (f === 'pickup' ? 'ready_for_pickup' : 'out_for_delivery')

export function makePreviewShop(ctx: Ctx) {
  const { fabrics, money, delay, fail } = ctx
  const offers: OfferRow[] = []
  const groups: GroupRow[] = []
  const orders: OrderRow[] = []
  const checkouts: CheckoutRow[] = []
  let nextOffer = 1

  const me = () => ctx.user() ?? fail('Please sign in.', 401, 'unauthenticated')
  const fabricById = (id: number) => fabrics.find((f) => f.id === id)
  const setAvailable = (f: Fabric, n: number) => {
    f.available_quantity = Math.max(0, n)
    f.stock_status = f.available_quantity === 0 ? 'out_of_stock' : f.available_quantity < 10 ? 'low_stock' : 'in_stock'
  }

  const sweep = () => {
    const now = Date.now()
    for (const o of offers) {
      if ((o.status === 'awaiting_vendor' || o.status === 'awaiting_buyer') && o.respondBy && Date.parse(o.respondBy) < now) { o.status = 'expired'; o.history.push({ by: 'system', action: 'expire', unit: null, at: new Date().toISOString() }) }
      if (o.status === 'accepted' && o.holdUntil && Date.parse(o.holdUntil) < now) o.status = 'expired'
    }
    for (const c of checkouts) {
      if (c.status === 'pending' && Date.parse(c.expiresAt) < now) release(c, 'expired')
    }
  }

  const sideOf = (u: User, o: OfferRow): 'buyer' | 'vendor' | null => (o.buyerId === u.id ? 'buyer' : ctx.vendorSlugOf(u) === o.vendorSlug ? 'vendor' : null)

  const view = (o: OfferRow, side: 'buyer' | 'vendor'): PriceOffer => {
    const f = fabricById(o.fabricId)!
    const myTurn = (side === 'vendor' && o.status === 'awaiting_vendor') || (side === 'buyer' && o.status === 'awaiting_buyer')
    const left = SHOP.offer_max_rounds - (side === 'vendor' ? o.vendorRounds : o.buyerRounds)
    const actions: PriceOffer['actions'] = []
    if (myTurn) { actions.push('accept', 'decline'); if (left > 0) actions.push('counter') }
    if (side === 'buyer' && o.status === 'awaiting_vendor') actions.push('withdraw')
    if (side === 'buyer' && o.status === 'accepted') actions.push('add_to_cart')
    return {
      id: o.id, status: o.status, status_label: LABELS[o.status], your_side: side, your_turn: myTurn, actions,
      quantity: o.quantity, list_unit: money(o.list), unit: money(o.unit), buyer_unit: money(o.buyerUnit),
      vendor_unit: o.vendorUnit === null ? null : money(o.vendorUnit), floor_unit: money(offerFloor(o.list)),
      total: money(o.unit * o.quantity), saving: money(Math.max(0, o.list - o.unit) * o.quantity), rounds_left: Math.max(0, left),
      note: o.note, respond_by: o.respondBy, hold_until: o.holdUntil, created_at: o.createdAt,
      fabric: { id: f.id, slug: f.slug, name: f.name, unit: f.unit, category_label: f.category_label, colors: f.colors, pattern: f.pattern, image_url: f.image_url,
        vendor: f.vendor ? { business_name: f.vendor.business_name, market: f.vendor.market, city: f.vendor.city } : null },
      buyer_name: side === 'vendor' ? o.buyerName.split(' ')[0]! : null,
      history: o.history.map((h) => ({ by: h.by, action: h.action, unit: h.unit === null ? null : money(h.unit), at: h.at })),
    }
  }

  const tell = (o: OfferRow, side: 'buyer' | 'vendor', action: string) => {
    const f = fabricById(o.fabricId)!
    const p = money(o.unit).formatted
    if (side === 'buyer') ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, action === 'accept' ? `Buyer accepted ${p} for ${f.name}` : `Buyer raised to ${p} for ${f.name}`, null, '/vendor?tab=offers')
    else ctx.notify(o.buyerId, action === 'accept' ? `Deal! ${p} per ${f.unit} for ${f.name}` : `Vendor countered: ${p} for ${f.name}`, action === 'accept' ? 'Your price is held for 48 hours.' : null, '/offers')
  }

  /** Vendors other than the demo vendor account answer automatically, so a solo review feels live. */
  const autoVendor = (o: OfferRow) => {
    if (o.vendorSlug === 'balogun-bolts') return // the "View as: Vendor" account answers these by hand
    setTimeout(() => {
      if (o.status !== 'awaiting_vendor') return
      const at = new Date().toISOString()
      if (o.unit >= bps(o.list, 9_200) || o.vendorRounds >= SHOP.offer_max_rounds) {
        o.status = 'accepted'; o.holdUntil = hours(SHOP.offer_hold_hours); o.respondBy = null
        o.history.push({ by: 'vendor', action: 'accept', unit: o.unit, at })
        tell(o, 'vendor', 'accept')
      } else {
        const mid = Math.min(o.list, Math.ceil((o.unit + (o.list - o.unit) * 0.55) / R) * R)
        o.vendorRounds++; o.vendorUnit = mid; o.unit = mid; o.status = 'awaiting_buyer'; o.respondBy = hours(SHOP.offer_response_hours)
        o.history.push({ by: 'vendor', action: 'counter', unit: mid, at })
        tell(o, 'vendor', 'counter')
      }
    }, 1_800)
  }

  const quoteOf = (u: User, items: CartItemInput[], fulfilment: Fulfilment, ignoreBusy?: string, opts: { country?: string | null; fixedUnit?: number | null } = {}): ShopQuote & { raw: { vendors: { slug: string; name: string; subtotal: number; delivery: number; lines: OrderRow['items'] }[]; subtotal: number; delivery: number; fee: number; total: number } } => {
    const blocking: ShopQuote['blocking'] = []
    const notices: ShopQuote['notices'] = []
    if (!items.length) blocking.push({ fabric_id: 0, code: 'empty_cart', message: 'Your cart is empty.' })
    const vendors = new Map<string, { slug: string; name: string; subtotal: number; delivery: number; lines: OrderRow['items'] }>()
    const seen = new Set<number>()
    for (const it of items) {
      const f = fabricById(it.fabric_id)
      if (!f || !f.vendor) { blocking.push({ fabric_id: it.fabric_id, code: 'fabric_unavailable', message: 'A fabric in your cart is no longer listed.' }); continue }
      if (seen.has(f.id)) { blocking.push({ fabric_id: f.id, code: 'duplicate_line', message: `${f.name} is in your cart twice.` }); continue }
      seen.add(f.id)
      const min = Math.max(1, f.min_order_quantity)
      if (it.quantity < min) blocking.push({ fabric_id: f.id, code: 'below_min_quantity', message: `${f.name}: the minimum is ${min} ${f.unit}.` })
      if (it.quantity > f.available_quantity) blocking.push({ fabric_id: f.id, code: 'insufficient_stock', message: f.available_quantity > 0 ? `${f.name}: only ${f.available_quantity} ${f.unit} left.` : `${f.name} is out of stock.` })
      let unit = f.price.amount_minor
      let offerId: number | null = null
      if (opts.fixedUnit != null) {
        unit = Math.min(opts.fixedUnit, f.price.amount_minor)
      } else if (it.price_offer_id) {
        const o = offers.find((x) => x.id === it.price_offer_id)
        const busy = orders.some((r) => r.status === 'pending_payment' && r.customerId !== u.id && r.checkoutRef !== ignoreBusy && r.items.some((i) => i.offerId === it.price_offer_id))
        if (!o || o.buyerId !== u.id || o.fabricId !== f.id || o.status !== 'accepted' || busy) notices.push({ fabric_id: f.id, code: 'offer_not_usable', message: `${f.name}: your agreed price is no longer valid, so the listed price applies.` })
        else if (it.quantity > o.quantity) notices.push({ fabric_id: f.id, code: 'offer_quantity', message: `${f.name}: your agreed price covers up to ${o.quantity} ${f.unit}. Lower the quantity to use it.` })
        else { unit = Math.min(o.unit, f.price.amount_minor); offerId = o.id }
      }
      const v = vendors.get(f.vendor.slug) ?? { slug: f.vendor.slug, name: f.vendor.business_name, subtotal: 0, delivery: 0, lines: [] }
      v.lines.push({ fabricId: f.id, name: f.name, unitName: f.unit, quantity: it.quantity, list: f.price.amount_minor, price: unit, offerId })
      v.subtotal += unit * it.quantity
      vendors.set(f.vendor.slug, v)
    }
    let fee0 = SHOP.delivery_fee_per_vendor_minor
    if (fulfilment === 'international') {
      const c = (opts.country ?? '').toUpperCase()
      if (!c) fail('Choose the country to ship to.', 422, 'country_required')
      if (c === 'NG') fail('For a Nigerian address, choose delivery instead.', 422, 'country_is_nigeria')
      fee0 = PREVIEW_SHIP[c] ?? fail('We do not ship to that country yet.', 422, 'country_not_served')
    }
    let subtotal = 0, delivery = 0
    for (const v of vendors.values()) { v.delivery = fulfilment === 'pickup' ? 0 : fee0; subtotal += v.subtotal; delivery += v.delivery }
    const fee = Math.min(bps(subtotal, SHOP.service_fee_bps), SHOP.service_fee_cap_minor)
    const total = subtotal + delivery + fee
    const list = [...vendors.values()]
    return {
      ok: blocking.length === 0,
      vendors: list.map((v, i) => ({
        vendor_id: i + 1, vendor_name: v.name, subtotal: money(v.subtotal), delivery: money(v.delivery), total: money(v.subtotal + v.delivery),
        lines: v.lines.map((l) => ({ fabric_id: l.fabricId, name: l.name, unit: l.unitName, quantity: l.quantity, unit_price: money(l.price), list_unit_price: money(l.list),
          line_total: money(l.price * l.quantity), saving: money((l.list - l.price) * l.quantity), price_offer_id: l.offerId })),
      })),
      subtotal: money(subtotal), delivery: money(delivery), service_fee: money(fee), total: money(total), blocking, notices,
      raw: { vendors: list, subtotal, delivery, fee, total },
    }
  }

  const orderView = (r: OrderRow, viewer: 'customer' | 'vendor'): FabricOrder => {
    const c = checkouts.find((x) => x.reference === r.checkoutRef)!
    const v = fabrics.find((f) => f.vendor?.slug === r.vendorSlug)?.vendor
    const next = (NEXT[r.status][viewer] ?? []).filter((s) => !['out_for_delivery', 'ready_for_pickup'].includes(s) || s === dispatch(r.fulfilment))
    const paid = !['pending_payment', 'cancelled'].includes(r.status)
    return {
      reference: r.reference, checkout_reference: r.checkoutRef, status: r.status, status_label: ORDER_LABELS[r.status], fulfilment: r.fulfilment,
      milestones: (['paid', 'packed', dispatch(r.fulfilment), 'delivered', 'completed'] as FabricOrderStatus[]).map((s) => ({ status: s, label: ORDER_LABELS[s] })),
      next: next.map((s) => ({ status: s, label: ORDER_LABELS[s] })),
      subtotal: money(r.subtotal), delivery: money(r.delivery),
      service_fee: viewer === 'customer' ? money(r.fee) : null, total: viewer === 'customer' ? money(r.subtotal + r.delivery + r.fee) : null,
      vendor_payout: viewer === 'vendor' ? money(r.subtotal + r.delivery - r.commission) : null,
      payout_status: viewer === 'vendor' ? r.payout : null,
      aso_ebi: r.groupCode ? { code: r.groupCode, title: groups.find((g) => g.code === r.groupCode)?.title ?? '' } : null,
      ship: { country: c.country, arrival_date: c.arrivalDate },
      vendor: v ? { business_name: v.business_name, market: v.market, city: v.city } : undefined,
      deliver_to: viewer === 'vendor' && paid ? { name: c.contact.name, phone: c.contact.phone, address: c.address } : null,
      items: r.items.map((i) => {
        const f = fabricById(i.fabricId)
        return { fabric_id: i.fabricId, slug: f?.slug ?? null, name: i.name, unit: i.unitName, quantity: i.quantity, unit_price: money(i.price), list_unit_price: money(i.list),
          line_total: money(i.price * i.quantity), negotiated: i.offerId !== null, colors: f?.colors ?? null, pattern: f?.pattern ?? null }
      }),
      events: r.events.map((e) => ({ to: e.to, label: ORDER_LABELS[e.to], by: e.by, note: e.note, at: e.at })),
      created_at: r.createdAt,
    }
  }

  const checkoutView = (c: CheckoutRow, url?: string): Checkout => ({
    reference: c.reference, payment_status: c.status, fulfilment: c.fulfilment, subtotal: money(c.subtotal), delivery: money(c.delivery),
    service_fee: money(c.fee), total: money(c.total), expires_at: c.expiresAt, paid_at: c.paidAt,
    ...(url ? { authorization_url: url } : {}),
    orders: orders.filter((o) => o.checkoutRef === c.reference).map((o) => orderView(o, 'customer')),
  })

  const release = (c: CheckoutRow, to: 'expired' | 'cancelled') => {
    for (const o of orders.filter((x) => x.checkoutRef === c.reference)) {
      for (const i of o.items) { const f = fabricById(i.fabricId); if (f) setAvailable(f, f.available_quantity + i.quantity) }
      o.status = 'cancelled'; o.events.push({ to: 'cancelled', by: to === 'expired' ? 'system' : 'customer', note: to === 'expired' ? 'Payment was not completed in time.' : 'Cancelled by the buyer before paying.', at: new Date().toISOString() })
    }
    c.status = to
    for (const g of groups) for (const sh of g.shares) if (sh.checkoutRef === c.reference && sh.status === 'pending') sh.status = 'cancelled'
  }

  const VENDOR_USER_ID: Record<string, number> = { 'balogun-bolts': 3 }

  /** Write a checkout and its per-vendor orders, holding stock (mirrors CheckoutService::persist). */
  const persist = (u: User, q: ReturnType<typeof quoteOf>, fulfilment: Fulfilment, expected: number, contact: { name: string; phone: string },
    address: Record<string, string> | null, extra: { country: string | null; arrivalDate: string | null; groupCode: string | null }): CheckoutRow => {
    if (!q.ok) fail(q.blocking[0]?.message ?? 'Your cart needs attention.', 422, 'cart_invalid')
    if (q.raw.total !== expected) fail('Prices or delivery changed since you looked. Review the new total before paying.', 422, 'cart_changed')
    const ref = 'OSP-' + rid(16)
    const c: CheckoutRow = { reference: ref, customerId: u.id, fulfilment, subtotal: q.raw.subtotal, delivery: q.raw.delivery, fee: q.raw.fee,
      total: q.raw.total, status: 'pending', expiresAt: new Date(Date.now() + SHOP.checkout_hold_minutes * 60_000).toISOString(), paidAt: null, simulatedPaid: false,
      contact, address: fulfilment === 'pickup' ? null : address, ...extra }
    checkouts.push(c)
    let rest = q.raw.fee
    q.raw.vendors.forEach((v, i) => {
      const fee = i === q.raw.vendors.length - 1 ? rest : Math.floor((q.raw.fee * v.subtotal) / Math.max(1, q.raw.subtotal))
      rest -= fee
      const at = new Date().toISOString()
      orders.push({ reference: 'FAB-' + rid(8), checkoutRef: ref, customerId: u.id, vendorSlug: v.slug, status: 'pending_payment', fulfilment,
        subtotal: v.subtotal, delivery: v.delivery, fee, commission: bps(v.subtotal, 500), createdAt: at, items: v.lines,
        events: [{ to: 'pending_payment', by: 'customer', note: null, at }], groupCode: extra.groupCode, payout: 'held' })
      for (const l of v.lines) { const f = fabricById(l.fabricId)!; setAvailable(f, f.available_quantity - l.quantity) }
    })
    return c
  }

  const offerOf = (id: number) => offers.find((o) => o.id === id) ?? fail('Not found.', 404, 'not_found')
  const act = (id: number) => {
    sweep()
    const u = me()
    const o = offerOf(id)
    const side = sideOf(u, o) ?? fail('You do not have access to this.', 403, 'forbidden')
    return { u, o, side }
  }
  const turn = (o: OfferRow, side: 'buyer' | 'vendor') => {
    const ok = (side === 'vendor' && o.status === 'awaiting_vendor') || (side === 'buyer' && o.status === 'awaiting_buyer')
    if (!ok) fail(`This offer is ${LABELS[o.status].toLowerCase()}. It is not your turn.`, 409, 'invalid_transition')
  }

  const api: Pick<Api,
    'makePriceOffer' | 'myPriceOffers' | 'vendorPriceOffers' | 'priceOffer' | 'acceptPriceOffer' | 'counterPriceOffer' | 'declinePriceOffer' | 'withdrawPriceOffer'
    | 'quote' | 'createCheckout' | 'checkout' | 'verifyCheckout' | 'cancelCheckout' | 'fabricOrders' | 'fabricOrder' | 'transitionFabricOrder'
    | 'vendorFabricOrders' | 'vendorListings' | 'updateListing' | 'createListing' | 'saveVendorProfile' | 'vendorProfile'
    | 'addListingMedia' | 'removeListingMedia' | 'asoEbi' | 'myAsoEbi' | 'createAsoEbi' | 'quoteAsoEbi' | 'joinAsoEbi'
    | 'closeAsoEbi' | 'reopenAsoEbi' | 'cancelAsoEbi'> = {
    makePriceOffer: async (slug, quantity, unit, note) => {
      sweep()
      const u = me()
      const f = fabrics.find((x) => x.slug === slug) ?? fail('Not found.', 404, 'not_found')
      const list = f.price.amount_minor
      if (ctx.vendorSlugOf(u) === f.vendor?.slug) fail('You cannot make an offer on your own listing.', 422, 'own_listing')
      if (quantity < Math.max(1, f.min_order_quantity)) fail(`The minimum order for this fabric is ${f.min_order_quantity}.`, 422, 'below_min_quantity')
      if (quantity > f.available_quantity) fail(f.available_quantity ? `Only ${f.available_quantity} left in stock.` : 'This fabric is out of stock.', 422, 'insufficient_stock')
      const live = offers.filter((o) => o.buyerId === u.id && (o.status === 'awaiting_vendor' || o.status === 'awaiting_buyer'))
      if (live.some((o) => o.fabricId === f.id)) fail('You already have an offer on this fabric. Answer or withdraw it first.', 422, 'duplicate_offer')
      if (unit >= list) fail('That is the listed price or more. Just add it to your cart.', 422, 'at_or_above_list')
      if (unit < offerFloor(list)) fail(`Offers start at ${money(offerFloor(list)).formatted} per unit for this fabric.`, 422, 'below_floor')
      const now = new Date().toISOString()
      const o: OfferRow = { id: nextOffer++, fabricId: f.id, buyerId: u.id, buyerName: u.name, vendorSlug: f.vendor!.slug, quantity, list, unit, buyerUnit: unit,
        vendorUnit: null, status: 'awaiting_vendor', buyerRounds: 0, vendorRounds: 0, note: note ?? null, respondBy: hours(SHOP.offer_response_hours), holdUntil: null,
        createdAt: now, history: [{ by: 'buyer', action: 'open', unit, at: now }] }
      offers.push(o)
      ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `New offer: ${money(unit).formatted} per ${f.unit} for ${f.name}`, `${quantity} ${f.unit}s. Answer within 24 hours.`, '/vendor?tab=offers')
      autoVendor(o)
      return delay(view(o, 'buyer'))
    },
    myPriceOffers: () => { sweep(); const u = me(); return delay(offers.filter((o) => o.buyerId === u.id).reverse().map((o) => view(o, 'buyer'))) },
    vendorPriceOffers: () => {
      sweep()
      const slug = ctx.vendorSlugOf(me())
      if (!slug) return delay({ data: [], is_vendor: false })
      const mine = offers.filter((o) => o.vendorSlug === slug).sort((a, b) => Number(b.status === 'awaiting_vendor') - Number(a.status === 'awaiting_vendor') || b.id - a.id)
      return delay({ data: mine.map((o) => view(o, 'vendor')), is_vendor: true })
    },
    priceOffer: (id) => { const { o, side } = act(id); return delay(view(o, side)) },
    acceptPriceOffer: async (id, expected) => {
      const { o, side } = act(id)
      if (o.unit !== expected) fail('The price changed while you were looking. Check the new price.', 422, 'offer_changed')
      turn(o, side)
      o.status = 'accepted'; o.holdUntil = hours(SHOP.offer_hold_hours); o.respondBy = null
      o.history.push({ by: side, action: 'accept', unit: o.unit, at: new Date().toISOString() })
      tell(o, side, 'accept')
      return delay(view(o, side))
    },
    counterPriceOffer: async (id, unit) => {
      const { o, side } = act(id)
      turn(o, side)
      const rounds = side === 'vendor' ? o.vendorRounds : o.buyerRounds
      if (rounds >= SHOP.offer_max_rounds) fail(`You have used all ${SHOP.offer_max_rounds} counters. Accept or decline.`, 422, 'counter_limit')
      if (side === 'vendor') {
        if (unit <= o.buyerUnit) fail("Your counter must be above the buyer's offer. To agree to their price, accept it.", 422, 'counter_too_low')
        if (unit > o.list) fail(`Your counter cannot be above your listed price of ${money(o.list).formatted}.`, 422, 'counter_above_list')
        o.vendorRounds++; o.vendorUnit = unit; o.status = 'awaiting_buyer'
      } else {
        if (unit <= o.buyerUnit) fail(`Raise your offer above ${money(o.buyerUnit).formatted}, or accept the vendor's price.`, 422, 'counter_not_raised')
        if (unit >= (o.vendorUnit ?? o.list)) fail("That matches the vendor's price. Accept it instead.", 422, 'counter_meets_vendor')
        o.buyerRounds++; o.buyerUnit = unit; o.status = 'awaiting_vendor'
      }
      o.unit = unit; o.respondBy = hours(SHOP.offer_response_hours)
      o.history.push({ by: side, action: 'counter', unit, at: new Date().toISOString() })
      tell(o, side, 'counter')
      if (side === 'buyer') autoVendor(o)
      return delay(view(o, side))
    },
    declinePriceOffer: async (id) => {
      const { o, side } = act(id)
      turn(o, side)
      o.status = 'declined'; o.respondBy = null
      o.history.push({ by: side, action: 'decline', unit: null, at: new Date().toISOString() })
      return delay(view(o, side))
    },
    withdrawPriceOffer: async (id) => {
      const { o, side } = act(id)
      if (side !== 'buyer' || o.status !== 'awaiting_vendor') fail('Only an offer still waiting for the vendor can be withdrawn.', 409, 'invalid_transition')
      o.status = 'withdrawn'; o.respondBy = null
      o.history.push({ by: 'buyer', action: 'withdraw', unit: null, at: new Date().toISOString() })
      return delay(view(o, side))
    },

    quote: (items, fulfilment, country) => { sweep(); const { raw: _raw, ...q } = quoteOf(me(), items, fulfilment, undefined, { country }); return delay(q) },
    createCheckout: async (input) => {
      sweep()
      const u = me()
      for (const old of checkouts.filter((c) => c.customerId === u.id && c.status === 'pending' && !c.groupCode)) release(old, 'cancelled')
      const q = quoteOf(u, input.items, input.fulfilment, undefined, { country: input.country })
      const c = persist(u, q, input.fulfilment, input.expected_total_minor, input.contact, (input.address as Record<string, string> | null) ?? null,
        { country: input.fulfilment === 'international' ? (input.country ?? '').toUpperCase() : null, arrivalDate: input.fulfilment === 'arrival' ? input.arrival_date ?? null : null, groupCode: null })
      return delay(checkoutView(c, `/checkout/preview-pay/${c.reference}`))
    },
    checkout: (ref) => { sweep(); const u = me(); const c = checkouts.find((x) => x.reference === ref && x.customerId === u.id) ?? fail('Not found.', 404, 'not_found'); return delay(checkoutView(c)) },
    verifyCheckout: async (ref) => {
      sweep()
      const u = me()
      const c = checkouts.find((x) => x.reference === ref && x.customerId === u.id) ?? fail('Not found.', 404, 'not_found')
      if (c.status === 'pending' && c.simulatedPaid) {
        c.status = 'paid'; c.paidAt = new Date().toISOString()
        for (const o of orders.filter((x) => x.checkoutRef === ref)) {
          o.status = 'paid'; o.events.push({ to: 'paid', by: 'system', note: 'Payment confirmed (simulated in the design preview).', at: c.paidAt })
          for (const i of o.items) { if (i.offerId) { const off = offers.find((x) => x.id === i.offerId); if (off && off.status === 'accepted') off.status = 'used' } }
          ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `New paid order ${o.reference}`, o.items.map((i) => `${i.quantity} × ${i.name}`).join(', '), '/vendor?tab=orders')
        }
        ctx.notify(c.customerId, 'Payment confirmed', `We received ${money(c.total).formatted}. Your vendor is packing your fabric.`, '/shop/orders')
        const g = groups.find((x) => x.code === c.groupCode)
        const sh = g?.shares.find((x) => x.checkoutRef === ref)
        if (g && sh) {
          sh.status = 'paid'
          const paid = g.shares.filter((x) => x.status === 'paid').length
          ctx.notify(g.organizerId, `${paid} of ${g.target} paid for ${g.title}`, `${sh.showName ? sh.guestName.split(' ')[0] : 'A guest'} paid for ${sh.yards} yards.`, `/aso-ebi/${g.code}`)
        }
      }
      return delay(checkoutView(c))
    },
    cancelCheckout: async (ref) => {
      const u = me()
      const c = checkouts.find((x) => x.reference === ref && x.customerId === u.id) ?? fail('Not found.', 404, 'not_found')
      if (c.status === 'pending') release(c, 'cancelled')
      return delay(checkoutView(c))
    },
    fabricOrders: () => { sweep(); const u = me(); return delay(orders.filter((o) => o.customerId === u.id).slice().reverse().map((o) => orderView(o, 'customer'))) },
    fabricOrder: (ref) => {
      const u = me()
      const o = orders.find((x) => x.reference === ref) ?? fail('Not found.', 404, 'not_found')
      if (o.customerId === u.id) return delay(orderView(o, 'customer'))
      if (ctx.vendorSlugOf(u) === o.vendorSlug && o.status !== 'pending_payment') return delay(orderView(o, 'vendor'))
      return fail('You do not have access to this.', 403, 'forbidden')
    },
    transitionFabricOrder: async (ref, status, note) => {
      const u = me()
      const o = orders.find((x) => x.reference === ref) ?? fail('Not found.', 404, 'not_found')
      const role = o.customerId === u.id ? 'customer' : ctx.vendorSlugOf(u) === o.vendorSlug ? 'vendor' : fail('You do not have access to this.', 403, 'forbidden')
      if ((status === 'out_for_delivery' || status === 'ready_for_pickup') && status !== dispatch(o.fulfilment)) {
        fail(o.fulfilment === 'pickup' ? 'The buyer is collecting this order. Mark it ready for pickup.' : 'The buyer chose delivery. Mark it out for delivery.', 422, 'wrong_dispatch_step')
      }
      if (!(NEXT[o.status][role] ?? []).includes(status)) fail(`Order: cannot move from '${o.status}' to '${status}' by ${role}.`, 409, 'invalid_transition')
      o.status = status
      o.events.push({ to: status, by: role, note: note ?? null, at: new Date().toISOString() })
      if (status === 'completed') { o.payout = 'releasable'; ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `Order ${o.reference} completed`, 'The buyer confirmed receipt. Your payout is released for payment.', '/vendor?tab=orders') }
      const what: Partial<Record<FabricOrderStatus, string>> = { packed: 'Your fabric is packed.', out_for_delivery: 'Your fabric is on its way.', ready_for_pickup: 'Your fabric is ready to collect.', delivered: 'The vendor marked your fabric as delivered. Confirm when you have it.' }
      if (role === 'vendor' && what[status]) ctx.notify(o.customerId, what[status]!, `Order ${o.reference}.`, `/shop/orders/${o.reference}`)
      return delay(orderView(o, role))
    },

    vendorFabricOrders: () => {
      sweep()
      const slug = ctx.vendorSlugOf(me())
      if (!slug) return delay({ data: [], is_vendor: false })
      return delay({ data: orders.filter((o) => o.vendorSlug === slug && o.status !== 'pending_payment').slice().reverse().map((o) => orderView(o, 'vendor')), is_vendor: true })
    },
    vendorListings: () => {
      const slug = ctx.vendorSlugOf(me()) ?? fail('You do not have access to this.', 403, 'forbidden')
      return delay(fabrics.filter((f) => f.vendor?.slug === slug).map(listing))
    },
    updateListing: async (id, patch) => {
      const slug = ctx.vendorSlugOf(me()) ?? fail('You do not have access to this.', 403, 'forbidden')
      const f = fabrics.find((x) => x.id === id && x.vendor?.slug === slug) ?? fail('You do not have access to this.', 403, 'forbidden')
      const held = reservedFor(f.id)
      if (patch.stock_quantity !== undefined) {
        if (patch.stock_quantity < held) fail('Stock is too low.', 422, 'validation_failed', { stock_quantity: [`${held} are held for buyers who are paying now. Stock cannot go below that.`] })
        setAvailable(f, patch.stock_quantity - held)
      }
      if (patch.price_minor !== undefined) f.price = money(patch.price_minor)
      if (patch.status) statuses.set(f.id, patch.status)
      return delay(listing(f))
    },
    createListing: async (input) => {
      const u = me()
      const slug = ctx.vendorSlugOf(u) ?? fail('Set up your shop first.', 403, 'forbidden')
      const v = fabrics.find((x) => x.vendor?.slug === slug)?.vendor ?? fail('Set up your shop first.', 403, 'forbidden')
      const f: Fabric = {
        id: Math.max(...fabrics.map((x) => x.id)) + 1,
        slug: input.name.toLowerCase().replace(/[^a-z0-9]+/g, '-') + '-' + rid(4).toLowerCase(),
        name: input.name, category: input.category, category_label: input.category, description: input.description ?? null,
        primary_color: input.primary_color, colors: [...new Set([input.primary_color, ...(input.colors ?? [])])], pattern: input.pattern ?? null,
        material: input.material ?? null, unit: input.unit, price: money(input.price_minor), available_quantity: input.stock_quantity,
        stock_status: input.stock_quantity === 0 ? 'out_of_stock' : input.stock_quantity < 10 ? 'low_stock' : 'in_stock',
        min_order_quantity: input.min_order_quantity ?? 1, image_url: null, is_demo: true, vendor: v, purchase_mode: 'checkout',
        offer_floor: money(offerFloor(input.price_minor)), offer_suggestions: offerSuggestions(input.price_minor).map(money),
      }
      fabrics.push(f)
      return delay(listing(f))
    },
    saveVendorProfile: async (input) => {
      profile = { ...profile, ...input, market: input.market ?? null, phone: input.phone ?? null, whatsapp: input.whatsapp ?? null,
        bank_name: input.bank_name ?? profile.bank_name, bank_account_name: input.bank_account_name ?? profile.bank_account_name,
        bank_account_last4: input.bank_account_number ? input.bank_account_number.slice(-4) : profile.bank_account_last4 }
      return delay(profile)
    },
    vendorProfile: () => delay(profile),
    addListingMedia: async (id, file) => {
      const slug = ctx.vendorSlugOf(me()) ?? fail('You do not have access to this.', 403, 'forbidden')
      const f = fabrics.find((x) => x.id === id && x.vendor?.slug === slug) ?? fail('You do not have access to this.', 403, 'forbidden')
      const kind = file.type.startsWith('video/') ? 'video' : file.type.startsWith('image/') ? 'photo' : fail('Upload a JPG, PNG or WebP photo, or an MP4, WebM or MOV video.', 422, 'media_type')
      const list = (f.media ??= [])
      if (kind === 'photo' && list.filter((m) => m.kind === 'photo').length >= 6) fail('Up to 6 photos per fabric. Remove one first.', 422, 'media_limit')
      if (kind === 'video' && list.some((m) => m.kind === 'video')) fail('One video per fabric. Remove the old one first.', 422, 'media_limit')
      list.push({ id: Date.now(), kind, url: URL.createObjectURL(file), mime: file.type })
      f.has_video = list.some((m) => m.kind === 'video')
      f.image_url = list.find((m) => m.kind === 'photo')?.url ?? null
      return delay(listing(f))
    },
    removeListingMedia: async (id, mediaId) => {
      const f = fabrics.find((x) => x.id === id) ?? fail('Not found.', 404, 'not_found')
      f.media = (f.media ?? []).filter((m) => m.id !== mediaId)
      f.has_video = f.media.some((m) => m.kind === 'video')
      f.image_url = f.media.find((m) => m.kind === 'photo')?.url ?? null
      return delay(listing(f))
    },

    asoEbi: (code) => { sweep(); const g = groupOf(code); return delay(groupView(g)) },
    myAsoEbi: () => { const u = me(); return delay(groups.filter((g) => g.organizerId === u.id || g.shares.some((x) => x.guestId === u.id && x.status === 'paid')).slice().reverse().map(groupView)) },
    createAsoEbi: async (slug, input) => {
      const u = me()
      const f = fabrics.find((x) => x.slug === slug) ?? fail('Not found.', 404, 'not_found')
      const deadline = new Date(input.deadline_at)
      if (input.yards_per_guest < Math.max(1, f.min_order_quantity) || input.yards_per_guest > 50) fail(`Set between ${Math.max(1, f.min_order_quantity)} and 50 per guest.`, 422, 'yards_out_of_range')
      if (input.target_guests < 2 || input.target_guests > 500) fail('Invite between 2 and 500 guests.', 422, 'guests_out_of_range')
      if (!(deadline.getTime() > Date.now())) fail('The payment deadline must be in the future.', 422, 'deadline_past')
      if (new Date(input.event_date + 'T23:59:59') < deadline) fail('The payment deadline must come before the event.', 422, 'event_before_deadline')
      let dealUnit: number | null = null, dealQty: number | null = null
      if (input.price_offer_id) {
        const o = offers.find((x) => x.id === input.price_offer_id)
        if (!o || o.buyerId !== u.id || o.fabricId !== f.id || o.status !== 'accepted') fail('That agreed price cannot be used for this group. It may have expired.', 422, 'offer_not_usable')
        dealUnit = Math.min(o!.unit, f.price.amount_minor); dealQty = o!.quantity; o!.status = 'used'
      }
      const code = Array.from({ length: 10 }, () => 'abcdefghjkmnpqrstuvwxyz23456789'[Math.floor(Math.random() * 31)]).join('')
      const g: GroupRow = { code, organizerId: u.id, organizerName: u.name, organizerPhone: input.organizer_phone, fabricId: f.id, title: input.title, message: input.message ?? null,
        eventDate: input.event_date, deadline: deadline.toISOString(), yards: input.yards_per_guest, target: input.target_guests, fulfilment: input.fulfilment,
        dealUnit, dealQty, status: 'open', shares: [] }
      groups.push(g)
      if (f.vendor) ctx.notify(VENDOR_USER_ID[f.vendor.slug] ?? 0, `Aṣọ-ẹbí started on ${f.name}`, `${g.target} guests × ${g.yards} ${f.unit}s for ${g.title}. Keep stock ready.`, '/vendor?tab=orders')
      return delay(groupView(g))
    },
    quoteAsoEbi: (code, yards) => { sweep(); const g = groupOf(code); const { raw: _raw, ...q } = shareQuote(me(), g, yards); return delay(q) },
    joinAsoEbi: async (code, input) => {
      sweep()
      const u = me()
      const g = groupOf(code)
      if (g.status !== 'open' || Date.parse(g.deadline) <= Date.now()) fail('This aṣọ-ẹbí is no longer taking payments. Ask the organiser.', 422, 'group_closed')
      for (const old of checkouts.filter((c) => c.customerId === u.id && c.groupCode === code && c.status === 'pending')) release(old, 'cancelled')
      const q = shareQuote(u, g, input.yards)
      const fulfil: Fulfilment = g.fulfilment === 'pickup_organizer' ? 'pickup' : 'delivery'
      const c = persist(u, q, fulfil, input.expected_total_minor, input.contact, (input.address as Record<string, string> | null) ?? null, { country: null, arrivalDate: null, groupCode: code })
      g.shares.push({ guestId: u.id, guestName: u.name, yards: input.yards, showName: input.show_name, checkoutRef: c.reference, status: 'pending' })
      return delay(checkoutView(c, `/checkout/preview-pay/${c.reference}`))
    },
    closeAsoEbi: async (code) => { const g = ownGroup(code); if (g.status !== 'open') fail('This group is not open.', 409, 'invalid_transition'); g.status = 'closed'; return delay(groupView(g)) },
    reopenAsoEbi: async (code) => { const g = ownGroup(code); if (Date.parse(g.deadline) < Date.now()) fail('The deadline has passed.', 422, 'deadline_past'); g.status = 'open'; return delay(groupView(g)) },
    cancelAsoEbi: async (code) => {
      const g = ownGroup(code)
      if (g.shares.some((x) => x.status === 'paid')) fail('Guests have already paid. Close the group instead, or contact OSAPS support to arrange refunds.', 422, 'group_has_payments')
      g.status = 'cancelled'
      return delay(groupView(g))
    },
  }

  let profile: VendorProfile = { business_name: 'Balogun Bolts', slug: 'balogun-bolts', city: 'Lagos', market: 'Balogun Market', phone: null, whatsapp: null, verified: false,
    bank_name: null, bank_account_name: null, bank_account_last4: null }

  const groupOf = (code: string) => groups.find((g) => g.code === code) ?? fail('Not found.', 404, 'not_found')
  const ownGroup = (code: string) => { const g = groupOf(code); if (g.organizerId !== me().id) fail('Only the organiser can change this group.', 422, 'forbidden'); return g }
  const committed = (g: GroupRow) => g.shares.filter((x) => x.status !== 'cancelled').reduce((n, x) => n + x.yards, 0)
  const shareQuote = (u: User, g: GroupRow, yards: number) => {
    const fixed = g.dealUnit !== null && g.dealQty !== null && committed(g) + yards <= g.dealQty ? g.dealUnit : null
    return quoteOf(u, [{ fabric_id: g.fabricId, quantity: yards }], g.fulfilment === 'pickup_organizer' ? 'pickup' : 'delivery', undefined, { fixedUnit: fixed })
  }
  const groupView = (g: GroupRow): AsoEbiGroup => {
    const f = fabricById(g.fabricId)!
    const u = ctx.user()
    const paid = g.shares.filter((x) => x.status === 'paid')
    const left = g.dealQty !== null ? Math.max(0, g.dealQty - committed(g)) : null
    const unit = g.dealUnit !== null && (left ?? 0) >= g.yards ? Math.min(g.dealUnit, f.price.amount_minor) : f.price.amount_minor
    const open = g.status === 'open' && Date.parse(g.deadline) > Date.now()
    const mine = u ? g.shares.filter((x) => x.guestId === u.id).pop() : undefined
    const organizer = u?.id === g.organizerId
    return {
      code: g.code, title: g.title, message: g.message, event_date: g.eventDate, deadline_at: g.deadline, status: open ? 'open' : g.status === 'open' ? 'closed' : g.status,
      yards_per_guest: g.yards, target_guests: g.target, fulfilment: g.fulfilment, unit_price: money(unit), list_unit_price: f.price,
      deal: g.dealUnit !== null ? { unit_price: money(g.dealUnit), quantity: g.dealQty!, left } : null,
      share_price: money(unit * g.yards), progress: { paid: paid.length, target: g.target, percent: Math.min(100, Math.floor((paid.length * 100) / Math.max(1, g.target))) },
      yards_paid: paid.reduce((n, x) => n + x.yards, 0), paid_names: paid.filter((x) => x.showName).map((x) => x.guestName.split(' ')[0]!),
      organizer_name: g.organizerName.split(' ')[0]!, is_organizer: organizer, my_share: mine ? { status: mine.status, yards: mine.yards } : null,
      ...(organizer ? { guests: g.shares.filter((x) => x.status !== 'cancelled').map((x) => ({ name: x.guestName, yards: x.yards, status: x.status })) } : {}),
      fabric: structuredClone(f),
    }
  }

  const statuses = new Map<number, VendorListing['status']>()
  const reservedFor = (fabricId: number) => orders.filter((o) => o.status === 'pending_payment').flatMap((o) => o.items).filter((i) => i.fabricId === fabricId).reduce((n, i) => n + i.quantity, 0)
  const listing = (f: Fabric): VendorListing => {
    const held = reservedFor(f.id)
    return { ...structuredClone(f), status: statuses.get(f.id) ?? 'active', stock_quantity: f.available_quantity + held, reserved_quantity: held }
  }

  /** Called by the simulated Paystack page. */
  const simulatePayment = (ref: string, outcome: 'success' | 'cancel') => {
    const c = checkouts.find((x) => x.reference === ref)
    if (!c || c.status !== 'pending') return
    if (outcome === 'success') c.simulatedPaid = true
    else release(c, 'cancelled')
  }

  return { api, simulatePayment }
}
