import { useEffect, useRef, useState, type PointerEvent } from 'react'
import { Link } from 'react-router-dom'
import { api } from '../api'
import { FabricCard, TailorCard } from '../components/cards'
import { Cowrie, GREETINGS } from '../components/Culture'
import { DrapedCloth } from '../components/Swatch'
import { ButtonLink, Skeleton } from '../components/ui'
import { HERO_VIDEO } from '../data/photos'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'
import { CultureBand, Cultures, Diaspora, Festivals } from './HomeCulture'
import { Lookbook, PhotoGallery, StyleWall, Weddings } from './HomeLookbook'

const STEPS = [
  ['Discover the fabric', 'Ankara, aṣọ-òkè, lace and adire from market vendors, with real prices and stock.'],
  ['Imagine the outfit', 'Describe it, share a photo, or let the stylist shape a look around your occasion and budget.'],
  ['Meet your maker', 'Tailors who make that garment respond. Name your price or compare offers, and ask questions first.'],
  ['Wear your story', 'Accepting locks the price and details. Follow every stage from cutting to fitting to delivery.'],
] as const

/** Motion is off for people who ask for less of it; the video then waits on its poster until they press play. */
function prefersLessMotion() {
  return typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
}

function Hero() {
  const hero = useRef<HTMLElement>(null)
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

  // Pointer position as CSS variables (-1…1); floating layers read them at different depths.
  const onMove = (e: PointerEvent<HTMLElement>) => {
    const el = hero.current
    if (!el || e.pointerType !== 'mouse') return
    const r = el.getBoundingClientRect()
    el.style.setProperty('--px', (((e.clientX - r.left) / r.width) * 2 - 1).toFixed(3))
    el.style.setProperty('--py', (((e.clientY - r.top) / r.height) * 2 - 1).toFixed(3))
  }

  return (
    <section className="hero hero--video" aria-labelledby="hero-title" ref={hero} onPointerMove={onMove}>
      <div className="hero__stage" aria-hidden>
        <img className="hero__backdrop" src={HERO_VIDEO.poster} alt="" fetchPriority="high" />
      </div>

      <figure className="hero__reel">
        <div className="hero__reel-frame">
          <video
            ref={video}
            className="hero__video"
            poster={HERO_VIDEO.poster}
            muted
            loop
            playsInline
            preload="metadata"
            aria-label={HERO_VIDEO.caption}
            onPlay={() => setPlaying(true)}
            onPause={() => setPlaying(false)}
          >
            <source src={HERO_VIDEO.src} type="video/mp4" />
            <source src={HERO_VIDEO.webm} type="video/webm" />
          </video>
          <button type="button" className="hero__playpause" onClick={toggle} aria-label={playing ? 'Pause the video' : 'Play the video'}>
            {playing ? (
              <svg viewBox="0 0 24 24" aria-hidden><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
            ) : (
              <svg viewBox="0 0 24 24" aria-hidden><path d="M8 5.5v13a1 1 0 0 0 1.5.86l10.5-6.5a1 1 0 0 0 0-1.72L9.5 4.64A1 1 0 0 0 8 5.5Z" /></svg>
            )}
          </button>
        </div>
        <figcaption className="hero__reel-caption">
          <span>{HERO_VIDEO.caption}</span>
          <small>Video: {HERO_VIDEO.credit}</small>
        </figcaption>
      </figure>

      <div className="hero__cowries" aria-hidden>
        <Cowrie className="hero__cowrie hero__cowrie--1" />
        <Cowrie className="hero__cowrie hero__cowrie--2" />
        <Cowrie className="hero__cowrie hero__cowrie--3" />
      </div>

      <div className="hero__inner">
        <p className="hero__welcome">
          {GREETINGS.map((g) => (
            <span key={g.lang} lang={g.lang === 'Yorùbá' ? 'yo' : g.lang === 'Hausa' ? 'ha' : 'ig'} title={`Welcome, in ${g.lang}`}>{g.word}</span>
          ))}
          <span className="hero__welcome-en">Welcome home.</span>
        </p>
        <h1 id="hero-title" className="hero__title">Your next masterpiece begins with fabric.</h1>
        <div className="hero__intro">
          <p className="hero__lede">Discover extraordinary textiles, meet exceptional makers, and create an outfit that feels entirely yours.</p>
          <div className="hero__actions">
            <ButtonLink to="/fabrics">Shop fabric</ButtonLink>
            <ButtonLink to="/stylist" variant="secondary">Get a look made</ButtonLink>
          </div>
        </div>
      </div>
    </section>
  )
}

/** Two ways in: just buy fabric (at the list price or your own), or have something made. */
function ShopModes() {
  const { meta } = useSession()
  const hold = meta?.shop?.offer_hold_hours ?? 48
  return (
    <section className="modes" aria-labelledby="modes-title">
      <h2 id="modes-title" className="sr-only">Shop or get it made</h2>
      <Link to="/fabrics" className="mode mode--shop" data-tilt>
        <span className="mode__kicker">Just shopping?</span>
        <span className="mode__title">Buy fabric. Or name your price.</span>
        <span className="mode__steps">
          <span><b>1</b> Pick a fabric and how many yards</span>
          <span><b>2</b> Pay the list price, or offer yours</span>
          <span><b>3</b> The vendor accepts, counters or declines</span>
        </span>
        <span className="mode__fine">An agreed price is held for you for {hold} hours. Pay by card, transfer or USSD.</span>
        <span className="mode__cta">Shop fabric →</span>
      </Link>
      <Link to="/stylist" className="mode mode--make" data-tilt>
        <span className="mode__kicker">Want it made?</span>
        <span className="mode__title">Tailors make you an offer.</span>
        <span className="mode__steps">
          <span><b>1</b> Tell the stylist the occasion</span>
          <span><b>2</b> Post your request and budget</span>
          <span><b>3</b> Compare tailors' offers and pick one</span>
        </span>
        <span className="mode__fine">Accepting locks the price. Follow every stage to delivery.</span>
        <span className="mode__cta">Start with the stylist →</span>
      </Link>
    </section>
  )
}

function Edit({ title, short, lede, category, panel = false }: { title: string; short: string; lede: string; category: string; panel?: boolean }) {
  const res = useAsync(() => api.fabrics({ category: [category], in_stock: true }), [category])
  const items = res.data?.data ?? []
  if (res.status !== 'loading' && items.length === 0) return null

  if (panel && items[0]) {
    const lead = items[0]
    return (
      <section className="edit edit--panel" aria-labelledby={`edit-${category}`}>
        <div className="edit__panel">
          <Link to={`/fabrics/${lead.slug}`} className="edit__drape" aria-label={lead.name}>
            <DrapedCloth pattern={lead.pattern} colors={lead.colors} seed={8} scale={1.2} />
          </Link>
          <div className="edit__panel-text">
            <h2 id={`edit-${category}`} className="edit__title">{title}</h2>
            <p className="edit__lede">{lede}</p>
            <div className="edit__lead">
              <Link to={`/fabrics/${lead.slug}`} className="edit__lead-name">{lead.name}</Link>
              <p>{lead.price.formatted} per {lead.unit}. {lead.material}{lead.vendor ? `, from ${lead.vendor.business_name}` : ''}.</p>
              <p className="edit__lead-note">The cloth is illustrated from the listing.</p>
            </div>
            {items.length > 1 && (
              <div className="edit__more">{items.slice(1, 3).map((f) => <FabricCard key={f.id} fabric={f} />)}</div>
            )}
            <Link to={`/fabrics?category=${category}`} className="text-link">See all {short}</Link>
          </div>
        </div>
      </section>
    )
  }

  return (
    <section className="edit" aria-labelledby={`edit-${category}`}>
      <header className="edit__head">
        <h2 id={`edit-${category}`} className="edit__title">{title}</h2>
        <div className="edit__side">
          <p className="edit__lede">{lede}</p>
          <Link to={`/fabrics?category=${category}`} className="text-link">See all {short}</Link>
        </div>
      </header>
      <div className="rail">
        {!res.data
          ? Array.from({ length: 3 }, (_, n) => <Skeleton key={n} block lines={2} />)
          : items.slice(0, 4).map((f) => <FabricCard key={f.id} fabric={f} />)}
      </div>
    </section>
  )
}

function StylistInvite() {
  const { meta } = useSession()
  const occasions = meta?.occasions.filter((o) => o.key !== 'other').slice(0, 6) ?? []
  return (
    <section className="invite" aria-labelledby="invite-title">
      <div className="invite__panel">
        <div className="invite__text">
          <h2 id="invite-title" className="invite__title">Have a look in mind? Let&rsquo;s bring it to life.</h2>
          <p>Tell the stylist the occasion and what you love. It shapes a concept, then finds fabrics and makers from the catalogue that fit it and your budget.</p>
        </div>
        <div className="invite__start">
          <p className="invite__question">What is the occasion?</p>
          <div className="invite__chips">
            {occasions.map((o) => (
              <Link key={o.key} to={`/stylist?occasion=${o.key}`} className="chip chip--on-red">{o.label}</Link>
            ))}
          </div>
          <ButtonLink to="/stylist" variant="secondary" className="btn--on-red">Start with the stylist</ButtonLink>
        </div>
      </div>
    </section>
  )
}

export default function Home() {
  const rack = useAsync(() => api.fabrics({ in_stock: true }), [])
  const makers = useAsync(() => api.tailors({}), [])

  return (
    <div className="home">
      <Hero />

      <CultureBand />

      <ShopModes />

      <Edit
        category="ankara"
        title="The Ankara Edit"
        short="Ankara"
        lede="Bold wax prints for wedding guests, family occasions and everyday elegance, chosen for colour that holds and patterns that photograph beautifully."
      />

      <Cultures />

      <Lookbook fabrics={rack.data?.data ?? []} />
      <Edit
        category="aso_oke"
        panel
        title="The Art of Aṣọ-òkè"
        short="aṣọ-òkè"
        lede="Hand-woven on narrow looms in strips, then joined. The cloth of ceremonies, from agbada to gele."
      />

      <Festivals />

      <Weddings fabrics={rack.data?.data ?? []} />
      <Edit
        category="lace"
        title="Lace Worth Celebrating"
        short="lace"
        lede="Guipure and beaded lace for occasionwear, made up by tailors who know how to line, finish and fit it."
      />

      <StyleWall />

      <StylistInvite />

      <Diaspora fabrics={rack.data?.data ?? []} />

      <section className="process" id="process" aria-labelledby="process-title">
        <h2 id="process-title" className="section-title">How OSAPS works</h2>
        <ol className="process__steps">
          {STEPS.map(([title, body], n) => (
            <li key={title} className="process__step">
              <span className="process__num" aria-hidden>{n + 1}</span>
              <h3 className="process__title">{title}</h3>
              <p>{body}</p>
            </li>
          ))}
        </ol>
      </section>

      <PhotoGallery />

      <section className="makers" aria-labelledby="makers-title">
        <header className="makers__head">
          <h2 id="makers-title" className="section-title">Meet the makers</h2>
          <Link to="/tailors" className="text-link">All tailors</Link>
        </header>
        <div className="makers__grid">
          {!makers.data
            ? Array.from({ length: 3 }, (_, n) => <Skeleton key={n} lines={4} />)
            : makers.data.data.slice(0, 3).map((t) => <TailorCard key={t.slug} tailor={t} />)}
        </div>
        <p className="makers__join">
          Are you a tailor? <Link to="/register?role=tailor">Join OSAPS</Link> and get requests that fit your craft.
        </p>
      </section>
    </div>
  )
}
