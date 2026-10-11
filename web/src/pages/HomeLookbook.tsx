import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import type { Fabric, RequestDraft } from '../api'
import { Motif, type MotifKind } from '../components/Culture'
import { FashionFigure, type Look } from '../components/FashionFigure'

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

const LOOKS: { look: Look; name: string; culture: string; motif: MotifKind; cats: string[]; garment: string; occasion: string }[] = [
  { look: 'agbada', name: 'Agbada with fila', culture: 'Yorùbá', motif: 'adire', cats: ['aso_oke', 'damask'], garment: 'agbada', occasion: 'traditional_wedding' },
  { look: 'iro_gele', name: 'Ìró, bùbá and gèlè', culture: 'Yorùbá', motif: 'adire', cats: ['aso_oke', 'adire'], garment: 'iro_buba', occasion: 'family_event' },
  { look: 'babban_riga', name: 'Babban riga and hula', culture: 'Hausa', motif: 'arewa', cats: ['damask', 'senator'], garment: 'kaftan', occasion: 'festival' },
  { look: 'igbo_woman', name: 'Blouse, double wrapper and coral', culture: 'Igbo', motif: 'uli', cats: ['george', 'lace'], garment: 'skirt_blouse', occasion: 'traditional_wedding' },
  { look: 'isiagu', name: 'Isiagu with red cap', culture: 'Igbo', motif: 'uli', cats: ['ankara', 'damask'], garment: 'senator', occasion: 'traditional_wedding' },
  { look: 'hausa_woman', name: 'Embroidered gown with mayafi', culture: 'Hausa', motif: 'arewa', cats: ['lace', 'ankara'], garment: 'gown', occasion: 'festival' },
]

/** A floating gallery of drawn looks, each "worn" in a real catalogue fabric. */
export function Lookbook({ fabrics }: { fabrics: Fabric[] }) {
  if (fabrics.length === 0) return null
  const used = new Set<number>()
  const looks = LOOKS.map((l) => {
    const f = pick(fabrics, l.cats, used)
    if (f) used.add(f.id)
    return { ...l, fabric: f }
  })
  return (
    <section className="lookbook" aria-labelledby="lookbook-title">
      <header className="lookbook__head">
        <h2 id="lookbook-title" className="section-title">The OSAPS lookbook</h2>
        <p>Six ways Nigeria dresses up, each drawn in a fabric you can buy today. Tap a look to have it made.</p>
      </header>
      <div className="lookbook__grid">
        {looks.map((l, i) => (
          <article key={l.look} className={`look look--${i + 1}`} data-tilt>
            <div className="look__stage">
              <Motif kind={l.motif} scale={0.8} className="look__motif" />
              {l.fabric && (
                <FashionFigure look={l.look} fabric={l.fabric} className="look__figure" title={`Illustration: ${l.name}, drawn in ${l.fabric.name}`} />
              )}
            </div>
            <div className="look__text">
              <p className="look__culture">{l.culture}</p>
              <h3 className="look__name">{l.name}</h3>
              {l.fabric && (
                <p className="look__fabric">
                  Drawn in <Link to={`/fabrics/${l.fabric.slug}`}>{l.fabric.name}</Link>, {l.fabric.price.formatted} per {l.fabric.unit}
                </p>
              )}
              <Link to="/requests/new" state={draft(l.garment, l.occasion, l.fabric, l.name)} className="btn btn--primary look__cta">Have this made</Link>
            </div>
          </article>
        ))}
      </div>
      <p className="lookbook__note">Fashion illustrations, not photographs. Each figure is drawn in the pattern and colours of the linked listing.</p>
    </section>
  )
}

const WEDDINGS: {
  key: string; name: string; ceremony: string; body: string
  bride: Look; groom: Look; cats: string[]; bridegarment: string; groomgarment: string
}[] = [
  {
    key: 'yoruba', name: 'Yorùbá wedding', ceremony: 'Engagement and white wedding',
    body: 'The bride wears ìró, bùbá and an ìpèlé sash over one shoulder, usually in hand-woven aṣọ-òkè, crowned with a gèlè. The groom matches her in agbada, bùbá and ṣòkòtò, and guests come in coordinated aṣọ-ẹbí.',
    bride: 'iro_gele', groom: 'agbada', cats: ['aso_oke', 'lace'], bridegarment: 'bridal', groomgarment: 'agbada',
  },
  {
    key: 'igbo', name: 'Igbo wedding', ceremony: 'Igba nkwu, the wine carrying',
    body: 'The bride carries a cup of palm wine through the guests to find her groom, sips, and offers him the rest. She wears a bright blouse and double wrapper in George, akwete or lace, with a large head tie and coral beads.',
    bride: 'igbo_woman', groom: 'isiagu', cats: ['george', 'lace'], bridegarment: 'bridal', groomgarment: 'senator',
  },
  {
    key: 'hausa', name: 'Hausa wedding', ceremony: 'Sa lalle to Fatiha',
    body: 'At the sa lalle, the bride and her friends paint their hands and feet with henna. The Fatiha is held at the mosque after Friday prayer. Brides favour long, finely embroidered gowns with a matching veil.',
    bride: 'hausa_woman', groom: 'babban_riga', cats: ['lace', 'damask'], bridegarment: 'bridal', groomgarment: 'kaftan',
  },
]

/** Wedding wardrobes for the three cultures, bride and groom side by side. */
export function Weddings({ fabrics }: { fabrics: Fabric[] }) {
  if (fabrics.length === 0) return null
  return (
    <section className="weddings" aria-labelledby="weddings-title">
      <header className="weddings__head">
        <h2 id="weddings-title" className="section-title">Wedding wardrobes</h2>
        <p>The biggest day deserves the right cloth. Brides, grooms and the whole aṣọ-ẹbí family, in the tradition you are marrying into.</p>
      </header>
      <div className="weddings__list">
        {WEDDINGS.map((w) => {
          const used = new Set<number>()
          const fb = pick(fabrics, w.cats, used)
          if (fb) used.add(fb.id)
          const fg = pick(fabrics, w.cats.slice().reverse(), used) ?? fb
          return (
            <article key={w.key} className={`wedding wedding--${w.key}`} aria-labelledby={`wed-${w.key}`}>
              <div className="wedding__stage">
                {fb && <FashionFigure look={w.bride} bridal fabric={fb} className="wedding__figure wedding__figure--bride" title={`Illustration: ${w.name} bride, drawn in ${fb.name}`} />}
                {fg && <FashionFigure look={w.groom} bridal fabric={fg} className="wedding__figure wedding__figure--groom" title={`Illustration: ${w.name} groom, drawn in ${fg.name}`} />}
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
      <p className="lookbook__note">Illustrations drawn in catalogue fabrics. Customs differ between families and towns; ask your tailor about yours.</p>
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
  if (items === null) return null

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
