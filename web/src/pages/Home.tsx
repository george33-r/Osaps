import { Link } from 'react-router-dom'
import { api, type Fabric } from '../api'
import { FabricCard, TailorCard } from '../components/cards'
import { DrapedCloth } from '../components/Swatch'
import { ButtonLink, Label, Skeleton } from '../components/ui'
import { useSession } from '../lib/session'
import { useAsync } from '../lib/useAsync'

const STEPS = [
  ['Discover the fabric', 'Browse Ankara, aṣọ-òkè, lace and adire from market vendors, with real prices and stock.'],
  ['Imagine the outfit', 'Describe it, share a photo, or let the AI stylist shape a look around your occasion and budget.'],
  ['Meet your maker', 'Tailors who make that garment respond. Name your price or compare offers, and ask questions first.'],
  ['Wear your story', 'Accepting locks the price and details. Follow each stage from cutting to fitting to delivery.'],
] as const

function Hero({ drapes }: { drapes: Fabric[] }) {
  const { meta } = useSession()
  const campaign = meta?.brand ?? { campaign_image: null, campaign_caption: null }
  const [first, second] = drapes

  return (
    <section className="hero" aria-labelledby="hero-title">
      <div className="hero__text">
        <Label>An African couture house, online</Label>
        <h1 id="hero-title" className="hero__title">Your next masterpiece begins with fabric.</h1>
        <p className="hero__lede">Discover extraordinary textiles, meet exceptional makers, and create an outfit that feels entirely yours.</p>
        <div className="hero__actions">
          <ButtonLink to="/stylist">Create your look</ButtonLink>
          <ButtonLink to="/fabrics" variant="secondary">Explore fabrics</ButtonLink>
        </div>
        <p className="hero__aside">
          Already know what you want? <Link to="/requests/new">Post a tailoring request</Link> and compare offers.
        </p>
      </div>

      <figure className="hero__visual">
        {campaign.campaign_image ? (
          <>
            <img className="hero__photo" src={campaign.campaign_image} alt={campaign.campaign_caption ?? 'OSAPS campaign'} fetchPriority="high" />
            {campaign.campaign_caption && <figcaption className="hero__caption">{campaign.campaign_caption}</figcaption>}
          </>
        ) : first ? (
          <>
            <Link to={`/fabrics/${first.slug}`} className="hero__drape hero__drape--a" aria-label={first.name}>
              <DrapedCloth pattern={first.pattern} colors={first.colors} seed={4} scale={1.3} />
            </Link>
            {second && (
              <Link to={`/fabrics/${second.slug}`} className="hero__drape hero__drape--b" aria-label={second.name}>
                <DrapedCloth pattern={second.pattern} colors={second.colors} seed={11} scale={1.1} />
              </Link>
            )}
            <figcaption className="hero__caption">
              <span className="label">In the catalogue now</span>
              {first.name}
              {second && <> and {second.name}</>}, illustrated from their listings.
            </figcaption>
          </>
        ) : (
          <div className="hero__drape hero__drape--a hero__drape--empty" />
        )}
      </figure>
    </section>
  )
}

function Edit({ title, short, lede, category, layout, dark = false }: { title: string; short: string; lede: string; category: string; layout: 'feature' | 'stagger' | 'band'; dark?: boolean }) {
  const res = useAsync(() => api.fabrics({ category: [category], in_stock: true }), [category])
  const items = res.data?.data ?? []
  if (res.status !== 'loading' && items.length === 0) return null

  return (
    <section className={`edit edit--${layout} ${dark ? 'edit--dark' : ''}`} aria-labelledby={`edit-${category}`}>
      <div className="edit__inner">
        <header className="edit__head">
          <Label>The edit</Label>
          <h2 id={`edit-${category}`} className="edit__title">{title}</h2>
          <p className="edit__lede">{lede}</p>
          <Link to={`/fabrics?category=${category}`} className="text-link">See all {short}</Link>
        </header>
        <div className={`edit__grid edit__grid--n${Math.min(items.length, 3)}`}>
          {!res.data
            ? Array.from({ length: 3 }, (_, i) => <Skeleton key={i} block lines={2} />)
            : layout === 'band' && items[0]
              ? (
                <>
                  <figure className="edit__feature">
                    <Link to={`/fabrics/${items[0].slug}`} className="edit__drape" aria-label={items[0].name}>
                      <DrapedCloth pattern={items[0].pattern} colors={items[0].colors} seed={8} scale={1.2} />
                    </Link>
                    <figcaption className="edit__drape-caption">
                      <Link to={`/fabrics/${items[0].slug}`}>{items[0].name}</Link>
                      <span>{items[0].price.formatted} per {items[0].unit} · illustrated from the listing</span>
                    </figcaption>
                  </figure>
                  {items.length === 1 ? (
                    <div className="edit__note">
                      {items[0].material && <p className="label">{items[0].material}</p>}
                      {items[0].description && <p>{items[0].description}</p>}
                      {items[0].vendor && <p className="edit__note-vendor">From {items[0].vendor.business_name}, {items[0].vendor.market ?? items[0].vendor.city}</p>}
                      <Link to={`/fabrics/${items[0].slug}`} className="text-link">View this fabric</Link>
                    </div>
                  ) : (
                    items.slice(1, 3).map((f) => <FabricCard key={f.id} fabric={f} />)
                  )}
                </>
              )
              : items.slice(0, 3).map((f, i) => <FabricCard key={f.id} fabric={f} feature={layout === 'feature' && i === 0} />)}
        </div>
      </div>
    </section>
  )
}

function StylistInvite() {
  const { meta } = useSession()
  const occasions = meta?.occasions.filter((o) => o.key !== 'other').slice(0, 6) ?? []
  return (
    <section className="invite" aria-labelledby="invite-title">
      <div className="invite__inner">
        <div className="invite__text">
          <Label>Your personal stylist</Label>
          <h2 id="invite-title" className="invite__title">Have a look in mind? Let&rsquo;s bring it to life.</h2>
          <p>Tell us the occasion and what you love. Your stylist shapes a concept, then finds fabrics and makers from our catalogue that fit it and your budget.</p>
          <ButtonLink to="/stylist" className="btn--on-dark">Meet your AI stylist</ButtonLink>
        </div>
        <div className="invite__start">
          <p className="invite__question">What is the occasion?</p>
          <div className="invite__chips">
            {occasions.map((o) => (
              <Link key={o.key} to={`/stylist?occasion=${o.key}`} className="chip chip--dark">{o.label}</Link>
            ))}
          </div>
        </div>
      </div>
    </section>
  )
}

export default function Home() {
  const rack = useAsync(() => api.fabrics({ in_stock: true, category: ['aso_oke', 'adire', 'lace'] }), [])
  const drapes = (() => {
    const list = rack.data?.data ?? []
    const a = list.find((f) => f.category === 'aso_oke') ?? list[0]
    const b = list.find((f) => f.category === 'adire' && f !== a) ?? list.find((f) => f !== a)
    return [a, b].filter((f): f is Fabric => Boolean(f))
  })()
  const makers = useAsync(() => api.tailors({}), [])

  return (
    <div className="home">
      <Hero drapes={drapes} />

      <Edit
        category="ankara"
        layout="feature"
        title="The Ankara Edit"
        short="Ankara"
        lede="Bold wax prints for wedding guests, family occasions and everyday elegance — chosen for colour that holds and patterns that photograph beautifully."
      />
      <Edit
        category="aso_oke"
        layout="band"
        dark
        title="The Art of Aṣọ-òkè"
        short="aṣọ-òkè"
        lede="Hand-woven on narrow looms in strips, then joined. The cloth of ceremonies, from agbada to gele."
      />
      <Edit
        category="lace"
        layout="stagger"
        title="Lace Worth Celebrating"
        short="lace"
        lede="Guipure and beaded lace for occasionwear, reimagined by tailors who know how to line, finish and fit it."
      />

      <StylistInvite />

      <section className="process" id="process" aria-labelledby="process-title">
        <div className="process__inner">
          <header className="process__head">
            <Label>From fabric to finished outfit</Label>
            <h2 id="process-title" className="section-title">How OSAPS works</h2>
          </header>
          <ol className="process__steps">
            {STEPS.map(([title, body], i) => (
              <li key={title} className="process__step">
                <span className="process__num" aria-hidden>{String(i + 1).padStart(2, '0')}</span>
                <h3 className="process__title">{title}</h3>
                <p>{body}</p>
              </li>
            ))}
          </ol>
        </div>
      </section>

      <section className="makers" aria-labelledby="makers-title">
        <div className="makers__inner">
          <header className="makers__head">
            <div>
              <Label>The makers</Label>
              <h2 id="makers-title" className="section-title">Meet the tailors</h2>
            </div>
            <Link to="/tailors" className="text-link">All tailors</Link>
          </header>
          <div className="makers__grid">
            {!makers.data
              ? Array.from({ length: 3 }, (_, i) => <Skeleton key={i} lines={4} />)
              : makers.data.data.slice(0, 3).map((t) => <TailorCard key={t.slug} tailor={t} />)}
          </div>
          <p className="makers__join">
            Are you a tailor? <Link to="/register?role=tailor">Join OSAPS</Link> and get requests that fit your craft.
          </p>
        </div>
      </section>
    </div>
  )
}
