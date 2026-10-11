import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api } from '../api'
import { TailorCard, TrustLine } from '../components/cards'
import { Badge, ButtonLink, EmptyState, ErrorState, PageHeader, Price, Skeleton } from '../components/ui'
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
        title="Tailors"
        intro="Track records here are counted from completed Osapa orders only. Nobody can buy a higher place in this list."
        aside={<ButtonLink to="/requests/new">Get offers from tailors</ButtonLink>}
      />
      <div className="toolbar">
        <label className="filters__inline">
          City
          <select className="input input--select" value={city} onChange={(e) => set('city', e.target.value)}>
            <option value="">All cities</option>
            {CITIES.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </select>
        </label>
        <label className="filters__inline">
          Makes
          <select className="input input--select" value={specialty} onChange={(e) => set('specialty', e.target.value)}>
            <option value="">Any garment</option>
            {meta?.garment_categories.map((g) => (
              <option key={g.key} value={g.key}>
                {g.label}
              </option>
            ))}
          </select>
        </label>
      </div>
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <div className="grid grid--tailors">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} lines={4} />)}</div>
      ) : res.data.data.length === 0 ? (
        <EmptyState title="No tailors match yet" action={<button className="btn btn--secondary" onClick={() => setParams({})}>Show all tailors</button>}>
          Post a request anyway: tailors who serve nearby cities can still respond.
        </EmptyState>
      ) : (
        <div className="grid grid--tailors">
          {res.data.data.map((t) => (
            <TailorCard key={t.slug} tailor={t} />
          ))}
        </div>
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
    <div className="page page--narrow">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/tailors">Tailors</Link> <span aria-hidden>/</span> <span>{t.business_name}</span>
      </nav>
      <header className="profile-head">
        <div className="tailor-card__monogram tailor-card__monogram--large" aria-hidden>
          {t.business_name.normalize('NFD').replace(/[̀-ͯ]/g, '').slice(0, 1)}
        </div>
        <div>
          <h1 className="page-header__title">{t.business_name}</h1>
          <p className="muted">Based in {t.city}. Works in {t.service_cities.join(', ')}.</p>
          <TrustLine tailor={t} />
        </div>
      </header>
      {t.is_demo && <p><Badge tone="waiting">Demonstration profile</Badge></p>}
      {t.bio && <p className="prose">{t.bio}</p>}
      <dl className="specs">
        <div><dt>Makes</dt><dd>{t.specialties.map((s) => s.label).join(', ')}</dd></div>
        {t.portfolio_tags.length > 0 && <div><dt>Style</dt><dd>{t.portfolio_tags.join(', ')}</dd></div>}
        {t.starting_price && <div><dt>Prices from</dt><dd><Price value={t.starting_price} /></dd></div>}
        <div><dt>Taking new work</dt><dd>{t.accepting_work ? 'Yes' : 'Not right now'}</dd></div>
      </dl>
      <EmptyState title="No portfolio photos yet">Portfolio uploads are coming in the next release.</EmptyState>
      <div className="fabric-detail__actions">
        <ButtonLink to="/requests/new">Post a request this tailor can respond to</ButtonLink>
      </div>
    </div>
  )
}
