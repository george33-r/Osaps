import { useEffect, useRef, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Fabric } from '../api'
import { FabricCard } from '../components/cards'
import { RecentlyViewed } from '../components/ProductKit'
import { ButtonLink, Skeleton } from '../components/ui'
import { HERO_VIDEO, LOOKBOOK } from '../data/photos'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

/**
 * Shop-first home: what we sell, how buying works, two ways to go further, and a door to the
 * style and culture pages. Everything else lives one tap away instead of on one long page.
 */

function prefersLessMotion() {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
}

function Hero() {
  const video = useRef<HTMLVideoElement>(null)
  const [playing, setPlaying] = useState(false)
  useEffect(() => {
    const v = video.current
    if (!v || prefersLessMotion()) return
    v.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
  }, [])
  const toggle = () => {
    const v = video.current
    if (!v) return
    if (v.paused) v.play().then(() => setPlaying(true)).catch(() => setPlaying(false))
    else { v.pause(); setPlaying(false) }
  }
  return (
    <section className="sh-hero" aria-labelledby="hero-title">
      <div className="sh-hero__text">
        <p className="sh-hero__kicker">Ẹ káàbọ̀ · Barka da zuwa · Nnọọ</p>
        <h1 id="hero-title" className="sh-hero__title">Fabric you'll love. At a price you can name.</h1>
        <p className="sh-hero__lede">Ankara, lace, plain and printed fabrics by the yard. Buy at the listed price or make an offer, then have it made by a tailor if you like.</p>
        <div className="sh-hero__actions">
          <ButtonLink to="/fabrics">Shop fabric</ButtonLink>
          <ButtonLink to="/requests/new" variant="secondary">Get it made</ButtonLink>
        </div>
      </div>
      <figure className="sh-hero__media">
        <video ref={video} className="sh-hero__video" poster={HERO_VIDEO.poster} muted loop playsInline preload="metadata" aria-label={HERO_VIDEO.caption}
          onPlay={() => setPlaying(true)} onPause={() => setPlaying(false)}>
          <source src={HERO_VIDEO.src} type="video/mp4" />
          <source src={HERO_VIDEO.webm} type="video/webm" />
        </video>
        <button type="button" className="sh-hero__play" onClick={toggle} aria-label={playing ? 'Pause the video' : 'Play the video'}>
          {playing
            ? <svg viewBox="0 0 24 24" aria-hidden><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
            : <svg viewBox="0 0 24 24" aria-hidden><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z" /></svg>}
        </button>
        <figcaption className="sh-hero__credit">Video: {HERO_VIDEO.credit}</figcaption>
      </figure>
    </section>
  )
}

function Trust() {
  const { meta } = useSession()
  const days = 7
  const hold = meta?.shop?.offer_hold_hours ?? 48
  const items = [
    ['Pay safely', 'Card, transfer or USSD through Paystack.'],
    ['Protected until it arrives', `The vendor is paid only after you have your fabric, and you get ${days} days to report a problem.`],
    ['Name your price', `Make an offer below the listed price. An agreed price is held for you for ${hold} hours.`],
  ] as const
  return (
    <ul className="sh-trust" aria-label="Why buy on OSAPS">
      {items.map(([t, b], i) => (
        <li key={t}>
          <span className="sh-trust__icon" aria-hidden>{['₦', '✓', '⇄'][i]}</span>
          <span><strong>{t}</strong><span>{b}</span></span>
        </li>
      ))}
    </ul>
  )
}

/** One tile per category that is actually in stock, with a real photo from that category. */
function Categories({ fabrics }: { fabrics: Fabric[] }) {
  const byCat = new Map<string, Fabric>()
  for (const f of fabrics) if (!byCat.has(f.category) && f.image_url) byCat.set(f.category, f)
  const cats = [...byCat.values()]
  if (cats.length < 2) return null
  return (
    <section className="sh-section" aria-labelledby="cats-title">
      <header className="sh-section__head">
        <h2 id="cats-title" className="sh-section__title">Shop by fabric</h2>
      </header>
      <ul className="sh-cats">
        {cats.map((f) => (
          <li key={f.category}>
            <Link to={`/fabrics?category=${f.category}`} className="sh-cat">
              <img src={f.image_url!} alt="" loading="lazy" decoding="async" />
              <span>{f.category_label}</span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

function Promos() {
  return (
    <section className="sh-promos" aria-label="More ways to shop">
      <Link to="/aso-ebi" className="sh-promo sh-promo--red">
        <span className="sh-promo__kicker">Aṣọ-ẹbí</span>
        <span className="sh-promo__title">One link for the whole family</span>
        <span className="sh-promo__body">Pick a fabric, share the link on WhatsApp, and every guest pays for their own yards.</span>
        <span className="sh-promo__cta">Start a group order →</span>
      </Link>
      <Link to="/requests/new" className="sh-promo">
        <span className="sh-promo__kicker">Get it made</span>
        <span className="sh-promo__title">Tailors make you an offer</span>
        <span className="sh-promo__body">Describe the outfit and your budget. Compare tailors' offers and pick the one you like.</span>
        <span className="sh-promo__cta">Post a request →</span>
      </Link>
    </section>
  )
}

function StyleTeaser() {
  const photos = LOOKBOOK.slice(0, 4)
  return (
    <section className="sh-section" aria-labelledby="style-title">
      <header className="sh-section__head">
        <h2 id="style-title" className="sh-section__title">Style ideas</h2>
        <Link to="/inspiration" className="text-link">See them all</Link>
      </header>
      <ul className="sh-style">
        {photos.map((p) => (
          <li key={p.src}>
            <Link to="/inspiration" className="sh-style__item">
              <img src={p.src} alt={p.alt} loading="lazy" decoding="async" />
              <span>{p.look}</span>
            </Link>
          </li>
        ))}
      </ul>
    </section>
  )
}

export default function Home() {
  const rack = useAsync(() => api.fabrics({ in_stock: true }), [])
  const fabrics = rack.data?.data ?? []
  const total = rack.data?.meta?.total ?? fabrics.length
  return (
    <div className="home home--shop">
      <Hero />
      <Trust />
      <Categories fabrics={fabrics} />
      <section className="sh-section" aria-labelledby="new-title">
        <header className="sh-section__head">
          <h2 id="new-title" className="sh-section__title">New in the store</h2>
          <Link to="/fabrics" className="text-link">Shop all{total ? ` ${total}` : ''} fabrics</Link>
        </header>
        <div className="sh-grid">
          {!rack.data
            ? Array.from({ length: 4 }, (_, n) => <Skeleton key={n} block lines={2} />)
            : fabrics.slice(0, 8).map((f) => <FabricCard key={f.id} fabric={f} />)}
        </div>
        <p className="sh-more"><ButtonLink to="/fabrics" variant="secondary">See all fabrics</ButtonLink></p>
      </section>
      <div className="sh-section"><RecentlyViewed fabrics={fabrics} title="Continue shopping" /></div>
      <Promos />
      <StyleTeaser />
    </div>
  )
}
