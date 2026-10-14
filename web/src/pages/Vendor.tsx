import { useEffect, useState, type FormEvent } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, ApiError, type FabricOrder, type LiveSession, type PriceOffer, type VendorListing, type VendorProfile } from '../api'
import { OfferCard } from '../components/ShopKit'
import { DeliveredWithCode } from '../components/Protection'
import { Swatch } from '../components/Swatch'
import { Badge, Button, EmptyState, ErrorState, Field, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { formatDate, groupDigits, humanise, nairaToMinor } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'
import { OrderTimeline } from './Shop'

type Tab = 'offers' | 'orders' | 'listings' | 'live' | 'shop'

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
    { key: 'live', label: 'Go live' },
    { key: 'shop', label: 'Shop & payouts' },
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
      {tab === 'live' && <GoLive />}
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
            {o.aso_ebi && <span className="cart-line__deal">Aṣọ-ẹbí · {o.aso_ebi.title}</span>}
            {o.ship?.country && <span className="cart-line__deal">Ship to {o.ship.country}</span>}
            {o.ship?.arrival_date && <span className="cart-line__deal">Deliver from {formatDate(o.ship.arrival_date)}</span>}
            <Badge tone={o.next.length ? 'waiting' : o.status === 'completed' ? 'good' : 'neutral'}>{o.status_label}</Badge>
            <span className="vorder__when">{o.created_at ? formatDate(o.created_at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''}</span>
          </header>
          <OrderTimeline order={o} />
          {o.dispute?.status === 'open' && (
            <p className="vorder__alert" role="status"><strong>Problem reported: {o.dispute.reason_label}.</strong> {o.dispute.details ? `“${o.dispute.details}” ` : ''}OSAPS support will contact you. Payout is on hold until it is settled.</p>
          )}
          {o.dispute?.status && o.dispute.status !== 'open' && (
            <p className="muted">Problem report settled{o.dispute.resolution_note ? `: ${o.dispute.resolution_note}` : '.'}</p>
          )}
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
            <span>You receive <strong>{o.vendor_payout?.formatted}</strong> <small className={`payout payout--${o.payout_status}`}>{o.payout_label ?? o.payout_status}</small></span>
            <span className="vorder__actions">
              {o.next.map((n) => n.needs_code ? (
                <DeliveredWithCode key={n.status} order={o} onDone={(u) => setList((l) => l?.map((x) => (x.reference === u.reference ? u : x)) ?? null)} />
              ) : (
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

function MediaRow({ f, onSaved }: { f: VendorListing; onSaved: (f: VendorListing) => void }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const media = f.media ?? []
  return (
    <tr className="media-row">
      <td colSpan={4}>
        <div className="media-strip">
          {media.map((m) => (
            <figure key={m.id} className="media-strip__item">
              {m.kind === 'video' ? <video src={m.url} muted playsInline preload="metadata" /> : <img src={m.url} alt="" />}
              <button type="button" className="media-strip__x" aria-label="Remove" onClick={async () => { try { onSaved(await api.removeListingMedia(f.id, m.id)) } catch (e) { toast(errorMessage(e), 'alert') } }}>×</button>
              {m.kind === 'video' && <span className="media-strip__tag">Video</span>}
            </figure>
          ))}
          <label className={`media-strip__add ${busy ? 'is-busy' : ''}`}>
            <span>{busy ? 'Uploading…' : media.length ? '+ Add' : '+ Add photos or a 15-second video of the real bolt'}</span>
            <input type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" disabled={busy} onChange={async (e) => {
              const file = e.target.files?.[0]
              e.target.value = ''
              if (!file) return
              setBusy(true)
              try { onSaved(await api.addListingMedia(f.id, file)); toast('Added. Buyers trust real photos and video.', 'good') }
              catch (err) { toast(err instanceof ApiError ? (err.field('file') ?? err.message) : errorMessage(err), 'alert') } finally { setBusy(false) }
            }} />
          </label>
        </div>
      </td>
    </tr>
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
          <tbody>{list.map((f) => {
            const save = (n: VendorListing) => setList((l) => l?.map((x) => (x.id === n.id ? n : x)) ?? null)
            return [<ListingRow key={f.id} f={f} onSaved={save} />, <MediaRow key={`m${f.id}`} f={f} onSaved={save} />]
          })}</tbody>
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
  const existing = useAsync(() => (user?.has_vendor_profile ? api.vendorProfile() : Promise.resolve(null)), [user?.has_vendor_profile])
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const s = (k: string) => String(f.get(k) ?? '').trim()
    setBusy(true); setError(null)
    try {
      const saved = await api.saveVendorProfile({
        business_name: s('business_name'), city: s('city'), market: s('market') || null, phone: s('phone') || null, whatsapp: s('whatsapp') || null,
        bank_name: s('bank_name') || null, bank_account_name: s('bank_account_name') || null, bank_account_number: s('bank_account_number') || null,
      })
      toast(saved.verification?.status === 'pending' ? 'Saved. OSAPS will check your shop, usually within a working day.' : 'Shop details saved.', 'good')
      existing.reload()
      await onSaved()
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError(errorMessage(err), 0, 'unknown')) } finally { setBusy(false) }
  }
  if (user?.has_vendor_profile && existing.status === 'loading') return <Skeleton lines={4} />
  const v = existing.data
  return (
    <form className="new-listing" onSubmit={submit} key={v?.slug ?? 'new'}>
      {v?.verification && <VerificationBanner v={v.verification} />}
      <Field label="Shop name" error={error?.field('business_name')}>{(p) => <input {...p} name="business_name" className="input" required minLength={2} defaultValue={v?.business_name ?? user?.name ?? ''} />}</Field>
      <div className="checkout__pair">
        <Field label="City" error={error?.field('city')}>{(p) => <input {...p} name="city" className="input" required defaultValue={v?.city ?? user?.city ?? ''} />}</Field>
        <Field label="Market" optional>{(p) => <input {...p} name="market" className="input" placeholder="Balogun Market" defaultValue={v?.market ?? ''} />}</Field>
      </div>
      <div className="checkout__pair">
        <Field label="Phone" optional hint="Shown to buyers only after they pay." error={error?.field('phone')}>{(p) => <input {...p} name="phone" className="input" type="tel" defaultValue={v?.phone ?? ''} />}</Field>
        <Field label="WhatsApp" optional error={error?.field('whatsapp')}>{(p) => <input {...p} name="whatsapp" className="input" type="tel" defaultValue={v?.whatsapp ?? ''} />}</Field>
      </div>
      <h3 className="checkout__h">Payouts</h3>
      <p className="muted">You are paid by bank transfer after the buyer has the fabric: they confirm in the app, or give you their 6-digit delivery code to enter. They then have 7 days to report a problem. Changing bank details sends your shop back for a quick check.</p>
      <div className="checkout__pair">
        <Field label="Bank" optional>{(p) => <input {...p} name="bank_name" className="input" defaultValue={v?.bank_name ?? ''} placeholder="GTBank" />}</Field>
        <Field label="Account name" optional>{(p) => <input {...p} name="bank_account_name" className="input" defaultValue={v?.bank_account_name ?? ''} />}</Field>
      </div>
      <Field label="Account number (10 digits)" optional hint={v?.bank_account_last4 ? `Saved: ••••••${v.bank_account_last4}. Leave empty to keep it.` : 'Stored encrypted. Only OSAPS payouts staff can see it.'} error={error?.field('bank_account_number')}>
        {(p) => <input {...p} name="bank_account_number" className="input" inputMode="numeric" pattern="[0-9]{10}" maxLength={10} />}
      </Field>
      {error && error.code !== 'validation_failed' && <p className="field__error">{error.message}</p>}
      <Button type="submit" busy={busy}>Save shop</Button>
    </form>
  )
}

const MISSING: Record<string, string> = {
  business_name: 'shop name', city: 'city', market: 'market', phone: 'phone', bank_name: 'bank', bank_account_name: 'account name', bank_account_number: '10-digit account number',
}

/** Where the shop stands with OSAPS. Buyers see listings only from verified shops. */
function VerificationBanner({ v }: { v: NonNullable<VendorProfile['verification']> }) {
  const tone = v.status === 'verified' ? 'good' : v.status === 'pending' ? 'waiting' : 'alert'
  const text = {
    verified: 'Buyers can see and buy your active listings.',
    pending: 'OSAPS is checking your shop and bank details. Your listings go live as soon as it is verified.',
    unverified: `Your listings are hidden until OSAPS verifies your shop. Add your ${v.missing.map((m) => MISSING[m] ?? m).join(', ')} and save to ask for review.`,
    rejected: 'Your shop needs changes before it can go live. Fix the details below and save to ask again.',
    suspended: 'Your shop is suspended and hidden from buyers. Contact OSAPS support.',
  }[v.status]
  return (
    <div className={`verify verify--${tone}`} role="status">
      <Badge tone={tone}>{v.label}</Badge>
      <p>{text}{v.note ? <><br /><strong>OSAPS note:</strong> {v.note}</> : null}</p>
    </div>
  )
}

function GoLive() {
  const res = useAsync(() => api.myLive(), [])
  const listings = useAsync(() => api.vendorListings(), [])
  const toast = useToast()
  const [list, setList] = useState<LiveSession[] | null>(null)
  const [picked, setPicked] = useState<number[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => { if (res.data) setList(res.data) }, [res.data])
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const d = new FormData(e.currentTarget)
    const s = (k: string) => String(d.get(k) ?? '').trim()
    setBusy(true); setError(null)
    try {
      const created = await api.createLive({ title: s('title'), platform: s('platform') as LiveSession['platform'], url: s('url'), starts_at: new Date(s('starts_at')).toISOString(), fabric_ids: picked })
      setList((l) => [...(l ?? []), created])
      toast('Live session added. Buyers see it on the Live page.', 'good')
      e.currentTarget.reset(); setPicked([])
    } catch (err) { setError(errorMessage(err)) } finally { setBusy(false) }
  }
  return (
    <div className="listings">
      <p className="muted">Go live on TikTok, Instagram, YouTube or Facebook as usual, and list the session here. Buyers watch, then name their price on the fabrics you feature.</p>
      <form className="new-listing" onSubmit={submit}>
        <Field label="Title">{(p) => <input {...p} name="title" className="input" required minLength={3} maxLength={100} placeholder="New Ankara drop: 40 prints under ₦5k" />}</Field>
        <div className="checkout__pair">
          <Field label="Platform">{(p) => <select {...p} name="platform" className="input"><option value="tiktok">TikTok</option><option value="instagram">Instagram</option><option value="youtube">YouTube</option><option value="facebook">Facebook</option></select>}</Field>
          <Field label="Starts">{(p) => <input {...p} name="starts_at" type="datetime-local" className="input" required />}</Field>
        </div>
        <Field label="Link to your live or profile" hint="Must be an https link on that platform.">{(p) => <input {...p} name="url" type="url" className="input" required placeholder="https://www.tiktok.com/@yourshop/live" />}</Field>
        {listings.data && listings.data.length > 0 && (
          <div className="field">
            <span className="field__label">Feature fabrics</span>
            <div className="name-price__chips">{listings.data.map((f) => <button key={f.id} type="button" className="chip" aria-pressed={picked.includes(f.id)} onClick={() => setPicked((p) => (p.includes(f.id) ? p.filter((x) => x !== f.id) : [...p, f.id].slice(0, 12)))}>{f.name}</button>)}</div>
          </div>
        )}
        {error && <p className="field__error" role="alert">{error}</p>}
        <Button type="submit" busy={busy}>Add live session</Button>
      </form>
      {list && list.length > 0 && (
        <ul className="aso__guests">
          {list.map((l) => (
            <li key={l.id}><span>{l.is_live ? '🔴 ' : ''}{l.title}</span><span>{formatDate(l.starts_at, { weekday: 'short', day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
              <button className="text-link" onClick={async () => { await api.deleteLive(l.id); setList((x) => x?.filter((y) => y.id !== l.id) ?? null) }}>Remove</button></li>
          ))}
        </ul>
      )}
    </div>
  )
}
