import { useEffect, useMemo, useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError, type AsoEbiGroup, type PriceOffer, type ShopQuote } from '../api'
import { Countdown, QtyStepper } from '../components/ShopKit'
import { Swatch } from '../components/Swatch'
import { Button, ButtonLink, EmptyState, ErrorState, Field, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { Approx } from '../lib/fx'
import { formatDate, formatNaira } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const isoDay = (d: Date) => d.toISOString().slice(0, 10)

function shareText(g: AsoEbiGroup) {
  return `Aṣọ-ẹbí for ${g.title} 🎉\n${g.yards_per_guest} ${g.fabric.unit}s of ${g.fabric.name}: ${g.share_price.formatted}. Pay your share here before ${formatDate(g.deadline_at)}:\n${window.location.origin}/aso-ebi/${g.code}`
}

/** Organiser starts a group on a fabric. */
export function AsoEbiNew() {
  const { slug = '' } = useParams()
  const { user } = useSession()
  const navigate = useNavigate()
  const fabric = useAsync(() => api.fabric(slug), [slug])
  const deals = useAsync(() => api.myPriceOffers().then((l) => l.filter((o) => o.status === 'accepted' && o.fabric?.slug === slug)), [slug])
  const [yards, setYards] = useState(5)
  const [guests, setGuests] = useState(20)
  const [dealId, setDealId] = useState<number | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  useEffect(() => { if (fabric.data) setYards((y) => Math.max(y, fabric.data!.min_order_quantity)) }, [fabric.data])

  if (fabric.status === 'error') return <div className="page"><ErrorState error={fabric.error} /></div>
  if (!fabric.data) return <div className="page"><Skeleton lines={5} /></div>
  const f = fabric.data
  const deal: PriceOffer | undefined = deals.data?.find((d) => d.id === dealId)
  const unit = deal ? Math.min(deal.unit.amount_minor, f.price.amount_minor) : f.price.amount_minor
  const totalYards = yards * guests

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const d = new FormData(e.currentTarget)
    const s = (k: string) => String(d.get(k) ?? '').trim()
    setBusy(true); setError(null)
    try {
      const g = await api.createAsoEbi(slug, {
        title: s('title'), message: s('message') || null, event_date: s('event_date'),
        deadline_at: new Date(s('deadline') + 'T23:59:00').toISOString(), yards_per_guest: yards, target_guests: guests,
        fulfilment: s('fulfilment') as 'deliver_each' | 'pickup_organizer', organizer_phone: s('phone'), price_offer_id: dealId,
      })
      navigate(`/aso-ebi/${g.code}?new=1`)
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')); setBusy(false) }
  }

  return (
    <div className="page page--narrow">
      <PageHeader label="Aṣọ-ẹbí" title="Dress the whole family" intro="Pick the yards per guest, share one link in your WhatsApp group, and everyone pays their own share. Same fabric, same bolt, colours that match." />
      <div className="aso-fabric">
        <span className="aso-fabric__swatch"><Swatch pattern={f.pattern} colors={f.colors} scale={0.8} /></span>
        <span><strong>{f.name}</strong><small>{f.vendor?.business_name} · {f.price.formatted} per {f.unit} · {f.available_quantity} in stock</small></span>
      </div>
      <form className="new-listing" onSubmit={submit}>
        <Field label="What is it for?" error={error?.field('title')}>{(p) => <input {...p} name="title" className="input" required minLength={3} maxLength={80} placeholder="Tola & Femi's wedding" />}</Field>
        <div className="checkout__pair">
          <div className="field"><span className="field__label">Per guest</span><QtyStepper value={yards} min={Math.max(1, f.min_order_quantity)} max={50} unit={f.unit} onChange={setYards} /></div>
          <div className="field"><span className="field__label">Guests you expect</span><QtyStepper value={guests} min={2} max={500} unit="guest" onChange={setGuests} /></div>
        </div>
        {totalYards > f.available_quantity && <p className="cart-line__issue">That is {totalYards} {f.unit}s; the vendor has {f.available_quantity} now. Guests who pay first get theirs; ask the vendor about more.</p>}
        {deals.data && deals.data.length > 0 && (
          <div className="field">
            <span className="field__label">Use your agreed bulk price</span>
            <div className="name-price__chips">
              <button type="button" className="chip" aria-pressed={dealId === null} onClick={() => setDealId(null)}>List price {f.price.formatted}</button>
              {deals.data.map((d) => <button key={d.id} type="button" className="chip" aria-pressed={dealId === d.id} onClick={() => setDealId(d.id)}>{d.unit.formatted} for {d.quantity} {f.unit}s</button>)}
            </div>
          </div>
        )}
        {!deals.data?.length && <p className="muted">Tip: buying a lot? <Link to={`/fabrics/${slug}`}>Name your price</Link> for {totalYards} {f.unit}s first, then use the deal here.</p>}
        <div className="checkout__pair">
          <Field label="Event date">{(p) => <input {...p} name="event_date" type="date" className="input" required min={isoDay(new Date(Date.now() + 86_400_000))} />}</Field>
          <Field label="Guests pay by" error={error?.field('deadline_at')}>{(p) => <input {...p} name="deadline" type="date" className="input" required min={isoDay(new Date())} />}</Field>
        </div>
        <Field label="How guests get their fabric">{(p) => (
          <select {...p} name="fulfilment" className="input">
            <option value="deliver_each">Delivered to each guest (they pay delivery)</option>
            <option value="pickup_organizer">I collect it all from the vendor (free)</option>
          </select>
        )}</Field>
        <Field label="Your phone" hint="The vendor calls this number if you collect." error={error?.field('organizer_phone')}>{(p) => <input {...p} name="phone" type="tel" className="input" defaultValue={user?.phone ?? ''} required />}</Field>
        <Field label="Message to guests" optional>{(p) => <textarea {...p} name="message" className="input" rows={3} maxLength={500} placeholder="Colour of the day is royal blue. Gèlè is optional!" />}</Field>
        <div className="aso-sum">
          <span>Each guest pays about</span>
          <strong>{formatNaira(unit * yards)}</strong>
          <small>{yards} × {formatNaira(unit)} + delivery and service fee at checkout</small>
          <Approx minor={unit * yards} />
        </div>
        {error && error.code !== 'validation_failed' && <p className="field__error" role="alert">{error.message}</p>}
        <Button type="submit" busy={busy}>Create my aṣọ-ẹbí link</Button>
      </form>
    </div>
  )
}

/** The page everyone opens from the WhatsApp link. */
export function AsoEbiPage() {
  const { code = '' } = useParams()
  const { user } = useSession()
  const toast = useToast()
  const navigate = useNavigate()
  const res = useAsync(() => api.asoEbi(code), [code, user?.id])
  const [group, setGroup] = useState<AsoEbiGroup | null>(null)
  const [yards, setYards] = useState(0)
  const [quote, setQuote] = useState<ShopQuote | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const isNew = new URLSearchParams(window.location.search).get('new') === '1'

  useEffect(() => { if (res.data) { setGroup(res.data); setYards((y) => y || res.data!.yards_per_guest) } }, [res.data])
  useEffect(() => {
    if (!user || !group || group.status !== 'open' || !yards) return
    let live = true
    api.quoteAsoEbi(code, yards).then((q) => live && setQuote(q)).catch(() => undefined)
    return () => { live = false }
  }, [user, group, yards, code])

  const wa = useMemo(() => (group ? `https://wa.me/?text=${encodeURIComponent(shareText(group))}` : '#'), [group])
  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!group) return <div className="page"><Skeleton lines={6} /></div>
  const g = group
  const f = g.fabric
  const delivery = g.fulfilment === 'deliver_each'

  const act = async (fn: () => Promise<AsoEbiGroup>, msg: string) => {
    try { setGroup(await fn()); toast(msg, 'good') } catch (e) { toast(errorMessage(e), 'alert') }
  }

  const pay = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (!quote?.ok) return
    const d = new FormData(e.currentTarget)
    const s = (k: string) => String(d.get(k) ?? '').trim()
    setBusy(true); setError(null)
    try {
      const c = await api.joinAsoEbi(code, {
        yards, show_name: d.get('show_name') === 'on', expected_total_minor: quote.total.amount_minor,
        contact: { name: s('name'), phone: s('phone') },
        address: delivery ? { line1: s('line1'), city: s('city'), state: s('state'), landmark: s('landmark') || undefined } : null,
      })
      try { sessionStorage.setItem('osaps-pending-checkout', c.reference) } catch { /* ignore */ }
      if (c.authorization_url?.startsWith('/')) navigate(c.authorization_url)
      else if (c.authorization_url) window.location.assign(c.authorization_url)
    } catch (err) {
      const e2 = err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')
      setError(e2)
      if (e2.code === 'cart_changed') setQuote(await api.quoteAsoEbi(code, yards).catch(() => null))
      setBusy(false)
    }
  }

  return (
    <div className="page aso">
      <div className="aso__hero">
        <div className="aso__cloth">
          {f.image_url ? <img src={f.image_url} alt={f.name} /> : <Swatch pattern={f.pattern} colors={f.colors} scale={1.6} />}
          {f.has_video && <span className="aso__badge">Real video on the fabric page</span>}
        </div>
        <div className="aso__info">
          <p className="label">Aṣọ-ẹbí · organised by {g.organizer_name}</p>
          <h1 className="aso__title">{g.title}</h1>
          <p className="aso__when">{formatDate(g.event_date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</p>
          {g.message && <p className="aso__msg">“{g.message}”</p>}
          <p className="aso__fabric"><Link to={`/fabrics/${f.slug}`}>{f.name}</Link> from {f.vendor?.business_name}, {f.vendor?.market ?? f.vendor?.city}</p>
          <div className="aso__price">
            <strong>{g.share_price.formatted}</strong>
            <span>for {g.yards_per_guest} {f.unit}s{g.deal && g.unit_price.amount_minor < g.list_unit_price.amount_minor ? ` · group price ${g.unit_price.formatted} (was ${g.list_unit_price.formatted})` : ''}</span>
            <Approx minor={g.share_price.amount_minor} />
          </div>
          <div className="aso__progress" role="img" aria-label={`${g.progress.paid} of ${g.progress.target} guests have paid`}>
            <div className="aso__bar"><span style={{ width: `${g.progress.percent}%` }} /></div>
            <p><strong>{g.progress.paid} of {g.progress.target}</strong> have paid{g.paid_names.length ? `: ${g.paid_names.slice(0, 8).join(', ')}${g.paid_names.length > 8 ? '…' : ''}` : ''}</p>
          </div>
          {g.status === 'open' ? <p className="muted">Pay by {formatDate(g.deadline_at)} · <Countdown iso={g.deadline_at} /></p> : <p className="aso__closed">{g.status === 'cancelled' ? 'This aṣọ-ẹbí was cancelled.' : 'Payments are closed. Ask the organiser.'}</p>}
          <div className="paid__actions">
            <a className="btn btn--primary aso__wa" href={wa} target="_blank" rel="noopener noreferrer">Share on WhatsApp</a>
            <Button variant="secondary" onClick={async () => { try { await navigator.clipboard.writeText(`${window.location.origin}/aso-ebi/${g.code}`); toast('Link copied.', 'good') } catch { toast('Copy the address from your browser bar.', 'neutral') } }}>Copy link</Button>
          </div>
          {isNew && g.is_organizer && <p className="aso__new" role="status">Your link is ready. Share it in the family group chat 🎉</p>}
        </div>
      </div>

      {g.status === 'open' && (
        <section className="aso__pay" aria-labelledby="pay-title">
          <h2 id="pay-title" className="checkout__h">{g.my_share?.status === 'paid' ? 'You have paid. Buying for someone else too?' : 'Pay your share'}</h2>
          {!user ? (
            <p><Link to={`/signin?next=${encodeURIComponent(`/aso-ebi/${g.code}`)}`}>Sign in</Link> or <Link to={`/register?next=${encodeURIComponent(`/aso-ebi/${g.code}`)}`}>create an account</Link> to pay. It takes a minute.</p>
          ) : (
            <form className="cart" onSubmit={pay}>
              <div className="checkout">
                <div className="checkout__block">
                  <div className="field"><span className="field__label">How much fabric</span><QtyStepper value={yards} min={Math.max(1, f.min_order_quantity)} max={50} unit={f.unit} onChange={setYards} /></div>
                  <Field label="Your name">{(p) => <input {...p} name="name" className="input" defaultValue={user.name} required minLength={2} />}</Field>
                  <Field label="Phone" error={error?.field('contact.phone')}>{(p) => <input {...p} name="phone" type="tel" className="input" defaultValue={user.phone ?? ''} required />}</Field>
                  {delivery ? (
                    <>
                      <Field label="Delivery address" error={error?.field('address.line1')}>{(p) => <input {...p} name="line1" className="input" required />}</Field>
                      <div className="checkout__pair">
                        <Field label="City">{(p) => <input {...p} name="city" className="input" defaultValue={user.city ?? ''} required />}</Field>
                        <Field label="State">{(p) => <input {...p} name="state" className="input" required />}</Field>
                      </div>
                      <Field label="Nearest landmark" optional>{(p) => <input {...p} name="landmark" className="input" />}</Field>
                    </>
                  ) : <p className="muted">{g.organizer_name} collects everyone's fabric from the vendor. No delivery fee.</p>}
                  <label className="check"><input type="checkbox" name="show_name" defaultChecked /> Show my first name on this page</label>
                </div>
              </div>
              <aside className="bag">
                <p className="bag__title">Your share</p>
                {quote ? (
                  <dl className="bag__lines">
                    <div><dt>{yards} × {quote.vendors[0]?.lines[0]?.unit_price.formatted}</dt><dd>{quote.subtotal.formatted}</dd></div>
                    <div><dt>Delivery</dt><dd>{quote.delivery.amount_minor ? quote.delivery.formatted : 'Free'}</dd></div>
                    <div><dt>Service fee</dt><dd>{quote.service_fee.formatted}</dd></div>
                    <div className="bag__total"><dt>Total</dt><dd>{quote.total.formatted}</dd></div>
                  </dl>
                ) : <Skeleton lines={3} />}
                {quote && <Approx minor={quote.total.amount_minor} />}
                {[...(quote?.blocking ?? []), ...(quote?.notices ?? [])].map((p) => <p key={p.code} className="cart-line__issue">{p.message}</p>)}
                {error && error.code !== 'validation_failed' && <p className="field__error" role="alert">{error.message}</p>}
                <Button type="submit" className="bag__cta" busy={busy} disabled={!quote?.ok}>{quote ? `Pay ${quote.total.formatted}` : '…'}</Button>
                <p className="bag__fine">Paystack: card, transfer or USSD.</p>
              </aside>
            </form>
          )}
        </section>
      )}

      {g.is_organizer && (
        <section className="aso__admin" aria-labelledby="org-title">
          <h2 id="org-title" className="checkout__h">Organiser</h2>
          <p className="muted">{g.yards_paid} {f.unit}s paid so far{g.deal ? ` · ${g.deal.left ?? 0} ${f.unit}s left at the group price` : ''}.</p>
          {g.guests && g.guests.length > 0 && (
            <ul className="aso__guests">{g.guests.map((x, i) => <li key={i}><span>{x.name}</span><span>{x.yards} {f.unit}s</span><span className={`aso__st aso__st--${x.status}`}>{x.status === 'paid' ? 'Paid' : 'Paying…'}</span></li>)}</ul>
          )}
          <div className="paid__actions">
            {g.status === 'open' && <Button variant="secondary" onClick={() => act(() => api.closeAsoEbi(code), 'Payments closed.')}>Close payments</Button>}
            {g.status === 'closed' && <Button variant="secondary" onClick={() => act(() => api.reopenAsoEbi(code), 'Reopened.')}>Reopen</Button>}
            {g.status !== 'cancelled' && g.progress.paid === 0 && <Button variant="quiet" onClick={() => act(() => api.cancelAsoEbi(code), 'Cancelled.')}>Cancel this aṣọ-ẹbí</Button>}
          </div>
        </section>
      )}
    </div>
  )
}

export function MyAsoEbi() {
  const res = useAsync(() => api.myAsoEbi(), [])
  return (
    <div className="page">
      <PageHeader title="Aṣọ-ẹbí" intro="Groups you organise and groups you paid into." aside={<ButtonLink to="/fabrics" variant="secondary">Start one from a fabric</ButtonLink>} />
      {res.status === 'error' ? <ErrorState error={res.error} onRetry={res.reload} /> : !res.data ? <Skeleton lines={4} /> : res.data.length === 0 ? (
        <EmptyState title="No aṣọ-ẹbí yet" action={<ButtonLink to="/fabrics">Choose a fabric</ButtonLink>}>Open any fabric and tap “Start aṣọ-ẹbí”.</EmptyState>
      ) : (
        <div className="forders">
          {res.data.map((g) => (
            <Link key={g.code} to={`/aso-ebi/${g.code}`} className="forder">
              <span className="forder__swatches"><span className="forder__swatch"><Swatch pattern={g.fabric.pattern} colors={g.fabric.colors} scale={0.5} /></span></span>
              <span className="forder__body"><strong>{g.title}</strong><span>{g.fabric.name} · {g.share_price.formatted} each</span><small>{g.is_organizer ? 'You organise' : 'You paid'} · {formatDate(g.event_date)}</small></span>
              <span className="forder__status">{g.progress.paid}/{g.progress.target} paid<strong>{g.status}</strong></span>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}
