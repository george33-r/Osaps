import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { api, ApiError, type Fabric, type FabricFilters, type FabricIdea, type VendorContact } from '../api'
import { BuyBox } from '../components/ShopKit'
import { TryOn } from '../components/TryOn'
import { Approx } from '../lib/fx'
import { FabricCard } from '../components/cards'
import { COLOR_HEX, DrapedCloth, Swatch } from '../components/Swatch'
import { Badge, Button, ButtonLink, EmptyState, ErrorState, Label, PageHeader, Skeleton, SourceNote, errorMessage } from '../components/ui'
import { humanise, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

const SORTS: { key: NonNullable<FabricFilters['sort']>; label: string }[] = [
  { key: 'newest', label: 'Newest' },
  { key: 'price_asc', label: 'Price, low to high' },
  { key: 'price_desc', label: 'Price, high to low' },
]

const ASK_EXAMPLES = ['A wine-coloured Ankara for a wedding guest', 'Soft lace for a classy evening dress', 'Blue fabric for a matching family outfit', 'Aṣọ-òkè for a traditional ceremony']

function AskBar({ initial }: { initial: string }) {
  const navigate = useNavigate()
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const q = String(new FormData(e.currentTarget).get('ask') ?? '').trim()
    navigate(q ? `/fabrics?ask=${encodeURIComponent(q)}` : '/fabrics')
  }
  return (
    <form className="ask" role="search" onSubmit={submit}>
      <label htmlFor="ask-input" className="label">Describe what you are looking for</label>
      <div className="ask__row">
        <input id="ask-input" name="ask" key={initial} defaultValue={initial} className="ask__input" placeholder={ASK_EXAMPLES[0]} autoComplete="off" />
        <button className="btn btn--primary" type="submit">Find fabric</button>
      </div>
      {!initial && (
        <p className="ask__examples">
          Try:{' '}
          {ASK_EXAMPLES.slice(1).map((ex, i) => (
            <span key={ex}>
              <Link to={`/fabrics?ask=${encodeURIComponent(ex)}`}>{ex}</Link>
              {i < ASK_EXAMPLES.length - 2 ? ', ' : ''}
            </span>
          ))}
        </p>
      )}
    </form>
  )
}

function AskResults({ q }: { q: string }) {
  const res = useAsync(() => api.aiSearch(q), [q])
  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return <div className="grid grid--fabrics">{Array.from({ length: 6 }, (_, i) => <Skeleton key={i} block lines={2} />)}</div>
  const r = res.data
  return (
    <section aria-live="polite">
      <div className="ask-summary">
        <p className="ask-summary__understood">{r.understood}</p>
        {r.relaxed.length > 0 && r.results.length > 0 && (
          <p className="ask-summary__relaxed">Nothing matched exactly, so these ignore the {r.relaxed.join(' and ')}.</p>
        )}
        <SourceNote source={r.source} notice={r.notice} />
      </div>
      {r.results.length === 0 ? (
        <EmptyState title="Nothing in the catalogue matches that yet" action={<ButtonLink to="/fabrics" variant="secondary">Browse all fabric</ButtonLink>}>
          Try a different colour or fabric type, or post a request and ask tailors to suggest fabric.
        </EmptyState>
      ) : (
        <div className="grid grid--fabrics">{r.results.map((f) => <FabricCard key={f.id} fabric={f} />)}</div>
      )}
    </section>
  )
}

export function FabricCatalogue() {
  const { meta } = useSession()
  const [params, setParams] = useSearchParams()
  const [filtersOpen, setFiltersOpen] = useState(false)
  const ask = params.get('ask') ?? ''

  const filters: FabricFilters = {
    category: params.getAll('category'),
    color: params.getAll('color'),
    max_price: params.get('max') ? Number(params.get('max')) * 100 : undefined,
    in_stock: params.get('in_stock') === '1',
    sort: (params.get('sort') as FabricFilters['sort']) ?? 'newest',
  }
  const key = params.toString()
  const result = useAsync(() => (ask ? Promise.resolve(null) : api.fabrics(filters)), [key])

  const toggle = (name: string, value: string) => {
    const next = new URLSearchParams(params)
    const values = next.getAll(name)
    next.delete(name)
    ;(values.includes(value) ? values.filter((v) => v !== value) : [...values, value]).forEach((v) => next.append(name, v))
    setParams(next, { replace: true })
  }
  const set = (name: string, value: string | null) => {
    const next = new URLSearchParams(params)
    if (value) next.set(name, value)
    else next.delete(name)
    setParams(next, { replace: true })
  }
  const activeCount = filters.category!.length + filters.color!.length + (filters.max_price ? 1 : 0) + (filters.in_stock ? 1 : 0)

  return (
    <div className="page">
      <PageHeader label="Discover" title="Fabrics" intro="Ankara, adire, aṣọ-òkè, lace and more from market vendors. Prices and stock come from each vendor's listing." />
      <AskBar initial={ask} />

      {ask ? (
        <AskResults q={ask} />
      ) : (
        <div className="catalogue">
          <div className="catalogue__bar">
            <button type="button" className="btn btn--secondary catalogue__filter-toggle" aria-expanded={filtersOpen} aria-controls="fabric-filters" onClick={() => setFiltersOpen((o) => !o)}>
              Filters{activeCount ? ` (${activeCount})` : ''}
            </button>
            <p className="catalogue__count">{result.data ? `${result.data.meta?.total ?? result.data.data.length} fabrics` : 'Loading'}</p>
            <label className="inline-field">
              <span>Sort</span>
              <select className="input input--select" value={filters.sort} onChange={(e) => set('sort', e.target.value === 'newest' ? null : e.target.value)}>
                {SORTS.map((s) => <option key={s.key} value={s.key}>{s.label}</option>)}
              </select>
            </label>
          </div>

          <aside id="fabric-filters" className={`filters ${filtersOpen ? 'is-open' : ''}`} aria-label="Filters">
            <div className="filters__head">
              <p className="label">Refine</p>
              <button type="button" className="filters__close" onClick={() => setFiltersOpen(false)} aria-label="Close filters">×</button>
            </div>
            <fieldset className="filters__group">
              <legend>Fabric</legend>
              <div className="chips">
                {meta?.fabric_categories.map((c) => (
                  <button key={c.key} type="button" className="chip" aria-pressed={filters.category!.includes(c.key)} onClick={() => toggle('category', c.key)}>{c.label}</button>
                ))}
              </div>
            </fieldset>
            <fieldset className="filters__group">
              <legend>Main colour</legend>
              <div className="colors">
                {(meta?.colors ?? []).filter((c) => COLOR_HEX[c]).map((c) => (
                  <button key={c} type="button" className="color-dot" style={{ ['--dot' as string]: COLOR_HEX[c] }} aria-pressed={filters.color!.includes(c)} aria-label={humanise(c)} title={humanise(c)} onClick={() => toggle('color', c)} />
                ))}
              </div>
            </fieldset>
            <fieldset className="filters__group">
              <legend>Price per unit</legend>
              <label className="inline-field" htmlFor="fabric-max">
                <span>Up to ₦</span>
                <input id="fabric-max" className="input input--short" inputMode="numeric" placeholder="Any" defaultValue={params.get('max') ?? ''}
                  onBlur={(e) => { const m = nairaToMinor(e.currentTarget.value); set('max', m ? String(m / 100) : null) }}
                  onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()} />
              </label>
            </fieldset>
            <label className="check">
              <input type="checkbox" checked={filters.in_stock} onChange={(e) => set('in_stock', e.target.checked ? '1' : null)} />
              In stock only
            </label>
            {activeCount > 0 && <button type="button" className="text-link" onClick={() => setParams({}, { replace: true })}>Clear filters</button>}
            <button type="button" className="btn btn--primary filters__done" onClick={() => setFiltersOpen(false)}>Show fabrics</button>
          </aside>

          <section className="catalogue__results" aria-live="polite" aria-busy={result.status === 'loading'}>
            {result.status === 'error' && !result.data ? (
              <ErrorState error={result.error} onRetry={result.reload} />
            ) : !result.data ? (
              <div className="grid grid--fabrics">{Array.from({ length: 8 }, (_, i) => <Skeleton key={i} block lines={2} />)}</div>
            ) : result.data.data.length === 0 ? (
              <EmptyState title="No fabric matches these filters" action={<button className="btn btn--secondary" onClick={() => setParams({}, { replace: true })}>Clear all filters</button>}>
                Try another colour or type, or raise the price limit.
              </EmptyState>
            ) : (
              <div className={`grid grid--fabrics ${result.status === 'loading' ? 'is-refreshing' : ''}`}>
                {result.data.data.map((f) => <FabricCard key={f.id} fabric={f} />)}
              </div>
            )}
          </section>
        </div>
      )}
    </div>
  )
}

function VendorPanel({ fabric }: { fabric: Fabric }) {
  const { user } = useSession()
  const [contact, setContact] = useState<VendorContact | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  if (!fabric.vendor) return null

  return (
    <section className="vendor" aria-labelledby="vendor-title">
      <Label>Sold by</Label>
      <h2 id="vendor-title" className="vendor__name">{fabric.vendor.business_name}</h2>
      <p className="vendor__where">{fabric.vendor.market ? `${fabric.vendor.market}, ` : ''}{fabric.vendor.city}</p>
      <p className="vendor__model">{fabric.purchase_mode === 'checkout'
        ? 'Pay on OSAPS and the vendor only ships once your payment is confirmed. You can still ask them a question first.'
        : 'You buy this fabric directly from the vendor. Paying through OSAPS is not switched on yet.'}</p>
      {contact ? (
        <div className="vendor__contact">
          {contact.whatsapp && <a className="btn btn--primary" href={`https://wa.me/${contact.whatsapp.replace(/\D/g, '')}?text=${encodeURIComponent(`Hello, I saw ${fabric.name} on OSAPS.`)}`} target="_blank" rel="noopener noreferrer">Message on WhatsApp</a>}
          {contact.phone && <a className="btn btn--secondary" href={`tel:${contact.phone}`}>Call {contact.phone}</a>}
          {contact.note && <p className="vendor__note">{contact.note}</p>}
        </div>
      ) : user ? (
        <>
          <Button variant="secondary" busy={busy} onClick={async () => {
            setBusy(true); setError(null)
            try { setContact(await api.vendorContact(fabric.slug)) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
          }}>Show how to reach the vendor</Button>
          {error && <p className="field__error">{error}</p>}
        </>
      ) : (
        <p><Link to={`/signin?next=${encodeURIComponent(`/fabrics/${fabric.slug}`)}`}>Sign in</Link> to see how to reach this vendor.</p>
      )}
    </section>
  )
}

function Ideas({ fabric }: { fabric: Fabric }) {
  const navigate = useNavigate()
  const [state, setState] = useState<{ source: string; notice: string | null; ideas: FabricIdea[] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  return (
    <section className="ideas" aria-labelledby="ideas-title">
      <div className="ideas__head">
        <h2 id="ideas-title" className="ideas__title">What could I make with this?</h2>
        {!state && (
          <Button variant="secondary" busy={busy} onClick={async () => {
            setBusy(true); setError(null)
            try { setState(await api.fabricIdeas(fabric.slug)) } catch (e) { setError(e instanceof ApiError ? e.message : errorMessage(e)) } finally { setBusy(false) }
          }}>Show outfit ideas</Button>
        )}
      </div>
      {error && <p className="field__error">{error}</p>}
      {state && (
        <>
          <ol className="ideas__list">
            {state.ideas.map((idea) => (
              <li key={idea.title} className="idea">
                <h3 className="idea__title">{idea.title}</h3>
                <p>{idea.description}</p>
                {idea.styling_note && <p className="idea__note">{idea.styling_note}</p>}
                <button className="text-link" onClick={() => navigate('/requests/new', { state: { draft: {
                  garment_category: idea.garment_category, occasion: idea.occasion, title: idea.title, description: idea.description,
                  style_tags: [], mode: 'open', fabric_status: 'buying_on_platform', fabric_id: fabric.id,
                } } })}>Ask tailors to make this</button>
              </li>
            ))}
          </ol>
          <SourceNote source={state.source} notice={state.notice} />
        </>
      )}
    </section>
  )
}

export function FabricDetail() {
  const { slug = '' } = useParams()
  const res = useAsync(() => api.fabric(slug), [slug])
  const related = useAsync(() => (res.data ? api.fabrics({ category: [res.data.category], in_stock: true }) : Promise.resolve(null)), [res.data?.category])

  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton block lines={4} /></div>
  const f = res.data
  const more = related.data?.data.filter((x) => x.id !== f.id).slice(0, 4) ?? []

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/fabrics">Fabrics</Link> <span aria-hidden>/</span> <Link to={`/fabrics?category=${f.category}`}>{f.category_label}</Link>
      </nav>
      <div className="product">
        <figure className="product__gallery">
          {f.media && f.media.length > 0 ? (
            <>
              {(() => {
                const video = f.media.find((m) => m.kind === 'video')
                const photos = f.media.filter((m) => m.kind === 'photo')
                return (
                  <>
                    {video ? (
                      <div className="product__main product__video">
                        <video src={video.url} controls muted loop playsInline preload="metadata" aria-label={`Video of the real bolt of ${f.name}`} />
                        <span className="product__real">Real video of this bolt</span>
                      </div>
                    ) : photos[0] ? <img className="product__main" src={photos[0].url} alt={f.name} /> : null}
                    {photos.slice(video ? 0 : 1, video ? 2 : 3).map((p) => <img key={p.id} className="product__detail" src={p.url} alt={`${f.name}, detail`} loading="lazy" />)}
                    <figcaption className="product__caption">Photos and video by the vendor, of the fabric you will receive.</figcaption>
                  </>
                )
              })()}
            </>
          ) : f.image_url ? (
            <img className="product__main" src={f.image_url} alt={f.name} />
          ) : (
            <>
              <div className="product__main"><DrapedCloth pattern={f.pattern} colors={f.colors} seed={f.id + 3} scale={1.5} title={`${f.name}, illustrated`} /></div>
              <div className="product__detail"><Swatch pattern={f.pattern} colors={f.colors} scale={2.6} /></div>
              <div className="product__detail"><Swatch pattern={f.pattern} colors={f.colors} scale={0.9} /></div>
              <figcaption className="product__caption">Illustrations drawn from the listing's pattern and colours. Vendor photographs will replace them.</figcaption>
            </>
          )}
        </figure>

        <div className="product__info">
          <Label>{f.category_label}</Label>
          <h1 className="product__name">{f.name}</h1>
          <p className="product__price">{f.price.formatted} <span>per {f.unit}</span> <Approx minor={f.price.amount_minor} /></p>
          <p className={`product__stock product__stock--${f.stock_status}`}>
            {f.stock_status === 'out_of_stock' ? 'Sold out' : f.stock_status === 'low_stock' ? `Only ${f.available_quantity} ${f.unit}s left` : `${f.available_quantity} ${f.unit}s available`}
          </p>
          {f.is_demo && <Badge tone="waiting">Demonstration listing</Badge>}
          <dl className="specs">
            <div><dt>Material</dt><dd>{f.material ?? 'Not stated'}</dd></div>
            <div><dt>Colours</dt><dd>{f.colors.map(humanise).join(', ')}</dd></div>
            {f.pattern && <div><dt>Pattern</dt><dd>{humanise(f.pattern)}</dd></div>}
            <div><dt>Minimum order</dt><dd>{f.min_order_quantity} {f.unit}{f.min_order_quantity > 1 ? 's' : ''}</dd></div>
          </dl>
          <BuyBox fabric={f} />
          <div className="product__actions product__actions--extra">
            <TryOn fabric={f} />
            {f.purchase_mode === 'checkout' && <ButtonLink variant="secondary" to={`/fabrics/${f.slug}/aso-ebi`}>Start aṣọ-ẹbí</ButtonLink>}
          </div>
          <div className="product__actions">
            <ButtonLink variant={f.purchase_mode === 'checkout' ? 'secondary' : 'primary'} to="/requests/new" state={{ draft: { fabric_status: 'buying_on_platform', fabric_id: f.id } }}>Have a tailor make something with this</ButtonLink>
          </div>
          <VendorPanel fabric={f} />
        </div>
      </div>

      <Ideas fabric={f} />

      {more.length > 0 && (
        <section className="related" aria-labelledby="related-title">
          <h2 id="related-title" className="section-title section-title--small">More {f.category_label}</h2>
          <div className="grid grid--fabrics">{more.map((x) => <FabricCard key={x.id} fabric={x} />)}</div>
        </section>
      )}
    </div>
  )
}
