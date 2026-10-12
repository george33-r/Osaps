import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, isPreview, type Checkout, type FabricOrder, type PriceOffer, type ShopQuote } from '../api'
import { Countdown, OfferCard, QtyStepper } from '../components/ShopKit'
import { Swatch } from '../components/Swatch'
import { Button, ButtonLink, EmptyState, ErrorState, Field, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { lineEstimate, useCart } from '../lib/cart'
import { formatDate, formatNaira } from '../lib/format'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

/** Server quote for the current cart, refreshed as it changes. */
function useQuote(enabled: boolean) {
  const cart = useCart()
  const [quote, setQuote] = useState<ShopQuote | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [loading, setLoading] = useState(false)
  const key = JSON.stringify([cart.items, cart.fulfilment])
  const seq = useRef(0)

  const refresh = async () => {
    if (!enabled || cart.items.length === 0) { setQuote(null); return null }
    const n = ++seq.current
    setLoading(true)
    try {
      const q = await api.quote(cart.items, cart.fulfilment)
      if (n === seq.current) { setQuote(q); setError(null) }
      return q
    } catch (e) {
      if (n === seq.current) setError(errorMessage(e))
      return null
    } finally {
      if (n === seq.current) setLoading(false)
    }
  }

  useEffect(() => {
    const t = window.setTimeout(refresh, 250)
    return () => window.clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key, enabled])

  return { quote, error, loading, refresh }
}

function Summary({ quote, estimate, children }: { quote: ShopQuote | null; estimate: number; children?: React.ReactNode }) {
  const saved = quote?.vendors.flatMap((v) => v.lines).reduce((n, l) => n + l.saving.amount_minor, 0) ?? 0
  return (
    <aside className="bag" aria-label="Order summary">
      <p className="bag__title">Summary</p>
      {quote ? (
        <dl className="bag__lines">
          <div><dt>Fabric</dt><dd>{quote.subtotal.formatted}</dd></div>
          <div><dt>Delivery{quote.vendors.length > 1 ? ` (${quote.vendors.length} vendors)` : ''}</dt><dd>{quote.delivery.amount_minor ? quote.delivery.formatted : 'Free pickup'}</dd></div>
          <div><dt>OSAPS service fee</dt><dd>{quote.service_fee.formatted}</dd></div>
          <div className="bag__total"><dt>Total</dt><dd>{quote.total.formatted}</dd></div>
        </dl>
      ) : (
        <dl className="bag__lines">
          <div><dt>Fabric (estimate)</dt><dd>{formatNaira(estimate)}</dd></div>
          <div className="bag__note"><dt>Delivery and fees are worked out at checkout.</dt></div>
        </dl>
      )}
      {saved > 0 && <p className="bag__saved">You saved {formatNaira(saved)} by naming your price.</p>}
      {children}
    </aside>
  )
}

function Fulfilment() {
  const cart = useCart()
  const { meta } = useSession()
  const fee = meta?.shop?.delivery_fee_per_vendor_minor
  return (
    <div className="fulfil" role="radiogroup" aria-label="How you get your fabric">
      {(['delivery', 'pickup'] as const).map((f) => (
        <button key={f} type="button" role="radio" aria-checked={cart.fulfilment === f} className="fulfil__opt" onClick={() => cart.setFulfilment(f)}>
          <strong>{f === 'delivery' ? 'Deliver to me' : "Pick up at the vendor's market"}</strong>
          <span>{f === 'delivery' ? (fee ? `${formatNaira(fee)} per vendor` : 'Delivery fee per vendor') : 'Free'}</span>
        </button>
      ))}
    </div>
  )
}

export function CartPage() {
  const cart = useCart()
  const { user } = useSession()
  const { quote, error } = useQuote(!!user)
  const estimate = cart.lines.reduce((n, l) => n + lineEstimate(l), 0)
  const problems = (id: number) => [...(quote?.blocking ?? []), ...(quote?.notices ?? [])].filter((p) => p.fabric_id === id)
  const priced = (id: number) => quote?.vendors.flatMap((v) => v.lines).find((l) => l.fabric_id === id)

  if (cart.lines.length === 0) {
    return (
      <div className="page page--narrow">
        <PageHeader title="Your cart" />
        <EmptyState title="Your cart is empty" action={<ButtonLink to="/fabrics">Shop fabric</ButtonLink>}>
          Add Ankara, aṣọ-òkè, lace and more, or name your price and let the vendor answer.
        </EmptyState>
      </div>
    )
  }

  return (
    <div className="page">
      <PageHeader title="Your cart" intro={`${cart.lines.length} ${cart.lines.length === 1 ? 'fabric' : 'fabrics'}. Fabric from different vendors arrives as separate orders.`} />
      <div className="cart">
        <div className="cart__lines">
          <Fulfilment />
          {cart.lines.map((l) => {
            const p = priced(l.fabric_id)
            const issues = problems(l.fabric_id)
            return (
              <article key={l.fabric_id} className={`cart-line ${issues.some((i) => quote?.blocking.includes(i)) ? 'has-block' : ''}`}>
                <Link to={`/fabrics/${l.slug}`} className="cart-line__swatch" aria-label={l.name}>
                  {l.image_url ? <img src={l.image_url} alt="" /> : <Swatch pattern={l.pattern} colors={l.colors} scale={0.8} />}
                </Link>
                <div className="cart-line__body">
                  <Link to={`/fabrics/${l.slug}`} className="cart-line__name">{l.name}</Link>
                  <p className="cart-line__vendor">{l.vendor_name}</p>
                  {l.price_offer_id && <span className="cart-line__deal">Your price {l.offer_unit_minor !== null ? formatNaira(l.offer_unit_minor) : ''} per {l.unit}</span>}
                  <QtyStepper value={l.quantity} min={1} max={l.price_offer_id && l.offer_quantity ? Math.max(l.offer_quantity, l.max) : Math.max(1, l.max)} unit={l.unit} onChange={(n) => cart.setQuantity(l.fabric_id, n)} />
                  {issues.map((i) => <p key={i.code} className={`cart-line__issue ${quote?.blocking.includes(i) ? 'is-block' : ''}`}>{i.message}</p>)}
                </div>
                <div className="cart-line__price">
                  <strong>{p ? p.line_total.formatted : formatNaira(lineEstimate(l))}</strong>
                  {p && p.saving.amount_minor > 0 && <small className="cart-line__was">{formatNaira(p.list_unit_price.amount_minor * p.quantity)}</small>}
                  <button className="text-link cart-line__remove" onClick={() => cart.remove(l.fabric_id)}>Remove</button>
                </div>
              </article>
            )
          })}
        </div>
        <Summary quote={quote} estimate={estimate}>
          {error && <p className="field__error">{error}</p>}
          {user ? (
            <ButtonLink to="/checkout" className={`bag__cta ${quote && !quote.ok ? 'is-disabled' : ''}`} aria-disabled={quote ? !quote.ok : undefined}
              onClick={(e) => { if (quote && !quote.ok) e.preventDefault() }}>
              {quote && !quote.ok ? 'Fix your cart to continue' : 'Checkout'}
            </ButtonLink>
          ) : (
            <ButtonLink to="/signin?next=%2Fcheckout" className="bag__cta">Sign in to check out</ButtonLink>
          )}
          <p className="bag__fine">Paid through Paystack. Card, bank transfer or USSD.</p>
        </Summary>
      </div>
    </div>
  )
}

const PENDING_KEY = 'osaps-pending-checkout'

export function CheckoutPage() {
  const cart = useCart()
  const { user, meta } = useSession()
  const navigate = useNavigate()
  const { quote, refresh, loading } = useQuote(true)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const [notice, setNotice] = useState<string | null>(null)

  if (cart.lines.length === 0) {
    return <div className="page page--narrow"><EmptyState title="Nothing to check out" action={<ButtonLink to="/fabrics">Shop fabric</ButtonLink>} /></div>
  }
  if (meta && !meta.features.payments) {
    return (
      <div className="page page--narrow">
        <PageHeader title="Checkout" />
        <EmptyState title="Online payment is not switched on yet">You can still contact each vendor from the fabric page.</EmptyState>
      </div>
    )
  }

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!quote?.ok) return
    const f = new FormData(e.currentTarget)
    const s = (k: string) => String(f.get(k) ?? '').trim()
    setBusy(true); setError(null); setNotice(null)
    try {
      const c = await api.createCheckout({
        items: cart.items,
        fulfilment: cart.fulfilment,
        expected_total_minor: quote.total.amount_minor,
        contact: { name: s('name'), phone: s('phone') },
        address: cart.fulfilment === 'delivery' ? { line1: s('line1'), line2: s('line2') || undefined, city: s('city'), state: s('state'), landmark: s('landmark') || undefined } : null,
      })
      try { sessionStorage.setItem(PENDING_KEY, c.reference) } catch { /* ignore */ }
      if (!c.authorization_url) throw new ApiError('Payment could not be started. Please try again.', 0, 'payment_start_failed')
      if (c.authorization_url.startsWith('/')) navigate(c.authorization_url)
      else window.location.assign(c.authorization_url)
    } catch (e) {
      const err = e instanceof ApiError ? e : new ApiError(errorMessage(e), 0, 'unknown')
      setError(err)
      if (err.code === 'cart_changed' || err.code === 'cart_invalid') { await refresh(); setNotice('We updated your total. Check it, then pay.') }
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <PageHeader title="Checkout" intro="Where should your fabric go? You pay on the next screen, through Paystack." />
      <form className="cart" onSubmit={submit}>
        <div className="checkout">
          <section className="checkout__block">
            <h2 className="checkout__h">1. How you get it</h2>
            <Fulfilment />
          </section>
          <section className="checkout__block">
            <h2 className="checkout__h">2. Your details</h2>
            <Field label="Full name" error={error?.field('contact.name')}>{(p) => <input {...p} name="name" className="input" defaultValue={user?.name ?? ''} autoComplete="name" required minLength={2} />}</Field>
            <Field label="Phone number" hint="The vendor calls this number about your order." error={error?.field('contact.phone')}>{(p) => <input {...p} name="phone" className="input" type="tel" inputMode="tel" defaultValue={user?.phone ?? ''} autoComplete="tel" placeholder="0801 234 5678" required />}</Field>
          </section>
          {cart.fulfilment === 'delivery' ? (
            <section className="checkout__block">
              <h2 className="checkout__h">3. Delivery address</h2>
              <Field label="Street address" error={error?.field('address.line1')}>{(p) => <input {...p} name="line1" className="input" autoComplete="address-line1" required />}</Field>
              <Field label="Flat, estate or office" optional>{(p) => <input {...p} name="line2" className="input" autoComplete="address-line2" />}</Field>
              <div className="checkout__pair">
                <Field label="City" error={error?.field('address.city')}>{(p) => <input {...p} name="city" className="input" defaultValue={user?.city ?? ''} autoComplete="address-level2" required />}</Field>
                <Field label="State" error={error?.field('address.state')}>{(p) => <input {...p} name="state" className="input" autoComplete="address-level1" required />}</Field>
              </div>
              <Field label="Nearest landmark" optional hint="Helps the rider find you.">{(p) => <input {...p} name="landmark" className="input" />}</Field>
            </section>
          ) : (
            <section className="checkout__block">
              <h2 className="checkout__h">3. Pickup</h2>
              <p className="muted">Each vendor will call you when your fabric is ready to collect at their market.</p>
            </section>
          )}
        </div>
        <Summary quote={quote} estimate={0}>
          {quote && quote.vendors.map((v) => (
            <div key={v.vendor_id} className="bag__vendor">
              <p><strong>{v.vendor_name}</strong></p>
              {v.lines.map((l) => <p key={l.fabric_id} className="bag__item">{l.quantity} × {l.name} <span>{l.line_total.formatted}</span></p>)}
            </div>
          ))}
          {[...(quote?.blocking ?? []), ...(quote?.notices ?? [])].map((p) => <p key={p.code + p.fabric_id} className="cart-line__issue">{p.message}</p>)}
          {notice && <p className="bag__notice" role="status">{notice}</p>}
          {error && error.code !== 'cart_changed' && (
            <p className="field__error" role="alert">{error.code === 'validation_failed' ? 'Check the highlighted details.' : error.message}</p>
          )}
          <Button type="submit" className="bag__cta" busy={busy} disabled={!quote?.ok || loading}>
            {quote ? `Pay ${quote.total.formatted}` : 'Working out your total…'}
          </Button>
          <p className="bag__fine">Your fabric is held for {meta?.shop?.checkout_hold_minutes ?? 60} minutes while you pay.</p>
        </Summary>
      </form>
    </div>
  )
}

/** Paystack sends the buyer back here. We ask the server, which asks Paystack. */
export function CheckoutComplete() {
  const [params] = useSearchParams()
  const cart = useCart()
  const reference = params.get('reference') ?? params.get('trxref') ?? (() => { try { return sessionStorage.getItem(PENDING_KEY) } catch { return null } })()
  const [checkout, setCheckout] = useState<Checkout | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [tries, setTries] = useState(0)

  useEffect(() => {
    if (!reference) return
    let stop = false
    const check = async (n: number) => {
      try {
        const c = await api.verifyCheckout(reference)
        if (stop) return
        setCheckout(c)
        setTries(n)
        if (c.payment_status === 'paid') {
          cart.clear()
          try { sessionStorage.removeItem(PENDING_KEY) } catch { /* ignore */ }
          return
        }
        // Paystack can take a few seconds to confirm a transfer: keep checking for about half a minute.
        if (c.payment_status === 'pending' && n < 8) window.setTimeout(() => check(n + 1), 4_000)
      } catch (e) {
        if (!stop) setError(errorMessage(e))
      }
    }
    check(0)
    return () => { stop = true }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reference])

  if (!reference) return <div className="page page--narrow"><EmptyState title="No payment to check" action={<ButtonLink to="/cart">Back to cart</ButtonLink>} /></div>
  if (error) return <div className="page page--narrow"><ErrorState error={new Error(error)} /></div>
  if (!checkout) return <div className="page page--narrow" aria-busy="true"><PageHeader title="Checking your payment…" /><Skeleton lines={3} /></div>

  if (checkout.payment_status === 'paid') {
    return (
      <div className="page page--narrow paid">
        <div className="paid__burst" aria-hidden>{Array.from({ length: 14 }, (_, i) => <span key={i} style={{ ['--i' as string]: i }} />)}</div>
        <PageHeader label="Payment confirmed" title="It's yours." intro={`We received ${checkout.total.formatted}. Each vendor has been told to pack your fabric.`} />
        <div className="paid__orders">
          {checkout.orders?.map((o) => (
            <Link key={o.reference} to={`/shop/orders/${o.reference}`} className="paid__order">
              <strong>{o.vendor?.business_name}</strong>
              <span>{o.items?.map((i) => `${i.quantity} × ${i.name}`).join(', ')}</span>
              <small>Order {o.reference}</small>
            </Link>
          ))}
        </div>
        <div className="paid__actions">
          <ButtonLink to="/shop/orders">Track my orders</ButtonLink>
          <ButtonLink to="/fabrics" variant="secondary">Keep shopping</ButtonLink>
        </div>
      </div>
    )
  }
  if (checkout.payment_status === 'needs_refund') {
    return <div className="page page--narrow"><PageHeader title="We are sorry" intro="Your payment arrived after the fabric sold out. OSAPS will refund you in full. Our team will contact you." /></div>
  }
  if (checkout.payment_status === 'pending') {
    return (
      <div className="page page--narrow">
        <PageHeader title="Waiting for Paystack" intro={tries >= 8 ? 'We have not heard back from Paystack yet. If you paid, this page will update; you can also check your orders later.' : 'Confirming your payment. This usually takes a few seconds.'} />
        {checkout.expires_at && <p className="muted">Your fabric is held: <Countdown iso={checkout.expires_at} /></p>}
        <div className="paid__actions">
          <Button variant="secondary" onClick={() => window.location.reload()}>Check again</Button>
          <ButtonLink to="/cart" variant="quiet">Back to cart</ButtonLink>
        </div>
      </div>
    )
  }
  return (
    <div className="page page--narrow">
      <PageHeader title="Payment not completed" intro="Nothing was charged. Your cart is still here when you are ready." />
      <ButtonLink to="/cart">Back to cart</ButtonLink>
    </div>
  )
}

/** Design preview only: stands in for the Paystack page. */
export function PreviewPay() {
  const { reference = '' } = useParams()
  const navigate = useNavigate()
  const res = useAsync(() => api.checkout(reference), [reference])
  if (!isPreview) return <div className="page page--narrow"><EmptyState title="Not available" /></div>
  const go = async (outcome: 'success' | 'cancel') => {
    // Static guard: production builds drop this branch and never bundle the preview adapter.
    if (import.meta.env.MODE === 'preview') {
      const { previewSimulatePayment } = await import('../api/preview')
      previewSimulatePayment(reference, outcome)
    }
    navigate(outcome === 'success' ? `/checkout/complete?reference=${reference}` : '/cart')
  }
  return (
    <div className="page page--narrow">
      <div className="fakepay">
        <p className="fakepay__tag">Design preview — Paystack opens here on the live site</p>
        <p className="fakepay__amount">{res.data?.total.formatted ?? '…'}</p>
        <p className="muted">No money moves in the preview.</p>
        <div className="paid__actions">
          <Button onClick={() => go('success')}>Pay (simulated)</Button>
          <Button variant="quiet" onClick={() => go('cancel')}>Cancel</Button>
        </div>
      </div>
    </div>
  )
}

export function MyOffers() {
  const res = useAsync(() => api.myPriceOffers(), [])
  const [list, setList] = useState<PriceOffer[] | null>(null)
  useEffect(() => { if (res.data) setList(res.data) }, [res.data])
  const update = (o: PriceOffer) => setList((l) => l?.map((x) => (x.id === o.id ? o : x)) ?? null)
  return (
    <div className="page">
      <PageHeader title="My offers" intro="Prices you named on fabric. An agreed price is held for you for 48 hours." />
      {res.status === 'error' ? <ErrorState error={res.error} onRetry={res.reload} /> : !list ? <Skeleton lines={4} /> : list.length === 0 ? (
        <EmptyState title="No offers yet" action={<ButtonLink to="/fabrics">Find fabric</ButtonLink>}>Open any fabric and choose “Name your price”.</EmptyState>
      ) : (
        <div className="offer-grid">{list.map((o) => <OfferCard key={o.id} offer={o} onChange={update} showFabric />)}</div>
      )}
    </div>
  )
}

export function OrderTimeline({ order }: { order: FabricOrder }) {
  const reached = new Set(order.events?.map((e) => e.to) ?? [])
  return (
    <ol className="track">
      {order.milestones.map((m) => (
        <li key={m.status} className={`track__step ${reached.has(m.status) ? 'is-done' : ''} ${order.status === m.status ? 'is-current' : ''}`}>{m.label}</li>
      ))}
    </ol>
  )
}

export function FabricOrderList() {
  const res = useAsync(() => api.fabricOrders(), [])
  return (
    <div className="page">
      <PageHeader title="Fabric orders" intro="Fabric you bought on OSAPS. Tailoring orders are under Orders." aside={<ButtonLink to="/orders" variant="secondary">Tailoring orders</ButtonLink>} />
      {res.status === 'error' ? <ErrorState error={res.error} onRetry={res.reload} /> : !res.data ? <Skeleton lines={4} /> : res.data.length === 0 ? (
        <EmptyState title="No fabric orders yet" action={<ButtonLink to="/fabrics">Shop fabric</ButtonLink>} />
      ) : (
        <div className="forders">
          {res.data.map((o) => (
            <Link key={o.reference} to={`/shop/orders/${o.reference}`} className={`forder forder--${o.status}`}>
              <span className="forder__swatches">
                {o.items?.slice(0, 3).map((i) => <span key={i.fabric_id} className="forder__swatch"><Swatch pattern={i.pattern} colors={i.colors ?? ['indigo']} scale={0.5} /></span>)}
              </span>
              <span className="forder__body">
                <strong>{o.vendor?.business_name}</strong>
                <span>{o.items?.map((i) => `${i.quantity} × ${i.name}`).join(', ')}</span>
                <small>{o.reference}{o.created_at ? ` · ${formatDate(o.created_at)}` : ''}</small>
              </span>
              <span className="forder__status">{o.status_label}<strong>{o.total?.formatted}</strong></span>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

export function FabricOrderDetail() {
  const { reference = '' } = useParams()
  const res = useAsync(() => api.fabricOrder(reference), [reference])
  const [order, setOrder] = useState<FabricOrder | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => { if (res.data) setOrder(res.data) }, [res.data])
  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!order) return <div className="page"><Skeleton lines={5} /></div>
  const labelFor = (s: string) => (s === 'delivered' ? (order.fulfilment === 'pickup' ? 'I have collected it' : 'I have received it') : s === 'completed' ? 'All good — complete order' : s)

  return (
    <div className="page page--narrow">
      <PageHeader label={`Order ${order.reference}`} title={order.status_label} intro={`From ${order.vendor?.business_name ?? 'the vendor'}${order.vendor?.market ? `, ${order.vendor.market}` : ''}.`} />
      {order.status !== 'cancelled' && <OrderTimeline order={order} />}
      <ul className="order-items">
        {order.items?.map((i) => (
          <li key={i.fabric_id}>
            <span className="order-items__swatch"><Swatch pattern={i.pattern} colors={i.colors ?? ['indigo']} scale={0.5} /></span>
            <span>{i.quantity} {i.unit}{i.quantity === 1 ? '' : 's'} · {i.slug ? <Link to={`/fabrics/${i.slug}`}>{i.name}</Link> : i.name}{i.negotiated && <em className="order-items__deal"> your price</em>}</span>
            <strong>{i.line_total.formatted}</strong>
          </li>
        ))}
      </ul>
      <dl className="bag__lines">
        <div><dt>Fabric</dt><dd>{order.subtotal.formatted}</dd></div>
        <div><dt>{order.fulfilment === 'pickup' ? 'Pickup' : 'Delivery'}</dt><dd>{order.delivery.amount_minor ? order.delivery.formatted : 'Free'}</dd></div>
        {order.service_fee && <div><dt>Service fee</dt><dd>{order.service_fee.formatted}</dd></div>}
        {order.total && <div className="bag__total"><dt>Total</dt><dd>{order.total.formatted}</dd></div>}
      </dl>
      {order.next.length > 0 && (
        <div className="paid__actions">
          {order.next.map((n) => (
            <Button key={n.status} busy={busy === n.status} onClick={async () => {
              setBusy(n.status); setError(null)
              try { setOrder(await api.transitionFabricOrder(order.reference, n.status)) } catch (e) { setError(errorMessage(e)) } finally { setBusy(null) }
            }}>{labelFor(n.status)}</Button>
          ))}
        </div>
      )}
      {error && <p className="field__error" role="alert">{error}</p>}
      <h2 className="section-title section-title--small order-history">History</h2>
      <ol className="events">
        {order.events?.map((e, i) => <li key={i}><strong>{e.label}</strong>{e.note ? ` — ${e.note}` : ''}{e.at ? <small> {formatDate(e.at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small> : null}</li>)}
      </ol>
    </div>
  )
}
