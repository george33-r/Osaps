import type { Announcement, AnnouncementAudience, TrendingFabric, AdminQuestion, AdminReviewQueue, Moment, MomentRing, MyQuestion, OrderReviewState, PublishedQuestion, ReviewInput, Story, TailorOfWeek, TailorReview, TailorReviewsState } from './types'
import type {
  FabricFilters,
  Fabric,
  JobRequest,
  Meta,
  NewJobRequest,
  NewOffer,
  Offer,
  Order,
  OrderStatus,
  Paginated,
  Tailor,
  TailorProfileInput,
  User,
  AiFabricSearch,
  StylistKnown,
  StylistTurn,
  FabricIdea,
  InspirationBriefResult,
  OfferMessage,
  VendorContact,
  PriceOffer,
  CartItemInput,
  Fulfilment,
  ShopQuote,
  CheckoutInput,
  Checkout,
  FabricOrder,
  DisputeReason,
  AdminOverview,
  AdminVendor,
  AdminOrderRow,
  AdminRefund,
  AdminPayoutGroup,
  AdminActionRow,
  VendorVerificationStatus,
  SavedCartLine,
  DesignPhoto,
  FabricOrderStatus,
  VendorListing,
  VendorProfileInput,
  NewListing,
  AsoEbiGroup,
  NewAsoEbi,
  JoinAsoEbi,
  AppNotification,
  Measurements,
  LiveSession,
  TryOnResult,
  VendorProfile,
  TailorDocKind,
  TailorVerificationState,
  VendorDocKind,
  VendorDocumentState,
  AdminTailorQueueRow,
  AdminTailorDirectoryRow,
} from './types'

/** One error shape for every failure: what the server said, or what the network did. */
export class ApiError extends Error {
  constructor(
    message: string,
    public readonly status: number,
    public readonly code: string,
    public readonly fieldErrors: Record<string, string[]> = {},
  ) {
    super(message)
  }

  field(name: string): string | undefined {
    return this.fieldErrors[name]?.[0]
  }
}

/** The full API surface the UI uses. Implemented over HTTP and, for design review only, in memory. */
export interface Api {
  meta(): Promise<Meta>
  me(): Promise<User | null>
  register(input: { name: string; email: string; password: string; password_confirmation: string; role: string; city?: string }): Promise<User>
  login(email: string, password: string): Promise<User>
  logout(): Promise<void>

  fabrics(filters: FabricFilters): Promise<Paginated<Fabric>>
  fabric(slug: string): Promise<Fabric>
  tailors(filters: { city?: string; specialty?: string }): Promise<Paginated<Tailor>>
  tailor(slug: string): Promise<Tailor>
  saveTailorProfile(input: TailorProfileInput): Promise<Tailor>
  /** Admin: create a tailor's profile for them, for a tailor account that has none. */
  adminCreateTailorProfile(userId: number, input: TailorProfileInput): Promise<Tailor>
  /** The signed-in tailor's identity checks: documents, what is missing, and the status. */
  tailorVerification(): Promise<TailorVerificationState | null>
  /** Uploads one document. ID and selfie need consent; the server refuses them without it. */
  uploadTailorDocument(kind: TailorDocKind, file: File, consent: boolean, expiresOn?: string | null): Promise<{ kind: string; status: string }>
  uploadTailorPhoto(file: File): Promise<{ photo_url: string }>
  submitTailorVerification(): Promise<{ status: string }>
  adminTailorQueue(status: string): Promise<AdminTailorQueueRow[]>
  adminReviewTailorDocument(id: number, decision: 'approved' | 'rejected', note?: string | null): Promise<{ id: number; status: string }>
  adminReviewTailorProfile(id: number, to: 'verified' | 'rejected' | 'suspended', note?: string | null): Promise<{ id: number; status: string }>
  adminTailorDirectory(status?: string): Promise<AdminTailorDirectoryRow[]>
  /** Reviews: the customer's review of one order, and whether it can still be written. */
  orderReview(reference: string): Promise<OrderReviewState>
  submitOrderReview(reference: string, input: ReviewInput, photos: File[]): Promise<TailorReview>
  correctReview(id: number, input: ReviewInput): Promise<TailorReview>
  appealReview(id: number, reason: string): Promise<{ id: number; status: string }>
  reportReview(id: number, reason: string, details: string | null): Promise<{ id: number; status: string }>
  respondToReview(id: number, body: string): Promise<{ id: number; body: string }>
  tailorReviews(slug: string): Promise<TailorReviewsState>
  myTailorReviews(): Promise<TailorReviewsState>
  adminReviewQueue(): Promise<AdminReviewQueue>
  adminModerateReview(id: number, to: 'published' | 'hidden', note: string | null): Promise<{ id: number; status: string }>
  adminDecideReport(id: number, decision: 'upheld' | 'dismissed', note: string | null): Promise<{ id: number; status: string }>
  adminDecideAppeal(id: number, decision: 'restored' | 'upheld', note: string | null): Promise<{ id: number; status: string }>
  adminDecidePhoto(id: number, decision: 'approved' | 'rejected'): Promise<{ id: number; status: string }>
  /** Home page stories. Writes send FormData so an optional photo can go with them. */
  stories(): Promise<Story[]>
  myStories(): Promise<Story[]>
  createMyStory(form: FormData): Promise<Story>
  updateMyStory(id: number, form: FormData): Promise<Story>
  deleteMyStory(id: number): Promise<{ deleted: boolean }>
  adminStories(status?: string): Promise<Story[]>
  adminCreateStory(form: FormData): Promise<Story>
  adminUpdateStory(id: number, form: FormData): Promise<Story>
  adminSetStoryStatus(id: number, status: 'published' | 'hidden', note: string | null): Promise<{ id: number; status: string }>
  adminDeleteStory(id: number): Promise<{ deleted: boolean }>
  /** Community: tailor of the week, saved stories, questions for tailors, moments. */
  tailorOfWeek(): Promise<TailorOfWeek | null>
  adminSetTailorOfWeek(tailorProfileId: number, reason: string): Promise<{ tailor: string; source: string }>
  savedStories(): Promise<Story[]>
  savedStoryIds(): Promise<number[]>
  saveStory(id: number): Promise<{ saved: boolean }>
  unsaveStory(id: number): Promise<{ saved: boolean }>
  moments(): Promise<MomentRing[]>
  myMoments(): Promise<Moment[]>
  postMoment(form: FormData): Promise<Moment>
  deleteMoment(id: number): Promise<{ deleted: boolean }>
  publicQuestions(): Promise<PublishedQuestion[]>
  myQuestions(): Promise<MyQuestion[]>
  askQuestion(question: string): Promise<{ id: number; status: string }>
  openQuestions(): Promise<{ id: number; question: string }[]>
  answerQuestion(id: number, answer: string): Promise<{ id: number; status: string }>
  adminQuestions(status: string): Promise<AdminQuestion[]>
  adminApproveQuestion(id: number): Promise<{ id: number; status: string }>
  adminHideQuestion(id: number, note: string): Promise<{ id: number; status: string }>
  adminPublishQuestion(id: number): Promise<{ id: number; status: string; story_id: number | null }>
  adminMoments(status: string): Promise<Moment[]>
  adminMomentStatus(id: number, status: 'published' | 'hidden', note: string | null): Promise<{ id: number; status: string }>
  adminOverrideTailor(profileId: number, note: string): Promise<{ id: number; status: string }>
  adminBulkMailTailors(userIds: number[], subject: string, body: string): Promise<{ queued: number }>
  adminUploadTailorDocument(profileId: number, kind: string, file: File, tailorAgreed: boolean, how: string, expiresOn?: string | null): Promise<{ id: number; kind: string; status: string }>
  adminSetTailorPhoto(profileId: number, file: File): Promise<{ photo_url: string }>
  vendorDocuments(): Promise<VendorDocumentState | null>
  /** Uploads one shop document. The owner's ID needs consent; the server refuses it without. */
  uploadVendorDocument(kind: VendorDocKind, file: File, consent: boolean, expiresOn?: string | null): Promise<{ kind: string; status: string }>
  adminReviewVendorDocument(id: number, decision: 'approved' | 'rejected', note?: string | null): Promise<{ id: number; status: string }>

  myRequests(): Promise<Paginated<JobRequest>>
  createRequest(input: NewJobRequest): Promise<JobRequest>
  request(id: string): Promise<JobRequest>
  cancelRequest(id: string): Promise<JobRequest>
  matches(id: string): Promise<Tailor[]>

  inbox(): Promise<{ data: JobRequest[]; has_profile: boolean }>
  submitOffer(requestId: string, input: NewOffer): Promise<Offer>
  counter(offerId: number, amountMinor: number, note: string | null): Promise<Offer>
  revise(offerId: number, amountMinor: number, deliveryDays: number | null, note: string | null): Promise<Offer>
  withdraw(offerId: number): Promise<Offer>
  decline(offerId: number): Promise<Offer>
  /** Sends the terms the customer saw; the server refuses with `offer_changed` if the tailor changed them. */
  accept(offerId: number, seen: { amount_minor: number; delivery_fee_minor: number | null }): Promise<Order>

  messages(offerId: number): Promise<OfferMessage[]>
  sendMessage(offerId: number, body: string, photo?: File | null): Promise<OfferMessage>
  addDesignPhoto(requestId: string, photo: File, caption?: string | null): Promise<DesignPhoto>
  removeDesignPhoto(requestId: string, photoId: number): Promise<void>
  /** Checkout without a sign-up form; refused with code account_exists if the email is registered. */
  quickAccount(input: { name: string; email: string; phone: string }): Promise<User>
  vendorContact(slug: string): Promise<VendorContact>

  aiSearch(q: string): Promise<AiFabricSearch>
  stylist(known: StylistKnown, message?: string | null, slot?: string | null): Promise<StylistTurn>
  fabricIdeas(slug: string): Promise<{ source: 'ai' | 'guide'; notice: string | null; ideas: FabricIdea[] }>
  inspiration(image: File): Promise<InspirationBriefResult>

  orders(): Promise<Paginated<Order>>
  order(reference: string): Promise<Order>
  transition(reference: string, status: OrderStatus, note: string | null): Promise<Order>

  // Fabric shop — inDrive-style price offers
  makePriceOffer(slug: string, quantity: number, unitPriceMinor: number, note?: string | null): Promise<PriceOffer>
  myPriceOffers(): Promise<PriceOffer[]>
  vendorPriceOffers(): Promise<{ data: PriceOffer[]; is_vendor: boolean }>
  priceOffer(id: number): Promise<PriceOffer>
  /** Sends the price the user saw; refused with `offer_changed` if the other side just changed it. */
  acceptPriceOffer(id: number, expectedUnitMinor: number): Promise<PriceOffer>
  counterPriceOffer(id: number, unitPriceMinor: number): Promise<PriceOffer>
  declinePriceOffer(id: number): Promise<PriceOffer>
  withdrawPriceOffer(id: number): Promise<PriceOffer>

  // Fabric shop — cart, checkout, orders
  quote(items: CartItemInput[], fulfilment: Fulfilment, country?: string | null, state?: string | null, express?: boolean): Promise<ShopQuote>
  createCheckout(input: CheckoutInput): Promise<Checkout>
  checkout(reference: string): Promise<Checkout>
  verifyCheckout(reference: string): Promise<Checkout>
  cancelCheckout(reference: string): Promise<Checkout>
  fabricOrders(): Promise<FabricOrder[]>
  fabricOrder(reference: string): Promise<FabricOrder>
  transitionFabricOrder(reference: string, status: FabricOrderStatus, note?: string | null, code?: string | null): Promise<FabricOrder>
  reportProblem(reference: string, reason: DisputeReason, details: string): Promise<FabricOrder>

  // Password reset (the answer is the same whether or not the email has an account)
  forgotPassword(email: string): Promise<string>
  resetPassword(input: { token: string; email: string; password: string; password_confirmation: string }): Promise<string>

  // Cart saved to the account
  savedCart(): Promise<SavedCartLine[]>
  saveCart(items: CartItemInput[]): Promise<SavedCartLine[]>

  // Back office (admins only)
  adminOverview(): Promise<AdminOverview>
  adminVendors(status: VendorVerificationStatus | 'all'): Promise<AdminVendor[]>
  adminReviewVendor(id: number, to: 'verified' | 'rejected' | 'suspended', note: string | null): Promise<void>
  adminDisputes(): Promise<AdminOrderRow[]>
  adminResolveDispute(reference: string, resolution: 'refund' | 'release', note: string): Promise<void>
  adminRefunds(): Promise<{ owed: AdminOrderRow[]; recent: AdminRefund[] }>
  adminRefund(reference: string, reason: string, amountMinor?: number | null): Promise<{ id: number; status: string; failure: string | null }>
  adminMarkRefundFailed(id: number, note: string): Promise<void>
  adminPayouts(): Promise<{ data: AdminPayoutGroup[]; two_person: boolean }>
  adminApprovePayout(reference: string): Promise<void>
  adminMarkPaid(reference: string, transferReference: string): Promise<void>
  adminStuck(): Promise<AdminOrderRow[]>
  adminActions(): Promise<AdminActionRow[]>

  // Vendor workspace
  vendorFabricOrders(): Promise<{ data: FabricOrder[]; is_vendor: boolean }>
  vendorListings(): Promise<VendorListing[]>
  updateListing(id: number, patch: { price_minor?: number; stock_quantity?: number; status?: 'active' | 'draft' | 'archived' }): Promise<VendorListing>
  createListing(input: NewListing): Promise<VendorListing>
  saveVendorProfile(input: VendorProfileInput): Promise<VendorProfile>
  vendorProfile(): Promise<VendorProfile>
  addListingMedia(fabricId: number, file: File): Promise<VendorListing>
  removeListingMedia(fabricId: number, mediaId: number): Promise<VendorListing>

  // Aṣọ-ẹbí group orders
  asoEbi(code: string): Promise<AsoEbiGroup>
  myAsoEbi(): Promise<AsoEbiGroup[]>
  createAsoEbi(slug: string, input: NewAsoEbi): Promise<AsoEbiGroup>
  quoteAsoEbi(code: string, yards: number, state?: string | null): Promise<ShopQuote>
  joinAsoEbi(code: string, input: JoinAsoEbi): Promise<Checkout>
  closeAsoEbi(code: string): Promise<AsoEbiGroup>
  /** Email announcements (opted-in people only) and trending fabrics (real sales only). */
  announcementAudience(audience: AnnouncementAudience): Promise<{ audience: string; recipients: number }>
  adminAnnouncements(): Promise<Announcement[]>
  sendAnnouncement(input: { audience: AnnouncementAudience; subject: string; body: string; story_id?: number | null }): Promise<{ id: number; recipients: number }>
  trendingFabrics(): Promise<TrendingFabric[]>
  reopenAsoEbi(code: string): Promise<AsoEbiGroup>
  cancelAsoEbi(code: string): Promise<AsoEbiGroup>

  // Account
  updateMe(input: { name?: string; phone?: string | null; city?: string | null; whatsapp_opt_in?: boolean; email_updates_opt_in?: boolean; currency?: string | null }): Promise<User>
  notifications(): Promise<{ data: AppNotification[]; unread: number }>
  markNotificationsRead(ids?: number[]): Promise<void>
  measurements(): Promise<Measurements>
  saveMeasurements(input: { unit: 'cm' | 'in'; values: Record<string, number | string>; notes?: string | null; share_with_tailors: boolean }): Promise<Measurements>

  // Live market
  live(): Promise<LiveSession[]>
  myLive(): Promise<LiveSession[]>
  createLive(input: { title: string; platform: LiveSession['platform']; url: string; starts_at: string; ends_at?: string | null; fabric_ids?: number[] }): Promise<LiveSession>
  deleteLive(id: number): Promise<void>

  // Try-on
  tryOn(photo: File, fabricId: number, garment: string): Promise<TryOnResult>
}

function readCookie(name: string): string | null {
  const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\/+^]/g, '\\$&') + '=([^;]*)'))
  return match?.[1] ? decodeURIComponent(match[1]) : null
}

let csrfReady: Promise<void> | null = null

async function ensureCsrf(): Promise<void> {
  if (readCookie('XSRF-TOKEN')) return
  csrfReady ??= fetch('/sanctum/csrf-cookie', { credentials: 'same-origin' }).then(() => undefined)
  await csrfReady
  csrfReady = null
}

async function http<T>(method: string, path: string, body?: unknown): Promise<T> {
  const unsafe = method !== 'GET'
  if (unsafe) await ensureCsrf()

  const headers: Record<string, string> = { Accept: 'application/json' }
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData
  if (body !== undefined && !isForm) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (unsafe && xsrf) headers['X-XSRF-TOKEN'] = xsrf

  let res: Response
  try {
    res = await fetch('/api/v1' + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : isForm ? (body as FormData) : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Could not reach OSAPS. Check your connection and try again.', 0, 'network')
  }

  if (res.status === 204) return undefined as T
  const json = await res.json().catch(() => null)
  if (!res.ok) {
    throw new ApiError(
      json?.message ?? `Request failed (${res.status}).`,
      res.status,
      json?.code ?? 'http_error',
      json?.errors ?? {},
    )
  }
  return json as T
}

function qs(params: Record<string, unknown>): string {
  const sp = new URLSearchParams()
  for (const [k, v] of Object.entries(params)) {
    if (v === undefined || v === null || v === '' || v === false) continue
    if (Array.isArray(v)) v.forEach((item) => sp.append(`${k}[]`, String(item)))
    else sp.set(k, v === true ? '1' : String(v))
  }
  const s = sp.toString()
  return s ? `?${s}` : ''
}

const data = <T,>(p: Promise<{ data: T }>) => p.then((r) => r.data)

export const httpApi: Api = {
  meta: () => data(http('GET', '/meta')),
  me: () =>
    data(http<{ data: User }>('GET', '/me')).catch((e) => {
      if (e instanceof ApiError && e.status === 401) return null
      throw e
    }),
  register: (input) => data(http('POST', '/auth/register', input)),
  login: (email, password) => data(http('POST', '/auth/login', { email, password })),
  logout: () => http('POST', '/auth/logout').then(() => undefined),

  fabrics: (f) => http('GET', '/fabrics' + qs({ ...f })),
  fabric: (slug) => data(http('GET', `/fabrics/${encodeURIComponent(slug)}`)),
  tailors: (f) => http('GET', '/tailors' + qs(f)),
  tailor: (slug) => data(http('GET', `/tailors/${encodeURIComponent(slug)}`)),
  saveTailorProfile: (input) => data(http('PUT', '/me/tailor-profile', input)),
  adminCreateTailorProfile: (userId, input) => data(http('POST', `/admin/users/${userId}/tailor-profile`, input)),
  tailorVerification: () => data(http('GET', '/me/tailor-verification')),
  uploadTailorDocument: (kind, file, consent, expiresOn) => {
    const form = new FormData()
    form.append('kind', kind)
    form.append('file', file)
    form.append('consent', consent ? '1' : '0')
    if (expiresOn) form.append('expires_on', expiresOn)
    return data(http('POST', '/me/tailor-verification/documents', form))
  },
  uploadTailorPhoto: (file) => {
    const form = new FormData()
    form.append('photo', file)
    return data(http('POST', '/me/tailor-profile/photo', form))
  },
  submitTailorVerification: () => data(http('POST', '/me/tailor-verification/submit')),
  adminTailorQueue: (status) => data(http('GET', `/admin/tailor-verifications${qs({ status })}`)),
  adminReviewTailorDocument: (id, decision, note) => data(http('POST', `/admin/tailor-documents/${id}/review`, { decision, note: note ?? null })),
  adminReviewTailorProfile: (id, to, note) => data(http('POST', `/admin/tailor-profiles/${id}/review`, { to, note: note ?? null })),
  orderReview: (reference) => data(http('GET', `/orders/${encodeURIComponent(reference)}/review`)),
  submitOrderReview: (reference, input, photos) => {
    const form = new FormData()
    for (const [k, v] of Object.entries(input)) if (v !== undefined && v !== null && v !== '') form.append(k, String(v))
    for (const p of photos) form.append('photos[]', p)
    return data(http('POST', `/orders/${encodeURIComponent(reference)}/review`, form))
  },
  correctReview: (id, input) => data(http('POST', `/tailor-reviews/${id}/correct`, input)),
  appealReview: (id, reason) => data(http('POST', `/tailor-reviews/${id}/appeal`, { reason })),
  reportReview: (id, reason, details) => data(http('POST', `/tailor-reviews/${id}/report`, { reason, details })),
  respondToReview: (id, body) => data(http('POST', `/tailor-reviews/${id}/respond`, { body })),
  tailorReviews: (slug) => data(http('GET', `/tailors/${encodeURIComponent(slug)}/reviews`)),
  myTailorReviews: () => data(http('GET', '/me/tailor-reviews')),
  adminReviewQueue: () => data(http('GET', '/admin/tailor-reviews')),
  adminModerateReview: (id, to, note) => data(http('POST', `/admin/tailor-reviews/${id}/moderate`, { to, note })),
  adminDecideReport: (id, decision, note) => data(http('POST', `/admin/tailor-review-reports/${id}/decide`, { decision, note })),
  adminDecideAppeal: (id, decision, note) => data(http('POST', `/admin/tailor-review-appeals/${id}/decide`, { decision, note })),
  adminDecidePhoto: (id, decision) => data(http('POST', `/admin/tailor-review-photos/${id}/decide`, { decision })),
  stories: () => data(http('GET', '/stories')),
  myStories: () => data(http('GET', '/me/stories')),
  createMyStory: (form) => data(http('POST', '/me/stories', form)),
  updateMyStory: (id, form) => data(http('POST', `/me/stories/${id}`, form)),
  deleteMyStory: (id) => data(http('DELETE', `/me/stories/${id}`)),
  adminStories: (status) => data(http('GET', `/admin/stories${status ? qs({ status }) : ''}`)),
  adminCreateStory: (form) => data(http('POST', '/admin/stories', form)),
  adminUpdateStory: (id, form) => data(http('POST', `/admin/stories/${id}`, form)),
  adminSetStoryStatus: (id, status, note) => data(http('POST', `/admin/stories/${id}/status`, { status, note })),
  adminDeleteStory: (id) => data(http('DELETE', `/admin/stories/${id}`)),
  tailorOfWeek: () => data(http('GET', '/tailor-of-week')),
  adminSetTailorOfWeek: (tailorProfileId, reason) => data(http('POST', '/admin/tailor-of-week', { tailor_profile_id: tailorProfileId, reason })),
  savedStories: () => data(http('GET', '/me/saved-stories')),
  savedStoryIds: () => data(http('GET', '/me/saved-story-ids')),
  saveStory: (id) => data(http('POST', `/stories/${id}/save`)),
  unsaveStory: (id) => data(http('DELETE', `/stories/${id}/save`)),
  moments: () => data(http('GET', '/moments')),
  myMoments: () => data(http('GET', '/me/moments')),
  postMoment: (form) => data(http('POST', '/moments', form)),
  deleteMoment: (id) => data(http('DELETE', `/moments/${id}`)),
  publicQuestions: () => data(http('GET', '/questions')),
  myQuestions: () => data(http('GET', '/me/questions')),
  askQuestion: (question) => data(http('POST', '/questions', { question })),
  openQuestions: () => data(http('GET', '/tailor/open-questions')),
  answerQuestion: (id, answer) => data(http('POST', `/questions/${id}/answer`, { answer })),
  adminQuestions: (status) => data(http('GET', `/admin/questions${qs({ status })}`)),
  adminApproveQuestion: (id) => data(http('POST', `/admin/questions/${id}/approve`)),
  adminHideQuestion: (id, note) => data(http('POST', `/admin/questions/${id}/hide`, { note })),
  adminPublishQuestion: (id) => data(http('POST', `/admin/questions/${id}/publish`)),
  adminMoments: (status) => data(http('GET', `/admin/moments${qs({ status })}`)),
  adminMomentStatus: (id, status, note) => data(http('POST', `/admin/moments/${id}/status`, { status, note })),
  announcementAudience: (audience) => data(http('GET', `/admin/announcements/audience${qs({ audience })}`)),
  adminAnnouncements: () => data(http('GET', '/admin/announcements')),
  sendAnnouncement: (input) => data(http('POST', '/admin/announcements', input)),
  trendingFabrics: () => data(http('GET', '/fabrics/trending')),
  adminTailorDirectory: (status) => data(http('GET', `/admin/tailors${status ? qs({ status }) : ''}`)),
  adminOverrideTailor: (profileId, note) => data(http('POST', `/admin/tailor-profiles/${profileId}/override-verify`, { note })),
  adminBulkMailTailors: (userIds, subject, body) => data(http('POST', '/admin/tailors/bulk-mail', { user_ids: userIds, subject, body })),
  adminSetTailorPhoto: (profileId, file) => {
    const form = new FormData()
    form.append('photo', file)
    return data(http('POST', `/admin/tailor-profiles/${profileId}/photo`, form))
  },
  adminUploadTailorDocument: (profileId, kind, file, tailorAgreed, how, expiresOn) => {
    const form = new FormData()
    form.append('kind', kind)
    form.append('file', file)
    form.append('tailor_agreed', tailorAgreed ? '1' : '0')
    form.append('how', how)
    if (expiresOn) form.append('expires_on', expiresOn)
    return data(http('POST', `/admin/tailor-profiles/${profileId}/documents`, form))
  },
  vendorDocuments: () => data(http('GET', '/me/vendor-documents')),
  uploadVendorDocument: (kind, file, consent, expiresOn) => {
    const form = new FormData()
    form.append('kind', kind)
    form.append('file', file)
    form.append('consent', consent ? '1' : '0')
    if (expiresOn) form.append('expires_on', expiresOn)
    return data(http('POST', '/me/vendor-documents', form))
  },
  adminReviewVendorDocument: (id, decision, note) => data(http('POST', `/admin/vendor-documents/${id}/review`, { decision, note: note ?? null })),

  myRequests: () => http('GET', '/job-requests'),
  createRequest: (input) => data(http('POST', '/job-requests', input)),
  request: (id) => data(http('GET', `/job-requests/${id}`)),
  cancelRequest: (id) => data(http('POST', `/job-requests/${id}/cancel`)),
  matches: (id) => data(http('GET', `/job-requests/${id}/matches`)),

  inbox: () =>
    http<{ data: JobRequest[]; meta: { has_profile: boolean } }>('GET', '/tailor/inbox').then((r) => ({
      data: r.data,
      has_profile: r.meta.has_profile,
    })),
  submitOffer: (id, input) => data(http('POST', `/job-requests/${id}/offers`, input)),
  counter: (id, amount_minor, note) => data(http('POST', `/offers/${id}/counter`, { amount_minor, note })),
  revise: (id, amount_minor, delivery_days, note) => data(http('POST', `/offers/${id}/revise`, { amount_minor, delivery_days, note })),
  withdraw: (id) => data(http('POST', `/offers/${id}/withdraw`)),
  decline: (id) => data(http('POST', `/offers/${id}/decline`)),
  accept: (id, seen) => data(http('POST', `/offers/${id}/accept`, { expected_amount_minor: seen.amount_minor, expected_delivery_fee_minor: seen.delivery_fee_minor })),

  messages: (id) => data(http('GET', `/offers/${id}/messages`)),
  sendMessage: (id, body, photo) => {
    if (!photo) return data(http('POST', `/offers/${id}/messages`, { body }))
    const f = new FormData(); if (body.trim()) f.append('body', body); f.append('photo', photo)
    return data(http('POST', `/offers/${id}/messages`, f))
  },
  addDesignPhoto: (id, photo, caption) => { const f = new FormData(); f.append('photo', photo); if (caption) f.append('caption', caption); return data(http('POST', `/job-requests/${encodeURIComponent(id)}/photos`, f)) },
  removeDesignPhoto: (id, photoId) => http('DELETE', `/job-requests/${encodeURIComponent(id)}/photos/${photoId}`).then(() => undefined),
  quickAccount: (input) => data(http('POST', '/auth/quick', input)),
  vendorContact: (slug) => data(http('GET', `/fabrics/${encodeURIComponent(slug)}/contact`)),

  aiSearch: (q) => data(http('GET', '/ai/fabric-search' + qs({ q }))),
  stylist: (known, message, slot) => data(http('POST', '/ai/stylist', { known, message: message ?? null, slot: slot ?? null })),
  fabricIdeas: (slug) => data(http('GET', `/ai/fabrics/${encodeURIComponent(slug)}/ideas`)),
  inspiration: (image) => {
    const form = new FormData()
    form.append('image', image)
    return data(http('POST', '/ai/inspiration', form))
  },

  orders: () => http('GET', '/orders'),
  order: (ref) => data(http('GET', `/orders/${encodeURIComponent(ref)}`)),
  transition: (ref, status, note) => data(http('POST', `/orders/${encodeURIComponent(ref)}/transitions`, { status, note })),

  makePriceOffer: (slug, quantity, unit_price_minor, note) => data(http('POST', `/fabrics/${encodeURIComponent(slug)}/price-offers`, { quantity, unit_price_minor, note: note ?? null })),
  myPriceOffers: () => data(http('GET', '/price-offers')),
  vendorPriceOffers: () =>
    http<{ data: PriceOffer[]; meta: { is_vendor: boolean } }>('GET', '/vendor/price-offers').then((r) => ({ data: r.data, is_vendor: r.meta.is_vendor })),
  priceOffer: (id) => data(http('GET', `/price-offers/${id}`)),
  acceptPriceOffer: (id, expected) => data(http('POST', `/price-offers/${id}/accept`, { expected_unit_price_minor: expected })),
  counterPriceOffer: (id, unit_price_minor) => data(http('POST', `/price-offers/${id}/counter`, { unit_price_minor })),
  declinePriceOffer: (id) => data(http('POST', `/price-offers/${id}/decline`)),
  withdrawPriceOffer: (id) => data(http('POST', `/price-offers/${id}/withdraw`)),

  quote: (items, fulfilment, country, state, express) => data(http('POST', '/shop/quote', { items, fulfilment, country: country ?? null, state: state ?? null, express: !!express })),
  createCheckout: (input) => data(http('POST', '/checkouts', input)),
  checkout: (ref) => data(http('GET', `/checkouts/${encodeURIComponent(ref)}`)),
  verifyCheckout: (ref) => data(http('POST', `/checkouts/${encodeURIComponent(ref)}/verify`)),
  cancelCheckout: (ref) => data(http('POST', `/checkouts/${encodeURIComponent(ref)}/cancel`)),
  fabricOrders: () => data(http('GET', '/fabric-orders')),
  fabricOrder: (ref) => data(http('GET', `/fabric-orders/${encodeURIComponent(ref)}`)),
  transitionFabricOrder: (ref, status, note, code) => data(http('POST', `/fabric-orders/${encodeURIComponent(ref)}/transitions`, { status, note: note ?? null, code: code ?? null })),
  reportProblem: (ref, reason, details) => data(http('POST', `/fabric-orders/${encodeURIComponent(ref)}/dispute`, { reason, details })),
  forgotPassword: (email) => http<{ message: string }>('POST', '/auth/forgot-password', { email }).then((r) => r.message),
  resetPassword: (input) => http<{ message: string }>('POST', '/auth/reset-password', input).then((r) => r.message),
  savedCart: () => data(http('GET', '/cart')),
  saveCart: (items) => data(http('PUT', '/cart', { items })),
  adminOverview: () => data(http('GET', '/admin/overview')),
  adminVendors: (status) => data(http('GET', `/admin/vendors?status=${encodeURIComponent(status)}`)),
  adminReviewVendor: (id, to, note) => http('POST', `/admin/vendors/${id}/review`, { to, note }).then(() => undefined),
  adminDisputes: () => data(http('GET', '/admin/disputes')),
  adminResolveDispute: (ref, resolution, note) => http('POST', `/admin/fabric-orders/${encodeURIComponent(ref)}/resolve`, { resolution, note }).then(() => undefined),
  adminRefunds: () => data(http('GET', '/admin/refunds')),
  adminRefund: (ref, reason, amountMinor) => data(http('POST', `/admin/fabric-orders/${encodeURIComponent(ref)}/refund`, { reason, amount_minor: amountMinor ?? null })),
  adminMarkRefundFailed: (id, note) => http('POST', `/admin/refunds/${id}/mark-failed`, { note }).then(() => undefined),
  adminPayouts: () => http<{ data: AdminPayoutGroup[]; meta: { two_person_payouts: boolean } }>('GET', '/admin/payouts').then((r) => ({ data: r.data, two_person: r.meta.two_person_payouts })),
  adminApprovePayout: (ref) => http('POST', `/admin/fabric-orders/${encodeURIComponent(ref)}/payout-approve`).then(() => undefined),
  adminMarkPaid: (ref, transferReference) => http('POST', `/admin/fabric-orders/${encodeURIComponent(ref)}/paid-out`, { transfer_reference: transferReference }).then(() => undefined),
  adminStuck: () => data(http('GET', '/admin/stuck')),
  adminActions: () => data(http('GET', '/admin/actions')),

  vendorFabricOrders: () =>
    http<{ data: FabricOrder[]; meta: { is_vendor: boolean } }>('GET', '/vendor/fabric-orders').then((r) => ({ data: r.data, is_vendor: r.meta.is_vendor })),
  vendorListings: () => data(http('GET', '/vendor/fabrics')),
  updateListing: (id, patch) => data(http('PATCH', `/vendor/fabrics/${id}`, patch)),
  createListing: (input) => data(http('POST', '/vendor/fabrics', input)),
  saveVendorProfile: (input) => data(http('PUT', '/me/vendor-profile', input)),
  vendorProfile: () => data(http('GET', '/me/vendor-profile')),
  addListingMedia: (id, file) => {
    const form = new FormData()
    form.append('file', file)
    return data(http('POST', `/vendor/fabrics/${id}/media`, form))
  },
  removeListingMedia: (id, mediaId) => data(http('DELETE', `/vendor/fabrics/${id}/media/${mediaId}`)),

  asoEbi: (code) => data(http('GET', `/aso-ebi/${encodeURIComponent(code)}`)),
  myAsoEbi: () => data(http('GET', '/aso-ebi')),
  createAsoEbi: (slug, input) => data(http('POST', `/fabrics/${encodeURIComponent(slug)}/aso-ebi`, input)),
  quoteAsoEbi: (code, yards, state) => data(http('POST', `/aso-ebi/${encodeURIComponent(code)}/quote`, { yards, state: state ?? null })),
  joinAsoEbi: (code, input) => data(http('POST', `/aso-ebi/${encodeURIComponent(code)}/join`, input)),
  closeAsoEbi: (code) => data(http('POST', `/aso-ebi/${encodeURIComponent(code)}/close`)),
  reopenAsoEbi: (code) => data(http('POST', `/aso-ebi/${encodeURIComponent(code)}/reopen`)),
  cancelAsoEbi: (code) => data(http('POST', `/aso-ebi/${encodeURIComponent(code)}/cancel`)),

  updateMe: (input) => data(http('PUT', '/me', input)),
  notifications: () =>
    http<{ data: AppNotification[]; meta: { unread: number } }>('GET', '/notifications').then((r) => ({ data: r.data, unread: r.meta.unread })),
  markNotificationsRead: (ids) => http('POST', '/notifications/read', { ids: ids ?? null }).then(() => undefined),
  measurements: () => data(http('GET', '/me/measurements')),
  saveMeasurements: (input) => data(http('PUT', '/me/measurements', input)),

  live: () => data(http('GET', '/live')),
  myLive: () => data(http('GET', '/vendor/live')),
  createLive: (input) => data(http('POST', '/vendor/live', input)),
  deleteLive: (id) => http('DELETE', `/vendor/live/${id}`).then(() => undefined),

  tryOn: (photo, fabricId, garment) => {
    const form = new FormData()
    form.append('photo', photo)
    form.append('fabric_id', String(fabricId))
    form.append('garment', garment)
    form.append('consent', '1')
    return data(http('POST', '/ai/try-on', form))
  },
}
