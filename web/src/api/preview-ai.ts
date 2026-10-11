/**
 * DESIGN-REVIEW ADAPTER — AI endpoints, behaving exactly as the live server does with no AI key:
 * keyword search, the guided (rule-based) stylist, style-guide fabric ideas, and an honest
 * "not switched on" answer for photo reading. Mirrors app/Ai/Features/* and app/Domain/Ai/*.
 */
import type { Api } from './client'
import type { Fabric, StylistKnown, StylistQuestion, StylistTurn } from './types'
import { GARMENTS, FABRIC_CATS, delay, fabrics, fail, money, tailors } from './preview'

export const OCCASIONS: Record<string, string> = {
  wedding_guest: 'Wedding guest', traditional_wedding: 'Traditional wedding', own_wedding: 'My own wedding',
  naming_ceremony: 'Naming ceremony', church_mosque: 'Church or mosque', work: 'Work', party: 'Party or birthday',
  everyday: 'Everyday', family_event: 'Family event (aṣọ-ẹbí)', other: 'Something else',
}
const COLORS = ['navy', 'indigo', 'gold', 'terracotta', 'emerald', 'burgundy', 'cream', 'black', 'white', 'orange', 'royal_blue', 'purple', 'green', 'red', 'brown', 'pink', 'silver']
const NEEDS: Record<string, string> = { fabric_and_tailoring: 'Fabric and tailoring', tailoring_only: 'Tailoring only — I have fabric', fabric_only: 'Fabric only' }
const TIMING: Record<string, string> = { two_weeks: 'Within 2 weeks', month: 'Within a month', three_months: 'Within 3 months', no_rush: 'No rush' }
const GARMENT_FABRICS: Record<string, string[]> = {
  agbada: ['aso_oke', 'damask', 'senator'], senator: ['senator', 'damask'], kaftan: ['senator', 'damask', 'adire'],
  buba_sokoto: ['aso_oke', 'adire', 'damask'], iro_buba: ['aso_oke', 'adire', 'ankara', 'lace'], gown: ['lace', 'ankara', 'george'],
  skirt_blouse: ['ankara', 'lace', 'adire'], suit: ['suiting'], bridal: ['lace', 'aso_oke', 'george'], aso_ebi: ['ankara', 'lace', 'aso_oke'],
  kids: ['ankara', 'adire'], alteration: [],
}
const YARDS: Record<string, number> = { agbada: 10, senator: 5, kaftan: 5, buba_sokoto: 5, iro_buba: 6, gown: 5, skirt_blouse: 5, suit: 4, bridal: 8, aso_ebi: 5, kids: 3 }

const WORDS = {
  cat: { aso_oke: ['aso oke', 'aso-oke', 'asooke', 'sanyan', 'alaari'], ankara: ['ankara', 'wax print'], adire: ['adire', 'tie dye', 'tie-dye'], lace: ['lace', 'guipure'], senator: ['senator'], suiting: ['suiting', 'wool'], george: ['george'], damask: ['damask', 'brocade'] } as Record<string, string[]>,
  color: { burgundy: ['burgundy', 'wine', 'maroon', 'oxblood'], navy: ['navy'], royal_blue: ['royal blue'], indigo: ['indigo'], gold: ['gold', 'golden', 'champagne'], emerald: ['emerald'], green: ['green'], cream: ['cream', 'ivory', 'off white', 'beige'], white: ['white'], black: ['black', 'charcoal'], purple: ['purple', 'lilac'], orange: ['orange', 'coral'], red: ['red'], pink: ['pink'], brown: ['brown'], terracotta: ['terracotta', 'rust'], silver: ['silver', 'grey', 'gray'] } as Record<string, string[]>,
  occasion: { own_wedding: ['my wedding', 'bride', 'groom'], traditional_wedding: ['traditional wedding', 'introduction'], wedding_guest: ['wedding'], naming_ceremony: ['naming'], church_mosque: ['church', 'mosque', 'eid', 'sallah'], work: ['work', 'office'], party: ['party', 'birthday', 'dinner'], family_event: ['family', 'aso ebi', 'asoebi'], everyday: ['everyday', 'casual'] } as Record<string, string[]>,
  garment: { agbada: ['agbada'], senator: ['senator'], kaftan: ['kaftan'], iro_buba: ['iro'], gown: ['gown', 'dress'], skirt_blouse: ['skirt', 'blouse'], suit: ['suit'], kids: ['kids', 'children'] } as Record<string, string[]>,
}

const norm = (t: string) => t.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase()
const match = (t: string, map: Record<string, string[]>) =>
  Object.entries(map).filter(([, ws]) => ws.some((w) => new RegExp(`(^|[^a-z])${w.replace(/[-]/g, '\\-')}([^a-z]|$)`).test(t))).map(([k]) => k)

function amount(t: string): number | null {
  for (const m of t.matchAll(/(₦|ngn)?\s*(\d{1,3}(?:,\d{3})+|\d+(?:\.\d+)?)\s*(k|m|thousand|million|naira)?(?![a-z\d])/g)) {
    const raw = Number(m[2]!.replace(/,/g, ''))
    const v = m[3] === 'k' || m[3] === 'thousand' ? raw * 1000 : m[3] === 'm' || m[3] === 'million' ? raw * 1_000_000 : raw
    if ((m[1] || m[3] || v >= 1000) && v >= 100) return Math.round(v)
  }
  return null
}

function read(text: string) {
  const t = norm(text)
  let colors = match(t, WORDS.color)
  if (/\bblue\b/.test(t) && !colors.some((c) => ['navy', 'royal_blue', 'indigo'].includes(c))) colors = [...colors, 'navy', 'royal_blue', 'indigo']
  const amt = amount(t)
  return {
    categories: match(t, WORDS.cat), colors, occasion: match(t, WORDS.occasion)[0] ?? null, garment: match(t, WORDS.garment)[0] ?? null,
    budget: amt, max: amt !== null && /\b(under|below|less than|max|within|up to)\b/.test(t) ? amt : null,
  }
}

function query(cats: string[], colors: string[], maxMinor: number | null): Fabric[] {
  return fabrics
    .filter((f) => f.available_quantity > 0)
    .filter((f) => !cats.length || cats.includes(f.category))
    .filter((f) => !colors.length || colors.includes(f.primary_color) || f.colors.some((c) => colors.includes(c)))
    .filter((f) => maxMinor === null || f.price.amount_minor <= maxMinor)
    .sort((a, b) => a.price.amount_minor - b.price.amount_minor)
}

function nextQuestion(k: StylistKnown): StylistQuestion | null {
  const kv = (m: Record<string, string>) => Object.entries(m).map(([value, label]) => ({ value, label }))
  if (k.occasion === undefined) return { slot: 'occasion', text: 'What is the occasion?', choices: kv(OCCASIONS), multiple: false, allow_text: true }
  if (k.garment === undefined) {
    const occ = OCCASIONS[k.occasion]?.toLowerCase()
    const garments = Object.fromEntries(Object.entries(GARMENTS).filter(([g]) => g !== 'alteration'))
    return { slot: 'garment', text: occ && occ !== 'something else' ? `Lovely. What would you like to wear for the ${occ}?` : 'What kind of outfit do you have in mind?', choices: kv(garments), multiple: false, allow_text: true }
  }
  if (k.colors === undefined) return { slot: 'colors', text: 'Which colours are you drawn to? Pick up to four, or skip if you are open.', choices: COLORS.map((c) => ({ value: c, label: c.replace('_', ' ').replace(/^./, (x) => x.toUpperCase()) })), multiple: true, allow_text: true }
  if (k.budget_naira === undefined) return { slot: 'budget_naira', text: 'Roughly what would you like to spend in total, fabric and tailoring together?', choices: [{ value: 30000, label: 'About ₦30,000' }, { value: 60000, label: 'About ₦60,000' }, { value: 120000, label: 'About ₦120,000' }, { value: 250000, label: '₦250,000 or more' }, { value: 0, label: 'Not sure yet' }], multiple: false, allow_text: true }
  if (k.needs === undefined) return { slot: 'needs', text: 'Do you need fabric, tailoring, or both?', choices: kv(NEEDS), multiple: false, allow_text: false }
  if (k.timing === undefined) return { slot: 'timing', text: 'When do you need to wear it?', choices: kv(TIMING), multiple: false, allow_text: false }
  return null
}

function conclude(k: StylistKnown): NonNullable<StylistTurn['result']> {
  const garment = k.garment!
  const needsFabric = k.needs !== 'tailoring_only'
  const needsTailoring = k.needs !== 'fabric_only'
  const cats = GARMENT_FABRICS[garment] ?? []
  let found = needsFabric ? query(cats, k.colors ?? [], null) : []
  if (needsFabric && !found.length) found = query(cats, [], null)
  const picks = found.slice(0, 4)
  const matched = needsTailoring ? tailors.filter((t) => t.specialties.some((s) => s.key === garment)).slice(0, 3) : []
  const colors = (k.colors ?? []).map((c) => c.replace('_', ' '))
  const occ = k.occasion ? OCCASIONS[k.occasion]?.toLowerCase() : null
  const gl = (GARMENTS[garment] ?? garment).toLowerCase()

  const lines: { key: string; label: string; minor: number; basis: string }[] = []
  const missing: string[] = []
  const yards = YARDS[garment] ?? 0
  if (needsFabric) picks[0] && yards ? lines.push({ key: 'fabric', label: `Fabric, about ${yards} units`, minor: picks[0].price.amount_minor * yards, basis: 'listed × assumption' }) : missing.push('a fabric with a listed price')
  let tailoring: number | null = null
  if (needsTailoring) {
    const prices = matched.map((t) => t.starting_price?.amount_minor ?? 0).filter((p) => p > 0).sort((a, b) => a - b)
    if (prices.length) {
      tailoring = prices[Math.floor((prices.length - 1) / 2)]!
      lines.push({ key: 'tailoring', label: 'Tailoring, typical starting price', minor: tailoring, basis: 'tailor_from' })
    } else missing.push('tailor prices for this garment')
  }
  if (tailoring !== null) lines.push({ key: 'service_fee', label: 'OSAPS service fee', minor: Math.min(Math.floor((tailoring * 250 + 5000) / 10000), 500000), basis: 'calculated' })
  const total = !missing.length && lines.length ? lines.reduce((s, l) => s + l.minor, 0) : null
  const budgetMinor = k.budget_naira ? k.budget_naira * 100 : null
  const within = total !== null && budgetMinor ? total <= budgetMinor : null

  const title = `${colors[0] ? colors[0].replace(/^./, (x) => x.toUpperCase()) + ' ' : ''}${gl}${occ ? ` for a ${occ}` : ''}`
  const concept = {
    title,
    summary: `A ${colors.length ? colors.join(' and ') : 'colour you love'} ${gl}${occ ? ` made for a ${occ}` : ''}, cut to your measurements by a tailor you choose.`,
    silhouette: 'Discuss the cut with your tailor; share a reference photo if you have one.',
    colour_direction: colors.length ? `Built around ${colors.join(', ')}.` : 'Open — pick from the fabrics below.',
    fabric_characteristics: cats.length ? `Usually made in ${cats.map((c) => FABRIC_CATS[c]).join(', ')}.` : 'Any fabric you already own.',
    finishing_details: 'Agree lining, closures and any embroidery in the offer before you accept.',
    questions_for_tailor: ['How many fittings are included?', 'Is lining included in the price?'],
    style_tags: (k.colors ?? []).slice(0, 2),
  }
  return {
    source: 'guided', notice: null, concept,
    fabrics: picks,
    tailors: matched.map((t) => ({ ...t, match: { score: 70, new_vendor: true, reasons: [`Specialises in ${gl}.`, 'New on OSAPS — limited order history so far.'], factors: {} } })),
    budget: {
      lines: lines.map((l) => ({ key: l.key, label: l.label, amount: money(l.minor), basis: l.basis })),
      missing, total: total === null ? null : money(total), budget: budgetMinor ? money(budgetMinor) : null, within_budget: within,
      note: within === false ? 'This is above your budget. A simpler style, a less costly fabric or a different tailor would bring it down; actual offers may also differ.' : total !== null ? 'An estimate, not a quote. Tailors set their own prices in their offers, and delivery is extra if charged.' : null,
    },
    request_draft: {
      garment_category: garment, occasion: k.occasion ?? null, occasion_label: k.occasion ? OCCASIONS[k.occasion] ?? null : null,
      title, description: `${concept.summary} ${concept.silhouette} ${concept.finishing_details}${k.preferences ? ' Preferences: ' + k.preferences : ''}`,
      style_tags: concept.style_tags, mode: k.budget_naira ? 'budget' : 'open',
      fabric_status: k.needs === 'tailoring_only' ? 'own_fabric' : picks.length ? 'buying_on_platform' : 'need_advice',
      fabric_id: picks[0]?.id ?? null,
    },
  }
}

const GUIDE: Record<string, [string, string][]> = {
  ankara: [['gown', 'A fitted Ankara gown with a structured bodice and flared hem.'], ['skirt_blouse', 'A peplum blouse with a pencil skirt in the same print.'], ['kids', "Matching children's pieces from the same bolt."]],
  adire: [['kaftan', 'A relaxed adire kaftan with contrast piping.'], ['iro_buba', 'A modern iro and buba with a cropped buba.'], ['skirt_blouse', 'A wrap skirt with a plain blouse to let the dye pattern lead.']],
  aso_oke: [['agbada', 'An agbada with aṣọ-òkè panels and matching fila.'], ['iro_buba', 'A ceremonial iro and buba with gele.'], ['bridal', 'A traditional bridal set with a beaded neckline.']],
  lace: [['gown', 'A lace evening gown with a lined sheath and scalloped hem.'], ['iro_buba', 'A lace buba over a silk iro.'], ['bridal', 'A lace bridal look with appliqué on a sheer yoke.']],
  senator: [['senator', 'A classic senator with subtle chest embroidery.'], ['kaftan', 'A kaftan with a concealed placket.'], ['agbada', 'A light agbada over a senator base.']],
  suiting: [['suit', 'A two-piece suit with a soft shoulder.'], ['senator', 'A tailored senator with sharp cuffs.']],
  george: [['iro_buba', 'A george wrapper with a lace buba.'], ['gown', 'A draped george gown for evening.']],
  damask: [['agbada', 'A damask agbada with tonal embroidery.'], ['kaftan', 'A damask kaftan with a mandarin collar.']],
}

type AiApi = Pick<Api, 'aiSearch' | 'stylist' | 'fabricIdeas' | 'inspiration'>

export const previewAi: AiApi = {
  aiSearch: (q) => {
    const r = read(q)
    let results = query(r.categories, r.colors, r.max ? r.max * 100 : null)
    const relaxed: string[] = []
    if (!results.length && r.max) { relaxed.push('price limit'); results = query(r.categories, r.colors, null) }
    if (!results.length && r.colors.length) { relaxed.push('colour'); results = query(r.categories, [], null) }
    const desc = [r.colors.length ? r.colors.map((c) => c.replace('_', ' ')).join(' or ') : '', r.categories.length ? r.categories.map((c) => FABRIC_CATS[c]).join(' or ') : 'fabric'].filter(Boolean).join(' ')
    return delay({
      source: 'keywords' as const,
      understood: !r.categories.length && !r.colors.length && !r.max ? 'Showing all fabric in stock — try naming a type, colour or budget.' : desc.replace(/^./, (x) => x.toUpperCase()) + (r.max ? ` up to ₦${r.max.toLocaleString('en-NG')} per unit` : '') + '.',
      criteria: { categories: r.categories, colors: r.colors, max_price_minor: r.max ? r.max * 100 : null, in_stock_only: true, terms: [], occasion: r.occasion },
      relaxed, notice: null, results: results.slice(0, 12),
    })
  },
  stylist: (incoming, message, slot) => {
    const k: StylistKnown = { ...incoming }
    if (message && message.trim()) {
      const r = read(message)
      if (r.occasion && !k.occasion) k.occasion = r.occasion
      if (r.garment && !k.garment) k.garment = r.garment
      if (r.colors.length && !k.colors) k.colors = r.colors.slice(0, 4)
      if (r.budget && k.budget_naira === undefined) k.budget_naira = r.budget
      if (slot === 'colors' && !k.colors) { k.colors = []; k.colors_open = true }
      if (!r.occasion && !r.garment && !r.colors.length && !r.budget && !k.preferences) k.preferences = message.trim().slice(0, 300)
    }
    const next = nextQuestion(k)
    return delay<StylistTurn>({ source: 'guided', notice: null, known: k, next_question: next, result: next ? null : conclude(k) })
  },
  fabricIdeas: (slug) => {
    const f = fabrics.find((x) => x.slug === slug)
    if (!f) fail('Not found.', 404, 'not_found')
    return delay({
      source: 'guide' as const, notice: null,
      ideas: (GUIDE[f!.category] ?? []).map(([g, d]) => ({ title: GARMENTS[g] ?? g, garment_category: g, occasion: null, description: d, styling_note: null })),
    })
  },
  inspiration: async () => {
    fail('Photo reading needs the AI stylist, which is not switched on yet. You can describe the outfit in words instead.', 503, 'ai_unavailable')
    throw new Error('unreachable')
  },
}
