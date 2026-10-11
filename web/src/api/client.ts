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
  accept(offerId: number): Promise<Order>

  orders(): Promise<Paginated<Order>>
  order(reference: string): Promise<Order>
  transition(reference: string, status: OrderStatus, note: string | null): Promise<Order>
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
  if (body !== undefined) headers['Content-Type'] = 'application/json'
  const xsrf = readCookie('XSRF-TOKEN')
  if (unsafe && xsrf) headers['X-XSRF-TOKEN'] = xsrf

  let res: Response
  try {
    res = await fetch('/api/v1' + path, {
      method,
      headers,
      credentials: 'same-origin',
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch {
    throw new ApiError('Could not reach Osapa. Check your connection and try again.', 0, 'network')
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
  accept: (id) => data(http('POST', `/offers/${id}/accept`)),

  orders: () => http('GET', '/orders'),
  order: (ref) => data(http('GET', `/orders/${encodeURIComponent(ref)}`)),
  transition: (ref, status, note) => data(http('POST', `/orders/${encodeURIComponent(ref)}/transitions`, { status, note })),
}
