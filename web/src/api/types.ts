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
}

export interface KeyLabel {
  key: string
  label: string
}

export interface Meta {
  brand: { name: string; tagline: string }
  currency: string
  garment_categories: KeyLabel[]
  fabric_categories: KeyLabel[]
  fabric_units: string[]
  colors: string[]
  fees: { customer_service_fee_bps: number; customer_service_fee_cap_minor: number }
  offer_rules: { expiry_hours: number; max_counter_rounds_per_side: number }
  features: { payments: boolean; ai_assistant: boolean }
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
