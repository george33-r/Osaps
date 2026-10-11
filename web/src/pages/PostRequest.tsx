import { useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError, type FabricStatus, type InspirationBriefResult, type PricingMode, type RequestDraft } from '../api'
import { Swatch } from '../components/Swatch'
import { Button, Field, Label, errorMessage } from '../components/ui'
import { formatDate, formatNaira, groupDigits, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const STEPS = ['The outfit', 'Fabric', 'Price', 'When and where', 'Review'] as const

const FABRIC_OPTIONS: { key: FabricStatus; label: string; hint: string }[] = [
  { key: 'own_fabric', label: 'I already have the fabric', hint: 'You will hand it to your tailor.' },
  { key: 'buying_on_platform', label: 'I am choosing fabric listed here', hint: 'Tailors will know exactly what they are working with.' },
  { key: 'need_advice', label: 'I would like fabric advice', hint: 'Tailors can suggest fabric in their offers.' },
]

/** Which step a server field error belongs to, so the form can jump there. */
const FIELD_STEP: Record<string, number> = {
  garment_category: 0, occasion: 0, title: 0, description: 0, style_tags: 0,
  fabric_status: 1, fabric_id: 1, mode: 2, budget_max_minor: 2, budget_min_minor: 2,
  city: 3, needed_by: 3, fitting_required: 3,
}

interface FormState {
  garment_category: string
  occasion: string
  title: string
  description: string
  style_tags: string
  fabric_status: FabricStatus
  fabric_id: number | null
  mode: PricingMode
  budget: string
  budget_min: string
  city: string
  needed_by: string
  fitting_required: boolean
}

function minDate() {
  return new Date(Date.now() + 86_400_000).toISOString().slice(0, 10)
}

function Inspiration({ onBrief }: { onBrief: (b: InspirationBriefResult) => void }) {
  const { meta } = useSession()
  const input = useRef<HTMLInputElement>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<string | null>(null)
  const [brief, setBrief] = useState<InspirationBriefResult | null>(null)
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  const choose = async (file: File | undefined) => {
    if (!file) return
    setMessage(null)
    setBrief(null)
    if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type)) return setMessage('Choose a JPG, PNG or WebP photo.')
    if (file.size > 5 * 1024 * 1024) return setMessage('That photo is larger than 5 MB. Choose a smaller one.')
    setPreview(URL.createObjectURL(file))
    setBusy(true)
    try {
      const b = await api.inspiration(file)
      setBrief(b)
      onBrief(b)
    } catch (e) {
      setMessage(e instanceof ApiError ? e.message : errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="inspiration">
      <div className="inspiration__drop">
        {preview ? <img src={preview} alt="Your inspiration" /> : <span className="inspiration__empty" aria-hidden />}
      </div>
      <div className="inspiration__text">
        <p className="inspiration__title">Have an inspiration photo?</p>
        <p className="muted">
          {meta?.features.ai_assistant
            ? 'Your stylist reads the visible details and drafts the description for you to edit. The photo is not stored.'
            : 'Photo reading switches on when the AI stylist is enabled. For now, describe the look in words.'}
        </p>
        <input ref={input} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" id="inspiration-file" onChange={(e) => choose(e.target.files?.[0])} />
        <Button type="button" variant="secondary" busy={busy} disabled={!meta?.features.ai_assistant} onClick={() => input.current?.click()}>
          {preview ? 'Use a different photo' : 'Add a photo'}
        </Button>
        {message && <p className="field__error" role="alert">{message}</p>}
        {brief && (
          <p className="inspiration__result">
            Draft added below — edit anything. <span className="muted">{brief.caveat}</span>
          </p>
        )}
      </div>
    </div>
  )
}

export default function PostRequest() {
  const { meta, user } = useSession()
  const navigate = useNavigate()
  const location = useLocation()
  const toast = useToast()
  const [params] = useSearchParams()
  const draft = (location.state as { draft?: Partial<RequestDraft> } | null)?.draft
  const preFabric = draft?.fabric_id ?? (params.get('fabric') ? Number(params.get('fabric')) : null)

  const [step, setStep] = useState(0)
  const [f, setF] = useState<FormState>(() => ({
    garment_category: draft?.garment_category ?? '',
    occasion: draft?.occasion ?? '',
    title: draft?.title ?? '',
    description: draft?.description ?? '',
    style_tags: (draft?.style_tags ?? []).join(', '),
    fabric_status: draft?.fabric_status ?? (preFabric ? 'buying_on_platform' : 'own_fabric'),
    fabric_id: preFabric,
    mode: draft?.mode ?? 'budget',
    budget: '',
    budget_min: '',
    city: user?.city ?? '',
    needed_by: '',
    fitting_required: true,
  }))
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)
  const [topError, setTopError] = useState<string | null>(null)
  const heading = useRef<HTMLHeadingElement>(null)
  useEffect(() => heading.current?.focus(), [step])

  const set = <K extends keyof FormState>(k: K, v: FormState[K]) => setF((s) => ({ ...s, [k]: v }))
  const fabrics = useAsync(() => (f.fabric_status === 'buying_on_platform' ? api.fabrics({ in_stock: true }) : Promise.resolve(null)), [f.fabric_status])
  const chosen = fabrics.data?.data.find((x) => x.id === f.fabric_id)

  const estimate = useMemo(() => {
    const minor = nairaToMinor(f.budget)
    if (!minor || !meta) return null
    const fee = Math.min(Math.floor((minor * meta.fees.customer_service_fee_bps + 5000) / 10_000), meta.fees.customer_service_fee_cap_minor || Infinity)
    return { tailoring: minor, fee, total: minor + fee }
  }, [f.budget, meta])

  const garmentLabel = meta?.garment_categories.find((g) => g.key === f.garment_category)?.label
  const occasionLabel = meta?.occasions.find((o) => o.key === f.occasion)?.label

  /** Light checks before moving on; the server re-validates everything on submit. */
  const check = (s: number): Record<string, string> => {
    const e: Record<string, string> = {}
    if (s === 0) {
      if (!f.garment_category) e.garment_category = 'Choose what should be made.'
      if (f.title.trim().length < 5) e.title = 'Give it a short title (at least 5 characters).'
      if (f.description.trim().length < 20) e.description = 'Describe it in a little more detail (at least 20 characters).'
    }
    if (s === 1 && f.fabric_status === 'buying_on_platform' && !f.fabric_id) e.fabric_id = 'Choose the fabric, or pick another option.'
    if (s === 2) {
      if (f.mode === 'budget' && !nairaToMinor(f.budget)) e.budget_max_minor = 'Enter your budget, or choose to compare offers.'
      const min = nairaToMinor(f.budget_min)
      const max = nairaToMinor(f.budget)
      if (f.mode === 'open' && min && max && min > max) e.budget_min_minor = 'The lowest amount must be below the highest.'
    }
    if (s === 3) {
      if (!f.city.trim()) e.city = 'Enter the city where it will be made.'
      if (!f.needed_by || f.needed_by < minDate()) e.needed_by = 'Choose a date from tomorrow onwards.'
    }
    return e
  }

  const next = () => {
    const e = check(step)
    setErrors(e)
    if (!Object.keys(e).length) setStep((s) => Math.min(s + 1, STEPS.length - 1))
  }

  const submit = async () => {
    setBusy(true)
    setTopError(null)
    try {
      const created = await api.createRequest({
        title: f.title.trim(),
        garment_category: f.garment_category,
        occasion: f.occasion || null,
        description: f.description.trim(),
        style_tags: f.style_tags.split(',').map((t) => t.trim().toLowerCase()).filter(Boolean).slice(0, 8),
        mode: f.mode,
        budget_max_minor: nairaToMinor(f.budget),
        budget_min_minor: f.mode === 'open' ? nairaToMinor(f.budget_min) : null,
        fabric_status: f.fabric_status,
        fabric_id: f.fabric_status === 'buying_on_platform' ? f.fabric_id : null,
        city: f.city.trim(),
        needed_by: f.needed_by,
        fitting_required: f.fitting_required,
      })
      toast('Request posted. Tailors can now send offers.', 'good')
      navigate(`/requests/${created.id}`)
    } catch (err) {
      if (err instanceof ApiError && Object.keys(err.fieldErrors).length) {
        const mapped = Object.fromEntries(Object.entries(err.fieldErrors).map(([k, v]) => [k, v[0] ?? 'Check this field.']))
        setErrors(mapped)
        const firstStep = Math.min(...Object.keys(mapped).map((k) => FIELD_STEP[k] ?? 4))
        setStep(firstStep)
        setTopError('Please fix the highlighted field.')
      } else {
        setTopError(errorMessage(err))
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page page--consult">
      <header className="consult__head">
        <Label>Create an outfit</Label>
        <h1 className="page-header__title">Your tailoring request</h1>
        <p className="page-header__intro">Tell us what you would like made. Tailors who make it will respond, and nothing is agreed until you accept an offer.</p>
      </header>

      <ol className="stepper" aria-label="Progress">
        {STEPS.map((s, i) => (
          <li key={s} className={`stepper__item ${i === step ? 'is-current' : i < step ? 'is-done' : ''}`} aria-current={i === step ? 'step' : undefined}>
            <button type="button" disabled={i > step} onClick={() => setStep(i)}>
              <span className="stepper__num">{i + 1}</span>
              <span className="stepper__label">{s}</span>
            </button>
          </li>
        ))}
      </ol>

      <form className="consult" noValidate onSubmit={(e) => { e.preventDefault(); step === STEPS.length - 1 ? submit() : next() }}>
        <h2 className="consult__step-title" tabIndex={-1} ref={heading}>{STEPS[step]}</h2>
        {topError && <p className="form__error" role="alert">{topError}</p>}

        {step === 0 && (
          <div className="consult__body">
            <fieldset className="tiles">
              <legend className="field__label">What should be made?</legend>
              <div className="tiles__grid">
                {meta?.garment_categories.map((g) => (
                  <label key={g.key} className={`tile ${f.garment_category === g.key ? 'is-on' : ''}`}>
                    <input type="radio" name="garment" value={g.key} checked={f.garment_category === g.key} onChange={() => set('garment_category', g.key)} />
                    {g.label}
                  </label>
                ))}
              </div>
              {errors.garment_category && <p className="field__error">{errors.garment_category}</p>}
            </fieldset>
            <Field label="Occasion" optional error={errors.occasion}>
              {(p) => (
                <select {...p} className="input input--select" value={f.occasion} onChange={(e) => set('occasion', e.target.value)}>
                  <option value="">Choose an occasion</option>
                  {meta?.occasions.map((o) => <option key={o.key} value={o.key}>{o.label}</option>)}
                </select>
              )}
            </Field>
            <Inspiration onBrief={(b) => setF((s) => ({
              ...s,
              garment_category: s.garment_category || b.garment_category || '',
              title: s.title || b.title,
              description: [b.summary, b.silhouette, b.neckline && `Neckline: ${b.neckline}.`, b.sleeves && `Sleeves: ${b.sleeves}.`, b.fabric_appearance && `Fabric look: ${b.fabric_appearance}.`, b.embellishments.length ? `Details: ${b.embellishments.join(', ')}.` : null].filter(Boolean).join(' '),
              style_tags: s.style_tags || [b.aesthetic, ...b.colors].filter(Boolean).join(', '),
            }))} />
            <Field label="Title" hint="A short name tailors will see first." error={errors.title}>
              {(p) => <input {...p} className="input" maxLength={140} value={f.title} onChange={(e) => set('title', e.target.value)} placeholder="Navy senator for a wedding" />}
            </Field>
            <Field label="Describe it" hint="Fit, length, neckline, embroidery, modesty or comfort wishes — anything that matters to you." error={errors.description}>
              {(p) => <textarea {...p} className="input input--area" rows={6} maxLength={3000} value={f.description} onChange={(e) => set('description', e.target.value)} />}
            </Field>
            <Field label="Style words" optional hint="Separate with commas, e.g. embroidered, slim fit." error={errors.style_tags}>
              {(p) => <input {...p} className="input" value={f.style_tags} onChange={(e) => set('style_tags', e.target.value)} />}
            </Field>
          </div>
        )}

        {step === 1 && (
          <div className="consult__body">
            <fieldset className="radios">
              <legend className="sr-only">Fabric</legend>
              {FABRIC_OPTIONS.map((o) => (
                <label key={o.key} className={`radio-card ${f.fabric_status === o.key ? 'is-on' : ''}`}>
                  <input type="radio" name="fabric_status" checked={f.fabric_status === o.key} onChange={() => set('fabric_status', o.key)} />
                  <span className="radio-card__label">{o.label}</span>
                  <span className="radio-card__hint">{o.hint}</span>
                </label>
              ))}
            </fieldset>
            {f.fabric_status === 'buying_on_platform' && (
              <div className="fabric-pick">
                {errors.fabric_id && <p className="field__error">{errors.fabric_id}</p>}
                {!fabrics.data ? <p className="muted">Loading fabric</p> : (
                  <div className="fabric-pick__list" role="radiogroup" aria-label="Choose fabric">
                    {fabrics.data.data.map((x) => (
                      <button type="button" role="radio" aria-checked={f.fabric_id === x.id} key={x.id} className="fabric-pick__item" onClick={() => set('fabric_id', x.id)}>
                        <Swatch pattern={x.pattern} colors={x.colors} className="fabric-pick__swatch" />
                        <span className="fabric-pick__name">{x.name}</span>
                        <span className="fabric-pick__price">{x.price.formatted} / {x.unit}</span>
                      </button>
                    ))}
                  </div>
                )}
                {chosen && <p className="muted">Selected: {chosen.name}. Fabric is bought from its vendor, separately from tailoring.</p>}
              </div>
            )}
          </div>
        )}

        {step === 2 && (
          <div className="consult__body">
            <div className="mode-choice" role="radiogroup" aria-label="How do you want to price this?">
              <label className={`mode-option ${f.mode === 'budget' ? 'is-on' : ''}`}>
                <input type="radio" name="mode" checked={f.mode === 'budget'} onChange={() => set('mode', 'budget')} />
                <span className="mode-option__title">I have a budget</span>
                <span className="mode-option__hint">Tailors accept your price or counter with a written reason. You decide.</span>
              </label>
              <label className={`mode-option ${f.mode === 'open' ? 'is-on' : ''}`}>
                <input type="radio" name="mode" checked={f.mode === 'open'} onChange={() => set('mode', 'open')} />
                <span className="mode-option__title">Let me compare offers</span>
                <span className="mode-option__hint">Tailors quote their own price. A range is optional.</span>
              </label>
            </div>
            <div className="form__row">
              {f.mode === 'open' && (
                <Field label="From" optional error={errors.budget_min_minor}>
                  {(p) => <div className="money-input"><span aria-hidden>₦</span><input {...p} inputMode="numeric" className="input" value={f.budget_min} onChange={(e) => set('budget_min', groupDigits(e.target.value))} /></div>}
                </Field>
              )}
              <Field label={f.mode === 'budget' ? 'Your tailoring budget' : 'Up to'} optional={f.mode === 'open'} hint="Tailoring only. Fabric and delivery are separate." error={errors.budget_max_minor}>
                {(p) => <div className="money-input"><span aria-hidden>₦</span><input {...p} inputMode="numeric" className="input" value={f.budget} onChange={(e) => set('budget', groupDigits(e.target.value))} placeholder="30,000" /></div>}
              </Field>
            </div>
            {estimate && (
              <div className="estimate" aria-live="polite">
                <p className="label">If a tailor accepts {f.mode === 'budget' ? 'your price' : 'your maximum'}</p>
                <dl>
                  <div><dt>Tailoring</dt><dd>{formatNaira(estimate.tailoring)}</dd></div>
                  <div><dt>OSAPS service fee</dt><dd>{formatNaira(estimate.fee)}</dd></div>
                  <div className="estimate__total"><dt>You would pay</dt><dd>{formatNaira(estimate.total)}</dd></div>
                </dl>
                <p className="estimate__note">Plus delivery if the tailor charges for it. Each offer shows its own full total.</p>
              </div>
            )}
          </div>
        )}

        {step === 3 && (
          <div className="consult__body">
            <div className="form__row">
              <Field label="City" error={errors.city}>
                {(p) => <input {...p} className="input" value={f.city} onChange={(e) => set('city', e.target.value)} autoComplete="address-level2" />}
              </Field>
              <Field label="Needed by" error={errors.needed_by}>
                {(p) => <input {...p} type="date" className="input" min={minDate()} value={f.needed_by} onChange={(e) => set('needed_by', e.target.value)} />}
              </Field>
            </div>
            <label className="check">
              <input type="checkbox" checked={f.fitting_required} onChange={(e) => set('fitting_required', e.target.checked)} />
              I want a fitting before the final finish
            </label>
          </div>
        )}

        {step === 4 && (
          <div className="consult__body">
            <dl className="summary">
              <div><dt>Outfit</dt><dd>{garmentLabel}{occasionLabel ? `, for ${occasionLabel.toLowerCase()}` : ''}<button type="button" className="text-link" onClick={() => setStep(0)}>Edit</button></dd></div>
              <div><dt>Title</dt><dd>{f.title}</dd></div>
              <div><dt>Description</dt><dd className="summary__long">{f.description}</dd></div>
              <div><dt>Fabric</dt><dd>{f.fabric_status === 'own_fabric' ? 'You have it' : f.fabric_status === 'need_advice' ? 'Advice wanted' : chosen?.name ?? 'Chosen from the catalogue'}<button type="button" className="text-link" onClick={() => setStep(1)}>Edit</button></dd></div>
              <div><dt>Price</dt><dd>{f.mode === 'budget' ? `Your budget: ${formatNaira(nairaToMinor(f.budget) ?? 0)}` : nairaToMinor(f.budget) || nairaToMinor(f.budget_min) ? `Open offers${nairaToMinor(f.budget_min) ? ` from ${formatNaira(nairaToMinor(f.budget_min)!)}` : ''}${nairaToMinor(f.budget) ? ` up to ${formatNaira(nairaToMinor(f.budget)!)}` : ''}` : 'Open offers, no range'}<button type="button" className="text-link" onClick={() => setStep(2)}>Edit</button></dd></div>
              <div><dt>When and where</dt><dd>{f.city}, by {f.needed_by ? formatDate(f.needed_by) : '—'}{f.fitting_required ? ', with a fitting' : ''}<button type="button" className="text-link" onClick={() => setStep(3)}>Edit</button></dd></div>
            </dl>
            <p className="muted">Tailors see your first name and city only — never your phone or email — until you accept an offer.</p>
          </div>
        )}

        <div className="consult__nav">
          {step > 0 && <Button type="button" variant="quiet" onClick={() => setStep((s) => s - 1)}>Back</Button>}
          <Button type="submit" busy={busy}>{step === STEPS.length - 1 ? 'Post request' : 'Continue'}</Button>
        </div>
      </form>
    </div>
  )
}
