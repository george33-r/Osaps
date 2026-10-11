import { useState } from 'react'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import { api, type FabricFilters } from '../api'
import { FabricCard } from '../components/cards'
import { COLOR_HEX, Swatch } from '../components/Swatch'
import { Badge, ButtonLink, EmptyState, ErrorState, PageHeader, Price, Skeleton } from '../components/ui'
import { humanise, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

const SORTS: { key: NonNullable<FabricFilters['sort']>; label: string }[] = [
  { key: 'newest', label: 'Newest' },
  { key: 'price_asc', label: 'Price: low to high' },
  { key: 'price_desc', label: 'Price: high to low' },
]

export function FabricCatalogue() {
  const { meta } = useSession()
  const [params, setParams] = useSearchParams()
  const [filtersOpen, setFiltersOpen] = useState(false)

  const filters: FabricFilters = {
    q: params.get('q') ?? undefined,
    category: params.getAll('category'),
    color: params.getAll('color'),
    max_price: params.get('max') ? Number(params.get('max')) * 100 : undefined,
    in_stock: params.get('in_stock') === '1',
    sort: (params.get('sort') as FabricFilters['sort']) ?? 'newest',
  }
  const key = params.toString()
  const result = useAsync(() => api.fabrics(filters), [key])

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
      <PageHeader
        title="Fabric"
        intro="Ankara, adire, aṣọ-òkè, lace and more from market vendors. Prices and stock come from the vendors' listings."
      />

      <div className="catalogue">
        <form
          className="catalogue__search"
          role="search"
          onSubmit={(e) => {
            e.preventDefault()
            set('q', (new FormData(e.currentTarget).get('q') as string).trim() || null)
          }}
        >
          <label htmlFor="fabric-q" className="sr-only">
            Search fabric
          </label>
          <input id="fabric-q" name="q" className="input" placeholder="Search by name or material, e.g. cashmere" defaultValue={filters.q} />
          <button className="btn btn--secondary" type="submit">
            Search
          </button>
          <button
            type="button"
            className="btn btn--quiet catalogue__filter-toggle"
            aria-expanded={filtersOpen}
            aria-controls="fabric-filters"
            onClick={() => setFiltersOpen((o) => !o)}
          >
            Filters{activeCount ? ` (${activeCount})` : ''}
          </button>
        </form>

        <aside id="fabric-filters" className={`filters ${filtersOpen ? 'filters--open' : ''}`} aria-label="Filters">
          <fieldset className="filters__group">
            <legend>Type</legend>
            <div className="chips">
              {meta?.fabric_categories.map((c) => (
                <button
                  key={c.key}
                  type="button"
                  className="chip"
                  aria-pressed={filters.category!.includes(c.key)}
                  onClick={() => toggle('category', c.key)}
                >
                  {c.label}
                </button>
              ))}
            </div>
          </fieldset>

          <fieldset className="filters__group">
            <legend>Main colour</legend>
            <div className="colors">
              {(meta?.colors ?? []).filter((c) => COLOR_HEX[c]).map((c) => (
                <button
                  key={c}
                  type="button"
                  className="color-dot"
                  style={{ ['--dot' as string]: COLOR_HEX[c] }}
                  aria-pressed={filters.color!.includes(c)}
                  aria-label={humanise(c)}
                  title={humanise(c)}
                  onClick={() => toggle('color', c)}
                />
              ))}
            </div>
          </fieldset>

          <fieldset className="filters__group">
            <legend>Price per unit</legend>
            <label className="filters__inline" htmlFor="fabric-max">
              Up to ₦
              <input
                id="fabric-max"
                className="input input--short"
                inputMode="numeric"
                placeholder="Any"
                defaultValue={params.get('max') ?? ''}
                onBlur={(e) => {
                  const minor = nairaToMinor(e.currentTarget.value)
                  set('max', minor ? String(minor / 100) : null)
                }}
                onKeyDown={(e) => e.key === 'Enter' && e.currentTarget.blur()}
              />
            </label>
          </fieldset>

          <label className="check">
            <input type="checkbox" checked={filters.in_stock} onChange={(e) => set('in_stock', e.target.checked ? '1' : null)} />
            Only show fabric in stock
          </label>

          {activeCount > 0 && (
            <button type="button" className="text-link filters__clear" onClick={() => setParams(filters.q ? { q: filters.q } : {}, { replace: true })}>
              Clear filters
            </button>
          )}
        </aside>

        <section className="catalogue__results" aria-live="polite" aria-busy={result.status === 'loading'}>
          <div className="catalogue__bar">
            <p className="muted">
              {result.data ? `${result.data.meta?.total ?? result.data.data.length} fabrics` : 'Loading fabric'}
            </p>
            <label className="filters__inline">
              Sort
              <select className="input input--select" value={filters.sort} onChange={(e) => set('sort', e.target.value === 'newest' ? null : e.target.value)}>
                {SORTS.map((s) => (
                  <option key={s.key} value={s.key}>
                    {s.label}
                  </option>
                ))}
              </select>
            </label>
          </div>

          {result.status === 'error' && !result.data ? (
            <ErrorState error={result.error} onRetry={result.reload} />
          ) : !result.data ? (
            <div className="grid grid--bolts">
              {Array.from({ length: 8 }, (_, i) => (
                <Skeleton key={i} block lines={2} />
              ))}
            </div>
          ) : result.data.data.length === 0 ? (
            <EmptyState
              title="No fabric matches these filters"
              action={
                <button className="btn btn--secondary" onClick={() => setParams({}, { replace: true })}>
                  Clear all filters
                </button>
              }
            >
              Try another colour or type, or raise the price limit.
            </EmptyState>
          ) : (
            <div className={`grid grid--bolts ${result.status === 'loading' ? 'is-refreshing' : ''}`}>
              {result.data.data.map((f) => (
                <FabricCard key={f.id} fabric={f} />
              ))}
            </div>
          )}
        </section>
      </div>
    </div>
  )
}

export function FabricDetail() {
  const { slug = '' } = useParams()
  const res = useAsync(() => api.fabric(slug), [slug])

  if (res.status === 'error') return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton block lines={4} /></div>
  const f = res.data

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/fabrics">Fabric</Link> <span aria-hidden>/</span> <span>{f.category_label}</span>
      </nav>
      <div className="fabric-detail">
        <figure className="fabric-detail__cloth">
          {f.image_url ? <img src={f.image_url} alt={f.name} /> : <Swatch pattern={f.pattern} colors={f.colors} scale={1.6} />}
          {!f.image_url && <figcaption>Illustration drawn from the listing's pattern and colours. Vendor photos coming soon.</figcaption>}
        </figure>
        <div className="fabric-detail__info">
          <h1 className="fabric-detail__name">{f.name}</h1>
          <p className="fabric-detail__price">
            <Price value={f.price} unit={f.unit} large />
          </p>
          {f.is_demo && <Badge tone="waiting">Demonstration listing</Badge>}
          <dl className="specs">
            <div><dt>Type</dt><dd>{f.category_label}</dd></div>
            <div><dt>Material</dt><dd>{f.material ?? 'Not stated'}</dd></div>
            <div><dt>Colours</dt><dd>{f.colors.map(humanise).join(', ')}</dd></div>
            <div><dt>Available</dt><dd>{f.available_quantity > 0 ? `${f.available_quantity} ${f.unit}s` : 'Out of stock'}</dd></div>
            <div><dt>Minimum order</dt><dd>{f.min_order_quantity} {f.unit}{f.min_order_quantity > 1 ? 's' : ''}</dd></div>
          </dl>
          {f.vendor && (
            <div className="vendor-box">
              <h2 className="vendor-box__title">Sold by {f.vendor.business_name}</h2>
              <p className="muted">
                {f.vendor.market ? `${f.vendor.market}, ` : ''}
                {f.vendor.city}
              </p>
              <p className="vendor-box__note">
                For now, buy this fabric from the vendor in person. Ordering and paying for fabric through Aṣọlà is not available yet.
              </p>
            </div>
          )}
          <div className="fabric-detail__actions">
            <ButtonLink to={`/requests/new?fabric=${f.id}`}>Have a tailor make something with this</ButtonLink>
          </div>
        </div>
      </div>
    </div>
  )
}
