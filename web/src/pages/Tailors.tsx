import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { Monogram, TailorCard, TrustLine } from '../components/cards'
import { Badge, ButtonLink, EmptyState, ErrorState, Label, PageHeader, Skeleton } from '../components/ui'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

const CITIES = ['Abuja', 'Lagos', 'Ibadan', 'Kano']

export function TailorDirectory() {
  const { meta } = useSession()
  const [params, setParams] = useSearchParams()
  const city = params.get('city') ?? ''
  const specialty = params.get('specialty') ?? ''
  const res = useAsync(() => api.tailors({ city: city || undefined, specialty: specialty || undefined }), [city, specialty])

  const set = (k: string, v: string) => {
    const next = new URLSearchParams(params)
    if (v) next.set(k, v)
    else next.delete(k)
    setParams(next, { replace: true })
  }

  return (
    <div className="page">
      <PageHeader
        label="Find a tailor"
        title="The makers"
        intro="Tailors and designers across Nigeria. Track records count completed OSAPS orders only, and nobody can pay for a higher place."
        aside={<ButtonLink to="/requests/new">Get offers from tailors</ButtonLink>}
      />
      <div className="toolbar">
        <label className="inline-field">
          <span>City</span>
          <select className="input input--select" value={city} onChange={(e) => set('city', e.target.value)}>
            <option value="">All cities</option>
            {CITIES.map((c) => <option key={c}>{c}</option>)}
          </select>
        </label>
        <label className="inline-field">
          <span>Makes</span>
          <select className="input input--select" value={specialty} onChange={(e) => set('specialty', e.target.value)}>
            <option value="">Any garment</option>
            {meta?.garment_categories.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}
          </select>
        </label>
      </div>
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <div className="grid grid--makers">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} lines={4} />)}</div>
      ) : res.data.data.length === 0 ? (
        <EmptyState title="No makers match yet" action={<button className="btn btn--secondary" onClick={() => setParams({})}>Show all makers</button>}>
          Post a request anyway: tailors who serve nearby cities can still respond.
        </EmptyState>
      ) : (
        <div className="grid grid--makers">{res.data.data.map((t) => <TailorCard key={t.slug} tailor={t} />)}</div>
      )}
    </div>
  )
}

export function TailorProfilePage() {
  const { slug = '' } = useParams()
  const res = useAsync(() => api.tailor(slug), [slug])
  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton lines={5} /></div>
  const t = res.data

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/tailors">Makers</Link> <span aria-hidden>/</span> <span>{t.business_name}</span>
      </nav>
      <article className="maker">
        <header className="maker__head">
          <Monogram name={t.business_name} large />
          <div>
            <Label>{t.specialties.map((s) => s.label).join(', ')}</Label>
            <h1 className="maker__name">{t.business_name}</h1>
            <p className="maker__where">Based in {t.city}. Works in {t.service_cities.join(', ')}.</p>
            <TrustLine tailor={t} />
            {t.is_demo && <Badge tone="waiting">Demonstration profile</Badge>}
          </div>
        </header>

        <div className="maker__body">
          <div className="maker__story">
            {t.bio && <p className="maker__bio">{t.bio}</p>}
            {t.track_record.is_new && (
              <p className="maker__new">
                New makers start without reviews. Ask to see past work in a message before you accept an offer, and agree fittings and alterations up front.
              </p>
            )}
            <section className="maker__portfolio" aria-labelledby="portfolio-title">
              <h2 id="portfolio-title" className="section-title section-title--small">Portfolio</h2>
              <EmptyState title="Portfolio photographs are coming">
                {t.business_name.split(' ')[0]} has not uploaded work yet. Portfolio uploads arrive in the next release.
              </EmptyState>
            </section>
          </div>
          <aside className="maker__facts">
            <dl className="specs">
              <div><dt>Makes</dt><dd>{t.specialties.map((s) => s.label).join(', ')}</dd></div>
              {t.portfolio_tags.length > 0 && <div><dt>Style</dt><dd>{t.portfolio_tags.join(', ')}</dd></div>}
              {t.starting_price && <div><dt>Prices from</dt><dd>{t.starting_price.formatted}</dd></div>}
              <div><dt>Taking new work</dt><dd>{t.accepting_work ? 'Yes' : 'Not right now'}</dd></div>
            </dl>
            <ButtonLink to="/requests/new">Post a request this maker can answer</ButtonLink>
          </aside>
        </div>
      </article>
    </div>
  )
}
