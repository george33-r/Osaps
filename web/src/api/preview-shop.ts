/**
 * DESIGN-REVIEW ADAPTER — fabric shop part (preview builds only; production never includes it).
 * Mirrors app/Domain/Shop and app/Services/{ShopOfferService,CheckoutService} closely enough to
 * exercise every screen. Paystack is simulated by an in-app page clearly labelled as a preview.
 */
import type { Api } from './client'
import type {
  AdminActionRow, AdminOrderRow, AdminPayoutGroup, AdminRefund, AdminVendor, AsoEbiGroup, CartItemInput, Checkout, DisputeReason, Fabric, FabricOrder,
  FabricOrderStatus, Fulfilment, MoneyValue, OrderRefund, PayoutStatus, PriceOffer, PriceOfferStatus, ShopQuote, User, VendorListing, VendorProfile,
} from './types'
import { DISPUTE_REASONS } from './types'

interface Ctx {
  notify: (userId: number, title: string, body: string | null, link: string) => void
  fabrics: Fabric[]
  vendorSlugOf: (u: User) => string | null
  user: () => User | null
  money: (minor: number) => MoneyValue
  delay: <T>(v: T) => Promise<T>
  fail: (message: string, status: number, code: string, fieldErrors?: Record<string, string[]>) => never
  onPaid?: (userId: number, fabricIds: number[]) => void
}

export const SHOP = {
  service_fee_bps: 250, service_fee_cap_minor: 500_000, delivery_fee_per_vendor_minor: 300_000, checkout_hold_minutes: 60,
  offer_floor_bps: 7000, offer_response_hours: 24, offer_hold_hours: 48, offer_max_rounds: 3,
}
const STATES = ['Abia', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta',
  'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'FCT (Abuja)', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi',
  'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara']
const SW = ['Ogun', 'Oyo', 'Osun', 'Ondo', 'Ekiti']
/** Same zones and default fees as config/marketplace.php (placeholders the owner sets in .env). */
export const DELIVERY = {
  zones: [
    { key: 'lagos', label: 'Lagos', fee_minor: 300_000, days: '1–2 working days', states: ['Lagos'] },
    { key: 'south_west', label: 'Ogun, Oyo, Osun, Ondo, Ekiti', fee_minor: 450_000, days: '2–3 working days', states: SW },
    { key: 'other', label: 'Other states and Abuja', fee_minor: 600_000, days: '3–5 working days', states: STATES.filter((x) => x !== 'Lagos' && !SW.includes(x)) },
  ],
  states: STATES,
  lowest_fee_minor: 300_000,
}
export const PIECE_YARDS: Record<string, number> = { lace: 5, ankara: 6 }
const stateFee = (state: string | null | undefined) => {
  if (!state) return null
  const s = /^(fct|abuja)/i.test(state.trim()) ? 'FCT (Abuja)' : STATES.find((x) => x.toLowerCase() === state.trim().toLowerCase())
  return s ? DELIVERY.zones.find((z) => z.states.includes(s))!.fee_minor : null
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
  groupCode: string | null; payout: PayoutStatus
  code: string | null; codeTries: number; deliveredBy: 'buyer' | 'code' | 'admin' | null; deliveredAt: string | null
  dispute: { status: 'open' | 'refunded' | 'released'; reason: DisputeReason; details: string; openedAt: string; resolvedAt: string | null; note: string | null } | null
  refunds: { id: number; amount: number; status: OrderRefund['status']; failure: string | null; reason: string; at: string }[]
  approvedBy: number | null; approvedAt: string | null
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
// Mirrors app/Domain/Shop/FabricOrderStatus: the vendor needs the buyer's code for "delivered" and never completes.
const NEXT: Record<FabricOrderStatus, Partial<Record<'vendor' | 'customer' | 'admin', FabricOrderStatus[]>>> = {
  pending_payment: {}, paid: { vendor: ['packed'], admin: ['cancelled'] }, packed: { vendor: ['out_for_delivery', 'ready_for_pickup'], admin: ['cancelled'] },
  out_for_delivery: { vendor: ['delivered'], customer: ['delivered'], admin: ['delivered', 'cancelled'] },
  ready_for_pickup: { vendor: ['delivered'], customer: ['delivered'], admin: ['delivered', 'cancelled'] },
  delivered: { customer: ['completed'], admin: ['completed', 'cancelled'] }, completed: {}, cancelled: {},
}
const PAYOUT_LABELS: Record<PayoutStatus, string> = {
  held: 'Held until the buyer has it', releasable: 'Ready for payout', approved: 'Payout approved', paid: 'Paid to you', withheld: 'Not payable (cancelled)',
}
const REFUND_LABELS: Record<OrderRefund['status'], string> = { pending: 'Refund on its way', processed: 'Refunded', failed: 'Refund failed, OSAPS is retrying' }
const PROTECTION_DAYS = 7
const OPENABLE: FabricOrderStatus[] = ['paid', 'packed', 'out_for_delivery', 'ready_for_pickup', 'delivered']
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

  /**
   * Design preview only: offers on printed fabrics are answered automatically, so a solo review
   * feels live. Every other fabric waits for the "View as: Vendor" account to answer by hand.
   */
  const autoVendor = (o: OfferRow) => {
    if (fabricById(o.fabricId)?.category !== 'print') return
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

  const quoteOf = (u: User | null, items: CartItemInput[], fulfilment: Fulfilment, ignoreBusy?: string, opts: { country?: string | null; fixedUnit?: number | null; state?: string | null } = {}): ShopQuote & { raw: { vendors: { slug: string; name: string; subtotal: number; delivery: number; lines: OrderRow['items'] }[]; subtotal: number; delivery: number; fee: number; total: number } } => {
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
        const busy = orders.some((r) => r.status === 'pending_payment' && r.customerId !== u?.id && r.checkoutRef !== ignoreBusy && r.items.some((i) => i.offerId === it.price_offer_id))
        if (!o || o.buyerId !== u?.id || o.fabricId !== f.id || o.status !== 'accepted' || busy) notices.push({ fabric_id: f.id, code: 'offer_not_usable', message: `${f.name}: your agreed price is no longer valid, so the listed price applies.` })
        else if (it.quantity > o.quantity) notices.push({ fabric_id: f.id, code: 'offer_quantity', message: `${f.name}: your agreed price covers up to ${o.quantity} ${f.unit}. Lower the quantity to use it.` })
        else { unit = Math.min(o.unit, f.price.amount_minor); offerId = o.id }
      }
      const v = vendors.get(f.vendor.slug) ?? { slug: f.vendor.slug, name: f.vendor.business_name, subtotal: 0, delivery: 0, lines: [] }
      v.lines.push({ fabricId: f.id, name: f.name, unitName: f.unit, quantity: it.quantity, list: f.price.amount_minor, price: unit, offerId })
      v.subtotal += unit * it.quantity
      vendors.set(f.vendor.slug, v)
    }
    let fee0 = SHOP.delivery_fee_per_vendor_minor
    if ((fulfilment === 'delivery' || fulfilment === 'arrival') && opts.state) fee0 = stateFee(opts.state) ?? fail('Choose your state from the list.', 422, 'validation_failed', { 'address.state': ['Choose your state from the list.'] })
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

  const orderView = (r: OrderRow, viewer: 'customer' | 'vendor' | 'admin'): FabricOrder => {
    const c = checkouts.find((x) => x.reference === r.checkoutRef)!
    const v = fabrics.find((f) => f.vendor?.slug === r.vendorSlug)?.vendor
    const disputeOpen = r.dispute?.status === 'open'
    const next = (NEXT[r.status][viewer] ?? []).filter((s) => (!['out_for_delivery', 'ready_for_pickup'].includes(s) || s === dispatch(r.fulfilment))
      && (s !== 'cancelled' || viewer === 'admin') && !(disputeOpen && s === 'completed'))
    const paid = !['pending_payment', 'cancelled'].includes(r.status)
    const seesMoney = viewer !== 'vendor'
    return {
      reference: r.reference, checkout_reference: r.checkoutRef, status: r.status, status_label: ORDER_LABELS[r.status], fulfilment: r.fulfilment,
      milestones: (['paid', 'packed', dispatch(r.fulfilment), 'delivered', 'completed'] as FabricOrderStatus[]).map((s) => ({ status: s, label: ORDER_LABELS[s] })),
      next: next.map((s) => ({ status: s, label: ORDER_LABELS[s], needs_code: viewer === 'vendor' && s === 'delivered' })),
      delivery_code: viewer === 'customer' && ['paid', 'packed', 'out_for_delivery', 'ready_for_pickup'].includes(r.status) ? r.code : null,
      delivery_code_locked: viewer === 'vendor' && r.codeTries >= 5,
      delivered_by: r.deliveredBy, protection_days: PROTECTION_DAYS,
      dispute: {
        can_open: viewer === 'customer' && !r.dispute && OPENABLE.includes(r.status),
        status: r.dispute?.status ?? null, reason: r.dispute?.reason ?? null, reason_label: r.dispute ? DISPUTE_REASONS[r.dispute.reason] : null,
        details: r.dispute?.details ?? null, opened_at: r.dispute?.openedAt ?? null, resolved_at: r.dispute?.resolvedAt ?? null, resolution_note: r.dispute?.note ?? null,
      },
      refunds: seesMoney ? r.refunds.map((x) => ({ id: x.id, amount: money(x.amount), status: x.status, label: REFUND_LABELS[x.status], failure: viewer === 'admin' ? x.failure : null, at: x.at })) : [],
      payout_label: viewer !== 'customer' ? PAYOUT_LABELS[r.payout] : null,
      subtotal: money(r.subtotal), delivery: money(r.delivery),
      service_fee: seesMoney ? money(r.fee) : null, total: seesMoney ? money(r.subtotal + r.delivery + r.fee) : null,
      vendor_payout: viewer !== 'customer' ? money(r.subtotal + r.delivery - r.commission) : null,
      payout_status: viewer !== 'customer' ? r.payout : null,
      aso_ebi: r.groupCode ? { code: r.groupCode, title: groups.find((g) => g.code === r.groupCode)?.title ?? '' } : null,
      ship: { country: c.country, arrival_date: c.arrivalDate },
      vendor: v ? { business_name: v.business_name, market: v.market, city: v.city } : undefined,
      deliver_to: viewer !== 'customer' && paid ? { name: c.contact.name, phone: c.contact.phone, address: c.address } : null,
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

  const VENDOR_USER_ID: Record<string, number> = { 'osaps-store': 3 }

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
        events: [{ to: 'pending_payment', by: 'customer', note: null, at }], groupCode: extra.groupCode, payout: 'held',
        code: null, codeTries: 0, deliveredBy: null, deliveredAt: null, dispute: null, refunds: [], approvedBy: null, approvedAt: null })
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
    | 'quote' | 'createCheckout' | 'checkout' | 'verifyCheckout' | 'cancelCheckout' | 'fabricOrders' | 'fabricOrder' | 'transitionFabricOrder' | 'reportProblem'
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

    quote: (items, fulfilment, country, state) => { sweep(); const { raw: _raw, ...q } = quoteOf(ctx.user(), items, fulfilment, undefined, { country, state }); return delay(q) },
    createCheckout: async (input) => {
      sweep()
      const u = me()
      for (const old of checkouts.filter((c) => c.customerId === u.id && c.status === 'pending' && !c.groupCode)) release(old, 'cancelled')
      if (input.fulfilment === 'pickup') fail('Pickup is not available yet. Choose delivery.', 422, 'pickup_unavailable')
      const st = (input.address as Record<string, string> | null)?.state ?? null
      if ((input.fulfilment === 'delivery' || input.fulfilment === 'arrival') && !st) fail('Choose your state.', 422, 'validation_failed', { 'address.state': ['Choose your state.'] })
      const q = quoteOf(u, input.items, input.fulfilment, undefined, { country: input.country, state: st })
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
        // Like CheckoutService::confirmPaid: bought fabrics leave the account's saved cart.
        if (!c.groupCode) ctx.onPaid?.(c.customerId, orders.filter((o) => o.checkoutRef === c.reference).flatMap((o) => o.items.map((i) => i.fabricId)))
        for (const o of orders.filter((x) => x.checkoutRef === ref)) {
          o.status = 'paid'; o.code = String(Math.floor(Math.random() * 1_000_000)).padStart(6, '0'); o.events.push({ to: 'paid', by: 'system', note: 'Payment confirmed (simulated in the design preview).', at: c.paidAt })
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
      if (u.role === 'admin') return delay(orderView(o, 'admin'))
      if (ctx.vendorSlugOf(u) === o.vendorSlug && o.status !== 'pending_payment') return delay(orderView(o, 'vendor'))
      return fail('You do not have access to this.', 403, 'forbidden')
    },
    transitionFabricOrder: async (ref, status, note, code) => {
      const u = me()
      const o = orders.find((x) => x.reference === ref) ?? fail('Not found.', 404, 'not_found')
      const role = u.role === 'admin' ? 'admin' : o.customerId === u.id ? 'customer' : ctx.vendorSlugOf(u) === o.vendorSlug ? 'vendor' : fail('You do not have access to this.', 403, 'forbidden')
      if ((status === 'out_for_delivery' || status === 'ready_for_pickup') && status !== dispatch(o.fulfilment)) {
        fail(o.fulfilment === 'pickup' ? 'The buyer is collecting this order. Mark it ready for pickup.' : 'The buyer chose delivery. Mark it out for delivery.', 422, 'wrong_dispatch_step')
      }
      if (role === 'admin' && ['cancelled', 'delivered', 'completed'].includes(status) && (note ?? '').trim().length < 5) fail('Add a note saying why. The buyer and vendor will see it.', 422, 'admin_note_required')
      if (o.dispute?.status === 'open' && status === 'completed') fail('A problem report is open on this order. OSAPS support will settle it first.', 422, 'dispute_open')
      if (!(NEXT[o.status][role] ?? []).includes(status)) fail(`Order: cannot move from '${o.status}' to '${status}' by ${role}.`, 409, 'invalid_transition')
      if (status === 'delivered' && role === 'vendor') {
        if (o.codeTries >= 5) fail('Too many wrong codes. Ask the buyer to confirm in the app, or contact OSAPS support.', 422, 'delivery_code_locked')
        const given = (code ?? '').replace(/\D/g, '')
        if (!given) fail('Enter the 6-digit code the buyer shows you when they receive the fabric.', 422, 'delivery_code_required')
        if (given !== o.code) {
          o.codeTries++
          const left = 5 - o.codeTries
          fail(left > 0 ? `That code is not right. ${left} tries left.` : 'That code is not right. Ask the buyer to confirm in the app, or contact OSAPS support.', 422, 'delivery_code_wrong')
        }
      }
      if (status === 'delivered') { o.deliveredBy = role === 'vendor' ? 'code' : role === 'customer' ? 'buyer' : 'admin'; o.deliveredAt = new Date().toISOString() }
      o.status = status
      o.payout = status === 'completed' ? 'releasable' : status === 'cancelled' ? 'withheld' : o.payout
      o.events.push({ to: status, by: role, note: note ?? null, at: new Date().toISOString() })
      if (role === 'admin') adminLog(u, `order_${status}`, `FabricOrder ${o.reference}`, note ?? null)
      if (status === 'completed' && role !== 'admin') ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `Order ${o.reference} completed`, 'The buyer confirmed all is well. Your payout is ready for OSAPS to send.', '/vendor?tab=orders')
      const what: Partial<Record<FabricOrderStatus, string>> = { packed: 'Your fabric is packed.', out_for_delivery: 'Your fabric is on its way. Give the rider your delivery code only when you have it.',
        ready_for_pickup: 'Your fabric is ready to collect. Give the vendor your delivery code when you collect it.', delivered: `Delivery confirmed with your code. Something wrong? Report it within ${PROTECTION_DAYS} days.` }
      if (role === 'vendor' && what[status]) ctx.notify(o.customerId, what[status]!, `Order ${o.reference}.`, `/shop/orders/${o.reference}`)
      return delay(orderView(o, role))
    },
    reportProblem: async (ref, reason, details) => {
      const u = me()
      const o = orders.find((x) => x.reference === ref && x.customerId === u.id) ?? fail('You do not have access to this.', 403, 'forbidden')
      if (o.dispute) fail('You have already reported a problem with this order. OSAPS support will reply.', 422, 'dispute_exists')
      if (!OPENABLE.includes(o.status)) fail(o.status === 'completed' ? 'This order is complete. Contact OSAPS support if something is wrong.' : 'You can report a problem once the order is paid.', 422, 'dispute_not_allowed')
      if (details.trim().length < 10) fail('Tell us a little more (at least 10 characters) so we can help.', 422, 'dispute_details')
      o.dispute = { status: 'open', reason, details: details.trim(), openedAt: new Date().toISOString(), resolvedAt: null, note: null }
      o.events.push({ to: o.status, by: 'customer', note: `Problem reported: ${DISPUTE_REASONS[reason]}.`, at: new Date().toISOString() })
      ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `Problem reported on ${o.reference}`, `${DISPUTE_REASONS[reason]}. OSAPS support will contact you. The payout is on hold until it is settled.`, '/vendor?tab=orders')
      ctx.notify(ADMIN_ID, `Problem report: ${o.reference}`, DISPUTE_REASONS[reason], '/admin?tab=disputes')
      return delay(orderView(o, 'customer'))
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
      const bankChanged = (input.bank_account_number && input.bank_account_number.slice(-4) !== profile.bank_account_last4)
        || (input.bank_name ?? null) !== profile.bank_name || (input.bank_account_name ?? null) !== profile.bank_account_name
      if (input.bank_account_number) bankNumber = input.bank_account_number
      profile = { ...profile, ...input, market: input.market ?? null, phone: input.phone ?? null, whatsapp: input.whatsapp ?? null,
        bank_name: input.bank_name ?? null, bank_account_name: input.bank_account_name ?? null,
        bank_account_last4: input.bank_account_number ? input.bank_account_number.slice(-4) : profile.bank_account_last4 }
      if (bankChanged && vStatus === 'verified') { vStatus = 'pending'; vNote = 'Bank details changed. OSAPS checks them again before the shop goes back live.'; vRequested = new Date().toISOString() }
      if ((vStatus === 'unverified' || vStatus === 'rejected') && missing().length === 0) {
        vStatus = 'pending'; vRequested = new Date().toISOString()
        ctx.notify(ADMIN_ID, `Shop to verify: ${profile.business_name}`, `${profile.market ?? ''}, ${profile.city}`, '/admin?tab=vendors')
      }
      return delay(profileView())
    },
    vendorProfile: () => delay(profileView()),
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
        if (!o || o.buyerId !== u?.id || o.fabricId !== f.id || o.status !== 'accepted') fail('That agreed price cannot be used for this group. It may have expired.', 422, 'offer_not_usable')
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
    quoteAsoEbi: (code, yards, state) => { sweep(); const g = groupOf(code); const { raw: _raw, ...q } = shareQuote(me(), g, yards, state); return delay(q) },
    joinAsoEbi: async (code, input) => {
      sweep()
      const u = me()
      const g = groupOf(code)
      if (g.status !== 'open' || Date.parse(g.deadline) <= Date.now()) fail('This aṣọ-ẹbí is no longer taking payments. Ask the organiser.', 422, 'group_closed')
      for (const old of checkouts.filter((c) => c.customerId === u.id && c.groupCode === code && c.status === 'pending')) release(old, 'cancelled')
      const q = shareQuote(u, g, input.yards, g.fulfilment === 'deliver_each' ? (input.address as Record<string, string> | null)?.state ?? null : null)
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

  // The demo shop starts verified (its fabrics are on sale). A second, unverified demo shop waits for review.
  let profile: VendorProfile = { business_name: 'OSAPS Store', slug: 'osaps-store', city: 'Lagos', market: 'Lagos Island', phone: '0803 000 0000', whatsapp: null, verified: true,
    bank_name: 'Demo Bank', bank_account_name: 'OSAPS Store', bank_account_last4: '6789' }
  let bankNumber = '0123456789'
  let vStatus: AdminVendor['status'] = 'verified'
  let vNote: string | null = null
  let vRequested: string | null = null
  const missing = () => {
    const m = (['business_name', 'city', 'market', 'phone', 'bank_name', 'bank_account_name'] as const).filter((k) => !(profile[k] ?? '').toString().trim())
    return /^\d{10}$/.test(bankNumber) ? [...m] : [...m, 'bank_account_number']
  }
  const V_LABELS: Record<AdminVendor['status'], string> = { unverified: 'Not submitted', pending: 'Waiting for review', verified: 'Verified', rejected: 'Changes needed', suspended: 'Suspended' }
  const profileView = (): VendorProfile => ({ ...profile, verified: vStatus === 'verified', verification: { status: vStatus, label: V_LABELS[vStatus], note: vNote, missing: missing() } })
  const otherShops: AdminVendor[] = [{
    id: 902, business_name: 'Onitsha Lace House', city: 'Onitsha', market: 'Main Market', phone: '0806 000 0000', whatsapp: null,
    owner: { name: 'Demo Owner', email: 'vendor-new@demo.osaps.test', joined: new Date().toISOString().slice(0, 10) },
    bank: { name: 'Demo Bank', account_name: 'Onitsha Lace House', account_number: '0987654321' }, fabrics: 4,
    status: 'pending', label: 'Waiting for review', note: null, requested_at: hours(-20), is_demo: true,
  }]

  // ---- Back office (mirrors AdminController + TrustService) ----
  const ADMIN_ID = 4
  let refundSeq = 1
  const actions: AdminActionRow[] = []
  const adminLog = (u: User, action: string, subject: string, note: string | null) =>
    actions.unshift({ id: actions.length + 1, admin: u.name, action, subject, note, at: new Date().toISOString() })
  const asAdmin = () => { const u = me(); if (u.role !== 'admin') fail('You do not have access to this.', 403, 'forbidden'); return u }
  const orderOf = (ref: string) => orders.find((x) => x.reference === ref) ?? fail('Not found.', 404, 'not_found')
  const totalOf = (o: OrderRow) => o.subtotal + o.delivery + o.fee
  const committedRefund = (o: OrderRow) => o.refunds.filter((x) => x.status !== 'failed').reduce((n, x) => n + x.amount, 0)
  const row = (o: OrderRow): AdminOrderRow => {
    const c = checkouts.find((x) => x.reference === o.checkoutRef)!
    const v = fabrics.find((f) => f.vendor?.slug === o.vendorSlug)?.vendor
    return { reference: o.reference, status: o.status, status_label: ORDER_LABELS[o.status], fulfilment: o.fulfilment, total: money(totalOf(o)),
      vendor: { business_name: v?.business_name ?? null, phone: profile.phone }, buyer: { name: c.contact.name, phone: c.contact.phone, email: null },
      items: o.items.map((i) => `${i.quantity} × ${i.name}`).join(', '), payout_status: o.payout, created_at: o.createdAt }
  }
  const refundOrder = (u: User, o: OrderRow, reason: string, amount?: number | null) => {
    const c = checkouts.find((x) => x.reference === o.checkoutRef)!
    const remaining = Math.max(0, totalOf(o) - committedRefund(o))
    const amt = amount ?? remaining
    if (c.status !== 'paid' && c.status !== 'needs_refund') fail('This order was never paid, so there is nothing to refund.', 422, 'refund_not_paid')
    if (o.status !== 'cancelled') fail('Cancel the order (or settle its problem report as a refund) before refunding.', 422, 'refund_order_not_cancelled')
    if (o.payout === 'paid' || o.payout === 'approved') fail('The vendor payout for this order was already approved or sent. Recover it from the vendor first.', 422, 'refund_payout_sent')
    if (remaining <= 0) fail('This order has already been refunded in full.', 422, 'refund_done')
    if (amt < 100 || amt > remaining) fail('Refund between ₦1 and the amount still owed to the buyer.', 422, 'refund_amount')
    const r = { id: refundSeq++, amount: amt, status: 'pending' as const, failure: null, reason, at: new Date().toISOString() }
    o.refunds.push(r)
    adminLog(u, 'refund_requested', `FabricOrder ${o.reference}`, reason)
    ctx.notify(o.customerId, `Refund started for ${o.reference}`, `${money(amt).formatted} is on its way back to how you paid. Banks usually take a few working days.`, `/shop/orders/${o.reference}`)
    // Simulated Paystack: the refund settles a few seconds later.
    setTimeout(() => { if (r.status === 'pending') (r as { status: OrderRefund['status'] }).status = 'processed' }, 3_000)
    return r
  }
  const payoutAmount = (o: OrderRow) => o.subtotal + o.delivery - o.commission
  const adminApi: Pick<Api, 'adminOverview' | 'adminVendors' | 'adminReviewVendor' | 'adminDisputes' | 'adminResolveDispute' | 'adminRefunds' | 'adminRefund'
    | 'adminMarkRefundFailed' | 'adminPayouts' | 'adminApprovePayout' | 'adminMarkPaid' | 'adminStuck' | 'adminActions'> = {
    adminOverview: () => {
      asAdmin(); sweep()
      return delay({
        vendors_pending: otherShops.filter((x) => x.status === 'pending').length + (vStatus === 'pending' ? 1 : 0),
        disputes_open: orders.filter((o) => o.dispute?.status === 'open').length,
        refunds_owed: orders.filter((o) => o.status === 'cancelled' && totalOf(o) > committedRefund(o) && ['paid', 'needs_refund'].includes(checkouts.find((c) => c.reference === o.checkoutRef)!.status)).length,
        refunds_unconfirmed: 0,
        payouts_releasable: orders.filter((o) => o.payout === 'releasable').length,
        payouts_approved: orders.filter((o) => o.payout === 'approved').length,
        stuck: 0, two_person_payouts: true, cron_last_run: new Date().toISOString(), queue: 'database', queued_jobs: 0, payments_enabled: true, mail: 'smtp',
      })
    },
    adminVendors: (status) => {
      asAdmin()
      const mine: AdminVendor = { id: 901, business_name: profile.business_name, city: profile.city, market: profile.market, phone: profile.phone, whatsapp: profile.whatsapp,
        owner: { name: 'Bola Balogun', email: 'vendor1@demo.osaps.test', joined: '2026-10-01' },
        bank: { name: profile.bank_name, account_name: profile.bank_account_name, account_number: bankNumber }, fabrics: fabrics.filter((f) => f.vendor?.slug === 'osaps-store').length,
        status: vStatus, label: V_LABELS[vStatus], note: vNote, requested_at: vRequested, is_demo: true }
      return delay([mine, ...otherShops].filter((v) => v.status === status))
    },
    adminReviewVendor: async (id, to, note) => {
      const u = asAdmin()
      if ((to === 'rejected' || to === 'suspended') && (note ?? '').trim().length < 5) fail('Say what the vendor needs to fix, so they can act on it.', 422, 'verification_note_required')
      if (id === 901) { vStatus = to; vNote = note; ctx.notify(VENDOR_USER_ID['osaps-store']!, `Shop review: ${V_LABELS[to]}`, to === 'verified' ? 'Your shop is verified. Your active listings are now visible to buyers.' : note, '/vendor?tab=shop') }
      else { const v = otherShops.find((x) => x.id === id) ?? fail('Not found.', 404, 'not_found'); v.status = to; v.label = V_LABELS[to]; v.note = note }
      adminLog(u, `vendor_${to}`, `FabricVendor #${id}`, note)
      await delay(null)
    },
    adminDisputes: () => { asAdmin(); return delay(orders.filter((o) => o.dispute?.status === 'open').map((o) => ({ ...row(o), reason: DISPUTE_REASONS[o.dispute!.reason], details: o.dispute!.details, opened_at: o.dispute!.openedAt }))) },
    adminResolveDispute: async (ref, resolution, note) => {
      const u = asAdmin()
      const o = orderOf(ref)
      const d = o.dispute
      if (!d || d.status !== 'open') return fail('This problem report is already settled.', 422, 'dispute_not_open')
      if (note.trim().length < 5) fail('Write a short note on how this was settled. Both sides will see it.', 422, 'dispute_note_required')
      o.dispute = { ...d, status: resolution === 'refund' ? 'refunded' : 'released', resolvedAt: new Date().toISOString(), note }
      const at = new Date().toISOString()
      if (resolution === 'refund') {
        o.status = 'cancelled'; o.payout = 'withheld'; o.events.push({ to: 'cancelled', by: 'admin', note: `Problem report settled with a refund: ${note}`, at })
        refundOrder(u, o, `Problem report: ${note}`)
      } else {
        o.status = 'completed'; o.payout = 'releasable'; o.events.push({ to: 'completed', by: 'admin', note: `Problem report settled in the vendor's favour: ${note}`, at })
      }
      adminLog(u, `dispute_${resolution}`, `FabricOrder ${o.reference}`, note)
      ctx.notify(o.customerId, `Your problem report on ${o.reference} is settled`, resolution === 'refund' ? `We are refunding you. ${note}` : `We checked with both sides and closed the report. ${note}`, `/shop/orders/${o.reference}`)
      await delay(null)
    },
    adminRefunds: () => {
      asAdmin()
      const owed = orders.filter((o) => o.status === 'cancelled' && !['paid', 'approved'].includes(o.payout) && totalOf(o) > committedRefund(o)
        && ['paid', 'needs_refund'].includes(checkouts.find((c) => c.reference === o.checkoutRef)!.status))
      const recent: AdminRefund[] = orders.flatMap((o) => o.refunds.map((r) => ({ id: r.id, order: o.reference, checkout: o.checkoutRef, amount: money(r.amount), status: r.status,
        label: REFUND_LABELS[r.status], failure: r.failure, reason: r.reason, at: r.at }))).sort((a, b) => b.id - a.id)
      return delay({ owed: owed.map((o) => ({ ...row(o), remaining: money(totalOf(o) - committedRefund(o)), checkout_status: checkouts.find((c) => c.reference === o.checkoutRef)!.status })), recent })
    },
    adminRefund: async (ref, reason, amount) => {
      const u = asAdmin()
      if (reason.trim().length < 5) fail('Give a reason (at least 5 characters).', 422, 'validation_failed', { reason: ['Give a reason (at least 5 characters).'] })
      const r = refundOrder(u, orderOf(ref), reason, amount)
      return delay({ id: r.id, status: r.status, failure: r.failure })
    },
    adminMarkRefundFailed: async (id, note) => {
      const u = asAdmin()
      const r = orders.flatMap((o) => o.refunds).find((x) => x.id === id) ?? fail('Not found.', 404, 'not_found')
      if (r.status !== 'pending') fail('Only a pending refund can be marked failed.', 422, 'refund_not_pending')
      r.status = 'failed'; r.failure = `Marked failed by admin: ${note}`
      adminLog(u, 'refund_marked_failed', `Refund #${id}`, note)
      await delay(null)
    },
    adminPayouts: () => {
      const u = asAdmin()
      const list = orders.filter((o) => o.payout === 'releasable' || o.payout === 'approved')
      const groups: AdminPayoutGroup[] = list.length ? [{
        vendor: { id: 901, business_name: profile.business_name, verified: vStatus === 'verified', bank_name: profile.bank_name, bank_account_name: profile.bank_account_name, bank_account_number: bankNumber },
        amount: money(list.reduce((n, o) => n + payoutAmount(o), 0)),
        orders: list.map((o) => ({ reference: o.reference, completed_at: [...o.events].reverse().find((e) => e.to === 'completed')?.at ?? null, amount: money(payoutAmount(o)),
          payout_status: o.payout, approved_by_me: o.approvedBy === u.id, approved_at: o.approvedAt })),
      }] : []
      return delay({ data: groups, two_person: true })
    },
    adminApprovePayout: async (ref) => {
      const u = asAdmin()
      const o = orderOf(ref)
      if (o.payout !== 'releasable') fail('This order is not waiting for a payout.', 422, 'payout_not_releasable')
      if (vStatus !== 'verified') fail('This vendor is not verified. Verify or settle the shop before paying it.', 422, 'payout_vendor_unverified')
      if (o.dispute?.status === 'open') fail('A problem report is open on this order.', 422, 'payout_dispute_open')
      o.payout = 'approved'; o.approvedBy = u.id; o.approvedAt = new Date().toISOString()
      adminLog(u, 'payout_approved', `FabricOrder ${o.reference}`, null)
      await delay(null)
    },
    adminMarkPaid: async (ref, transferRef) => {
      const u = asAdmin()
      const o = orderOf(ref)
      if (transferRef.trim().length < 4) fail('Enter the bank transfer reference so the payment can be traced.', 422, 'payout_reference_required')
      if (o.payout !== 'approved') fail('Another admin must approve this payout before it is recorded as paid.', 422, 'payout_not_approved')
      if (o.approvedBy === u.id) fail('You approved this payout. A different admin must send it and record it.', 422, 'payout_needs_second_admin')
      o.payout = 'paid'
      o.events.push({ to: o.status, by: 'admin', note: 'Vendor payout sent.', at: new Date().toISOString() })
      adminLog(u, 'payout_paid', `FabricOrder ${o.reference}`, transferRef)
      ctx.notify(VENDOR_USER_ID[o.vendorSlug] ?? 0, `Payout sent for ${o.reference}`, `${money(payoutAmount(o)).formatted} was sent to your bank account.`, '/vendor?tab=orders')
      await delay(null)
    },
    adminStuck: () => { asAdmin(); return delay([]) },
    adminActions: () => { asAdmin(); return delay(actions.slice(0, 100)) },
  }

  const groupOf = (code: string) => groups.find((g) => g.code === code) ?? fail('Not found.', 404, 'not_found')
  const ownGroup = (code: string) => { const g = groupOf(code); if (g.organizerId !== me().id) fail('Only the organiser can change this group.', 422, 'forbidden'); return g }
  const committed = (g: GroupRow) => g.shares.filter((x) => x.status !== 'cancelled').reduce((n, x) => n + x.yards, 0)
  const shareQuote = (u: User, g: GroupRow, yards: number, state?: string | null) => {
    const fixed = g.dealUnit !== null && g.dealQty !== null && committed(g) + yards <= g.dealQty ? g.dealUnit : null
    return quoteOf(u, [{ fabric_id: g.fabricId, quantity: yards }], g.fulfilment === 'pickup_organizer' ? 'pickup' : 'delivery', undefined, { fixedUnit: fixed, state })
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

  return { api: { ...api, ...adminApi }, simulatePayment }
}
