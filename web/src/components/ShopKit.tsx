import { useEffect, useMemo, useRef, useState } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { api, ApiError, type Fabric, type PriceOffer } from '../api'
import { useCart } from '../lib/cart'
import { formatNaira, groupDigits, nairaToMinor, timeLeft } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { PieceChoice, YardHelper, useOffscreen } from './ProductKit'
import { useDeliveryInfo, useExpressInfo } from './StatePicker'
import { Swatch } from './Swatch'
import { WhatsAppLink, whatsappHref } from './WhatsApp'
import { Button, errorMessage } from './ui'

export function CartIcon() {
  const { count } = useCart()
  return (
    <Link to="/cart" className="icon-btn cart-icon" aria-label={count ? `Cart, ${count} ${count === 1 ? 'fabric' : 'fabrics'}` : 'Cart'}>
      <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round">
        <path d="M5 7h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 7Z" />
        <path d="M9 9V6a3 3 0 0 1 6 0v3" />
      </svg>
      {count > 0 && <span className="cart-icon__count" key={count}>{count}</span>}
    </Link>
  )
}

export function QtyStepper({ value, min, max, unit, onChange, label = 'Quantity' }: { value: number; min: number; max: number; unit: string; onChange: (n: number) => void; label?: string }) {
  const clamp = (n: number) => Math.max(min, Math.min(Math.max(min, max), Number.isFinite(n) ? Math.round(n) : min))
  return (
    <div className="qty" role="group" aria-label={label}>
      <button type="button" className="qty__btn" onClick={() => onChange(clamp(value - 1))} disabled={value <= min} aria-label="One less">−</button>
      <input className="qty__input" inputMode="numeric" value={value} aria-label={`${label} in ${unit}s`}
        onChange={(e) => onChange(clamp(Number(e.target.value.replace(/\D/g, '')) || min))} />
      <button type="button" className="qty__btn" onClick={() => onChange(clamp(value + 1))} disabled={value >= max} aria-label="One more">+</button>
      <span className="qty__unit">{unit}{value === 1 ? '' : 's'}</span>
    </div>
  )
}

/** Live countdown text that refreshes every 30 seconds. */
export function Countdown({ iso, prefix }: { iso: string; prefix?: string }) {
  const [, tick] = useState(0)
  useEffect(() => {
    const t = window.setInterval(() => tick((n) => n + 1), 30_000)
    return () => window.clearInterval(t)
  }, [])
  const left = timeLeft(iso)
  return <span className={`countdown ${left === 'expired' ? 'countdown--over' : ''}`}>{prefix ? `${prefix} ` : ''}{left}</span>
}

/** A big naira amount you can type, nudge in ₦50 steps, or slide between the floor and the list price. */
function PriceDial({ minor, onChange, floor, ceiling, label }: { minor: number; onChange: (m: number) => void; floor: number; ceiling: number; label: string }) {
  const [text, setText] = useState(groupDigits(String(Math.floor(minor / 100))))
  useEffect(() => setText(groupDigits(String(Math.floor(minor / 100)))), [minor])
  const step = 5_000
  const set = (m: number) => onChange(Math.max(floor, Math.min(ceiling, Math.round(m / step) * step)))
  return (
    <div className="dial">
      <div className="dial__row">
        <button type="button" className="dial__nudge" onClick={() => set(minor - step)} disabled={minor <= floor} aria-label="₦50 less">−</button>
        <label className="dial__amount">
          <span className="sr-only">{label}</span>
          <span aria-hidden className="dial__naira">₦</span>
          <input inputMode="numeric" value={text} aria-label={label}
            onChange={(e) => setText(groupDigits(e.target.value))}
            onBlur={() => { const m = nairaToMinor(text); if (m !== null) set(m); else setText(groupDigits(String(Math.floor(minor / 100)))) }}
            onKeyDown={(e) => e.key === 'Enter' && (e.currentTarget as HTMLInputElement).blur()} />
        </label>
        <button type="button" className="dial__nudge" onClick={() => set(minor + step)} disabled={minor >= ceiling} aria-label="₦50 more">+</button>
      </div>
      {ceiling > floor && (
        <input type="range" className="dial__range" min={floor} max={ceiling} step={step} value={Math.max(floor, Math.min(ceiling, minor))}
          onChange={(e) => onChange(Number(e.target.value))} aria-label={`${label}, slider`} />
      )}
      <div className="dial__ends"><span>{formatNaira(floor)}</span><span>{formatNaira(ceiling)}</span></div>
    </div>
  )
}

const BY: Record<string, string> = { buyer: 'You', vendor: 'Vendor', system: 'OSAPS' }
function trailText(h: NonNullable<PriceOffer['history']>[number], side: 'buyer' | 'vendor') {
  const who = side === 'vendor' ? (h.by === 'vendor' ? 'You' : h.by === 'buyer' ? 'Buyer' : 'OSAPS') : BY[h.by]
  switch (h.action) {
    case 'open': return `${who} offered ${h.unit?.formatted}`
    case 'counter': return `${who} countered ${h.unit?.formatted}`
    case 'accept': return `${who} accepted ${h.unit?.formatted}`
    case 'decline': return `${who} declined`
    case 'withdraw': return `${who} withdrew`
    default: return 'Offer expired'
  }
}

/** One price offer, inDrive-style: the price trail, a countdown, and the moves open to you. */
export function OfferCard({ offer, onChange, showFabric = false }: { offer: PriceOffer; onChange: (o: PriceOffer) => void; showFabric?: boolean }) {
  const cart = useCart()
  const toast = useToast()
  const navigate = useNavigate()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [countering, setCountering] = useState(false)
  const side = offer.your_side
  const floor = side === 'vendor' ? offer.buyer_unit.amount_minor + 5_000 : offer.buyer_unit.amount_minor + 5_000
  const ceiling = side === 'vendor' ? offer.list_unit.amount_minor : (offer.vendor_unit?.amount_minor ?? offer.list_unit.amount_minor) - 5_000
  const [counter, setCounter] = useState(Math.min(ceiling, Math.max(floor, Math.round((offer.unit.amount_minor + (side === 'vendor' ? offer.list_unit.amount_minor : offer.buyer_unit.amount_minor)) / 2 / 5_000) * 5_000)))
  const live = offer.status === 'awaiting_vendor' || offer.status === 'awaiting_buyer'

  // Waiting on the other side: check back every few seconds, like a ride request.
  useEffect(() => {
    if (!live || offer.your_turn) return
    const t = window.setInterval(async () => {
      try { onChange(await api.priceOffer(offer.id)) } catch { /* keep the last state */ }
    }, 4_000)
    return () => window.clearInterval(t)
  }, [live, offer.your_turn, offer.id, onChange])

  const run = async (kind: string, fn: () => Promise<PriceOffer>) => {
    setBusy(kind); setError(null)
    try {
      const next = await fn()
      onChange(next)
      setCountering(false)
      if (kind === 'accept') toast(side === 'buyer' ? `Deal! ${next.unit.formatted} per ${offer.fabric?.unit ?? 'unit'} is yours for 48 hours.` : 'Deal agreed. The buyer has 48 hours to pay.', 'good')
    } catch (e) {
      setError(e instanceof ApiError ? e.message : errorMessage(e))
      if (e instanceof ApiError && e.code === 'offer_changed') { try { onChange(await api.priceOffer(offer.id)) } catch { /* ignore */ } }
    } finally { setBusy(null) }
  }

  const theirPrice = offer.status === 'awaiting_buyer' ? offer.vendor_unit : offer.status === 'awaiting_vendor' ? offer.buyer_unit : offer.unit
  const headline = offer.status === 'accepted' ? 'Deal' : offer.your_turn ? (side === 'buyer' ? 'Vendor says' : 'Buyer offers') : side === 'buyer' ? 'You offered' : 'You countered'

  return (
    <article className={`offer-card offer-card--${offer.status} ${offer.your_turn ? 'is-your-turn' : ''}`}>
      {showFabric && offer.fabric && (
        <Link to={`/fabrics/${offer.fabric.slug}`} className="offer-card__fabric">
          <span className="offer-card__swatch"><Swatch pattern={offer.fabric.pattern} colors={offer.fabric.colors} scale={0.6} /></span>
          <span><strong>{offer.fabric.name}</strong><small>{offer.fabric.vendor?.business_name}{side === 'vendor' && offer.buyer_name ? ` · from ${offer.buyer_name}` : ''}</small></span>
        </Link>
      )}
      <div className="offer-card__top">
        <span className={`offer-pill offer-pill--${offer.status}`}>{offer.your_turn ? 'Your move' : offer.status_label}</span>
        {live && offer.respond_by && <Countdown iso={offer.respond_by} />}
        {offer.status === 'accepted' && offer.hold_until && <Countdown iso={offer.hold_until} prefix="Price held," />}
      </div>
      <p className="offer-card__label">{headline}</p>
      <p className="offer-card__price">{theirPrice?.formatted}<span> per {offer.fabric?.unit ?? 'unit'} × {offer.quantity}</span></p>
      <p className="offer-card__meta">
        Listed at {offer.list_unit.formatted}
        {offer.saving.amount_minor > 0 && <> · <strong className="offer-card__save">saves {offer.saving.formatted}</strong></>}
      </p>

      {offer.history && offer.history.length > 0 && (
        <ol className="offer-trail" aria-label="Price history">
          {offer.history.map((h, i) => (
            <li key={i} className={`offer-trail__step offer-trail__step--${(side === 'vendor' ? h.by === 'vendor' : h.by === 'buyer') ? 'me' : h.by === 'system' ? 'sys' : 'them'}`}>{trailText(h, side)}</li>
          ))}
        </ol>
      )}

      {live && !offer.your_turn && (
        <p className="offer-card__waiting"><span className="pulse" aria-hidden /> {side === 'buyer' ? 'Waiting for the vendor to answer…' : 'Waiting for the buyer…'}</p>
      )}

      {countering && (
        <div className="offer-card__counter">
          <PriceDial minor={counter} onChange={setCounter} floor={floor} ceiling={Math.max(floor, ceiling)} label="Your counter per unit" />
          <p className="offer-card__meta">Total {formatNaira(counter * offer.quantity)} · {offer.rounds_left} {offer.rounds_left === 1 ? 'counter' : 'counters'} left</p>
        </div>
      )}

      <div className="offer-card__actions">
        {offer.actions.includes('accept') && !countering && (
          <Button busy={busy === 'accept'} onClick={() => run('accept', () => api.acceptPriceOffer(offer.id, offer.unit.amount_minor))}>Accept {offer.unit.formatted}</Button>
        )}
        {offer.actions.includes('counter') && (countering ? (
          <>
            <Button busy={busy === 'counter'} onClick={() => run('counter', () => api.counterPriceOffer(offer.id, counter))}>Send {formatNaira(counter)}</Button>
            <Button variant="quiet" onClick={() => setCountering(false)}>Back</Button>
          </>
        ) : (
          <Button variant="secondary" onClick={() => setCountering(true)}>Counter</Button>
        ))}
        {offer.actions.includes('decline') && !countering && (
          <Button variant="quiet" busy={busy === 'decline'} onClick={() => run('decline', () => api.declinePriceOffer(offer.id))}>Decline</Button>
        )}
        {offer.actions.includes('withdraw') && (
          <Button variant="quiet" busy={busy === 'withdraw'} onClick={() => run('withdraw', () => api.withdrawPriceOffer(offer.id))}>Withdraw</Button>
        )}
        {offer.actions.includes('add_to_cart') && (
          <Button onClick={() => { cart.addOffer(offer); toast(`Added at ${offer.unit.formatted} per ${offer.fabric?.unit ?? 'unit'}.`, 'good'); navigate('/cart') }}>
            Buy {offer.quantity} at {offer.unit.formatted}
          </Button>
        )}
      </div>
      {error && <p className="field__error" role="alert">{error}</p>}
    </article>
  )
}

/** Name your price on a fabric. */
function NamePrice({ fabric, quantity, onSent }: { fabric: Fabric; quantity: number; onSent: (o: PriceOffer) => void }) {
  const list = fabric.price.amount_minor
  const floor = fabric.offer_floor?.amount_minor ?? Math.ceil((list * 0.7) / 5_000) * 5_000
  const suggestions = fabric.offer_suggestions ?? []
  const [price, setPrice] = useState(suggestions[1]?.amount_minor ?? suggestions[0]?.amount_minor ?? floor)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const ceiling = Math.max(floor, list - 5_000)
  const pct = Math.round(((list - price) / list) * 100)

  return (
    <div className="name-price">
      <p className="name-price__title">Name your price</p>
      <p className="name-price__hint">The vendor can accept, counter or decline. Offers from {formatNaira(floor)} per {fabric.unit}.</p>
      {suggestions.length > 0 && (
        <div className="name-price__chips" role="group" aria-label="Quick prices">
          {suggestions.map((s) => (
            <button key={s.amount_minor} type="button" className="chip" aria-pressed={price === s.amount_minor} onClick={() => setPrice(s.amount_minor)}>
              {s.formatted}
            </button>
          ))}
        </div>
      )}
      <PriceDial minor={price} onChange={setPrice} floor={floor} ceiling={ceiling} label={`Your price per ${fabric.unit}`} />
      <p className="name-price__sum">
        {quantity} × {formatNaira(price)} = <strong>{formatNaira(price * quantity)}</strong>
        {pct > 0 && <span className="name-price__off">{pct}% under list</span>}
      </p>
      <input className="input" maxLength={200} placeholder="Add a note (optional), e.g. buying for aṣọ-ẹbí" value={note} onChange={(e) => setNote(e.target.value)} aria-label="Note to the vendor" />
      <Button busy={busy} className="name-price__send" onClick={async () => {
        setBusy(true); setError(null)
        try { onSent(await api.makePriceOffer(fabric.slug, quantity, price, note.trim() || null)) }
        catch (e) { setError(e instanceof ApiError ? e.message : errorMessage(e)) }
        finally { setBusy(false) }
      }}>Send offer</Button>
      {error && <p className="field__error" role="alert">{error}</p>}
    </div>
  )
}

/** The buy box on a fabric page: quantity, add to cart, or name your price. */
export function BuyBox({ fabric }: { fabric: Fabric }) {
  const { user, meta } = useSession()
  const cart = useCart()
  const toast = useToast()
  const location = useLocation()
  const min = Math.max(1, fabric.min_order_quantity)
  const piece = fabric.unit === 'yard' && (fabric.piece_yards ?? 0) >= min && (fabric.piece_yards ?? 0) <= fabric.available_quantity ? fabric.piece_yards! : 0
  const [qty, setQty] = useState(piece || min)
  const box = useRef<HTMLElement>(null)
  const offscreen = useOffscreen(box)
  const delivery = useDeliveryInfo(cart.shipState || null)
  const express = useExpressInfo(cart.shipState || null)
  const [mode, setMode] = useState<'buy' | 'offer'>('buy')
  const [offer, setOffer] = useState<PriceOffer | null>(null)
  const soldOut = fabric.available_quantity < min
  const payments = meta?.features.payments ?? false
  const inCart = cart.lines.find((l) => l.fabric_id === fabric.id)

  // Pick up an offer already running on this fabric.
  useEffect(() => {
    if (!user) return
    let live = true
    api.myPriceOffers().then((list) => {
      const mine = list.find((o) => o.fabric?.id === fabric.id && ['awaiting_vendor', 'awaiting_buyer', 'accepted'].includes(o.status))
      if (live && mine) { setOffer(mine); setMode('offer') }
    }).catch(() => undefined)
    return () => { live = false }
  }, [user, fabric.id])

  const signIn = `/signin?next=${encodeURIComponent(location.pathname)}`
  const total = useMemo(() => fabric.price.amount_minor * qty, [fabric.price.amount_minor, qty])

  const url = typeof window !== 'undefined' ? window.location.origin + location.pathname : ''

  // Until online payment is switched on, shoppers can still order: the same choices, sent on WhatsApp.
  if (!payments) {
    const wa = whatsappHref(meta?.support?.whatsapp, `Hello OSAPS, I would like to order ${qty} ${fabric.unit}${qty === 1 ? '' : 's'} of ${fabric.name} (${fabric.price.formatted} per ${fabric.unit}, ${formatNaira(total)} for the fabric). ${url}\nMy state: `)
    return (
      <section className="buybox buybox--wa" aria-label="Order this fabric">
        {soldOut ? <p className="buybox__soldout">Sold out for now.</p> : (
          <>
            <PieceChoice fabric={fabric} qty={qty} onPick={setQty} />
            <QtyStepper value={qty} min={min} max={fabric.available_quantity} unit={fabric.unit} onChange={setQty} />
            <YardHelper fabric={fabric} onPick={setQty} />
            <p className="buybox__total">{formatNaira(total)}</p>
            {wa ? (
              <a className="btn btn--primary buybox__cta buybox__cta--wa" href={wa} target="_blank" rel="noopener noreferrer">Order on WhatsApp</a>
            ) : <p className="muted">Ordering opens soon.</p>}
            <p className="buybox__trust">Paying on the site is not switched on yet. Send your order on WhatsApp and OSAPS will confirm the delivery price for your state and how to pay. Buyer protection on this site covers orders paid through the site.</p>
          </>
        )}
      </section>
    )
  }

  const add = () => { cart.addFabric(fabric, qty); toast(`${fabric.name} added to your cart.`, 'good') }

  return (
    <>
    <section className="buybox" aria-label="Buy this fabric" ref={box}>
      <div className="buybox__tabs" role="tablist">
        <button role="tab" aria-selected={mode === 'buy'} className="buybox__tab" onClick={() => setMode('buy')}>Buy now</button>
        <button role="tab" aria-selected={mode === 'offer'} className="buybox__tab" onClick={() => setMode('offer')}>Name your price</button>
      </div>

      {soldOut ? (
        <p className="buybox__soldout">Sold out for now.</p>
      ) : (
        <>
          {!(mode === 'offer' && offer) && (
            <>
              <PieceChoice fabric={fabric} qty={qty} onPick={setQty} />
              <QtyStepper value={qty} min={min} max={fabric.available_quantity} unit={fabric.unit} onChange={setQty} />
              <YardHelper fabric={fabric} onPick={setQty} />
            </>
          )}
          {mode === 'buy' ? (
            <div className="buybox__buy">
              <p className="buybox__total">{formatNaira(total)}</p>
              <Button className="buybox__cta" onClick={add}>
                {inCart ? 'Update cart' : 'Add to cart'}
              </Button>
              {inCart && <Link to="/cart" className="text-link">Go to cart</Link>}
            </div>
          ) : !user ? (
            <p className="buybox__signin"><Link to={signIn}>Sign in</Link> to name your price. Vendors usually answer within a day.</p>
          ) : offer ? (
            <>
              <OfferCard offer={offer} onChange={setOffer} />
              {['declined', 'expired', 'withdrawn', 'used'].includes(offer.status) && (
                <Button variant="secondary" onClick={() => setOffer(null)}>Make a new offer</Button>
              )}
            </>
          ) : (
            <NamePrice fabric={fabric} quantity={qty} onSent={setOffer} />
          )}
        </>
      )}
      {delivery && <p className="buybox__delivery">{delivery.text}. <Link to="/buyer-protection#delivery" className="text-link">Delivery prices</Link></p>}
      {express && <p className="buybox__express">⚡ Need it fast? Express delivery {express.from ? 'from ' : ''}{formatNaira(express.fee)}{express.days ? `, ${express.days.toLowerCase()}` : ''}{express.from && 'label' in express ? ` (${express.label})` : ''}. Choose it in your cart.</p>}
      <p className="buybox__trust">Pay with card, transfer or USSD through Paystack. The vendor is paid only after you confirm you have your fabric. <Link to="/buyer-protection" className="text-link">How you are protected</Link></p>
      <div className="buybox__help">
        <WhatsAppLink text={`Hello OSAPS, I have a question about ${fabric.name}: ${url}`}>Ask on WhatsApp</WhatsAppLink>
        <a className="wa-link wa-link--share" href={`https://wa.me/?text=${encodeURIComponent(`${fabric.name}, ${fabric.price.formatted} per ${fabric.unit} on OSAPS: ${url}`)}`} target="_blank" rel="noopener noreferrer">
          <span>Share with family</span>
        </a>
      </div>
    </section>
    {!soldOut && mode === 'buy' && (
      <div className={`buybar ${offscreen ? 'is-shown' : ''}`} aria-hidden={!offscreen}>
        <div className="buybar__text">
          <strong>{formatNaira(total)}</strong>
          <span>{qty} {fabric.unit}{qty === 1 ? '' : 's'} · {fabric.name}</span>
        </div>
        <Button className="buybar__cta" onClick={add} tabIndex={offscreen ? 0 : -1}>{inCart ? 'Update cart' : 'Add to cart'}</Button>
      </div>
    )}
    </>
  )
}
