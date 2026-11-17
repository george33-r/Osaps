import { useState, type FormEvent } from 'react'
import { api, type AdminTailorDirectoryRow } from '../api'
import { Button, errorMessage } from './ui'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'

/** Admin: set up a profile for a tailor who has an account but no profile yet. The tailor is told afterwards. */
export function AdminCreateTailorProfile({ row, onDone, onCancel }: { row: AdminTailorDirectoryRow; onDone: () => void; onCancel: () => void }) {
  const { meta } = useSession()
  const toast = useToast()
  const [specs, setSpecs] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const categories = meta?.garment_categories ?? []

  const toggle = (key: string) => setSpecs((s) => (s.includes(key) ? s.filter((x) => x !== key) : [...s, key]))

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const list = (k: string) => String(f.get(k) ?? '').split(',').map((s) => s.trim()).filter(Boolean)
    setBusy(true)
    setError(null)
    try {
      await api.adminCreateTailorProfile(row.user_id, {
        business_name: String(f.get('business_name') ?? '').trim(),
        bio: String(f.get('bio') ?? '').trim() || null,
        city: String(f.get('city') ?? '').trim(),
        service_cities: list('service_cities'),
        specialties: specs,
        weekly_capacity: Number(f.get('weekly_capacity') ?? 5),
        accepting_work: true,
        portfolio_tags: [],
        experience_years: null,
        starting_price_minor: null,
      })
      toast(`Profile created for ${row.name ?? row.email ?? 'this tailor'}. They have been told.`, 'good')
      onDone()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="new-listing" onSubmit={submit} aria-label="Create tailor profile">
      <h3 className="sh-section__title">Create profile for {row.name ?? row.email}</h3>
      <p className="muted">Fill this in from what the tailor has told you. They can change it later, and they will be told you created it.</p>
      <label className="field">
        <span className="field__label">Business name</span>
        <input name="business_name" className="input" required maxLength={120} defaultValue={row.business_name ?? row.name ?? ''} />
      </label>
      <label className="field">
        <span className="field__label">City</span>
        <input name="city" className="input" required maxLength={80} defaultValue={row.city ?? ''} />
      </label>
      <label className="field">
        <span className="field__label">Cities they serve (comma separated)</span>
        <input name="service_cities" className="input" required defaultValue={row.city ?? ''} />
      </label>
      <fieldset className="field">
        <legend className="field__label">Specialties (choose at least one)</legend>
        <div>
          {categories.map((c) => (
            <button key={c.key} type="button" className="chip" aria-pressed={specs.includes(c.key)} onClick={() => toggle(c.key)}>{c.label}</button>
          ))}
        </div>
      </fieldset>
      <label className="field">
        <span className="field__label">Weekly capacity</span>
        <input name="weekly_capacity" type="number" min={1} max={200} defaultValue={5} className="input" />
      </label>
      <label className="field">
        <span className="field__label">Short bio (optional)</span>
        <textarea name="bio" className="input" rows={3} maxLength={1500} />
      </label>
      {error && <p className="field__error">{error}</p>}
      <div style={{ display: 'flex', gap: 8 }}>
        <Button type="submit" busy={busy} disabled={specs.length === 0}>Create profile</Button>
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  )
}
