// Mirrors the Laravel API resources in app/Http/Resources. Keep in sync with docs/API.md.

export interface MoneyValue {
  amount_minor: number
  currency: string
  formatted: string
}

export type Role = 'customer' | 'tailor' | 'fabric_vendor' | 'admin'

export interface User {
  id: number
  name: string
  email: string
  phone: string | null
  role: Role
  city: string | null
  has_tailor_profile?: boolean
  has_vendor_profile?: boolean
  whatsapp_opt_in?: boolean
  currency?: string | null
}

export interface KeyLabel {
  key: string
  label: string
}

export interface Meta {
  brand: { name: string; tagline: string; campaign_image?: string | null; campaign_caption?: string | null }
  currency: string
  garment_categories: KeyLabel[]
  occasions: KeyLabel[]
  support: { email?: string; whatsapp?: string }
  fabric_categories: KeyLabel[]
  fabric_units: string[]
  colors: string[]
  fees: { customer_service_fee_bps: number; customer_service_fee_cap_minor: number }
  offer_rules: { expiry_hours: number; max_counter_rounds_per_side: number }
  shop?: {
    service_fee_bps: number
    service_fee_cap_minor: number
    delivery_fee_per_vendor_minor: number
    checkout_hold_minutes: number
    offer_floor_bps: number
    offer_response_hours: number
    offer_hold_hours: number
    offer_max_rounds: number
  }
  diaspora?: {
    rates: Record<string, number>
    rates_updated: string | null
    ship_countries: { code: string; fee_minor: number }[]
  }
  features: { payments: boolean; ai_assistant: boolean; disputes: boolean; whatsapp?: boolean; try_on?: boolean }
}

export interface Fabric {
  id: number
  slug: string
  name: string
  category: string
  category_label: string
  description: string | null
  primary_color: string
  colors: string[]
  pattern: string | null
  material: string | null
  unit: string
  price: MoneyValue
  available_quantity: number
  stock_status: 'in_stock' | 'low_stock' | 'out_of_stock'
  min_order_quantity: number
  image_url: string | null
  is_demo: boolean
  vendor?: { business_name: string; slug: string; city: string; market: string | null; verified: boolean }
  purchase_mode: 'contact_vendor' | 'checkout'
  /** Lowest price per unit a buyer may offer. */
  offer_floor?: MoneyValue
  /** Quick-pick offer prices, highest first. */
  offer_suggestions?: MoneyValue[]
  /** Real photos and a short video of the actual bolt, from the vendor. */
  media?: FabricMedia[]
  has_video?: boolean
}

export interface FabricMedia {
  id: number
  kind: 'photo' | 'video'
  url: string
  mime: string
}

export interface MatchResult {
  score: number
  new_vendor: boolean
  reasons: string[]
  factors: Record<string, number>
}

export interface Tailor {
  slug: string
  business_name: string
  bio: string | null
  city: string
  service_cities: string[]
  specialties: KeyLabel[]
  portfolio_tags: string[]
  starting_price: MoneyValue | null
  accepting_work: boolean
  verified: boolean
  track_record: { completed_orders: number; on_time_rate: number | null; is_new: boolean }
  is_demo: boolean
  match?: MatchResult
}

export type OfferStatus =
  | 'awaiting_customer'
  | 'awaiting_tailor'
  | 'accepted'
  | 'declined'
  | 'withdrawn'
  | 'expired'
  | 'not_selected'

export interface OfferRevision {
  author_role: 'tailor' | 'customer'
  amount: MoneyValue
  delivery_days: number | null
  note: string | null
  created_at: string
}

export interface CostLine {
  key: string
  label: string
  amount: MoneyValue
}

export interface Offer {
  id: number
  status: OfferStatus
  amount: MoneyValue
  at_customer_budget: boolean
  delivery_days: number | null
  includes_fitting: boolean | null
  alterations_included: number | null
  delivery_fee: MoneyValue | null
  message: string | null
  expires_at: string
  rounds: { tailor_used: number; customer_used: number; max_per_side: number }
  actions: OfferStatus[]
  tailor?: Tailor | { business_name: string }
  job_request?: { id: string; title: string; status: string }
  revisions?: OfferRevision[]
  comparison?: { badges: string[]; flags: string[]; questions: string[]; source: 'rules' }
  cost_breakdown?: { lines: CostLine[]; total: MoneyValue; within_budget: boolean | null }
  created_at: string
}

export type PricingMode = 'budget' | 'open'
export type FabricStatus = 'own_fabric' | 'buying_on_platform' | 'need_advice'

export interface JobRequest {
  id: string
  title: string
  garment_category: string
  garment_label: string
  occasion: string | null
  occasion_label: string | null
  description: string
  style_tags: string[]
  mode: PricingMode
  budget_min: MoneyValue | null
  budget_max: MoneyValue | null
  fabric_status: FabricStatus
  fabric?: { slug: string; name: string } | null
  city: string
  needed_by: string
  fitting_required: boolean
  status: 'open' | 'awarded' | 'cancelled' | 'expired'
  offers_close_at: string
  is_owner: boolean
  customer: { first_name: string }
  offer_count?: number
  live_offer_count?: number
  offers?: Offer[]
  order_reference?: string | null
  match?: MatchResult
  my_offer?: Offer | { status: OfferStatus } | null
  created_at: string
}

export interface NewJobRequest {
  title: string
  garment_category: string
  occasion: string | null
  description: string
  style_tags: string[]
  mode: PricingMode
  budget_max_minor: number | null
  budget_min_minor: number | null
  fabric_status: FabricStatus
  fabric_id: number | null
  city: string
  needed_by: string
  fitting_required: boolean
}

export interface NewOffer {
  amount_minor: number
  delivery_days: number
  includes_fitting: boolean | null
  alterations_included: number | null
  delivery_fee_minor: number | null
  message: string | null
}

export type OrderStatus =
  | 'confirmed'
  | 'in_progress'
  | 'fitting'
  | 'ready'
  | 'delivered'
  | 'completed'
  | 'disputed'
  | 'cancelled'

export interface Order {
  reference: string
  offer_id: number
  /** The customer's saved measurements, in cm — shown to the hired tailor if the customer allows. */
  measurements?: { values: Record<string, number>; notes: string | null; labels: Record<string, string> } | null
  status: OrderStatus
  status_label: string
  viewer_role: 'customer' | 'tailor' | 'admin' | null
  spec: {
    title: string
    garment_category: string
    description: string
    style_tags: string[]
    fabric_status: FabricStatus
    fabric: { id: number; name: string } | null
    city: string
    needed_by: string
    fitting_required: boolean
    terms: {
      amount_minor: number
      delivery_days: number | null
      includes_fitting: boolean | null
      alterations_included: number | null
      delivery_fee_minor: number | null
      message: string | null
      negotiation_rounds: number
    }
    accepted_at: string
  }
  spec_version: number
  costs: { tailoring: MoneyValue; delivery: MoneyValue; service_fee: MoneyValue; total: MoneyValue }
  tailor_payout?: { gross: MoneyValue; commission: MoneyValue; net: MoneyValue }
  payment_status: string
  due_on: string | null
  milestones: { status: OrderStatus; label: string }[]
  actions: { status: OrderStatus; label: string; requires_reason: boolean }[]
  tailor?: { business_name: string; slug?: string }
  customer?: { name: string; phone?: string | null; city?: string | null }
  events?: { from: string | null; to: OrderStatus; label: string; actor_role: string; note: string | null; at: string }[]
  created_at: string
}

export interface Paginated<T> {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number; per_page: number }
}

export interface FabricFilters {
  q?: string
  category?: string[]
  color?: string[]
  max_price?: number
  in_stock?: boolean
  sort?: 'newest' | 'price_asc' | 'price_desc'
}

export interface TailorProfileInput {
  business_name: string
  bio: string | null
  city: string
  service_cities: string[]
  specialties: string[]
  portfolio_tags: string[]
  weekly_capacity: number
  starting_price_minor: number | null
  accepting_work: boolean
}

// ---- Messages and vendor contact ----

export interface OfferMessage {
  id: number
  author_role: 'customer' | 'tailor'
  mine: boolean
  body: string
  created_at: string
}

export interface VendorContact {
  business_name: string
  market: string | null
  city: string
  phone: string | null
  whatsapp: string | null
  note: string | null
  purchase_mode: 'contact_vendor'
  protected_by_osaps: boolean
}

// ---- AI-assisted features. "source" always says whether AI or rules produced the content. ----

export interface AiFabricSearch {
  source: 'ai' | 'keywords'
  understood: string
  criteria: { categories: string[]; colors: string[]; max_price_minor: number | null; in_stock_only: boolean; terms: string[]; occasion: string | null }
  relaxed: string[]
  notice: string | null
  results: Fabric[]
}

export interface StylistChoice {
  value: string | number
  label: string
}

export interface StylistQuestion {
  slot: 'occasion' | 'garment' | 'colors' | 'budget_naira' | 'needs' | 'timing'
  text: string
  choices: StylistChoice[]
  multiple: boolean
  allow_text: boolean
}

export interface StylistKnown {
  occasion?: string
  garment?: string
  colors?: string[]
  colors_open?: boolean
  budget_naira?: number
  needs?: string
  timing?: string
  preferences?: string
}

export interface BudgetLine {
  key: string
  label: string
  amount: MoneyValue
  basis: string
}

export interface RequestDraft {
  garment_category: string
  occasion: string | null
  occasion_label?: string | null
  title: string
  description: string
  style_tags: string[]
  mode: PricingMode
  fabric_status: FabricStatus
  fabric_id: number | null
}

export interface StylistResult {
  source: 'ai' | 'guided'
  notice: string | null
  concept: {
    title: string
    summary: string
    silhouette: string
    colour_direction: string
    fabric_characteristics: string
    finishing_details: string
    why?: string
    questions_for_tailor: string[]
    style_tags: string[]
  }
  fabrics: Fabric[]
  tailors: Tailor[]
  budget: { lines: BudgetLine[]; missing: string[]; total: MoneyValue | null; budget: MoneyValue | null; within_budget: boolean | null; note: string | null }
  request_draft: RequestDraft
}

export interface StylistTurn {
  source: 'ai' | 'guided'
  notice: string | null
  known: StylistKnown
  next_question: StylistQuestion | null
  result: StylistResult | null
}

export interface FabricIdea {
  title: string
  garment_category: string
  occasion: string | null
  description: string
  styling_note: string | null
}

export interface InspirationBriefResult {
  source: 'ai'
  garment_category: string | null
  title: string
  summary: string
  silhouette: string | null
  neckline: string | null
  sleeves: string | null
  fabric_appearance: string | null
  embellishments: string[]
  colors: string[]
  aesthetic: string | null
  confidence: 'low' | 'medium' | 'high'
  caveat: string
}

// ---------------------------------------------------------------- Fabric shop

export type PriceOfferStatus = 'awaiting_vendor' | 'awaiting_buyer' | 'accepted' | 'declined' | 'withdrawn' | 'expired' | 'used'
export type PriceOfferAction = 'accept' | 'decline' | 'counter' | 'withdraw' | 'add_to_cart'

export interface PriceOffer {
  id: number
  status: PriceOfferStatus
  status_label: string
  your_side: 'buyer' | 'vendor'
  your_turn: boolean
  actions: PriceOfferAction[]
  quantity: number
  list_unit: MoneyValue
  unit: MoneyValue
  buyer_unit: MoneyValue
  vendor_unit: MoneyValue | null
  floor_unit: MoneyValue
  total: MoneyValue
  saving: MoneyValue
  rounds_left: number
  note: string | null
  respond_by: string | null
  hold_until: string | null
  created_at: string | null
  fabric?: {
    id: number
    slug: string
    name: string
    unit: string
    category_label: string
    colors: string[]
    pattern: string | null
    image_url: string | null
    vendor: { business_name: string; market: string | null; city: string } | null
  }
  buyer_name: string | null
  history?: { by: 'buyer' | 'vendor' | 'system'; action: string; unit: MoneyValue | null; at: string | null }[]
}

export interface CartItemInput {
  fabric_id: number
  quantity: number
  price_offer_id?: number | null
}

export type Fulfilment = 'delivery' | 'pickup' | 'arrival' | 'international'

export interface ShopProblem {
  fabric_id: number
  code: string
  message: string
}

export interface QuoteLine {
  fabric_id: number
  name: string
  unit: string
  quantity: number
  unit_price: MoneyValue
  list_unit_price: MoneyValue
  line_total: MoneyValue
  saving: MoneyValue
  price_offer_id: number | null
}

export interface ShopQuote {
  ok: boolean
  vendors: { vendor_id: number; vendor_name: string; subtotal: MoneyValue; delivery: MoneyValue; total: MoneyValue; lines: QuoteLine[] }[]
  subtotal: MoneyValue
  delivery: MoneyValue
  service_fee: MoneyValue
  total: MoneyValue
  blocking: ShopProblem[]
  notices: ShopProblem[]
}

export interface CheckoutInput {
  items: CartItemInput[]
  fulfilment: Fulfilment
  expected_total_minor: number
  contact: { name: string; phone: string }
  address?: { line1: string; line2?: string; city: string; state?: string; postcode?: string; landmark?: string } | null
  country?: string | null
  arrival_date?: string | null
}

export type FabricOrderStatus = 'pending_payment' | 'paid' | 'packed' | 'out_for_delivery' | 'ready_for_pickup' | 'delivered' | 'completed' | 'cancelled'

export type PayoutStatus = 'held' | 'releasable' | 'approved' | 'paid' | 'withheld'

export type DisputeReason = 'not_received' | 'wrong_item' | 'damaged' | 'short' | 'not_as_described' | 'other'

export const DISPUTE_REASONS: Record<DisputeReason, string> = {
  not_received: 'I have not received it',
  wrong_item: 'Wrong fabric or colour',
  damaged: 'Damaged or faulty',
  short: 'Less than I paid for',
  not_as_described: 'Not as described',
  other: 'Something else',
}

export interface OrderDispute {
  can_open: boolean
  status: 'open' | 'refunded' | 'released' | null
  reason: DisputeReason | null
  reason_label: string | null
  details: string | null
  opened_at: string | null
  resolved_at: string | null
  resolution_note: string | null
}

export interface OrderRefund {
  id: number
  amount: MoneyValue
  status: 'pending' | 'processed' | 'failed'
  label: string
  failure: string | null
  at: string | null
}

export interface FabricOrder {
  reference: string
  checkout_reference: string | null
  status: FabricOrderStatus
  status_label: string
  fulfilment: Fulfilment
  milestones: { status: FabricOrderStatus; label: string }[]
  next: { status: FabricOrderStatus; label: string; needs_code?: boolean }[]
  /** Only the buyer sees this, only while the fabric is on its way. */
  delivery_code?: string | null
  delivery_code_locked?: boolean
  delivered_by?: 'buyer' | 'code' | 'admin' | null
  protection_days?: number
  dispute?: OrderDispute
  refunds?: OrderRefund[]
  payout_label?: string | null
  subtotal: MoneyValue
  delivery: MoneyValue
  service_fee: MoneyValue | null
  total: MoneyValue | null
  vendor_payout: MoneyValue | null
  vendor?: { business_name: string; market: string | null; city: string }
  deliver_to: { name: string | null; phone: string | null; address: Record<string, string> | null } | null
  payout_status?: PayoutStatus | null
  aso_ebi?: { code: string; title: string } | null
  ship?: { country: string | null; arrival_date: string | null } | null
  items?: {
    fabric_id: number
    slug: string | null
    name: string
    unit: string
    quantity: number
    unit_price: MoneyValue
    list_unit_price: MoneyValue
    line_total: MoneyValue
    negotiated: boolean
    colors: string[] | null
    pattern: string | null
  }[]
  events?: { to: FabricOrderStatus; label: string; by: string; note: string | null; at: string | null }[]
  created_at: string | null
}

export interface Checkout {
  reference: string
  payment_status: 'pending' | 'paid' | 'expired' | 'cancelled' | 'needs_refund'
  fulfilment: Fulfilment
  subtotal: MoneyValue
  delivery: MoneyValue
  service_fee: MoneyValue
  total: MoneyValue
  expires_at: string | null
  paid_at: string | null
  authorization_url?: string
  orders?: FabricOrder[]
}

export interface VendorListing extends Fabric {
  status: 'active' | 'draft' | 'archived'
  stock_quantity: number
  reserved_quantity: number
}

export interface VendorProfileInput {
  business_name: string
  city: string
  market?: string | null
  phone?: string | null
  whatsapp?: string | null
  bank_name?: string | null
  bank_account_number?: string | null
  bank_account_name?: string | null
}

export interface NewListing {
  name: string
  category: string
  description?: string | null
  primary_color: string
  colors?: string[]
  pattern?: string | null
  material?: string | null
  unit: string
  price_minor: number
  stock_quantity: number
  min_order_quantity?: number
  status?: 'active' | 'draft'
}

// ---------------------------------------------------------------- Aṣọ-ẹbí, account, live, try-on

export interface AsoEbiGroup {
  code: string
  title: string
  message: string | null
  event_date: string
  deadline_at: string
  status: 'open' | 'closed' | 'cancelled'
  yards_per_guest: number
  target_guests: number
  fulfilment: 'deliver_each' | 'pickup_organizer'
  unit_price: MoneyValue
  list_unit_price: MoneyValue
  deal: { unit_price: MoneyValue; quantity: number; left: number | null } | null
  share_price: MoneyValue
  progress: { paid: number; target: number; percent: number }
  yards_paid: number
  paid_names: string[]
  organizer_name: string
  is_organizer: boolean
  my_share?: { status: 'pending' | 'paid' | 'cancelled'; yards: number } | null
  guests?: { name: string; yards: number; status: string }[]
  fabric: Fabric
}

export interface NewAsoEbi {
  title: string
  message?: string | null
  event_date: string
  deadline_at: string
  yards_per_guest: number
  target_guests: number
  fulfilment: 'deliver_each' | 'pickup_organizer'
  organizer_phone: string
  price_offer_id?: number | null
}

export interface JoinAsoEbi {
  yards: number
  show_name: boolean
  expected_total_minor: number
  contact: { name: string; phone: string }
  address?: { line1: string; line2?: string; city: string; state: string; landmark?: string } | null
}

export interface AppNotification {
  id: number
  kind: string
  title: string
  body: string | null
  link: string | null
  read: boolean
  at: string | null
}

export interface Measurements {
  unit: 'cm' | 'in'
  values: Record<string, number>
  notes: string | null
  share_with_tailors: boolean
  updated_at: string | null
  fields: { key: string; label: string }[]
}

export interface LiveSession {
  id: number
  title: string
  platform: 'tiktok' | 'instagram' | 'youtube' | 'facebook'
  url: string
  youtube_id: string | null
  starts_at: string
  ends_at: string | null
  is_live: boolean
  vendor: { business_name: string | null; market: string | null; city: string | null }
  fabrics: Fabric[]
}

export interface TryOnResult {
  image: string
  notice: string
  left_today: number
}

export interface VendorProfile {
  business_name: string
  slug: string
  city: string
  market: string | null
  phone: string | null
  whatsapp: string | null
  verified: boolean
  verification?: { status: VendorVerificationStatus; label: string; note: string | null; missing: string[] }
  bank_name: string | null
  bank_account_name: string | null
  bank_account_last4: string | null
}

/** A cart line saved to the account, with enough to draw it on another device. */
export interface SavedCartLine {
  fabric_id: number
  quantity: number
  price_offer_id: number | null
  fabric: { slug: string; name: string; unit: string; colors: string[]; pattern: string | null; image_url: string | null; vendor_name: string | null; list_minor: number; min: number; available: number }
  offer: { unit_minor: number; quantity: number } | null
}

export type VendorVerificationStatus = 'unverified' | 'pending' | 'verified' | 'rejected' | 'suspended'

// ---- Back office -------------------------------------------------------------------------

export interface AdminOverview {
  vendors_pending: number
  disputes_open: number
  refunds_owed: number
  refunds_unconfirmed: number
  payouts_releasable: number
  payouts_approved: number
  stuck: number
  two_person_payouts: boolean
  cron_last_run: string | null
  queue: string
  queued_jobs: number | null
  payments_enabled: boolean
  mail: string
}

export interface AdminVendor {
  id: number
  business_name: string
  city: string | null
  market: string | null
  phone: string | null
  whatsapp: string | null
  owner: { name: string | null; email: string | null; joined: string | null }
  bank: { name: string | null; account_name: string | null; account_number: string | null }
  fabrics: number
  status: VendorVerificationStatus
  label: string
  note: string | null
  requested_at: string | null
  is_demo: boolean
}

export interface AdminOrderRow {
  reference: string
  status: FabricOrderStatus
  status_label: string
  fulfilment: Fulfilment
  total: MoneyValue
  vendor: { business_name: string | null; phone: string | null } | null
  buyer: { name: string | null; phone: string | null; email: string | null } | null
  items: string | null
  payout_status: PayoutStatus | null
  created_at: string | null
  reason?: string
  details?: string | null
  opened_at?: string | null
  remaining?: MoneyValue
  checkout_status?: string
  since?: string | null
}

export interface AdminRefund {
  id: number
  order: string | null
  checkout: string | null
  amount: MoneyValue
  status: 'pending' | 'processed' | 'failed'
  label: string
  failure: string | null
  reason: string
  at: string | null
}

export interface AdminPayoutGroup {
  vendor: { id: number | null; business_name: string | null; verified: boolean; bank_name: string | null; bank_account_name: string | null; bank_account_number: string | null }
  amount: MoneyValue
  orders: { reference: string; completed_at: string | null; amount: MoneyValue; payout_status: PayoutStatus; approved_by_me: boolean; approved_at: string | null }[]
}

export interface AdminActionRow {
  id: number
  admin: string | null
  action: string
  subject: string
  note: string | null
  at: string | null
}
