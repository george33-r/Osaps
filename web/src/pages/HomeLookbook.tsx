import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import type { Fabric, RequestDraft } from '../api'
import { LOOKBOOK, P, STYLE_WALL, type Photo } from '../data/photos'

/** Picks a real in-stock fabric for a drawn look, preferring the categories that suit it. */
function pick(fabrics: Fabric[], cats: string[], skip: Set<number>): Fabric | undefined {
  for (const c of cats) {
    const f = fabrics.find((x) => x.category === c && !skip.has(x.id))
    if (f) return f
  }
  return fabrics.find((x) => !skip.has(x.id))
}

function draft(garment: string, occasion: string, fabric?: Fabric, title?: string): { draft: Partial<RequestDraft> } {
  return {
    draft: {
      garment_category: garment,
      occasion,
      title: title ?? '',
      fabric_status: fabric ? 'buying_on_platform' : 'need_advice',
      fabric_id: fabric?.id ?? null,
    },
  }
}

/** A floating photo lookbook. Each look links to similar cloth in the catalogue and can be made to order. */
export function Lookbook({ fabrics }: { fabrics: Fabric[] }) {
  const used = new Set<number>()
  return (
    <section className="lookbook" aria-labelledby="lookbook-title">
      <header className="lookbook__head">
        <h2 id="lookbook-title" className="section-title">The OSAPS lookbook</h2>
        <p>Agbada, gèlè, coral, aṣọ-òkè and Ankara, the way Nigeria really dresses up. See a look you love? Have it made.</p>
      </header>
      <div className="lookbook__grid">
        {LOOKBOOK.map((ph, i) => {
          const f = pick(fabrics, ph.fabricCats, used)
          if (f) used.add(f.id)
          return (
            <figure key={ph.src} className={`look look--${(i % 6) + 1}`} data-tilt>
              <div className="look__photo">
                <img src={ph.src} alt={ph.alt} loading="lazy" decoding="async" />
                <span className="look__culture">{ph.culture}</span>
              </div>
              <figcaption className="look__text">
                <span className="look__name">{ph.look}</span>
                {f && (
                  <span className="look__fabric">
                    Similar cloth: <Link to={`/fabrics/${f.slug}`}>{f.name}</Link>, {f.price.formatted} per {f.unit}
                  </span>
                )}
                <Link to="/requests/new" state={draft(ph.garment, ph.occasion, f, ph.look)} className="btn btn--primary look__cta">Have this made</Link>
                <small className="look__credit">Image: {ph.credit}</small>
              </figcaption>
            </figure>
          )
        })}
      </div>
    </section>
  )
}

const WEDDINGS: { key: string; name: string; ceremony: string; body: string; main: Photo; side: Photo; bridegarment: string; groomgarment: string }[] = [
  {
    key: 'yoruba', name: 'Yorùbá wedding', ceremony: 'Engagement and white wedding',
    body: 'The bride wears ìró, bùbá and an ìpèlé sash over one shoulder, usually in hand-woven aṣọ-òkè, crowned with a gèlè. The groom matches her in agbada, bùbá and ṣòkòtò, and his friends turn out in coordinated aṣọ-ẹbí.',
    main: P.asoOkeCouple, side: P.groomsmen, bridegarment: 'bridal', groomgarment: 'agbada',
  },
  {
    key: 'igbo', name: 'Igbo wedding', ceremony: 'Igba nkwu, the wine carrying',
    body: 'The bride carries a cup of palm wine through the guests to find her groom, sips, and offers him the rest. She wears a bright blouse and double wrapper in George, akwete or lace, with a large head tie and coral beads.',
    main: P.igboCouple, side: P.igboBride, bridegarment: 'bridal', groomgarment: 'senator',
  },
  {
    key: 'hausa', name: 'Hausa wedding', ceremony: 'Sa lalle to Fatiha',
    body: 'At the sa lalle, the bride and her friends paint their hands and feet with henna. The Fatiha is held at the mosque after Friday prayer. Grooms and their friends often match in kaftans and embroidered caps; brides favour long, finely embroidered gowns with a matching veil.',
    main: P.teal, side: P.whiteRiga, bridegarment: 'bridal', groomgarment: 'kaftan',
  },
]

/** Wedding wardrobes for the three cultures, in photographs. */
export function Weddings({ fabrics }: { fabrics: Fabric[] }) {
  return (
    <section className="weddings" aria-labelledby="weddings-title">
      <header className="weddings__head">
        <h2 id="weddings-title" className="section-title">Wedding wardrobes</h2>
        <p>The biggest day deserves the right cloth. Brides, grooms and the whole aṣọ-ẹbí family, in the tradition you are marrying into.</p>
      </header>
      <div className="weddings__list">
        {WEDDINGS.map((w) => {
          const used = new Set<number>()
          const fb = pick(fabrics, w.main.fabricCats, used)
          if (fb) used.add(fb.id)
          const fg = pick(fabrics, w.side.fabricCats, used) ?? fb
          return (
            <article key={w.key} className={`wedding wedding--${w.key}`} aria-labelledby={`wed-${w.key}`}>
              <div className="wedding__photos">
                <figure className="wedding__photo wedding__photo--main" data-tilt>
                  <img src={w.main.src} alt={w.main.alt} loading="lazy" decoding="async" />
                  <figcaption>Image: {w.main.credit}</figcaption>
                </figure>
                <figure className="wedding__photo wedding__photo--side" data-tilt>
                  <img src={w.side.src} alt={w.side.alt} loading="lazy" decoding="async" />
                  <figcaption>Image: {w.side.credit}</figcaption>
                </figure>
              </div>
              <div className="wedding__text">
                <p className="wedding__ceremony">{w.ceremony}</p>
                <h3 id={`wed-${w.key}`} className="wedding__name">{w.name}</h3>
                <p>{w.body}</p>
                <div className="wedding__actions">
                  <Link to="/requests/new" state={draft(w.bridegarment, 'own_wedding', fb, `${w.name}: bride`)} className="btn btn--primary">The bride&rsquo;s look</Link>
                  <Link to="/requests/new" state={draft(w.groomgarment, 'own_wedding', fg, `${w.name}: groom`)} className="btn btn--secondary">The groom&rsquo;s look</Link>
                  <Link to="/stylist?occasion=family_event" className="text-link">Aṣọ-ẹbí for guests</Link>
                </div>
              </div>
            </article>
          )
        })}
      </div>
      <figure className="wedding-extra" data-tilt>
        <img src={P.whiteCoral.src} alt={P.whiteCoral.alt} loading="lazy" decoding="async" />
        <figcaption>
          <strong>And every tradition in between.</strong> Nigeria has hundreds of wedding styles, like this one in white and coral. Tell your tailor yours.
          <small>Image: {P.whiteCoral.credit}</small>
        </figcaption>
      </figure>
    </section>
  )
}

/** More looks as a floating photo wall; each tile can be made to order. */
export function StyleWall() {
  return (
    <section className="stylewall" aria-labelledby="stylewall-title">
      <header className="lookbook__head">
        <h2 id="stylewall-title" className="section-title">From the street to the palace</h2>
        <p>Senator on a Friday, aṣọ-òkè at the owambe, red regalia for the chieftaincy. Tap any look to have one made.</p>
      </header>
      <div className="stylewall__grid">
        {STYLE_WALL.map((ph, i) => (
          <Link
            key={ph.src}
            to="/requests/new"
            state={draft(ph.garment, ph.occasion, undefined, ph.look)}
            className={`stylewall__tile stylewall__tile--${(i % 4) + 1}`}
            data-tilt
          >
            <img src={ph.src} alt={ph.alt} loading="lazy" decoding="async" />
            <span className="stylewall__info">
              <span className="stylewall__culture">{ph.culture}</span>
              <span className="stylewall__look">{ph.look}</span>
              <span className="stylewall__cta">Have this made</span>
              <small>Image: {ph.credit}</small>
            </span>
          </Link>
        ))}
      </div>
    </section>
  )
}

interface GalleryItem {
  src: string
  alt: string
  name?: string
  credit: string
  consent: boolean
  look?: string
}

/**
 * Real photographs, added by the owner to public/gallery/gallery.json. An entry is shown only when
 * it is marked as consented and carries a credit, so no one appears on OSAPS without permission.
 */
export function PhotoGallery() {
  const [items, setItems] = useState<GalleryItem[] | null>(null)
  useEffect(() => {
    let live = true
    fetch(`${import.meta.env.BASE_URL}gallery/gallery.json`, { cache: 'no-cache' })
      .then((r) => (r.ok ? r.json() : { items: [] }))
      .then((d: { items?: GalleryItem[] }) => {
        if (live) setItems((d.items ?? []).filter((i) => i.consent === true && i.credit && i.src && i.alt))
      })
      .catch(() => live && setItems([]))
    return () => { live = false }
  }, [])
  if (items === null || items.length === 0) return null

  return (
    <section className="gallery" aria-labelledby="gallery-title">
      <header className="lookbook__head">
        <h2 id="gallery-title" className="section-title">Worn by real people</h2>
        <p>Customers, models and creators wearing OSAPS, shown only with their written permission.</p>
      </header>
      {items.length > 0 ? (
        <div className="gallery__grid">
          {items.map((i) => (
            <figure key={i.src} className="gallery__item" data-tilt>
              <img src={i.src.startsWith('http') ? i.src : `${import.meta.env.BASE_URL}gallery/${i.src}`} alt={i.alt} loading="lazy" decoding="async" />
              <figcaption>
                {i.name && <strong>{i.name}</strong>}
                {i.look && <span>{i.look}</span>}
                <small>Photo: {i.credit}</small>
              </figcaption>
            </figure>
          ))}
        </div>
      ) : (
        <div className="gallery__empty">
          <p className="gallery__empty-title">The first photos are on their way.</p>
          <p>Had an outfit made through OSAPS? Send us a photo with your permission to share it, and you could be the first face in this gallery.</p>
        </div>
      )}
    </section>
  )
}
