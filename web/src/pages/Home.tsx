import { Link } from 'react-router-dom'
import { api } from '../api'
import { FabricCard } from '../components/cards'
import { Swatch } from '../components/Swatch'
import { ButtonLink, Skeleton } from '../components/ui'
import { useAsync } from '../lib/useAsync'

const STEPS = [
  ['Describe the outfit', 'Say what you want made, when you need it and whether you have fabric. Add your budget, or leave it open.'],
  ['Tailors respond', 'Tailors who make that kind of garment accept your budget, explain a different price, or quote from scratch.'],
  ['Compare and agree', 'See every offer side by side with the full cost, what is included and what is missing. Counter if you want.'],
  ['Track it to your door', 'Accepting locks the price and scope. Follow each stage from cutting to fitting to delivery.'],
] as const

export default function Home() {
  const shelf = useAsync(() => api.fabrics({ in_stock: true }), [])
  const rack = shelf.data?.data.slice(0, 4) ?? []

  return (
    <div className="home">
      <section className="hero">
        <div className="hero__text">
          <h1 className="hero__title">From fabric to finished outfit.</h1>
          <p className="hero__lede">
            Choose your cloth, describe the look and name your price, or let tailors compete for the job. You decide who makes it, and
            you see every naira before you agree.
          </p>
          <div className="hero__actions">
            <ButtonLink to="/requests/new">Post a tailoring request</ButtonLink>
            <ButtonLink to="/fabrics" variant="secondary">
              Browse fabric
            </ButtonLink>
          </div>
        </div>

        <div className="rack" aria-label="Fabric in stock now">
          {shelf.status === 'loading' && !rack.length
            ? Array.from({ length: 4 }, (_, i) => <div key={i} className="rack__bolt rack__bolt--empty" />)
            : rack.map((f, i) => (
                <Link key={f.id} to={`/fabrics/${f.slug}`} className="rack__bolt" style={{ ['--i' as string]: i }}>
                  <Swatch pattern={f.pattern} colors={f.colors} scale={1.2} />
                  <span className="rack__label">
                    <span className="rack__name">{f.name}</span>
                    <span className="rack__price">
                      {f.price.formatted} / {f.unit}
                    </span>
                  </span>
                </Link>
              ))}
        </div>
      </section>

      <section className="modes" aria-labelledby="modes-title">
        <h2 id="modes-title" className="section-title">
          Two ways to price a job
        </h2>
        <div className="modes__grid">
          <article className="mode">
            <div className="mode__swatch" aria-hidden>
              <Swatch pattern="stripes" colors={['indigo', 'gold', 'cream']} />
            </div>
            <h3 className="mode__title">Name your price</h3>
            <p>
              Set the amount you want to pay for tailoring. Tailors accept it as it is, or counter with a written reason. You decide whether
              a higher price is worth it.
            </p>
          </article>
          <article className="mode">
            <div className="mode__swatch" aria-hidden>
              <Swatch pattern="lattice" colors={['terracotta', 'cream', 'indigo']} />
            </div>
            <h3 className="mode__title">Get open offers</h3>
            <p>
              Describe the outfit and let qualified tailors quote. Offers are never ranked by price alone, and the cheapest is never chosen
              for you.
            </p>
          </article>
        </div>
      </section>

      <section className="how" id="how" aria-labelledby="how-title">
        <h2 id="how-title" className="section-title">
          How it works
        </h2>
        <ol className="how__steps">
          {STEPS.map(([title, body], i) => (
            <li key={title} className="how__step">
              <span className="how__num" aria-hidden>
                {i + 1}
              </span>
              <h3 className="how__title">{title}</h3>
              <p>{body}</p>
            </li>
          ))}
        </ol>
      </section>

      <section className="shelf" aria-labelledby="shelf-title">
        <div className="shelf__head">
          <h2 id="shelf-title" className="section-title">
            On the shelf
          </h2>
          <Link to="/fabrics" className="text-link">
            See all fabric
          </Link>
        </div>
        {shelf.status === 'error' ? (
          <p className="muted">Fabric could not be loaded right now.</p>
        ) : !shelf.data ? (
          <div className="grid grid--bolts">
            {Array.from({ length: 4 }, (_, i) => (
              <Skeleton key={i} block lines={2} />
            ))}
          </div>
        ) : (
          <div className="grid grid--bolts">
            {shelf.data.data.slice(4, 14).map((f) => (
              <FabricCard key={f.id} fabric={f} />
            ))}
          </div>
        )}
      </section>

      <section className="for-tailors" aria-labelledby="tailors-title">
        <div className="for-tailors__pattern" aria-hidden>
          <Swatch pattern="sunburst" colors={['indigo', 'gold', 'terracotta']} scale={1.4} />
        </div>
        <div className="for-tailors__text">
          <h2 id="tailors-title" className="for-tailors__title">
            Tailors: get matched to work you are good at
          </h2>
          <p>
            Requests reach you based on what you make and where you work, not on who pays for placement. New tailors are shown honestly as
            new, with a fair chance to be seen.
          </p>
          <ButtonLink to="/register?role=tailor" variant="secondary" className="btn--on-dark">
            Join as a tailor
          </ButtonLink>
        </div>
      </section>
    </div>
  )
}
