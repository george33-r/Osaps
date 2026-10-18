import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { api, ApiError, type JobRequest, type Offer } from '../api'
import { MessageThread } from '../components/Messages'
import { DesignPhotos } from '../components/DesignPhotos'
import {
  Badge,
  Button,
  ButtonLink,
  EmptyState,
  ErrorState,
  Field,
  OfferStatusBadge,
  PageHeader,
  Skeleton,
  errorMessage,
} from '../components/ui'
import { formatDate, groupDigits, nairaToMinor, plural, timeLeft } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

function MatchNote({ request }: { request: JobRequest }) {
  if (!request.match) return null
  return (
    <div className="match-note">
      <span className="match-note__score" aria-label={`Match score ${request.match.score} out of 100`}>
        {request.match.score}
      </span>
      <ul>
        {request.match.reasons.slice(0, 3).map((r) => (
          <li key={r}>{r}</li>
        ))}
      </ul>
    </div>
  )
}

export function TailorInbox() {
  const res = useAsync(() => api.inbox(), [])
  return (
    <div className="page">
      <PageHeader
        title="Job inbox"
        intro="Open requests, best fit first. Fit is worked out from what you make and where you work."
        aside={<ButtonLink to="/tailor/profile" variant="secondary">Edit profile</ButtonLink>}
      />
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <Skeleton lines={6} />
      ) : !res.data.has_profile ? (
        <EmptyState title="Set up your tailor profile first" action={<ButtonLink to="/tailor/profile">Set up profile</ButtonLink>}>
          Tell customers what you make and where you work. You need a profile before you can send offers.
        </EmptyState>
      ) : res.data.data.length === 0 ? (
        <EmptyState title="No open requests right now">New requests appear here as customers post them.</EmptyState>
      ) : (
        <ul className="rows">
          {res.data.data.map((r) => (
            <li key={r.id}>
              <Link to={`/tailor/requests/${r.id}`} className="row row--job">
                <MatchNote request={r} />
                <div className="row__main">
                  <h2 className="row__title">{r.title}</h2>
                  <p className="row__meta">
                    {r.garment_label} in {r.city}, needed by {formatDate(r.needed_by)}
                  </p>
                  <p className="row__meta">
                    {r.mode === 'budget' && r.budget_max ? `Customer's price: ${r.budget_max.formatted}` : r.budget_max ? `Open offers up to ${r.budget_max.formatted}` : 'Open offers, no range given'}
                  </p>
                </div>
                <div className="row__side">
                  {r.my_offer ? <OfferStatusBadge status={r.my_offer.status} viewer="tailor" /> : <Badge tone="waiting">New</Badge>}
                  <span className="row__count">{plural(r.offer_count ?? 0, 'offer')} so far</span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function OfferForm({ request, onDone }: { request: JobRequest; onDone: () => void }) {
  const toast = useToast()
  const budget = request.mode === 'budget' ? request.budget_max : null
  const [amount, setAmount] = useState(budget ? groupDigits(String(budget.amount_minor / 100)) : '')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const minor = nairaToMinor(amount)
  const needsReason = request.mode === 'budget' ? !!budget && minor !== budget.amount_minor : !!request.budget_max && !!minor && minor > request.budget_max.amount_minor

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const fitting = f.get('includes_fitting')
    setBusy(true)
    setError(null)
    try {
      await api.submitOffer(request.id, {
        amount_minor: minor ?? 0,
        delivery_days: Number(f.get('delivery_days')),
        includes_fitting: fitting === 'yes' ? true : fitting === 'no' ? false : null,
        alterations_included: f.get('alterations') === '' ? null : Number(f.get('alterations')),
        delivery_fee_minor: f.get('delivery_fee') === '' ? null : nairaToMinor(String(f.get('delivery_fee'))) ?? 0,
        message: String(f.get('message') ?? '').trim() || null,
      })
      toast('Offer sent to the customer.', 'good')
      onDone()
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="form" onSubmit={submit} noValidate>
      <h2 className="form__section-title">Your offer</h2>
      {error && !Object.keys(error.fieldErrors).length && <p className="form__error" role="alert">{error.message}</p>}
      {budget && (
        <div className="segmented" role="group" aria-label="Price">
          <button type="button" className={`segmented__option ${minor === budget.amount_minor ? 'is-on' : ''}`} onClick={() => setAmount(groupDigits(String(budget.amount_minor / 100)))}>
            Accept {budget.formatted}
          </button>
          <button type="button" className={`segmented__option ${minor !== budget.amount_minor ? 'is-on' : ''}`} onClick={() => setAmount('')}>
            Propose a different price
          </button>
        </div>
      )}
      <div className="form__row">
        <Field label="Price for tailoring" error={error?.field('amount_minor')}>
          {(p) => (
            <div className="money-input">
              <span aria-hidden>₦</span>
              <input {...p} inputMode="numeric" className="input" value={amount} onChange={(e) => setAmount(groupDigits(e.target.value))} />
            </div>
          )}
        </Field>
        <Field label="Ready in (days)" error={error?.field('delivery_days')}>
          {(p) => <input {...p} name="delivery_days" type="number" min={1} max={120} className="input" required />}
        </Field>
      </div>
      <div className="form__row">
        <Field label="Fitting included?">
          {(p) => (
            <select {...p} name="includes_fitting" className="input input--select" defaultValue={request.fitting_required ? 'yes' : ''}>
              <option value="">Prefer not to say</option>
              <option value="yes">Yes</option>
              <option value="no">No</option>
            </select>
          )}
        </Field>
        <Field label="Free alterations" optional>
          {(p) => <input {...p} name="alterations" type="number" min={0} max={10} className="input" />}
        </Field>
        <Field label="Delivery fee" optional hint="Enter 0 if delivery is free.">
          {(p) => (
            <div className="money-input">
              <span aria-hidden>₦</span>
              <input {...p} name="delivery_fee" inputMode="numeric" className="input" />
            </div>
          )}
        </Field>
      </div>
      <Field
        label={needsReason ? 'Why this price?' : 'Message to the customer'}
        optional={!needsReason}
        hint={needsReason ? 'Required when your price differs from the customer’s. Explain what the work involves.' : undefined}
        error={error?.code === 'explanation_required' ? error.message : error?.field('message')}
      >
        {(p) => <textarea {...p} name="message" className="input input--area" rows={4} maxLength={1000} />}
      </Field>
      <p className="muted">Your offer stays open for 48 hours. Not stating fitting, alterations or delivery is allowed, but customers are prompted to ask about it.</p>
      <Button type="submit" busy={busy} disabled={!minor}>
        Send offer
      </Button>
    </form>
  )
}

function Negotiation({ offer, request, onDone }: { offer: Offer; request: JobRequest; onDone: () => void }) {
  const toast = useToast()
  const [amount, setAmount] = useState('')
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const live = offer.status === 'awaiting_customer' || offer.status === 'awaiting_tailor'
  const roundsLeft = offer.rounds.max_per_side - offer.rounds.tailor_used
  const customerAsked = offer.status === 'awaiting_tailor' ? offer.revisions?.at(-1)?.amount : null

  const run = async (key: string, fn: () => Promise<unknown>, done: string) => {
    setBusy(key)
    setError(null)
    try {
      await fn()
      toast(done, 'good')
      setAmount('')
      setNote('')
      onDone()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="negotiation">
      <div className="section-bar">
        <h2 className="form__section-title">Your offer</h2>
        <OfferStatusBadge status={offer.status} viewer="tailor" />
      </div>
      <ol className="revisions">
        {offer.revisions?.map((r, i) => (
          <li key={i} className={`revisions__item revisions__item--${r.author_role}`}>
            <span className="revisions__who">{r.author_role === 'tailor' ? 'You' : request.customer.first_name || 'Customer'}</span>
            <span className="revisions__amount">{r.amount.formatted}</span>
            {r.note && <p className="revisions__note">{r.note}</p>}
          </li>
        ))}
      </ol>
      {live && <p className="muted">{timeLeft(offer.expires_at)} before this offer expires.</p>}
      {error && <p className="form__error" role="alert">{error}</p>}

      {customerAsked && (
        <div className="notice">
          <p>
            The customer asked for <strong>{customerAsked.formatted}</strong>.
          </p>
          <Button busy={busy === 'agree'} onClick={() => run('agree', () => api.revise(offer.id, customerAsked.amount_minor, null, null), 'You agreed. The customer can now accept.')}>
            Agree to {customerAsked.formatted}
          </Button>
        </div>
      )}

      {live && roundsLeft > 0 && (
        <form
          className="form form--inline"
          onSubmit={(e) => {
            e.preventDefault()
            const minor = nairaToMinor(amount)
            if (!minor) return setError('Enter an amount in naira.')
            run('revise', () => api.revise(offer.id, minor, null, note.trim() || null), 'New price sent.')
          }}
        >
          <Field label={customerAsked ? 'Or propose another price' : 'Change your price'} hint={`${plural(roundsLeft, 'change')} left.`}>
            {(p) => (
              <div className="money-input">
                <span aria-hidden>₦</span>
                <input {...p} inputMode="numeric" className="input" value={amount} onChange={(e) => setAmount(groupDigits(e.target.value))} />
              </div>
            )}
          </Field>
          <Field label="Reason" optional={request.mode !== 'budget'}>
            {(p) => <input {...p} className="input" value={note} onChange={(e) => setNote(e.target.value)} maxLength={500} />}
          </Field>
          <Button type="submit" variant="secondary" busy={busy === 'revise'}>
            Send new price
          </Button>
        </form>
      )}

      {live && (
        <Button variant="quiet" busy={busy === 'withdraw'} onClick={() => run('withdraw', () => api.withdraw(offer.id), 'Offer withdrawn.')}>
          Withdraw offer
        </Button>
      )}
      <MessageThread offerId={offer.id} otherParty={request.customer.first_name || 'the customer'} open closed={!live && offer.status !== 'accepted'} />

      {offer.status === 'accepted' && (
        <div className="notice notice--good">The customer accepted your offer. <Link to="/orders">Go to your orders</Link></div>
      )}
    </section>
  )
}

export function TailorJob() {
  const { id = '' } = useParams()
  const res = useAsync(() => api.request(id), [id])
  if (res.status === 'error' && !res.data) return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton lines={8} /></div>
  const r = res.data
  const mine = r.my_offer && 'id' in r.my_offer ? r.my_offer : null

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/tailor/inbox">Job inbox</Link> <span aria-hidden>/</span> <span>{r.title}</span>
      </nav>
      <PageHeader title={r.title} intro={`${r.garment_label} for ${r.customer.first_name || 'a customer'} in ${r.city}`} />
      <div className="request-layout">
        <section className="request-layout__offers">
          {mine ? <Negotiation offer={mine} request={r} onDone={res.reload} /> : r.status === 'open' ? <OfferForm request={r} onDone={res.reload} /> : <p>This request is closed.</p>}
        </section>
        <aside className="request-layout__side">
          <section className="brief">
            <h2 className="brief__title">The brief</h2>
            <p>{r.description}</p>
            {r.style_tags.length > 0 && <p className="muted">Style: {r.style_tags.join(', ')}</p>}
            <DesignPhotos requestId={String(r.id)} photos={r.photos ?? []} editable={false} />
            <dl className="terms terms--compact">
              <div><dt>Price</dt><dd>{r.mode === 'budget' && r.budget_max ? `${r.budget_max.formatted} (customer's price)` : r.budget_max ? `Open, up to ${r.budget_max.formatted}` : 'Open offers'}</dd></div>
              <div><dt>Needed by</dt><dd>{formatDate(r.needed_by)}</dd></div>
              <div><dt>Fabric</dt><dd>{r.fabric_status === 'own_fabric' ? 'Customer has it' : r.fabric_status === 'need_advice' ? 'Wants your advice' : r.fabric?.name ?? 'Buying on OSAPS'}</dd></div>
              <div><dt>Fitting</dt><dd>{r.fitting_required ? 'Wanted' : 'Not needed'}</dd></div>
            </dl>
          </section>
          {r.match && (
            <section className="matches">
              <h2 className="brief__title">Why you are a fit</h2>
              <MatchNote request={r} />
            </section>
          )}
        </aside>
      </div>
    </div>
  )
}

export function TailorProfileEdit() {
  const { meta, user, refresh } = useSession()
  const navigate = useNavigate()
  const toast = useToast()
  const [specs, setSpecs] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const list = (k: string) => String(f.get(k) ?? '').split(',').map((s) => s.trim()).filter(Boolean)
    setBusy(true)
    setError(null)
    try {
      await api.saveTailorProfile({
        business_name: String(f.get('business_name') ?? '').trim(),
        bio: String(f.get('bio') ?? '').trim() || null,
        city: String(f.get('city') ?? '').trim(),
        service_cities: list('service_cities'),
        specialties: specs,
        portfolio_tags: list('portfolio_tags').map((t) => t.toLowerCase()),
        weekly_capacity: Number(f.get('weekly_capacity') || 5),
        starting_price_minor: nairaToMinor(String(f.get('starting_price') ?? '')),
        accepting_work: f.get('accepting_work') === 'on',
      })
      await refresh()
      toast('Profile saved.', 'good')
      navigate('/tailor/inbox')
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page page--form">
      <PageHeader title="Your tailor profile" intro="Customers see this when you send an offer. Verification by the OSAPS team comes after you sign up." />
      <form className="form form--wide" onSubmit={submit} noValidate>
        {error && <p className="form__error" role="alert">{Object.keys(error.fieldErrors).length ? 'Please fix the highlighted fields.' : error.message}</p>}
        <Field label="Business name" error={error?.field('business_name')}>
          {(p) => <input {...p} name="business_name" className="input" defaultValue={user?.name} />}
        </Field>
        <Field label="About your work" optional>
          {(p) => <textarea {...p} name="bio" className="input input--area" rows={3} maxLength={1500} />}
        </Field>
        <fieldset className="filters__group">
          <legend className="field__label">What you make</legend>
          <div className="chips">
            {meta?.garment_categories.map((g) => (
              <button key={g.key} type="button" className="chip" aria-pressed={specs.includes(g.key)} onClick={() => setSpecs((s) => (s.includes(g.key) ? s.filter((x) => x !== g.key) : [...s, g.key]))}>
                {g.label}
              </button>
            ))}
          </div>
          {error?.field('specialties') && <p className="field__error">Choose at least one.</p>}
        </fieldset>
        <div className="form__row">
          <Field label="Your city" error={error?.field('city')}>
            {(p) => <input {...p} name="city" className="input" defaultValue={user?.city ?? ''} />}
          </Field>
          <Field label="Cities you serve" hint="Separate with commas." error={error?.field('service_cities')}>
            {(p) => <input {...p} name="service_cities" className="input" defaultValue={user?.city ?? ''} />}
          </Field>
        </div>
        <div className="form__row">
          <Field label="Orders you can take per week">
            {(p) => <input {...p} name="weekly_capacity" type="number" min={1} max={200} defaultValue={5} className="input" />}
          </Field>
          <Field label="Prices from" optional>
            {(p) => (
              <div className="money-input">
                <span aria-hidden>₦</span>
                <input {...p} name="starting_price" inputMode="numeric" className="input" />
              </div>
            )}
          </Field>
        </div>
        <Field label="Style words" optional hint="e.g. embroidered, beaded, minimal">
          {(p) => <input {...p} name="portfolio_tags" className="input" />}
        </Field>
        <label className="check">
          <input type="checkbox" name="accepting_work" defaultChecked />I am taking new work
        </label>
        <Button type="submit" busy={busy}>
          Save profile
        </Button>
      </form>
    </div>
  )
}

