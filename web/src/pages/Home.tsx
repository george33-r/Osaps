import { useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, type Fabric } from '../api'
import { FabricCard, TailorCard } from '../components/cards'
import { ClothStage } from '../components/ClothStage'
import { DrapedCloth, Swatch } from '../components/Swatch'
import { ButtonLink, Skeleton } from '../components/ui'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

const STEPS = [
  ['Discover the fabric', 'Ankara, aṣọ-òkè, lace and adire from market vendors, with real prices and stock.'],
  ['Imagine the outfit', 'Describe it, share a photo, or let the stylist shape a look around your occasion and budget.'],
  ['Meet your maker', 'Tailors who make that garment respond. Name your price or compare offers, and ask questions first.'],
  ['Wear your story', 'Accepting locks the price and details. Follow every stage from cutting to fitting to delivery.'],
] as const

const HERO_ORDER = ['aso_oke', 'ankara', 'adire', 'lace', 'george', 'damask']

function Hero({ fabrics }: { fabrics: Fabric[] }) {
  const { meta } = useSession()
  const campaign = meta?.brand ?? { campaign_image: null, campaign_caption: null }
  const picks = useMemo(() => {
    const out: Fabric[] = []
    for (const cat of HERO_ORDER) {
      const f = fabrics.find((x) => x.category === cat)
      if (f) out.push(f)
    }
    return out.slice(0, 5)
  }, [fabrics])
  const [i, setI] = useState(0)
  const current = picks[i] ?? picks[0]

  return (
    <section className="hero" aria-labelledby="hero-title">
      <div className="hero__stage">
        {campaign.campaign_image ? (
          <img className="hero__photo" src={campaign.campaign_image} alt={campaign.campaign_caption ?? 'OSAPS campaign'} fetchPriority="high" />
        ) : current ? (
          <ClothStage
            className="hero__cloth"
            fabric={current}
            label={`Moving illustration of ${current.name}, drawn from its listing's pattern and colours`}
          />
        ) : null}
      </div>

      <div className="hero__inner">
        <h1 id="hero-title" className="hero__title">Your next masterpiece begins with fabric.</h1>
        <div className="hero__foot">
          <div className="hero__intro">
            <p className="hero__lede">Discover extraordinary textiles, meet exceptional makers, and create an outfit that feels entirely yours.</p>
            <div className="hero__actions">
              <ButtonLink to="/stylist">Create your look</ButtonLink>
              <ButtonLink to="/fabrics" variant="secondary">Explore fabrics</ButtonLink>
            </div>
          </div>

          {!campaign.campaign_image && current && (
            <div className="hero__now">
              <p className="hero__now-text">
                <Link to={`/fabrics/${current.slug}`} className="hero__now-name">{current.name}</Link>
                <span>{current.price.formatted} per {current.unit}, from {current.vendor?.market ?? current.vendor?.city ?? 'a market vendor'}</span>
                <span className="hero__now-note">Illustrated from the listing. Touch the cloth to move it.</span>
              </p>
              {picks.length > 1 && (
                <div className="hero__swatches" role="radiogroup" aria-label="Fabric shown on the cloth">
                  {picks.map((f, n) => (
                    <button key={f.id} type="button" role="radio" aria-checked={n === i} aria-label={f.name} className="hero__swatch" onClick={() => setI(n)}>
                      <Swatch pattern={f.pattern} colors={f.colors} scale={0.6} />
                    </button>
                  ))}
                </div>
              )}
            </div>
          )}
          {campaign.campaign_image && campaign.campaign_caption && <p className="hero__now-note">{campaign.campaign_caption}</p>}
        </div>
      </div>
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
      <Hero fabrics={rack.data?.data ?? []} />

      <Edit
        category="ankara"
        title="The Ankara Edit"
        short="Ankara"
        lede="Bold wax prints for wedding guests, family occasions and everyday elegance, chosen for colour that holds and patterns that photograph beautifully."
      />
      <Edit
        category="aso_oke"
        panel
        title="The Art of Aṣọ-òkè"
        short="aṣọ-òkè"
        lede="Hand-woven on narrow looms in strips, then joined. The cloth of ceremonies, from agbada to gele."
      />
      <Edit
        category="lace"
        title="Lace Worth Celebrating"
        short="lace"
        lede="Guipure and beaded lace for occasionwear, made up by tailors who know how to line, finish and fit it."
      />

      <StylistInvite />

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
