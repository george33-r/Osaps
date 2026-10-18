import { useEffect, useState, type RefObject } from 'react'
import { Link } from 'react-router-dom'
import type { Fabric } from '../api'
import { formatNaira, humanise } from '../lib/format'
import { useSession } from '../lib/session'
import { FabricCard } from './cards'

/**
 * Small helpers for the product page: buying by the piece, "how many yards do I need?",
 * a sticky buy bar for phones and a "recently viewed" shelf.
 */

/** Piece buttons, e.g. "Full piece · 5 yards · ₦30,000" and, for longer pieces, a half piece. */
export function PieceChoice({ fabric, qty, onPick }: { fabric: Fabric; qty: number; onPick: (n: number) => void }) {
  const piece = fabric.piece_yards ?? 0
  if (fabric.unit !== 'yard' || piece < 2) return null
  const half = piece >= 6 ? Math.floor(piece / 2) : 0
  const max = fabric.available_quantity
  const options = [
    ...(half >= Math.max(1, fabric.min_order_quantity) && half <= max ? [{ label: 'Half piece', yards: half }] : []),
    ...(piece <= max ? [{ label: 'Full piece', yards: piece }] : []),
    ...(piece * 2 <= max ? [{ label: '2 pieces', yards: piece * 2 }] : []),
  ]
  if (options.length === 0) return null
  return (
    <div className="pieces" role="group" aria-label="Buy by the piece">
      <p className="pieces__label">{fabric.category_label} is usually sold in pieces of {piece} yards.</p>
      <div className="pieces__row">
        {options.map((o) => (
          <button key={o.label} type="button" className="piece" aria-pressed={qty === o.yards} onClick={() => onPick(o.yards)}>
            <strong>{o.label}</strong>
            <span>{o.yards} yards · {formatNaira(fabric.price.amount_minor * o.yards)}</span>
          </button>
        ))}
      </div>
    </div>
  )
}

/** "What are you sewing?" — a typical yardage per garment, with a one-tap "use this". */
export function YardHelper({ fabric, onPick }: { fabric: Fabric; onPick: (n: number) => void }) {
  const { meta } = useSession()
  const [garment, setGarment] = useState('')
  const typical = meta?.shop?.typical_yards ?? {}
  const garments = (meta?.garment_categories ?? []).filter((g) => (typical[g.key] ?? 0) > 0)
  if (fabric.unit !== 'yard' || garments.length === 0) return null
  const yards = garment ? typical[garment] ?? 0 : 0
  const fits = yards > 0 && yards <= fabric.available_quantity && yards >= fabric.min_order_quantity
  return (
    <details className="yards">
      <summary>Not sure how many yards? <span>What are you sewing?</span></summary>
      <div className="yards__body">
        <label className="field">
          <span className="field__label">Outfit</span>
          <select className="input input--select" value={garment} onChange={(e) => setGarment(e.target.value)}>
            <option value="">Choose an outfit</option>
            {garments.map((g) => <option key={g.key} value={g.key}>{g.label}</option>)}
          </select>
        </label>
        {yards > 0 && (
          <p className="yards__answer">
            Most adults need about <strong>{yards} yards</strong> for {humanise(garment).toLowerCase()}.
            {' '}Taller or bigger sizes, long sleeves or a full skirt can need 1–2 yards more. Your tailor can confirm.
            {fits && <> <button type="button" className="text-link" onClick={() => onPick(yards)}>Use {yards} yards</button></>}
          </p>
        )}
      </div>
    </details>
  )
}

/** True while the element is off screen. Used to show the sticky bar only once the buy box has scrolled away. */
export function useOffscreen(ref: RefObject<Element | null>) {
  const [off, setOff] = useState(false)
  useEffect(() => {
    const el = ref.current
    if (!el || typeof IntersectionObserver === 'undefined') return
    const io = new IntersectionObserver(([e]) => { if (e) setOff(!e.isIntersecting && e.boundingClientRect.top < 0) })
    io.observe(el)
    return () => io.disconnect()
  }, [ref])
  return off
}

const RECENT_KEY = 'osaps-recent-v1'

type Recent = Pick<Fabric, 'id' | 'slug'>

function readRecent(): Recent[] {
  try {
    const raw = window.localStorage.getItem(RECENT_KEY)
    const list = raw ? JSON.parse(raw) : []
    return Array.isArray(list) ? list.filter((x) => x && typeof x.slug === 'string') : []
  } catch {
    return []
  }
}

/** Remember a fabric someone looked at (only its id and slug, on this device). */
export function rememberViewed(f: Fabric) {
  try {
    const list = [{ id: f.id, slug: f.slug }, ...readRecent().filter((x) => x.id !== f.id)].slice(0, 12)
    window.localStorage.setItem(RECENT_KEY, JSON.stringify(list))
  } catch {
    /* storage blocked: nothing to remember */
  }
}

/** "You looked at" — fabrics from the catalogue the shopper opened before, newest first. */
export function RecentlyViewed({ fabrics, exclude, title = 'You looked at' }: { fabrics: Fabric[]; exclude?: number; title?: string }) {
  const [recent] = useState(readRecent)
  const byId = new Map(fabrics.map((f) => [f.id, f]))
  const shown = recent.filter((r) => r.id !== exclude).map((r) => byId.get(r.id)).filter((f): f is Fabric => !!f).slice(0, 4)
  if (shown.length === 0) return null
  return (
    <section className="related recent" aria-labelledby="recent-title">
      <div className="sh-section__head">
        <h2 id="recent-title" className="section-title section-title--small">{title}</h2>
        <Link to="/fabrics" className="text-link">All fabrics</Link>
      </div>
      <div className="grid grid--fabrics">{shown.map((f) => <FabricCard key={f.id} fabric={f} />)}</div>
    </section>
  )
}
