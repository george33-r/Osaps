import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, type Measurements } from '../api'
import { Button, ErrorState, Field, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { CurrencyPicker } from '../lib/fx'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

export function AccountPage() {
  const { user, meta, setUser } = useSession()
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  if (!user) return null
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const d = new FormData(e.currentTarget)
    setBusy(true); setError(null)
    try {
      const u = await api.updateMe({
        name: String(d.get('name') ?? '').trim(), phone: String(d.get('phone') ?? '').trim() || null,
        city: String(d.get('city') ?? '').trim() || null, whatsapp_opt_in: d.get('wa') === 'on',
      })
      setUser(u)
      toast('Saved.', 'good')
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')) } finally { setBusy(false) }
  }
  return (
    <div className="page page--narrow">
      <PageHeader title="Your account" intro="How we reach you, and how prices are shown." />
      <form className="new-listing" onSubmit={submit}>
        <Field label="Name">{(p) => <input {...p} name="name" className="input" defaultValue={user.name} required minLength={2} />}</Field>
        <div className="checkout__pair">
          <Field label="Phone (WhatsApp)" error={error?.field('phone')}>{(p) => <input {...p} name="phone" type="tel" className="input" defaultValue={user.phone ?? ''} placeholder="0801 234 5678" />}</Field>
          <Field label="City" optional>{(p) => <input {...p} name="city" className="input" defaultValue={user.city ?? ''} />}</Field>
        </div>
        <label className="check wa-opt">
          <input type="checkbox" name="wa" defaultChecked={!!user.whatsapp_opt_in} />
          <span><strong>Send me updates on WhatsApp</strong><small>New offers, counteroffers, payments and deliveries. Turn off any time.{meta && !meta.features.whatsapp ? ' (Coming soon: we will start sending once WhatsApp is switched on.)' : ''}</small></span>
        </label>
        {error && error.code !== 'validation_failed' && <p className="field__error">{error.message}</p>}
        <Button type="submit" busy={busy}>Save</Button>
      </form>
      <section className="account__more">
        <CurrencyPicker />
        <p className="muted">Shopping from abroad? Foreign amounts are approximate; you always pay in naira and your bank converts.</p>
        <Link className="btn btn--secondary" to="/account/measurements">My measurements</Link>
      </section>
    </div>
  )
}

/** Drawn guide: where each tape measurement goes. */
function MeasureGuide({ active }: { active: string | null }) {
  const lines: Record<string, string> = {
    neck: 'M88 64 Q100 70 112 64', shoulder: 'M66 78 L134 78', chest: 'M70 104 Q100 112 130 104', under_bust: 'M74 122 Q100 128 126 122',
    waist: 'M76 146 Q100 152 124 146', hip: 'M70 176 Q100 184 130 176', sleeve: 'M134 80 L154 168', round_sleeve: 'M140 104 L154 100',
    top_length: 'M100 70 L100 196', gown_length: 'M108 70 L108 300', trouser_length: 'M90 180 L86 300', thigh: 'M74 204 L98 204', ankle: 'M80 296 L96 296', height: 'M174 30 L174 310',
  }
  return (
    <svg className="measure-guide" viewBox="0 0 200 320" role="img" aria-label="Where to measure">
      <g fill="none" stroke="currentColor" strokeWidth="2" opacity="0.5">
        <circle cx="100" cy="42" r="16" /><path d="M88 60 L66 78 L56 170 M112 60 L134 78 L144 170 M70 80 L76 190 L84 300 M130 80 L124 190 L116 300 M76 190 L124 190 M100 196 L100 300" />
      </g>
      {Object.entries(lines).map(([k, d]) => <path key={k} d={d} fill="none" stroke={active === k ? '#f2be45' : 'transparent'} strokeWidth="4" strokeLinecap="round" strokeDasharray={active === k ? '6 4' : undefined} />)}
    </svg>
  )
}

const TIPS: Record<string, string> = {
  neck: 'Around the base of the neck, one finger under the tape.', shoulder: 'Across the back, from shoulder bone to shoulder bone.',
  chest: 'Around the fullest part, under the arms, tape level.', under_bust: 'Snug, just under the bust.', waist: 'Around the natural waist, above the navel.',
  hip: 'Around the fullest part of the hips, feet together.', sleeve: 'Shoulder bone to wrist, arm slightly bent.', round_sleeve: 'Around the fullest part of the upper arm.',
  top_length: 'From the shoulder, down the front, to where the top should end.', gown_length: 'From the shoulder to where the gown or kaftan should end.',
  trouser_length: 'Waist to ankle, along the outside of the leg.', thigh: 'Around the fullest part of the thigh.', ankle: 'Around the ankle where the trouser ends.', height: 'Standing straight, no shoes.',
}

export function MeasurementsPage() {
  const res = useAsync(() => api.measurements(), [])
  const toast = useToast()
  const [m, setM] = useState<Measurements | null>(null)
  const [unit, setUnit] = useState<'cm' | 'in'>('cm')
  const [vals, setVals] = useState<Record<string, string>>({})
  const [active, setActive] = useState<string | null>('chest')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (!res.data) return
    setM(res.data)
    setUnit(res.data.unit)
    const conv = (cm: number) => String(res.data!.unit === 'in' ? Math.round((cm / 2.54) * 10) / 10 : cm)
    setVals(Object.fromEntries(Object.entries(res.data.values).map(([k, v]) => [k, conv(v)])))
  }, [res.data])
  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!m) return <div className="page"><Skeleton lines={6} /></div>

  return (
    <div className="page">
      <PageHeader title="My measurements" intro="Measure once with a friend and a soft tape. The tailor you hire sees them, then checks at your first fitting." />
      <div className="measure">
        <div className="measure__guide">
          <MeasureGuide active={active} />
          <p className="measure__tip">{active ? TIPS[active] : 'Tap a measurement to see where it goes.'}</p>
        </div>
        <form className="measure__form" onSubmit={async (e) => {
          e.preventDefault()
          setBusy(true); setError(null)
          try {
            const share = (new FormData(e.currentTarget)).get('share') === 'on'
            setM(await api.saveMeasurements({ unit, values: vals, notes: m.notes, share_with_tailors: share }))
            toast('Measurements saved.', 'good')
          } catch (err) { setError(errorMessage(err)) } finally { setBusy(false) }
        }}>
          <div className="buybox__tabs" role="radiogroup" aria-label="Units">
            {(['cm', 'in'] as const).map((u) => <button key={u} type="button" role="radio" aria-checked={unit === u} className="buybox__tab" onClick={() => setUnit(u)}>{u === 'cm' ? 'Centimetres' : 'Inches'}</button>)}
          </div>
          <div className="measure__grid">
            {m.fields.map((f) => (
              <label key={f.key} className={`measure__field ${active === f.key ? 'is-active' : ''}`}>
                <span>{f.label}</span>
                <span className="measure__input"><input inputMode="decimal" value={vals[f.key] ?? ''} onFocus={() => setActive(f.key)} onChange={(e) => setVals((v) => ({ ...v, [f.key]: e.target.value.replace(/[^\d.]/g, '') }))} /><small>{unit}</small></span>
              </label>
            ))}
          </div>
          <label className="check"><input type="checkbox" name="share" defaultChecked={m.share_with_tailors} /> Share with the tailor I hire (only after I accept their offer)</label>
          {error && <p className="field__error" role="alert">{error}</p>}
          <Button type="submit" busy={busy}>Save measurements</Button>
          {m.updated_at && <p className="muted">Last saved {new Date(m.updated_at).toLocaleDateString('en-GB')}.</p>}
        </form>
      </div>
    </div>
  )
}
