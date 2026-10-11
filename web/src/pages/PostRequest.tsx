import { useMemo, useState, type FormEvent } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError, type FabricStatus, type PricingMode } from '../api'
import { Swatch } from '../components/Swatch'
import { Button, Field, PageHeader } from '../components/ui'
import { formatNaira, groupDigits, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const FABRIC_OPTIONS: { key: FabricStatus; label: string; hint: string }[] = [
  { key: 'own_fabric', label: 'I already have the fabric', hint: 'You will hand it to the tailor.' },
  { key: 'buying_on_platform', label: 'I am buying fabric listed here', hint: 'Pick it below so tailors know what they will work with.' },
  { key: 'need_advice', label: 'I need advice on fabric', hint: 'Tailors can suggest fabric in their offer.' },
]

function minDate() {
  const d = new Date(Date.now() + 86_400_000)
  return d.toISOString().slice(0, 10)
}

export default function PostRequest() {
  const { meta, user } = useSession()
  const navigate = useNavigate()
  const toast = useToast()
  const [params] = useSearchParams()
  const preselected = params.get('fabric')

  const [mode, setMode] = useState<PricingMode>('budget')
  const [fabricStatus, setFabricStatus] = useState<FabricStatus>(preselected ? 'buying_on_platform' : 'own_fabric')
  const [fabricId, setFabricId] = useState<number | null>(preselected ? Number(preselected) : null)
  const [budget, setBudget] = useState('')
  const [budgetMin, setBudgetMin] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const fabrics = useAsync(() => (fabricStatus === 'buying_on_platform' ? api.fabrics({ in_stock: true }) : Promise.resolve(null)), [fabricStatus])
  const chosen = fabrics.data?.data.find((f) => f.id === fabricId)

  // Deterministic estimate from the published fee rules — no AI, no guessed market prices.
  const estimate = useMemo(() => {
    const minor = nairaToMinor(budget)
    if (!minor || !meta) return null
    const fee = Math.min(Math.floor((minor * meta.fees.customer_service_fee_bps + 5000) / 10_000), meta.fees.customer_service_fee_cap_minor || Infinity)
    return { tailoring: minor, fee, total: minor + fee }
  }, [budget, meta])

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const created = await api.createRequest({
        title: String(f.get('title') ?? '').trim(),
        garment_category: String(f.get('garment_category')),
        description: String(f.get('description') ?? '').trim(),
        style_tags: String(f.get('style_tags') ?? '')
          .split(',')
          .map((t) => t.trim().toLowerCase())
          .filter(Boolean)
          .slice(0, 8),
        mode,
        budget_max_minor: nairaToMinor(budget),
        budget_min_minor: mode === 'open' ? nairaToMinor(budgetMin) : null,
        fabric_status: fabricStatus,
        fabric_id: fabricStatus === 'buying_on_platform' ? fabricId : null,
        city: String(f.get('city') ?? '').trim(),
        needed_by: String(f.get('needed_by') ?? ''),
        fitting_required: f.get('fitting_required') === 'on',
      })
      toast('Request posted. Tailors can now send offers.', 'good')
      navigate(`/requests/${created.id}`)
    } catch (err) {
      const apiErr = err instanceof ApiError ? err : new ApiError('Could not post the request.', 0, 'unknown')
      setError(apiErr)
      window.scrollTo({ top: 0, behavior: 'smooth' })
    } finally {
      setBusy(false)
    }
  }

  const fe = (name: string) => error?.field(name)

  return (
    <div className="page page--form">
      <PageHeader
        title="Post a tailoring request"
        intro="Tailors who make this kind of outfit will respond. Nothing is agreed until you accept an offer."
      />

      <form className="form form--wide" onSubmit={submit} noValidate>
        {error && (
          <p className="form__error" role="alert">
            {Object.keys(error.fieldErrors).length ? 'Please fix the highlighted fields.' : error.message}
          </p>
        )}

        <section className="form__section">
          <h2 className="form__section-title">The outfit</h2>
          <Field label="What should be made?" error={fe('garment_category')}>
            {(p) => (
              <select {...p} name="garment_category" className="input input--select" defaultValue="senator">
                {meta?.garment_categories.map((g) => (
                  <option key={g.key} value={g.key}>
                    {g.label}
                  </option>
                ))}
              </select>
            )}
          </Field>
          <Field label="Title" hint="A short name tailors will see first." error={fe('title')}>
            {(p) => <input {...p} name="title" className="input" maxLength={140} placeholder="Navy senator for a wedding" />}
          </Field>
          <Field label="Describe it" hint="Fit, length, neckline, embroidery, anything that matters to you." error={fe('description')}>
            {(p) => <textarea {...p} name="description" className="input input--area" rows={5} maxLength={3000} />}
          </Field>
          <Field label="Style words" optional hint="Separate with commas, e.g. embroidered, slim fit." error={fe('style_tags')}>
            {(p) => <input {...p} name="style_tags" className="input" />}
          </Field>
        </section>

        <section className="form__section">
          <h2 className="form__section-title">Fabric</h2>
          <fieldset className="radios">
            <legend className="sr-only">Fabric</legend>
            {FABRIC_OPTIONS.map((o) => (
              <label key={o.key} className={`radio-card ${fabricStatus === o.key ? 'is-on' : ''}`}>
                <input type="radio" name="fabric_status" value={o.key} checked={fabricStatus === o.key} onChange={() => setFabricStatus(o.key)} />
                <span className="radio-card__label">{o.label}</span>
                <span className="radio-card__hint">{o.hint}</span>
              </label>
            ))}
          </fieldset>
          {fabricStatus === 'buying_on_platform' && (
            <div className="fabric-pick">
              {fe('fabric_id') && <p className="field__error">{fe('fabric_id')}</p>}
              {!fabrics.data ? (
                <p className="muted">Loading fabric</p>
              ) : (
                <div className="fabric-pick__list" role="radiogroup" aria-label="Choose fabric">
                  {fabrics.data.data.map((f) => (
                    <button
                      type="button"
                      role="radio"
                      aria-checked={fabricId === f.id}
                      key={f.id}
                      className="fabric-pick__item"
                      onClick={() => setFabricId(f.id)}
                    >
                      <Swatch pattern={f.pattern} colors={f.colors} className="fabric-pick__swatch" />
                      <span className="fabric-pick__name">{f.name}</span>
                      <span className="fabric-pick__price">
                        {f.price.formatted} / {f.unit}
                      </span>
                    </button>
                  ))}
                </div>
              )}
              {chosen && <p className="muted">Selected: {chosen.name}. Fabric cost is separate from the tailoring price.</p>}
            </div>
          )}
        </section>

        <section className="form__section">
          <h2 className="form__section-title">Price</h2>
          <div className="segmented" role="radiogroup" aria-label="How do you want to price this?">
            <label className={`segmented__option ${mode === 'budget' ? 'is-on' : ''}`}>
              <input type="radio" name="mode" value="budget" checked={mode === 'budget'} onChange={() => setMode('budget')} />
              Name your price
            </label>
            <label className={`segmented__option ${mode === 'open' ? 'is-on' : ''}`}>
              <input type="radio" name="mode" value="open" checked={mode === 'open'} onChange={() => setMode('open')} />
              Get open offers
            </label>
          </div>
          <p className="muted form__explain">
            {mode === 'budget'
              ? 'Tailors can accept your price, or counter with a written reason. You choose whether to accept a counteroffer.'
              : 'Tailors quote their own price. A range is optional; quotes above your maximum must explain why.'}
          </p>
          <div className="form__row">
            {mode === 'open' && (
              <Field label="From" optional error={fe('budget_min_minor')}>
                {(p) => (
                  <div className="money-input">
                    <span aria-hidden>₦</span>
                    <input {...p} inputMode="numeric" className="input" value={budgetMin} onChange={(e) => setBudgetMin(groupDigits(e.target.value))} />
                  </div>
                )}
              </Field>
            )}
            <Field
              label={mode === 'budget' ? 'Your tailoring budget' : 'Up to'}
              optional={mode === 'open'}
              hint="Tailoring only. Fabric and delivery are separate."
              error={fe('budget_max_minor')}
            >
              {(p) => (
                <div className="money-input">
                  <span aria-hidden>₦</span>
                  <input {...p} inputMode="numeric" className="input" value={budget} onChange={(e) => setBudget(groupDigits(e.target.value))} placeholder="30,000" />
                </div>
              )}
            </Field>
          </div>
          {estimate && (
            <div className="estimate" aria-live="polite">
              <p className="estimate__title">If a tailor accepts {mode === 'budget' ? 'your price' : 'your maximum'}</p>
              <dl>
                <div><dt>Tailoring</dt><dd>{formatNaira(estimate.tailoring)}</dd></div>
                <div><dt>Aṣọlà service fee</dt><dd>{formatNaira(estimate.fee)}</dd></div>
                <div className="estimate__total"><dt>You would pay</dt><dd>{formatNaira(estimate.total)}</dd></div>
              </dl>
              <p className="estimate__note">Plus delivery, if the tailor charges for it. Each offer shows its own full total.</p>
            </div>
          )}
        </section>

        <section className="form__section">
          <h2 className="form__section-title">Where and when</h2>
          <div className="form__row">
            <Field label="City" error={fe('city')}>
              {(p) => <input {...p} name="city" className="input" defaultValue={user?.city ?? ''} autoComplete="address-level2" />}
            </Field>
            <Field label="Needed by" error={fe('needed_by')}>
              {(p) => <input {...p} name="needed_by" type="date" className="input" min={minDate()} />}
            </Field>
          </div>
          <label className="check">
            <input type="checkbox" name="fitting_required" defaultChecked />I want a fitting before the final finish
          </label>
        </section>

        <div className="form__submit">
          <Button type="submit" busy={busy}>
            Post request
          </Button>
          <p className="muted">Your name, phone and email are not shown to tailors. They see your first name and city only.</p>
        </div>
      </form>
    </div>
  )
}
