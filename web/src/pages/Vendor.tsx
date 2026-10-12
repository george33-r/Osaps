import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, ApiError, type FabricOrder, type PriceOffer, type VendorListing } from '../api'
import { OfferCard } from '../components/ShopKit'
import { Swatch } from '../components/Swatch'
import { Badge, Button, EmptyState, ErrorState, Field, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { formatDate, groupDigits, humanise, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'
import { OrderTimeline } from './Shop'

type Tab = 'offers' | 'orders' | 'listings' | 'shop'

/** Everything a fabric vendor needs: answer price offers, ship paid orders, keep price and stock right. */
export function VendorHub() {
  const { user, refresh } = useSession()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) || 'offers'
  const offers = useAsync(() => api.vendorPriceOffers(), [])
  const orders = useAsync(() => api.vendorFabricOrders(), [])
  const waiting = offers.data?.data.filter((o) => o.your_turn).length ?? 0
  const toShip = orders.data?.data.filter((o) => o.next.length > 0).length ?? 0

  // New price offers arrive while you watch.
  useEffect(() => {
    const t = window.setInterval(() => { offers.reload(); orders.reload() }, 15_000)
    return () => window.clearInterval(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  if (user?.role !== 'fabric_vendor') {
    return (
      <div className="page page--narrow">
        <PageHeader title="For fabric vendors" intro="Sell your fabric on OSAPS: buyers pay through Paystack before you ship." />
        <Link className="btn btn--primary" to="/register?role=fabric_vendor">Open a vendor account</Link>
      </div>
    )
  }
  if (!user.has_vendor_profile) {
    return (
      <div className="page page--narrow">
        <PageHeader title="Set up your shop" intro="Buyers see your shop name and market. Your phone stays private until someone has paid." />
        <ShopForm onSaved={refresh} />
      </div>
    )
  }

  const tabs: { key: Tab; label: string; n?: number }[] = [
    { key: 'offers', label: 'Price offers', n: waiting },
    { key: 'orders', label: 'Orders', n: toShip },
    { key: 'listings', label: 'Listings' },
    { key: 'shop', label: 'Shop details' },
  ]

  return (
    <div className="page">
      <PageHeader label="Vendor" title="Your shop" intro="Answer offers fast: buyers who get a reply within an hour are far more likely to buy." />
      <div className="vtabs" role="tablist">
        {tabs.map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} className="vtabs__tab" onClick={() => setParams({ tab: t.key }, { replace: true })}>
            {t.label}{t.n ? <span className="vtabs__n">{t.n}</span> : null}
          </button>
        ))}
      </div>
      {tab === 'offers' && <VendorOffers state={offers} />}
      {tab === 'orders' && <VendorOrders state={orders} />}
      {tab === 'listings' && <Listings />}
      {tab === 'shop' && <ShopForm onSaved={refresh} />}
    </div>
  )
}

function VendorOffers({ state }: { state: ReturnType<typeof useAsync<{ data: PriceOffer[]; is_vendor: boolean }>> }) {
  const [list, setList] = useState<PriceOffer[] | null>(null)
  useEffect(() => { if (state.data) setList(state.data.data) }, [state.data])
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!list) return <Skeleton lines={4} />
  if (list.length === 0) return <EmptyState title="No price offers yet">When a buyer names a price on your fabric, it lands here. Accept, counter or decline.</EmptyState>
  return <div className="offer-grid">{list.map((o) => <OfferCard key={o.id} offer={o} showFabric onChange={(n) => setList((l) => l?.map((x) => (x.id === n.id ? n : x)) ?? null)} />)}</div>
}

function VendorOrders({ state }: { state: ReturnType<typeof useAsync<{ data: FabricOrder[]; is_vendor: boolean }>> }) {
  const [list, setList] = useState<FabricOrder[] | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => { if (state.data) setList(state.data.data) }, [state.data])
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!list) return <Skeleton lines={4} />
  if (list.length === 0) return <EmptyState title="No paid orders yet">Orders appear here as soon as the buyer's payment is confirmed.</EmptyState>
  return (
    <div className="vorders">
      {error && <p className="field__error" role="alert">{error}</p>}
      {list.map((o) => (
        <article key={o.reference} className={`vorder ${o.next.length ? 'needs-action' : ''}`}>
          <header className="vorder__head">
            <strong>{o.reference}</strong>
            <Badge tone={o.next.length ? 'waiting' : o.status === 'completed' ? 'good' : 'neutral'}>{o.status_label}</Badge>
            <span className="vorder__when">{o.created_at ? formatDate(o.created_at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''}</span>
          </header>
          <OrderTimeline order={o} />
          <ul className="order-items">
            {o.items?.map((i) => (
              <li key={i.fabric_id}>
                <span className="order-items__swatch"><Swatch pattern={i.pattern} colors={i.colors ?? ['indigo']} scale={0.5} /></span>
                <span>{i.quantity} {i.unit}{i.quantity === 1 ? '' : 's'} · {i.name}{i.negotiated && <em className="order-items__deal"> agreed price</em>}</span>
                <strong>{i.line_total.formatted}</strong>
              </li>
            ))}
          </ul>
          {o.deliver_to && (
            <div className="vorder__to">
              <p className="label">{o.fulfilment === 'pickup' ? 'Collecting' : 'Deliver to'}</p>
              <p><strong>{o.deliver_to.name}</strong> · <a href={`tel:${o.deliver_to.phone}`}>{o.deliver_to.phone}</a></p>
              {o.deliver_to.address && <p className="muted">{Object.values(o.deliver_to.address).filter(Boolean).join(', ')}</p>}
            </div>
          )}
          <footer className="vorder__foot">
            <span>You receive <strong>{o.vendor_payout?.formatted}</strong></span>
            <span className="vorder__actions">
              {o.next.map((n) => (
                <Button key={n.status} busy={busy === o.reference + n.status} onClick={async () => {
                  setBusy(o.reference + n.status); setError(null)
                  try { const u = await api.transitionFabricOrder(o.reference, n.status); setList((l) => l?.map((x) => (x.reference === u.reference ? u : x)) ?? null) }
                  catch (e) { setError(errorMessage(e)) } finally { setBusy(null) }
                }}>Mark {n.label.toLowerCase()}</Button>
              ))}
            </span>
          </footer>
        </article>
      ))}
    </div>
  )
}

function ListingRow({ f, onSaved }: { f: VendorListing; onSaved: (f: VendorListing) => void }) {
  const toast = useToast()
  const [price, setPrice] = useState(groupDigits(String(f.price.amount_minor / 100)))
  const [stock, setStock] = useState(String(f.stock_quantity))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const dirty = nairaToMinor(price) !== f.price.amount_minor || Number(stock) !== f.stock_quantity
  return (
    <tr className={f.status !== 'active' ? 'is-off' : ''}>
      <td>
        <span className="listing__name">
          <span className="order-items__swatch"><Swatch pattern={f.pattern} colors={f.colors} scale={0.5} /></span>
          <Link to={`/fabrics/${f.slug}`}>{f.name}</Link>
        </span>
        {f.reserved_quantity > 0 && <small className="muted"> {f.reserved_quantity} held for buyers paying now</small>}
      </td>
      <td><label className="sr-only" htmlFor={`p${f.id}`}>Price per {f.unit}</label><span className="listing__money">₦<input id={`p${f.id}`} className="input input--short" inputMode="numeric" value={price} onChange={(e) => setPrice(groupDigits(e.target.value))} /></span><small className="muted"> / {f.unit}</small></td>
      <td><label className="sr-only" htmlFor={`s${f.id}`}>Stock</label><input id={`s${f.id}`} className="input input--short" inputMode="numeric" value={stock} onChange={(e) => setStock(e.target.value.replace(/\D/g, ''))} /></td>
      <td>
        <Button variant={dirty ? 'primary' : 'secondary'} busy={busy} disabled={!dirty} onClick={async () => {
          const p = nairaToMinor(price)
          if (p === null) { setError('Enter a price.'); return }
          setBusy(true); setError(null)
          try { onSaved(await api.updateListing(f.id, { price_minor: p, stock_quantity: Number(stock) })); toast(`${f.name} updated.`, 'good') }
          catch (e) { setError(e instanceof ApiError ? (e.field('stock_quantity') ?? e.message) : errorMessage(e)) } finally { setBusy(false) }
        }}>Save</Button>
        {error && <p className="field__error">{error}</p>}
      </td>
    </tr>
  )
}

function Listings() {
  const res = useAsync(() => api.vendorListings(), [])
  const [list, setList] = useState<VendorListing[] | null>(null)
  const [adding, setAdding] = useState(false)
  useEffect(() => { if (res.data) setList(res.data) }, [res.data])
  if (res.status === 'error') return <ErrorState error={res.error} onRetry={res.reload} />
  if (!list) return <Skeleton lines={4} />
  return (
    <div className="listings">
      <div className="listings__bar">
        <p className="muted">{list.length} listings. Buyers can never be charged more than the price you set here.</p>
        <Button variant={adding ? 'quiet' : 'primary'} onClick={() => setAdding((a) => !a)}>{adding ? 'Close' : 'Add a fabric'}</Button>
      </div>
      {adding && <NewListingForm onCreated={(f) => { setList((l) => [f, ...(l ?? [])]); setAdding(false) }} />}
      <div className="table-wrap">
        <table className="listing-table">
          <thead><tr><th>Fabric</th><th>Price</th><th>Stock</th><th><span className="sr-only">Save</span></th></tr></thead>
          <tbody>{list.map((f) => <ListingRow key={f.id} f={f} onSaved={(n) => setList((l) => l?.map((x) => (x.id === n.id ? n : x)) ?? null)} />)}</tbody>
        </table>
      </div>
    </div>
  )
}

function NewListingForm({ onCreated }: { onCreated: (f: VendorListing) => void }) {
  const { meta } = useSession()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const s = (k: string) => String(f.get(k) ?? '').trim()
    const price = nairaToMinor(s('price'))
    setBusy(true); setError(null)
    try {
      onCreated(await api.createListing({
        name: s('name'), category: s('category'), primary_color: s('color'), unit: s('unit'), material: s('material') || null,
        pattern: s('pattern') || null, description: s('description') || null, price_minor: price ?? 0, stock_quantity: Number(s('stock')) || 0,
        min_order_quantity: Number(s('min')) || 1,
      }))
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')) } finally { setBusy(false) }
  }
  return (
    <form className="new-listing" onSubmit={submit}>
      <Field label="Fabric name" error={error?.field('name')}>{(p) => <input {...p} name="name" className="input" required minLength={3} placeholder="Royal blue Ankara, Hollandais" />}</Field>
      <div className="checkout__pair">
        <Field label="Type">{(p) => <select {...p} name="category" className="input">{meta?.fabric_categories.map((c) => <option key={c.key} value={c.key}>{c.label}</option>)}</select>}</Field>
        <Field label="Main colour">{(p) => <select {...p} name="color" className="input">{meta?.colors.map((c) => <option key={c} value={c}>{humanise(c)}</option>)}</select>}</Field>
      </div>
      <div className="checkout__pair">
        <Field label="Price (₦)" error={error?.field('price_minor')}>{(p) => <input {...p} name="price" className="input" inputMode="numeric" required placeholder="4,500" />}</Field>
        <Field label="Sold per">{(p) => <select {...p} name="unit" className="input">{meta?.fabric_units.map((u) => <option key={u} value={u}>{u}</option>)}</select>}</Field>
      </div>
      <div className="checkout__pair">
        <Field label="In stock" error={error?.field('stock_quantity')}>{(p) => <input {...p} name="stock" className="input" inputMode="numeric" required placeholder="50" />}</Field>
        <Field label="Minimum order" optional>{(p) => <input {...p} name="min" className="input" inputMode="numeric" placeholder="1" />}</Field>
      </div>
      <div className="checkout__pair">
        <Field label="Material" optional>{(p) => <input {...p} name="material" className="input" placeholder="Cotton wax print" />}</Field>
        <Field label="Pattern" optional>{(p) => <input {...p} name="pattern" className="input" placeholder="Floral" />}</Field>
      </div>
      <Field label="Description" optional>{(p) => <textarea {...p} name="description" className="input" rows={3} maxLength={1000} />}</Field>
      {error && error.code !== 'validation_failed' && <p className="field__error">{error.message}</p>}
      <Button type="submit" busy={busy}>List it</Button>
      <p className="muted">Photos are coming soon; until then buyers see an illustration drawn from your colours and pattern.</p>
    </form>
  )
}

function ShopForm({ onSaved }: { onSaved: () => void | Promise<void> }) {
  const { user } = useSession()
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const s = (k: string) => String(f.get(k) ?? '').trim()
    setBusy(true); setError(null)
    try {
      await api.saveVendorProfile({ business_name: s('business_name'), city: s('city'), market: s('market') || null, phone: s('phone') || null, whatsapp: s('whatsapp') || null })
      toast('Shop details saved.', 'good')
      await onSaved()
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')) } finally { setBusy(false) }
  }
  return (
    <form className="new-listing" onSubmit={submit}>
      <Field label="Shop name" error={error?.field('business_name')}>{(p) => <input {...p} name="business_name" className="input" required minLength={2} defaultValue={user?.name ?? ''} />}</Field>
      <div className="checkout__pair">
        <Field label="City" error={error?.field('city')}>{(p) => <input {...p} name="city" className="input" required defaultValue={user?.city ?? ''} />}</Field>
        <Field label="Market" optional>{(p) => <input {...p} name="market" className="input" placeholder="Balogun Market" />}</Field>
      </div>
      <div className="checkout__pair">
        <Field label="Phone" optional hint="Shown to buyers only after they pay." error={error?.field('phone')}>{(p) => <input {...p} name="phone" className="input" type="tel" />}</Field>
        <Field label="WhatsApp" optional error={error?.field('whatsapp')}>{(p) => <input {...p} name="whatsapp" className="input" type="tel" />}</Field>
      </div>
      {error && error.code !== 'validation_failed' && <p className="field__error">{error.message}</p>}
      <Button type="submit" busy={busy}>Save shop</Button>
    </form>
  )
}
