/**
 * DESIGN-REVIEW ADAPTER — in-memory stand-in for the Laravel API.
 *
 * Loaded only when the app runs with `vite --mode preview` (a persistent banner says so).
 * Production builds never include it. It mirrors the server's rules closely enough to
 * exercise every screen, but the Laravel API (app/Services/NegotiationService.php) is the
 * authority; nothing here is a source of truth.
 */
import { ApiError, type Api } from './client'
import type {
  Fabric,
  JobRequest,
  Meta,
  MoneyValue,
  Offer,
  OfferStatus,
  Order,
  OrderStatus,
  Tailor,
  User,
} from './types'

const GARMENTS: Record<string, string> = {
  agbada: 'Agbada', senator: 'Senator', kaftan: 'Kaftan', buba_sokoto: 'Buba & Sokoto', iro_buba: 'Iro & Buba',
  gown: 'Gown', skirt_blouse: 'Skirt & Blouse', suit: 'Suit', bridal: 'Bridal', aso_ebi: 'Aṣọ-ẹbí (group order)',
  kids: "Children's wear", alteration: 'Alteration',
}
const FABRIC_CATS: Record<string, string> = {
  ankara: 'Ankara', adire: 'Adire', aso_oke: 'Aṣọ-òkè', lace: 'Lace', senator: 'Senator fabric', suiting: 'Suiting',
  george: 'George', damask: 'Damask', other: 'Other textiles',
}
const FEE_BPS = 250
const FEE_CAP = 500_000
const MAX_ROUNDS = 3
const EXPIRY_HOURS = 48

const money = (minor: number): MoneyValue => ({
  amount_minor: minor,
  currency: 'NGN',
  formatted: '₦' + Math.floor(minor / 100).toLocaleString('en-NG') + (minor % 100 ? '.' + String(minor % 100).padStart(2, '0') : ''),
})
const slug = (s: string) => s.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '')
const delay = <T,>(v: T) => new Promise<T>((r) => setTimeout(() => r(structuredClone(v)), 120))
const fail = (message: string, status: number, code: string, fieldErrors: Record<string, string[]> = {}) => {
  throw new ApiError(message, status, code, fieldErrors)
}

// --- Demonstration data (mirrors database/seeders/DemoSeeder.php) ---
type F = [string, string, string, string[], string, string, string, number, number, number]
const vendors = [
  { business_name: 'Balogun Bolts', slug: 'balogun-bolts', city: 'Lagos', market: 'Balogun Market', verified: false },
  { business_name: 'Wuse Textiles', slug: 'wuse-textiles', city: 'Abuja', market: 'Wuse Market', verified: false },
  { business_name: 'Kantin Kwari Cloth', slug: 'kantin-kwari-cloth', city: 'Kano', market: 'Kantin Kwari Market', verified: false },
]
const fabricRows: F[] = [
  ['Indigo Sunburst Ankara', 'ankara', 'indigo', ['indigo', 'gold', 'cream'], 'sunburst', 'Cotton wax print', 'yard', 4500, 120, 0],
  ['Terracotta Kola Ankara', 'ankara', 'terracotta', ['terracotta', 'cream', 'black'], 'circles', 'Cotton wax print', 'yard', 3800, 80, 0],
  ['Emerald Lattice Ankara', 'ankara', 'emerald', ['emerald', 'gold', 'black'], 'lattice', 'Cotton wax print', 'yard', 4200, 6, 0],
  ['Midnight Adire Eleko', 'adire', 'indigo', ['indigo', 'white'], 'resist', 'Hand-dyed cotton', 'yard', 6000, 40, 1],
  ['Oniko Tie-Dye Adire', 'adire', 'royal_blue', ['royal_blue', 'white'], 'tie_dye', 'Hand-dyed cotton', 'yard', 5500, 25, 1],
  ['Burgundy Sanyan Aṣọ-òkè', 'aso_oke', 'burgundy', ['burgundy', 'gold'], 'stripes', 'Hand-woven cotton & silk', 'bundle', 85000, 12, 1],
  ['Gold Alaari Aṣọ-òkè', 'aso_oke', 'gold', ['gold', 'cream'], 'stripes', 'Hand-woven metallic yarn', 'bundle', 95000, 0, 1],
  ['Ivory Guipure Lace', 'lace', 'cream', ['cream'], 'floral', 'Polyester guipure', 'yard', 18000, 30, 0],
  ['Champagne Beaded Lace', 'lace', 'gold', ['gold', 'cream'], 'floral', 'Tulle with bead work', 'yard', 32000, 15, 0],
  ['Navy Cashmere Senator', 'senator', 'navy', ['navy'], 'plain', 'Cashmere blend', 'yard', 7500, 200, 2],
  ['Charcoal Senator Twill', 'senator', 'black', ['black'], 'twill', 'Polyester-wool blend', 'yard', 6200, 150, 2],
  ['Cream Senator Linen', 'senator', 'cream', ['cream'], 'plain', 'Linen blend', 'yard', 6800, 90, 2],
  ['Navy Super 120s Suiting', 'suiting', 'navy', ['navy'], 'plain', 'Wool', 'metre', 22000, 45, 0],
  ['Wine Silk George', 'george', 'burgundy', ['burgundy', 'gold'], 'paisley', 'Silk george', 'piece', 65000, 8, 0],
  ['Emerald Damask Brocade', 'damask', 'emerald', ['emerald'], 'jacquard', 'Cotton damask', 'yard', 9500, 70, 2],
  ['Royal Purple Ankara', 'ankara', 'purple', ['purple', 'gold', 'orange'], 'feathers', 'Cotton wax print', 'yard', 4000, 55, 1],
]
const fabrics: Fabric[] = fabricRows.map(([name, cat, primary, colors, pattern, material, unit, naira, stock, v], i) => ({
  id: i + 1,
  slug: slug(name),
  name,
  category: cat,
  category_label: FABRIC_CATS[cat] ?? cat,
  description: `${material}. Demonstration listing — illustrative price and stock.`,
  primary_color: primary,
  colors,
  pattern,
  material,
  unit,
  price: money(naira * 100),
  available_quantity: stock,
  stock_status: stock === 0 ? 'out_of_stock' : stock < 10 ? 'low_stock' : 'in_stock',
  min_order_quantity: unit === 'yard' ? 2 : 1,
  image_url: null,
  is_demo: true,
  vendor: vendors[v]!,
  purchase_mode: 'contact_vendor',
}))

type T = [string, string, string[], string[], string[], number, string]
const tailorRows: T[] = [
  ['Ọ̀ṣọ́ Atelier', 'Abuja', ['Abuja'], ['senator', 'agbada', 'kaftan'], ['embroidered', 'minimal'], 2_500_000, 'Menswear studio focused on clean senator cuts and hand-finished embroidery.'],
  ['Threadline by Zainab', 'Abuja', ['Abuja', 'Kaduna'], ['gown', 'skirt_blouse', 'aso_ebi'], ['beaded', 'off-shoulder'], 3_000_000, 'Occasion womenswear: structured gowns and coordinated aṣọ-ẹbí groups.'],
  ['Kúnlé Tailoring House', 'Lagos', ['Lagos', 'Ibadan'], ['agbada', 'buba_sokoto', 'suit'], ['embroidered', 'classic'], 4_500_000, 'Traditional ceremonial wear and tailored suits.'],
  ['Adaeze Couture', 'Lagos', ['Lagos'], ['bridal', 'gown'], ['lace', 'beaded'], 12_000_000, 'Bridal and evening couture with lace appliqué.'],
  ['Northern Needle', 'Kano', ['Kano', 'Abuja'], ['kaftan', 'senator', 'kids'], ['minimal'], 1_500_000, "Everyday kaftans and senators, family and children's orders welcome."],
  ['Iro & Co.', 'Ibadan', ['Ibadan', 'Lagos'], ['iro_buba', 'aso_ebi', 'alteration'], ['adire', 'classic'], 1_800_000, 'Iro and buba in aṣọ-òkè and adire; quick alterations.'],
]
const tailors: Tailor[] = tailorRows.map(([name, city, cities, specs, tags, from, bio]) => ({
  slug: slug(name),
  business_name: name,
  bio,
  city,
  service_cities: cities,
  specialties: specs.map((k) => ({ key: k, label: GARMENTS[k] ?? k })),
  portfolio_tags: tags,
  starting_price: money(from),
  accepting_work: true,
  verified: false,
  track_record: { completed_orders: 0, on_time_rate: null, is_new: true },
  is_demo: true,
}))

const users: Record<string, User> = {
  customer: { id: 1, name: 'Amaka Demo', email: 'customer@demo.asola.test', phone: null, role: 'customer', city: 'Abuja' },
  tailor: { id: 2, name: 'Ọ̀ṣọ́ Atelier', email: 'tailor1@demo.asola.test', phone: null, role: 'tailor', city: 'Abuja', has_tailor_profile: true },
}
let current: User | null = null
try {
  const saved = sessionStorage.getItem('asola-preview-user')
  if (saved && users[saved]) current = users[saved]!
} catch {
  /* storage unavailable — stay signed out */
}

// Negotiation state
interface OfferRow extends Offer {
  requestId: string
  tailorSlug: string
  tailorUserId: number
}
const requests: JobRequest[] = []
const offers: OfferRow[] = []
const orders: (Order & { customerId: number; tailorUserId: number })[] = []
let offerSeq = 100

const hours = (h: number) => new Date(Date.now() + h * 3_600_000).toISOString()
const days = (d: number) => new Date(Date.now() + d * 86_400_000).toISOString().slice(0, 10)

function seedRequest() {
  const id = '01JPREVIEW0000000000000001'
  requests.push({
    id, title: 'Navy senator for my cousin’s wedding', garment_category: 'senator', garment_label: 'Senator',
    description: 'Classic navy senator, slightly slim fit, with subtle gold embroidery on the placket and cuffs. I already have 4 yards of cashmere.',
    style_tags: ['embroidered'], mode: 'budget', budget_min: null, budget_max: money(3_000_000), fabric_status: 'own_fabric',
    fabric: null, city: 'Abuja', needed_by: days(24), fitting_required: true, status: 'open', offers_close_at: hours(24 * 6),
    is_owner: true, customer: { first_name: 'Amaka' }, created_at: hours(-20),
  })
  const make = (t: number, amount: number, d: number, fit: boolean | null, alt: number | null, fee: number | null, msg: string | null, atBudget: boolean) => {
    const tailor = tailors[t]!
    offers.push({
      id: offerSeq++, requestId: id, tailorSlug: tailor.slug, tailorUserId: t === 0 ? 2 : 10 + t,
      status: 'awaiting_customer', amount: money(amount), at_customer_budget: atBudget, delivery_days: d,
      includes_fitting: fit, alterations_included: alt, delivery_fee: fee === null ? null : money(fee), message: msg,
      expires_at: hours(40), rounds: { tailor_used: 0, customer_used: 0, max_per_side: MAX_ROUNDS }, actions: [],
      revisions: [{ author_role: 'tailor', amount: money(amount), delivery_days: d, note: msg, created_at: hours(-18) }],
      created_at: hours(-18),
    })
  }
  make(0, 3_000_000, 10, true, 1, 0, 'Happy to work to your budget. One fitting at my Wuse 2 studio, then final delivery.', true)
  make(4, 3_400_000, 7, true, 2, 150_000, 'Hand embroidery on both cuffs adds work; I can finish a week early.', false)
  make(1, 2_600_000, 14, null, null, null, null, false)
}
seedRequest()

function feeFor(minor: number) {
  return Math.min(Math.floor((minor * FEE_BPS + 5000) / 10_000), FEE_CAP)
}

const OFFER_NEXT: Record<OfferStatus, Partial<Record<'customer' | 'tailor', OfferStatus[]>>> = {
  awaiting_customer: { customer: ['accepted', 'declined', 'awaiting_tailor'], tailor: ['withdrawn'] },
  awaiting_tailor: { tailor: ['awaiting_customer', 'withdrawn'] },
  accepted: {}, declined: {}, withdrawn: {}, expired: {}, not_selected: {},
}
const ORDER_NEXT: Record<OrderStatus, Partial<Record<'customer' | 'tailor', OrderStatus[]>>> = {
  confirmed: { tailor: ['in_progress', 'cancelled'], customer: ['cancelled'] },
  in_progress: { tailor: ['fitting', 'ready'], customer: ['disputed'] },
  fitting: { tailor: ['in_progress', 'ready'], customer: ['disputed'] },
  ready: { tailor: ['delivered'], customer: ['disputed'] },
  delivered: { customer: ['completed', 'disputed'] },
  disputed: {}, completed: {}, cancelled: {},
}
const ORDER_LABELS: Record<OrderStatus, string> = {
  confirmed: 'Order confirmed', in_progress: 'Tailoring in progress', fitting: 'Ready for fitting', ready: 'Ready for delivery',
  delivered: 'Delivered', completed: 'Completed', disputed: 'Under review', cancelled: 'Cancelled',
}

function viewOffer(o: OfferRow, role: 'customer' | 'tailor', all?: OfferRow[]): Offer {
  const expired = ['awaiting_customer', 'awaiting_tailor'].includes(o.status) && new Date(o.expires_at) <= new Date()
  const status: OfferStatus = expired ? 'expired' : o.status
  const req = requests.find((r) => r.id === o.requestId)!
  const tailor = tailors.find((t) => t.slug === o.tailorSlug)!
  const fee = o.delivery_fee?.amount_minor ?? 0
  const service = feeFor(o.amount.amount_minor)
  const out: Offer = {
    ...o, status, actions: expired ? [] : OFFER_NEXT[o.status][role] ?? [], tailor,
    job_request: { id: req.id, title: req.title, status: req.status },
    cost_breakdown: {
      lines: [
        { key: 'tailoring', label: 'Tailoring', amount: o.amount },
        ...(fee > 0 ? [{ key: 'delivery', label: 'Delivery', amount: money(fee) }] : []),
        { key: 'service_fee', label: 'Aṣọlà service fee', amount: money(service) },
      ],
      total: money(o.amount.amount_minor + fee + service),
      within_budget: req.budget_max ? o.amount.amount_minor <= req.budget_max.amount_minor : null,
    },
  }
  if (all) {
    const live = all.filter((x) => x.status === 'awaiting_customer' || x.status === 'awaiting_tailor')
    if (live.includes(o)) {
      const totals = live.map((x) => x.amount.amount_minor + (x.delivery_fee?.amount_minor ?? 0))
      const min = Math.min(...totals)
      const daysList = live.map((x) => x.delivery_days).filter((d): d is number => d !== null)
      const sorted = [...totals].sort((a, b) => a - b)
      const median = sorted.length % 2 ? sorted[(sorted.length - 1) / 2]! : (sorted[sorted.length / 2 - 1]! + sorted[sorted.length / 2]!) / 2
      const total = o.amount.amount_minor + (o.delivery_fee?.amount_minor ?? 0)
      const c = { badges: [] as string[], flags: [] as string[], questions: [] as string[], source: 'rules' as const }
      if (live.length > 1 && total === min) c.badges.push('Lowest price')
      if (live.length > 1 && daysList.length && o.delivery_days === Math.min(...daysList)) c.badges.push('Fastest')
      if (o.delivery_days === null) { c.flags.push('No completion time given'); c.questions.push('When will the outfit be ready?') }
      if (o.includes_fitting === null) { c.flags.push('Fitting not specified'); c.questions.push('Is a fitting session included before final delivery?') }
      if (o.alterations_included === null) { c.flags.push('Alterations not specified'); c.questions.push('How many free alterations are included if it does not fit?') }
      if (o.delivery_fee === null) { c.flags.push('Delivery cost not stated'); c.questions.push('Is delivery included, or is there a separate charge?') }
      if (live.length >= 3 && total < median * 0.6) { c.flags.push('Much lower than other offers'); c.questions.push('Can you confirm exactly what this price includes?') }
      out.comparison = c
    }
  }
  return out
}

function requireUser(role?: User['role']): User {
  if (!current) fail('Please sign in.', 401, 'unauthenticated')
  if (role && current!.role !== role) fail('You do not have access to this.', 403, 'forbidden')
  return current!
}

function score(req: JobRequest, t: Tailor) {
  const spec = t.specialties.some((s) => s.key === req.garment_category) ? 1 : 0.2
  const loc = t.service_cities.map((c) => c.toLowerCase()).includes(req.city.toLowerCase()) ? 1 : 0.1
  const portfolio = req.style_tags.length ? req.style_tags.filter((x) => t.portfolio_tags.includes(x)).length / req.style_tags.length : 0.5
  const total = spec * 30 + loc * 20 + 0.6 * 25 + 1 * 10 + 0.5 * 10 + portfolio * 5
  const reasons: string[] = []
  if (spec === 1) reasons.push(`Specialises in ${GARMENTS[req.garment_category]?.toLowerCase() ?? req.garment_category}.`)
  if (loc === 1) reasons.push(`Serves ${req.city}.`)
  reasons.push('New on Aṣọlà — limited order history so far.')
  if (portfolio > 0 && req.style_tags.length) reasons.push('Has portfolio work in a similar style.')
  return { score: Math.round(total), new_vendor: true, reasons, factors: {} }
}

export const previewApi: Api = {
  meta: () =>
    delay<Meta>({
      brand: { name: 'Aṣọlà', tagline: 'From fabric to finished outfit — your style, your budget, your choice.' },
      currency: 'NGN',
      garment_categories: Object.entries(GARMENTS).map(([key, label]) => ({ key, label })),
      fabric_categories: Object.entries(FABRIC_CATS).map(([key, label]) => ({ key, label })),
      fabric_units: ['yard', 'metre', 'piece', 'bundle'],
      colors: ['navy', 'indigo', 'gold', 'terracotta', 'emerald', 'burgundy', 'cream', 'black', 'white', 'orange', 'royal_blue', 'purple'],
      fees: { customer_service_fee_bps: FEE_BPS, customer_service_fee_cap_minor: FEE_CAP },
      offer_rules: { expiry_hours: EXPIRY_HOURS, max_counter_rounds_per_side: MAX_ROUNDS },
      features: { payments: false, ai_assistant: false },
    }),
  me: () => delay(current),
  register: async (input) => {
    if (input.password !== input.password_confirmation) fail('Passwords do not match.', 422, 'validation_failed', { password: ['The passwords do not match.'] })
    const role = input.role === 'tailor' ? 'tailor' : 'customer'
    current = users[role]!
    try { sessionStorage.setItem('asola-preview-user', role) } catch { /* ignore */ }
    return delay(current)
  },
  login: async (email) => {
    const found = Object.entries(users).find(([, u]) => u.email === email)
    if (!found) fail('Those details do not match an account.', 422, 'validation_failed', { email: ['Those details do not match an account.'] })
    current = found![1]
    try { sessionStorage.setItem('asola-preview-user', found![0]) } catch { /* ignore */ }
    return delay(current)
  },
  logout: async () => {
    current = null
    try { sessionStorage.removeItem('asola-preview-user') } catch { /* ignore */ }
  },

  fabrics: (f) => {
    let list = fabrics.filter((x) => {
      if (f.q && !`${x.name} ${x.material} ${x.description}`.toLowerCase().includes(f.q.toLowerCase())) return false
      if (f.category?.length && !f.category.includes(x.category)) return false
      if (f.color?.length && !f.color.includes(x.primary_color)) return false
      if (f.max_price !== undefined && x.price.amount_minor > f.max_price) return false
      if (f.in_stock && x.available_quantity === 0) return false
      return true
    })
    if (f.sort === 'price_asc') list = [...list].sort((a, b) => a.price.amount_minor - b.price.amount_minor)
    else if (f.sort === 'price_desc') list = [...list].sort((a, b) => b.price.amount_minor - a.price.amount_minor)
    else list = [...list].reverse()
    return delay({ data: list, meta: { current_page: 1, last_page: 1, total: list.length, per_page: 24 } })
  },
  fabric: (s) => {
    const f = fabrics.find((x) => x.slug === s)
    if (!f) fail('Not found.', 404, 'not_found')
    return delay(f!)
  },
  tailors: (f) => {
    const list = tailors.filter((t) => (!f.city || t.city === f.city) && (!f.specialty || t.specialties.some((s) => s.key === f.specialty)))
    return delay({ data: list })
  },
  tailor: (s) => {
    const t = tailors.find((x) => x.slug === s)
    if (!t) fail('Not found.', 404, 'not_found')
    return delay(t!)
  },

  saveTailorProfile: async (input) => {
    requireUser('tailor')
    const t = tailors[0]!
    Object.assign(t, {
      business_name: input.business_name, bio: input.bio, city: input.city, service_cities: input.service_cities,
      specialties: input.specialties.map((k) => ({ key: k, label: GARMENTS[k] ?? k })), portfolio_tags: input.portfolio_tags,
      starting_price: input.starting_price_minor ? money(input.starting_price_minor) : null, accepting_work: input.accepting_work,
    })
    return delay(t)
  },
  myRequests: () => {
    const u = requireUser('customer')
    const mine = requests.filter((r) => r.is_owner && u.role === 'customer').map((r) => ({
      ...r,
      offer_count: offers.filter((o) => o.requestId === r.id).length,
      live_offer_count: offers.filter((o) => o.requestId === r.id && ['awaiting_customer', 'awaiting_tailor'].includes(o.status)).length,
      order_reference: orders.find((o) => o.spec.title === r.title)?.reference ?? null,
    }))
    return delay({ data: [...mine].reverse() })
  },
  createRequest: async (input) => {
    requireUser('customer')
    const errors: Record<string, string[]> = {}
    if (input.title.trim().length < 5) errors.title = ['Give your request a title of at least 5 characters.']
    if (input.description.trim().length < 20) errors.description = ['Describe the outfit in at least 20 characters.']
    if (input.mode === 'budget' && !input.budget_max_minor) errors.budget_max_minor = ['Tell tailors your budget, or switch to open offers.']
    if (!input.city.trim()) errors.city = ['Enter the city where you want the outfit made.']
    if (!input.needed_by || input.needed_by <= days(0)) errors.needed_by = ['Choose a date in the future.']
    if (Object.keys(errors).length) fail('Please fix the highlighted fields.', 422, 'validation_failed', errors)
    const id = '01JPREVIEW' + String(Date.now()).padStart(16, '0')
    const req: JobRequest = {
      id, title: input.title, garment_category: input.garment_category, garment_label: GARMENTS[input.garment_category] ?? input.garment_category,
      description: input.description, style_tags: input.style_tags, mode: input.mode,
      budget_min: input.mode === 'open' && input.budget_min_minor ? money(input.budget_min_minor) : null,
      budget_max: input.budget_max_minor ? money(input.budget_max_minor) : null,
      fabric_status: input.fabric_status, fabric: input.fabric_id ? { slug: fabrics[input.fabric_id - 1]!.slug, name: fabrics[input.fabric_id - 1]!.name } : null,
      city: input.city, needed_by: input.needed_by, fitting_required: input.fitting_required, status: 'open',
      offers_close_at: hours(24 * 7), is_owner: true, customer: { first_name: 'Amaka' }, created_at: new Date().toISOString(),
    }
    requests.push(req)
    return delay(req)
  },
  request: (id) => {
    const u = requireUser()
    const r = requests.find((x) => x.id === id)
    if (!r) fail('Not found.', 404, 'not_found')
    const list = offers.filter((o) => o.requestId === id)
    if (u.role === 'customer') {
      const sorted = [...list].sort((a, b) => a.amount.amount_minor - b.amount.amount_minor)
      const order = orders.find((o) => o.spec.title === r!.title)
      return delay({ ...r!, offers: sorted.map((o) => viewOffer(o, 'customer', list)), order_reference: order?.reference ?? null })
    }
    const mine = list.find((o) => o.tailorUserId === u.id)
    return delay({ ...r!, is_owner: false, my_offer: mine ? viewOffer(mine, 'tailor') : null, match: score(r!, tailors[0]!) })
  },
  cancelRequest: (id) => {
    requireUser('customer')
    const r = requests.find((x) => x.id === id)
    if (!r || r.status !== 'open') fail('This request is no longer open.', 409, 'invalid_transition')
    r!.status = 'cancelled'
    offers.filter((o) => o.requestId === id && ['awaiting_customer', 'awaiting_tailor'].includes(o.status)).forEach((o) => (o.status = 'not_selected'))
    return delay(r!)
  },
  matches: (id) => {
    const r = requests.find((x) => x.id === id)!
    return delay(tailors.map((t) => ({ ...t, match: score(r, t) })).sort((a, b) => b.match.score - a.match.score))
  },

  inbox: () => {
    const u = requireUser('tailor')
    const open = requests.filter((r) => r.status === 'open')
    return delay({
      data: open.map((r) => {
        const mine = offers.find((o) => o.requestId === r.id && o.tailorUserId === u.id)
        return { ...r, is_owner: false, offer_count: offers.filter((o) => o.requestId === r.id).length, my_offer: mine ? { status: mine.status } : null, match: score(r, tailors[0]!) }
      }).sort((a, b) => b.match.score - a.match.score),
      has_profile: true,
    })
  },
  submitOffer: async (id, input) => {
    const u = requireUser('tailor')
    const r = requests.find((x) => x.id === id)
    if (!r || r.status !== 'open') fail('This request is no longer accepting offers.', 422, 'request_closed')
    if (offers.some((o) => o.requestId === id && o.tailorUserId === u.id)) fail('You already have an offer on this request. Update it instead.', 422, 'duplicate_offer')
    if (r!.mode === 'budget' && r!.budget_max && input.amount_minor !== r!.budget_max.amount_minor && (input.message ?? '').trim().length < 15)
      fail('Explain why your price differs from the customer\'s budget (at least 15 characters).', 422, 'explanation_required')
    const row: OfferRow = {
      id: offerSeq++, requestId: id, tailorSlug: tailors[0]!.slug, tailorUserId: u.id, status: 'awaiting_customer',
      amount: money(input.amount_minor), at_customer_budget: r!.mode === 'budget' && input.amount_minor === r!.budget_max?.amount_minor,
      delivery_days: input.delivery_days, includes_fitting: input.includes_fitting, alterations_included: input.alterations_included,
      delivery_fee: input.delivery_fee_minor === null ? null : money(input.delivery_fee_minor), message: input.message,
      expires_at: hours(EXPIRY_HOURS), rounds: { tailor_used: 0, customer_used: 0, max_per_side: MAX_ROUNDS }, actions: [],
      revisions: [{ author_role: 'tailor', amount: money(input.amount_minor), delivery_days: input.delivery_days, note: input.message, created_at: new Date().toISOString() }],
      created_at: new Date().toISOString(),
    }
    offers.push(row)
    return delay(viewOffer(row, 'tailor'))
  },
  counter: async (id, amount, note) => {
    requireUser('customer')
    const o = offers.find((x) => x.id === id)!
    if (o.status !== 'awaiting_customer') fail(`Offer: cannot move from '${o.status}' to 'awaiting_tailor' by customer.`, 409, 'invalid_transition')
    if (o.rounds.customer_used >= MAX_ROUNDS) fail(`Counteroffer limit reached (${MAX_ROUNDS} rounds). Accept, decline or withdraw.`, 422, 'counter_limit')
    o.status = 'awaiting_tailor'
    o.rounds.customer_used++
    o.amount = money(amount)
    o.expires_at = hours(EXPIRY_HOURS)
    o.revisions!.push({ author_role: 'customer', amount: money(amount), delivery_days: o.delivery_days, note, created_at: new Date().toISOString() })
    return delay(viewOffer(o, 'customer'))
  },
  revise: async (id, amount, deliveryDays, note) => {
    requireUser('tailor')
    const o = offers.find((x) => x.id === id)!
    const agreeing = o.status === 'awaiting_tailor' && amount === o.amount.amount_minor
    if (!agreeing) {
      if (o.rounds.tailor_used >= MAX_ROUNDS) fail(`Counteroffer limit reached (${MAX_ROUNDS} rounds). Accept, decline or withdraw.`, 422, 'counter_limit')
      o.rounds.tailor_used++
    }
    o.status = 'awaiting_customer'
    o.amount = money(amount)
    if (deliveryDays) o.delivery_days = deliveryDays
    o.expires_at = hours(EXPIRY_HOURS)
    o.revisions!.push({ author_role: 'tailor', amount: money(amount), delivery_days: o.delivery_days, note: note ?? (agreeing ? 'Agreed to your price.' : null), created_at: new Date().toISOString() })
    return delay(viewOffer(o, 'tailor'))
  },
  withdraw: async (id) => {
    requireUser('tailor')
    const o = offers.find((x) => x.id === id)!
    o.status = 'withdrawn'
    return delay(viewOffer(o, 'tailor'))
  },
  decline: async (id) => {
    requireUser('customer')
    const o = offers.find((x) => x.id === id)!
    if (o.status !== 'awaiting_customer') fail(`Offer: cannot move from '${o.status}' to 'declined' by customer.`, 409, 'invalid_transition')
    o.status = 'declined'
    return delay(viewOffer(o, 'customer'))
  },
  accept: async (id) => {
    const u = requireUser('customer')
    const o = offers.find((x) => x.id === id)!
    const r = requests.find((x) => x.id === o.requestId)!
    const existing = orders.find((x) => x.spec.title === r.title)
    if (o.status === 'accepted' && existing) return delay(existing)
    if (o.status !== 'awaiting_customer') fail(`Offer: cannot move from '${o.status}' to 'accepted' by customer.`, 409, 'invalid_transition')
    o.status = 'accepted'
    r.status = 'awarded'
    offers.filter((x) => x.requestId === r.id && x.id !== id && ['awaiting_customer', 'awaiting_tailor'].includes(x.status)).forEach((x) => (x.status = 'not_selected'))
    const fee = o.delivery_fee?.amount_minor ?? 0
    const service = feeFor(o.amount.amount_minor)
    const now = new Date().toISOString()
    const order = {
      reference: 'ASL-' + Math.random().toString(36).slice(2, 10).toUpperCase(), status: 'confirmed' as OrderStatus,
      status_label: ORDER_LABELS.confirmed, viewer_role: 'customer' as const,
      spec: {
        title: r.title, garment_category: r.garment_category, description: r.description, style_tags: r.style_tags,
        fabric_status: r.fabric_status, fabric: null, city: r.city, needed_by: r.needed_by, fitting_required: r.fitting_required,
        terms: {
          amount_minor: o.amount.amount_minor, delivery_days: o.delivery_days, includes_fitting: o.includes_fitting,
          alterations_included: o.alterations_included, delivery_fee_minor: o.delivery_fee?.amount_minor ?? null,
          message: o.message, negotiation_rounds: o.rounds.tailor_used + o.rounds.customer_used,
        },
        accepted_at: now,
      },
      spec_version: 1,
      costs: { tailoring: o.amount, delivery: money(fee), service_fee: money(service), total: money(o.amount.amount_minor + fee + service) },
      payment_status: 'unpaid', due_on: o.delivery_days ? days(o.delivery_days) : r.needed_by,
      milestones: (['confirmed', 'in_progress', 'fitting', 'ready', 'delivered', 'completed'] as OrderStatus[]).map((s) => ({ status: s, label: ORDER_LABELS[s] })),
      actions: [],
      tailor: { business_name: tailors.find((t) => t.slug === o.tailorSlug)!.business_name, slug: o.tailorSlug },
      customer: { name: u.name },
      events: [{ from: null, to: 'confirmed' as OrderStatus, label: ORDER_LABELS.confirmed, actor_role: 'customer', note: 'Offer accepted. Price and scope locked.', at: now }],
      created_at: now, customerId: u.id, tailorUserId: o.tailorUserId,
    }
    orders.push(order)
    return previewApi.order(order.reference)
  },

  orders: () => {
    const u = requireUser()
    const list = orders.filter((o) => (u.role === 'tailor' ? o.tailorUserId === u.id : o.customerId === u.id))
    return Promise.all(list.map((o) => previewApi.order(o.reference))).then((data) => ({ data: data.reverse() }))
  },
  order: (ref) => {
    const u = requireUser()
    const o = orders.find((x) => x.reference === ref)
    if (!o) fail('Not found.', 404, 'not_found')
    const role = u.id === o!.tailorUserId ? 'tailor' : 'customer'
    const actions = (ORDER_NEXT[o!.status][role] ?? []).map((s) => ({ status: s, label: ORDER_LABELS[s], requires_reason: s === 'cancelled' || s === 'disputed' }))
    const view: Order = { ...o!, viewer_role: role, actions }
    if (role === 'tailor') {
      const commission = Math.floor((o!.costs.tailoring.amount_minor * 1000 + 5000) / 10_000)
      view.tailor_payout = { gross: o!.costs.tailoring, commission: money(commission), net: money(o!.costs.tailoring.amount_minor - commission) }
    }
    return delay(view)
  },
  transition: async (ref, status, note) => {
    const u = requireUser()
    const o = orders.find((x) => x.reference === ref)!
    const role = u.id === o.tailorUserId ? 'tailor' : 'customer'
    if (!(ORDER_NEXT[o.status][role] ?? []).includes(status)) fail(`Order: cannot move from '${o.status}' to '${status}' by ${role}.`, 409, 'invalid_transition')
    if ((status === 'cancelled' || status === 'disputed') && (note ?? '').trim().length < 10) fail('Please give a reason (at least 10 characters).', 422, 'reason_required')
    o.events!.push({ from: o.status, to: status, label: ORDER_LABELS[status], actor_role: role, note, at: new Date().toISOString() })
    o.status = status
    o.status_label = ORDER_LABELS[status]
    return previewApi.order(ref)
  },
}

/** Lets the preview banner switch who you are, so both sides of a negotiation can be reviewed. */
export function previewSwitchUser(role: 'customer' | 'tailor' | null) {
  current = role ? users[role]! : null
  try {
    if (role) sessionStorage.setItem('asola-preview-user', role)
    else sessionStorage.removeItem('asola-preview-user')
  } catch {
    /* ignore */
  }
}
