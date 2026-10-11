import { Link } from 'react-router-dom'
import type { Fabric } from '../api'
import { P, type Photo } from '../data/photos'
import { Cowrie, GREETINGS, LagosClock, Motif, useDettyCountdown, type MotifKind } from '../components/Culture'

const WORDS = ['Owambe', 'Aṣọ-ẹbí', 'Gèlè', 'Babban riga', 'Isiagu', 'Adire', 'Aṣọ-òkè', 'Akwete', 'Durbar', 'Ojude Oba', 'Iri Ji', 'Detty December', 'Fila', 'Hula', 'Shadda', 'George wrapper']

/** A woven strip of the words people dress for, moving slowly in two directions. */
export function CultureBand() {
  const row = (words: string[], key: string) => (
    <div className="culture-band__track" aria-hidden key={key}>
      {[...words, ...words].map((w, i) => (
        <span key={`${key}${i}`} className="culture-band__word">{w}<Cowrie className="culture-band__cowrie" /></span>
      ))}
    </div>
  )
  return (
    <section className="culture-band" aria-label="Words we dress for">
      <p className="sr-only">{WORDS.join(', ')}</p>
      {row(WORDS, 'a')}
      {row([...WORDS].reverse(), 'b')}
    </section>
  )
}

const CULTURES: {
  key: string; name: string; people: string; greeting: string; motif: MotifKind; motifNote: string
  body: string; wear: string[]; fabrics: { label: string; to: string }; plan: { label: string; to: string }
}[] = [
  {
    key: 'arewa', name: 'Arewa', people: 'Hausa and the North', greeting: 'Barka da zuwa', motif: 'arewa',
    motifNote: 'Pattern inspired by the Dagin Arewa, the Northern knot used in building, design and hand embroidery.',
    body: 'Flowing babban riga and kaftan, often richly embroidered at the neck and chest, worn with a hula cap. In Kano, the Kofar Mata dye pits have coloured cloth with indigo for centuries.',
    wear: ['Babban riga', 'Kaftan', 'Hula cap', 'Shadda'],
    fabrics: { label: 'Shadda and damask', to: '/fabrics?category=damask' },
    plan: { label: 'Plan a Sallah look', to: '/stylist?occasion=festival' },
  },
  {
    key: 'yoruba', name: 'Yorùbá', people: 'The South West', greeting: 'Ẹ káàbọ̀', motif: 'adire',
    motifNote: 'Pattern inspired by adire, the indigo resist-dyed cloth of the Yorùbá.',
    body: 'Agbada, bùbá and ṣòkòtò, ìró and bùbá, finished with a gèlè or fila. Aṣọ-òkè is hand-woven in narrow strips; adire is indigo resist-dyed, a craft long associated with Abẹ́òkúta.',
    wear: ['Agbada', 'Ìró and bùbá', 'Gèlè', 'Aṣọ-ẹbí'],
    fabrics: { label: 'Aṣọ-òkè and adire', to: '/fabrics?category=aso_oke' },
    plan: { label: 'Plan an owambe look', to: '/stylist?occasion=family_event' },
  },
  {
    key: 'igbo', name: 'Igbo', people: 'The South East', greeting: 'Nnọọ', motif: 'uli',
    motifNote: 'Pattern inspired by uli, the line art Igbo women painted on skin and walls.',
    body: 'Isiagu tops with the lion-head print, George wrappers and coral beads for celebrations, and akwete cloth hand-woven by the women of Akwete.',
    wear: ['Isiagu', 'George wrapper', 'Coral beads', 'Akwete'],
    fabrics: { label: 'George and lace', to: '/fabrics?category=george' },
    plan: { label: 'Plan a traditional wedding look', to: '/stylist?occasion=traditional_wedding' },
  },
]

/** Three cultures, three wardrobes, each drawn in its own pattern language. */
export function Cultures() {
  return (
    <section className="cultures" aria-labelledby="cultures-title">
      <header className="cultures__head">
        <h2 id="cultures-title" className="section-title">Dress like you never left home</h2>
        <p className="cultures__lede">Nigeria dresses in many languages. Start from the one you grew up with, or the one you are marrying into.</p>
      </header>
      <div className="cultures__grid">
        {CULTURES.map((c) => (
          <article key={c.key} className={`culture culture--${c.key}`} data-tilt aria-labelledby={`culture-${c.key}`}>
            <div className="culture__art">
              <Motif kind={c.motif} scale={c.motif === 'uli' ? 0.9 : 1} />
              <p className="culture__greeting" lang={c.key === 'arewa' ? 'ha' : c.key === 'yoruba' ? 'yo' : 'ig'}>{c.greeting}</p>
            </div>
            <div className="culture__text">
              <p className="culture__people">{c.people}</p>
              <h3 id={`culture-${c.key}`} className="culture__name">{c.name}</h3>
              <p>{c.body}</p>
              <ul className="culture__wear">{c.wear.map((w) => <li key={w}>{w}</li>)}</ul>
              <div className="culture__links">
                <Link to={c.plan.to} className="btn btn--primary">{c.plan.label}</Link>
                <Link to={c.fabrics.to} className="text-link">{c.fabrics.label}</Link>
              </div>
              <p className="culture__note">{c.motifNote}</p>
            </div>
          </article>
        ))}
      </div>
    </section>
  )
}

const FESTIVAL_PHOTOS: Record<string, Photo> = { durbar: P.durbar }

const FESTIVALS: { key: string; name: string; when: string; where: string; body: string; occasion: string; motif: MotifKind }[] = [
  {
    key: 'detty', name: 'Detty December', when: 'December', where: 'Lagos',
    body: 'About six weeks of concerts, weddings and parties, when the diaspora flies home and every night needs an outfit.',
    occasion: 'december', motif: 'adire',
  },
  {
    key: 'ojude', name: 'Ojude Oba', when: 'Third day after Eid al-Adha', where: 'Ijẹ̀bú-Òde',
    body: 'Age-grade groups (regberegbe) parade before the Awujale in outfits chosen together each year, and the Balogun families ride in on horseback.',
    occasion: 'festival', motif: 'adire',
  },
  {
    key: 'durbar', name: 'Durbar', when: 'Eid al-Fitr and Eid al-Adha', where: 'Kano, Katsina, Zaria and more',
    body: 'After Sallah prayers, horsemen in full regalia ride out with musicians to pay homage to the Emir.',
    occasion: 'festival', motif: 'arewa',
  },
  {
    key: 'iriji', name: 'Iri Ji, the New Yam festival', when: 'Harvest season, dates vary by community', where: 'Across Igboland',
    body: 'Communities give thanks for the new yam with dances, masquerades and families coming home.',
    occasion: 'festival', motif: 'uli',
  },
  {
    key: 'osun', name: 'Ọ̀ṣun-Òṣogbo', when: 'August', where: 'Òṣogbo',
    body: 'Worshippers and visitors gather at the sacred grove on the Ọ̀ṣun river, a UNESCO World Heritage site.',
    occasion: 'festival', motif: 'adire',
  },
]

/** The calendar people dress for, with a live countdown to Detty December. */
export function Festivals() {
  const { days, inDecember } = useDettyCountdown()
  return (
    <section className="festivals" aria-labelledby="festivals-title">
      <Cowrie className="festivals__cowrie festivals__cowrie--1" />
      <Cowrie className="festivals__cowrie festivals__cowrie--2" />
      <Cowrie className="festivals__cowrie festivals__cowrie--3" />
      <header className="festivals__head">
        <h2 id="festivals-title" className="section-title">Dress for the season</h2>
        <p className="festivals__count">
          {inDecember ? <strong>It is Detty December.</strong> : <><strong>{days} {days === 1 ? 'day' : 'days'}</strong> until Detty December.</>}
          <span> Post your request early so your tailor has time for fittings.</span>
        </p>
      </header>
      <div className="festivals__rail">
        {FESTIVALS.map((f) => (
          <article key={f.key} className={`festival festival--${f.key}`} data-tilt aria-labelledby={`fest-${f.key}`}>
            <div className="festival__art">
              <Motif kind={f.motif} scale={0.7} />
              {(() => {
                const ph = FESTIVAL_PHOTOS[f.key]
                return ph ? (
                  <figure className="festival__photo">
                    <img src={ph.src} alt={ph.alt} loading="lazy" decoding="async" />
                    <figcaption>Image: {ph.credit}</figcaption>
                  </figure>
                ) : null
              })()}
            </div>
            <div className="festival__text">
              <p className="festival__when">{f.when}</p>
              <h3 id={`fest-${f.key}`} className="festival__name">{f.name}</h3>
              <p className="festival__where">{f.where}</p>
              <p>{f.body}</p>
              <Link to={`/stylist?occasion=${f.occasion}`} className="text-link">Plan an outfit for it</Link>
            </div>
          </article>
        ))}
      </div>
      <p className="festivals__note">Dates for Ojude Oba and Durbar follow the Islamic calendar; Iri Ji dates are set by each community. Patterns are drawings inspired by the traditions named; photographs are used with permission.</p>
    </section>
  )
}

/** For shoppers abroad: honest about what OSAPS does and does not do across borders. */
export function Diaspora({ fabrics }: { fabrics: Fabric[] }) {
  const markets = new Map<string, { city: string; count: number }>()
  for (const f of fabrics) {
    const m = f.vendor?.market
    if (!m) continue
    const e = markets.get(m) ?? { city: f.vendor!.city, count: 0 }
    e.count++
    markets.set(m, e)
  }
  return (
    <section className="diaspora" aria-labelledby="diaspora-title">
      <div className="diaspora__panel">
        <div className="diaspora__intro">
          <ul className="diaspora__greetings" aria-label="Welcome">
            {GREETINGS.map((g) => <li key={g.lang}><span lang={g.lang === 'Yorùbá' ? 'yo' : g.lang === 'Hausa' ? 'ha' : 'ig'}>{g.word}</span><small>{g.lang}</small></li>)}
          </ul>
          <h2 id="diaspora-title" className="diaspora__title">In London, Houston or Toronto? Shop like you are in Balogun.</h2>
          <LagosClock />
        </div>
        <ol className="diaspora__steps">
          <li><span><strong>Prices stay in naira,</strong> exactly as vendors and tailors list them.</span></li>
          <li><span><strong>Ask before you accept.</strong> Message any tailor about your measurements and international courier, and agree it in the offer.</span></li>
          <li><span><strong>Aṣọ-ẹbí for everyone.</strong> One request can dress relatives at home and abroad in matching cloth.</span></li>
          <li><span><strong>Pay the tailor directly for now.</strong> Paying through OSAPS is not available yet, and we say so on every order.</span></li>
        </ol>
        {markets.size > 0 && (
          <div className="diaspora__markets">
            <p className="label">Markets in the catalogue now</p>
            <ul>
              {[...markets.entries()].map(([m, e]) => (
                <li key={m}><span>{m}</span><small>{e.city}, {e.count} {e.count === 1 ? 'fabric' : 'fabrics'}</small></li>
              ))}
            </ul>
          </div>
        )}
        <div className="diaspora__actions">
          <Link to="/requests/new" className="btn btn--primary">Post a tailoring request</Link>
          <Link to="/fabrics" className="btn btn--secondary">Browse the markets</Link>
        </div>
      </div>
    </section>
  )
}
