import { useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError, type JobRequest, type Offer, type Tailor } from '../api'
import { TrustLine } from '../components/cards'
import { MessageThread } from '../components/Messages'
import {
  Badge,
  Button,
  ButtonLink,
  Dialog,
  EmptyState,
  ErrorState,
  Field,
  OfferStatusBadge,
  PageHeader,
  Price,
  RequestStatusBadge,
  Skeleton,
  errorMessage,
} from '../components/ui'
import { formatDate, formatNaira, groupDigits, nairaToMinor, plural, timeLeft } from '../lib/format'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

export function MyRequests() {
  const res = useAsync(() => api.myRequests(), [])
  return (
    <div className="page">
      <PageHeader title="My requests" aside={<ButtonLink to="/requests/new">Post a request</ButtonLink>} />
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <Skeleton lines={6} />
      ) : res.data.data.length === 0 ? (
        <EmptyState title="You have not posted a request yet" action={<ButtonLink to="/requests/new">Post your first request</ButtonLink>}>
          Describe an outfit and tailors will send offers. You can name your price or let them quote.
        </EmptyState>
      ) : (
        <ul className="rows">
          {res.data.data.map((r) => (
            <li key={r.id}>
              <Link to={`/requests/${r.id}`} className="row">
                <div className="row__main">
                  <h2 className="row__title">{r.title}</h2>
                  <p className="row__meta">
                    {r.garment_label}, needed by {formatDate(r.needed_by)}
                  </p>
                </div>
                <div className="row__side">
                  <RequestStatusBadge status={r.status} />
                  {r.status === 'open' && <span className="row__count">{plural(r.live_offer_count ?? 0, 'live offer')}</span>}
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function tailorOf(o: Offer): Tailor | null {
  return o.tailor && 'slug' in o.tailor ? o.tailor : null
}

function OfferCard({ offer, request, onChanged }: { offer: Offer; request: JobRequest; onChanged: () => void }) {
  const toast = useToast()
  const navigate = useNavigate()
  const [dialog, setDialog] = useState<null | 'accept' | 'counter'>(null)
  const [busy, setBusy] = useState(false)
  const [counterAmount, setCounterAmount] = useState('')
  const [counterNote, setCounterNote] = useState('')
  const [counterError, setCounterError] = useState<string | null>(null)
  const tailor = tailorOf(offer)
  const live = offer.status === 'awaiting_customer' || offer.status === 'awaiting_tailor'
  const roundsLeft = offer.rounds.max_per_side - offer.rounds.customer_used
  const budget = request.budget_max?.amount_minor
  const diff = budget ? offer.amount.amount_minor - budget : 0

  const act = async (fn: () => Promise<unknown>, done: string) => {
    setBusy(true)
    try {
      await fn()
      toast(done, 'good')
      setDialog(null)
      onChanged()
    } catch (e) {
      toast(errorMessage(e), 'alert')
      onChanged()
    } finally {
      setBusy(false)
    }
  }

  return (
    <article className={`offer ${live ? '' : 'offer--closed'}`} aria-labelledby={`offer-${offer.id}`}>
      <header className="offer__head">
        <div>
          <h3 id={`offer-${offer.id}`} className="offer__tailor">
            {tailor ? <Link to={`/tailors/${tailor.slug}`}>{tailor.business_name}</Link> : offer.tailor?.business_name}
          </h3>
          {tailor && <TrustLine tailor={tailor} />}
        </div>
        <div className="offer__price">
          {offer.status === 'awaiting_tailor' && <span className="offer__price-label">Your counteroffer</span>}
          <Price value={offer.amount} large />
          {offer.at_customer_budget && <Badge tone="good">Accepts your price</Badge>}
          {!offer.at_customer_budget && budget !== undefined && diff !== 0 && (
            <span className={`offer__diff ${diff > 0 ? 'is-over' : ''}`}>
              {formatNaira(Math.abs(diff))} {diff > 0 ? 'above' : 'below'} your budget
            </span>
          )}
        </div>
      </header>

      <div className="offer__status">
        <OfferStatusBadge status={offer.status} viewer="customer" />
        {offer.comparison?.badges.map((b) => (
          <Badge key={b} tone="neutral">
            {b}
          </Badge>
        ))}
        {live && <span className="muted offer__expiry">{timeLeft(offer.expires_at)}</span>}
      </div>

      {offer.message && <blockquote className="offer__message">{offer.message}</blockquote>}

      <dl className="terms">
        <div><dt>Ready in</dt><dd>{offer.delivery_days ? plural(offer.delivery_days, 'day') : <span className="is-missing">Not stated</span>}</dd></div>
        <div><dt>Fitting</dt><dd>{offer.includes_fitting === null ? <span className="is-missing">Not stated</span> : offer.includes_fitting ? 'Included' : 'Not included'}</dd></div>
        <div><dt>Free alterations</dt><dd>{offer.alterations_included === null ? <span className="is-missing">Not stated</span> : offer.alterations_included}</dd></div>
        <div><dt>Delivery</dt><dd>{offer.delivery_fee === null ? <span className="is-missing">Not stated</span> : offer.delivery_fee.amount_minor === 0 ? 'Free' : offer.delivery_fee.formatted}</dd></div>
      </dl>

      {offer.comparison && offer.comparison.questions.length > 0 && (
        <div className="ask-first">
          <p className="ask-first__title">Worth asking before you accept</p>
          <ul>
            {offer.comparison.questions.map((q) => <li key={q}>{q}</li>)}
          </ul>
          <p className="ask-first__source">Found by comparing the offers with fixed rules, not AI. Tap a question below to send it.</p>
        </div>
      )}

      <MessageThread
        offerId={offer.id}
        otherParty={offer.tailor?.business_name ?? 'the tailor'}
        suggestions={live ? offer.comparison?.questions ?? [] : []}
        open={live && (offer.comparison?.questions.length ?? 0) > 0}
        closed={!live && offer.status !== 'accepted'}
      />

      {offer.cost_breakdown && (
        <details className="breakdown">
          <summary>
            Total you would pay <strong>{offer.cost_breakdown.total.formatted}</strong>
          </summary>
          <dl>
            {offer.cost_breakdown.lines.map((l) => (
              <div key={l.key}>
                <dt>{l.label}</dt>
                <dd>{l.amount.formatted}</dd>
              </div>
            ))}
          </dl>
        </details>
      )}

      {offer.revisions && offer.revisions.length > 1 && (
        <details className="history">
          <summary>Negotiation so far ({plural(offer.revisions.length, 'step')})</summary>
          <ol>
            {offer.revisions.map((r, i) => (
              <li key={i}>
                <strong>{r.author_role === 'customer' ? 'You' : 'Tailor'}</strong> proposed {r.amount.formatted}
                {r.note && <> — “{r.note}”</>}
              </li>
            ))}
          </ol>
        </details>
      )}

      {live && (
        <div className="offer__actions">
          {offer.actions.includes('accepted') && <Button onClick={() => setDialog('accept')}>Accept offer</Button>}
          {offer.actions.includes('awaiting_tailor') && roundsLeft > 0 && (
            <Button variant="secondary" onClick={() => setDialog('counter')}>
              Counter
            </Button>
          )}
          {offer.actions.includes('declined') && (
            <Button variant="quiet" busy={busy} onClick={() => act(() => api.decline(offer.id), 'Offer declined.')}>
              Decline
            </Button>
          )}
          {offer.status === 'awaiting_tailor' && <p className="muted">You countered. Waiting for the tailor to agree or reply.</p>}
        </div>
      )}

      <Dialog open={dialog === 'accept'} onClose={() => setDialog(null)} title="Accept this offer?">
        <p>
          You are choosing <strong>{offer.tailor?.business_name}</strong>. The price and the details below will be locked into your order, and
          the other offers will be closed.
        </p>
        <dl className="terms terms--dialog">
          <div><dt>Tailoring</dt><dd>{offer.amount.formatted}</dd></div>
          {offer.cost_breakdown?.lines.filter((l) => l.key !== 'tailoring').map((l) => (
            <div key={l.key}><dt>{l.label}</dt><dd>{l.amount.formatted}</dd></div>
          ))}
          <div className="terms__total"><dt>Total</dt><dd>{offer.cost_breakdown?.total.formatted}</dd></div>
        </dl>
        <p className="muted">Paying through OSAPS is not available yet. Your order is confirmed now, and you arrange payment with the tailor.</p>
        <div className="dialog__actions">
          <Button
            busy={busy}
            onClick={async () => {
              setBusy(true)
              try {
                const order = await api.accept(offer.id)
                toast('Offer accepted. Your order is confirmed.', 'good')
                navigate(`/orders/${order.reference}`)
              } catch (e) {
                toast(errorMessage(e), 'alert')
                setDialog(null)
                onChanged()
              } finally {
                setBusy(false)
              }
            }}
          >
            Accept and confirm order
          </Button>
          <Button variant="quiet" onClick={() => setDialog(null)}>
            Keep comparing
          </Button>
        </div>
      </Dialog>

      <Dialog open={dialog === 'counter'} onClose={() => setDialog(null)} title="Counter this offer">
        <form
          className="form"
          onSubmit={async (e) => {
            e.preventDefault()
            const minor = nairaToMinor(counterAmount)
            if (!minor) return setCounterError('Enter an amount in naira.')
            setCounterError(null)
            setBusy(true)
            try {
              await api.counter(offer.id, minor, counterNote.trim() || null)
              toast('Counteroffer sent.', 'good')
              setDialog(null)
              onChanged()
            } catch (err) {
              setCounterError(err instanceof ApiError ? err.field('amount_minor') ?? err.message : errorMessage(err))
            } finally {
              setBusy(false)
            }
          }}
        >
          <p className="muted">
            The tailor asked for {offer.amount.formatted}. You have {plural(roundsLeft, 'counteroffer')} left on this offer.
          </p>
          <Field label="Your price" error={counterError ?? undefined}>
            {(p) => (
              <div className="money-input">
                <span aria-hidden>₦</span>
                <input {...p} inputMode="numeric" className="input" value={counterAmount} onChange={(e) => setCounterAmount(groupDigits(e.target.value))} autoFocus />
              </div>
            )}
          </Field>
          <Field label="Note to the tailor" optional>
            {(p) => <textarea {...p} className="input input--area" rows={3} maxLength={500} value={counterNote} onChange={(e) => setCounterNote(e.target.value)} />}
          </Field>
          <div className="dialog__actions">
            <Button type="submit" busy={busy}>
              Send counteroffer
            </Button>
            <Button type="button" variant="quiet" onClick={() => setDialog(null)}>
              Cancel
            </Button>
          </div>
        </form>
      </Dialog>
    </article>
  )
}

export function RequestDetail() {
  const { id = '' } = useParams()
  const toast = useToast()
  const res = useAsync(() => api.request(id), [id])
  const matches = useAsync(() => api.matches(id), [id])
  const [cancelOpen, setCancelOpen] = useState(false)
  const [busy, setBusy] = useState(false)

  if (res.status === 'error' && !res.data) return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton lines={8} /></div>
  const r = res.data
  const offers = r.offers ?? []
  const live = offers.filter((o) => o.status === 'awaiting_customer' || o.status === 'awaiting_tailor')
  const closed = offers.filter((o) => !live.includes(o))

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/requests">My requests</Link> <span aria-hidden>/</span> <span>{r.title}</span>
      </nav>
      <PageHeader
        label={r.occasion_label ?? r.garment_label}
        title={r.title}
        intro={
          <>
            {r.garment_label} in {r.city}, needed by {formatDate(r.needed_by)}.{' '}
            {r.mode === 'budget' && r.budget_max ? <>Your price: {r.budget_max.formatted}.</> : <>Open to offers{r.budget_max ? ` up to ${r.budget_max.formatted}` : ''}.</>}
          </>
        }
        aside={<RequestStatusBadge status={r.status} />}
      />

      {r.status === 'awarded' && r.order_reference && (
        <div className="notice notice--good">
          You chose a tailor. <Link to={`/orders/${r.order_reference}`}>Follow your order</Link>
        </div>
      )}

      <div className="request-layout">
        <section className="request-layout__offers" aria-labelledby="offers-title">
          <div className="section-bar">
            <h2 id="offers-title" className="section-title section-title--small">
              {live.length ? `${plural(live.length, 'offer')} to compare` : 'Offers'}
            </h2>
            {r.status === 'open' && <span className="muted">Offers close {timeLeft(r.offers_close_at).replace(' left', '')} from now</span>}
          </div>
          {live.length === 0 && r.status === 'open' && (
            <EmptyState title="No offers yet">
              Tailors who make {r.garment_label.toLowerCase()} in {r.city} can see your request. Most offers arrive within a day or two.
            </EmptyState>
          )}
          <div className="offers">
            {live.map((o) => (
              <OfferCard key={o.id} offer={o} request={r} onChanged={res.reload} />
            ))}
          </div>
          {closed.length > 0 && (
            <details className="closed-offers">
              <summary>{plural(closed.length, 'closed offer')}</summary>
              <div className="offers">
                {closed.map((o) => (
                  <OfferCard key={o.id} offer={o} request={r} onChanged={res.reload} />
                ))}
              </div>
            </details>
          )}
        </section>

        <aside className="request-layout__side">
          <section className="brief">
            <h2 className="brief__title">Your brief</h2>
            <p>{r.description}</p>
            {r.style_tags.length > 0 && <p className="muted">Style: {r.style_tags.join(', ')}</p>}
            <dl className="terms terms--compact">
              <div><dt>Fabric</dt><dd>{r.fabric_status === 'own_fabric' ? 'You have it' : r.fabric_status === 'need_advice' ? 'Advice wanted' : r.fabric?.name ?? 'Buying on OSAPS'}</dd></div>
              <div><dt>Fitting</dt><dd>{r.fitting_required ? 'Wanted' : 'Not needed'}</dd></div>
            </dl>
            {r.status === 'open' && (
              <Button variant="quiet" onClick={() => setCancelOpen(true)}>
                Cancel request
              </Button>
            )}
          </section>

          {r.status === 'open' && matches.data && matches.data.length > 0 && (
            <section className="matches">
              <h2 className="brief__title">Tailors who fit this request</h2>
              <p className="muted matches__how">Ranked by what they make, where they work and their OSAPS record. Not by price or payment.</p>
              <ol className="matches__list">
                {matches.data.slice(0, 4).map((t) => (
                  <li key={t.slug}>
                    <Link to={`/tailors/${t.slug}`} className="matches__name">{t.business_name}</Link>
                    <ul className="matches__reasons">
                      {t.match?.reasons.map((reason) => <li key={reason}>{reason}</li>)}
                    </ul>
                  </li>
                ))}
              </ol>
            </section>
          )}
        </aside>
      </div>

      <Dialog open={cancelOpen} onClose={() => setCancelOpen(false)} title="Cancel this request?">
        <p>Tailors will no longer see it, and any open offers will close. This cannot be undone.</p>
        <div className="dialog__actions">
          <Button
            variant="danger"
            busy={busy}
            onClick={async () => {
              setBusy(true)
              try {
                await api.cancelRequest(r.id)
                toast('Request cancelled.', 'neutral')
                setCancelOpen(false)
                res.reload()
              } catch (e) {
                toast(errorMessage(e), 'alert')
              } finally {
                setBusy(false)
              }
            }}
          >
            Cancel request
          </Button>
          <Button variant="quiet" onClick={() => setCancelOpen(false)}>
            Keep it open
          </Button>
        </div>
      </Dialog>
    </div>
  )
}
