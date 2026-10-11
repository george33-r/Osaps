import { useEffect, useId, useState, type ReactElement } from 'react'

/**
 * Drawn cultural motifs. Each is an original drawing *inspired by* a tradition, not a copy of a
 * specific artwork, and is captioned as such wherever it is used.
 *  - arewa: the Dagin Arewa, the star-shaped Northern knot of Hausa building, design and embroidery
 *  - adire: Yoruba indigo resist-dye, a grid of hand-drawn squares, circles, dots and lines
 *  - uli:   Igbo line art, painted by women on skin and walls, with spirals, dots and open space
 */
export type MotifKind = 'arewa' | 'adire' | 'uli'

function ArewaTile() {
  // Two interlaced squares form the eight-pointed knot; a light inner line makes the bands read as braided cord.
  const band = (d: string) => (
    <>
      <path d={d} fill="none" stroke="#f2be45" strokeWidth="7" strokeLinejoin="round" />
      <path d={d} fill="none" stroke="#0d1640" strokeWidth="3.2" strokeLinejoin="round" />
      <path d={d} fill="none" stroke="#f6f1e7" strokeWidth="1.1" strokeLinejoin="round" />
    </>
  )
  return (
    <>
      <rect width="80" height="80" fill="#14206b" />
      <g transform="translate(40 40)">
        {band('M-20 -20 H20 V20 H-20 Z')}
        {band('M0 -28.3 L28.3 0 L0 28.3 L-28.3 0 Z')}
        <circle r="5" fill="#f6f1e7" />
        <circle r="2.2" fill="#14206b" />
      </g>
      {[0, 80].map((x) => [0, 80].map((y) => <circle key={`${x}${y}`} cx={x} cy={y} r="3" fill="#f6f1e7" opacity="0.7" />))}
    </>
  )
}

function AdireTile() {
  const cream = '#e9e3f5'
  return (
    <>
      <rect width="96" height="96" fill="#1b2a7a" />
      <g fill="none" stroke={cream} strokeWidth="1.6" opacity="0.92">
        <rect x="2" y="2" width="44" height="44" />
        <rect x="50" y="50" width="44" height="44" />
        <circle cx="24" cy="24" r="15" />
        <circle cx="24" cy="24" r="9" />
        <circle cx="72" cy="72" r="15" />
        <circle cx="72" cy="72" r="9" />
        <path d="M54 8 H90 M54 16 H90 M54 24 H90 M54 32 H90 M54 40 H90" />
        <path d="M8 54 V90 M16 54 V90 M24 54 V90 M32 54 V90 M40 54 V90" />
      </g>
      <g fill={cream}>
        <circle cx="24" cy="24" r="3" />
        <circle cx="72" cy="72" r="3" />
        {[58, 66, 74, 82].map((x) => <circle key={x} cx={x} cy="4.5" r="1.2" />)}
      </g>
    </>
  )
}

function UliTile() {
  const ink = '#24160f'
  return (
    <>
      <rect width="120" height="120" fill="#e9c98a" />
      <g fill="none" stroke={ink} strokeWidth="2.4" strokeLinecap="round">
        <path d="M30 34 m-14 0 a14 14 0 1 1 28 0 a10 10 0 1 1 -20 0 a6 6 0 1 1 12 0" />
        <path d="M70 18 C82 30 98 30 110 18" />
        <path d="M70 30 C82 42 98 42 110 30" />
        <path d="M8 80 L24 66 L40 80 L56 66 L72 80" />
        <path d="M86 70 a16 16 0 0 0 0 32" />
        <path d="M96 76 a10 10 0 0 0 0 20" />
        <path d="M20 102 C40 92 60 112 80 102" />
      </g>
      <g fill={ink}>
        {[12, 20, 28, 36, 44].map((x) => <circle key={x} cx={x} cy="58" r="1.6" />)}
        <circle cx="30" cy="34" r="2" />
        <circle cx="104" cy="88" r="2.4" />
      </g>
      <path d="M0 120 H120" stroke="#b5532c" strokeWidth="6" opacity="0.5" />
    </>
  )
}

const TILES: Record<MotifKind, { size: number; draw: () => ReactElement }> = {
  arewa: { size: 80, draw: ArewaTile },
  adire: { size: 96, draw: AdireTile },
  uli: { size: 120, draw: UliTile },
}

export function Motif({ kind, scale = 1, className, title }: { kind: MotifKind; scale?: number; className?: string; title?: string }) {
  const id = useId().replace(/:/g, '')
  const t = TILES[kind]
  const Draw = t.draw
  return (
    <svg className={className} viewBox="0 0 400 400" preserveAspectRatio="xMidYMid slice" role={title ? 'img' : undefined} aria-label={title} aria-hidden={title ? undefined : true}>
      <defs>
        <pattern id={`m${id}`} width={t.size * scale} height={t.size * scale} patternUnits="userSpaceOnUse">
          <g transform={`scale(${scale})`}><Draw /></g>
        </pattern>
      </defs>
      <rect width="400" height="400" fill={`url(#m${id})`} />
    </svg>
  )
}

/** A cowrie shell, once currency across West Africa and still sewn onto festival dress. */
export function Cowrie({ className }: { className?: string }) {
  const id = useId().replace(/:/g, '')
  return (
    <svg className={className} viewBox="0 0 60 80" aria-hidden>
      <defs>
        <radialGradient id={`c${id}`} cx="0.35" cy="0.3" r="0.8">
          <stop offset="0" stopColor="#fffaf0" />
          <stop offset="0.6" stopColor="#efe2c4" />
          <stop offset="1" stopColor="#c9b48a" />
        </radialGradient>
      </defs>
      <ellipse cx="30" cy="40" rx="26" ry="37" fill={`url(#c${id})`} />
      <path d="M30 10 C24 26 24 54 30 70 C36 54 36 26 30 10 Z" fill="#5a4630" />
      <g stroke="#fffaf0" strokeWidth="2.2" strokeLinecap="round">
        {[18, 25, 32, 39, 46, 53, 60].map((y) => <path key={y} d={`M${y < 40 ? 26.5 : 26} ${y} h7`} />)}
      </g>
    </svg>
  )
}

/** "Welcome" in Yorùbá, Hausa and Igbo. */
export const GREETINGS = [
  { word: 'Ẹ káàbọ̀', lang: 'Yorùbá' },
  { word: 'Barka da zuwa', lang: 'Hausa' },
  { word: 'Nnọọ', lang: 'Igbo' },
] as const

function lagosNow() {
  return new Date(new Date().toLocaleString('en-US', { timeZone: 'Africa/Lagos' }))
}

/** The current time in Lagos, so a shopper abroad knows when the market is awake. */
export function LagosClock() {
  const [now, setNow] = useState(() => lagosNow())
  useEffect(() => {
    const t = window.setInterval(() => setNow(lagosNow()), 30_000)
    return () => window.clearInterval(t)
  }, [])
  const time = now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
  const h = now.getHours()
  const part = h < 6 ? 'night' : h < 12 ? 'morning' : h < 17 ? 'afternoon' : h < 21 ? 'evening' : 'night'
  return (
    <p className="lagos-clock">
      <span className="lagos-clock__time">{time}</span>
      <span className="lagos-clock__label">in Lagos right now, {part === 'night' ? 'a quiet night' : `a ${part}`} in the markets</span>
    </p>
  )
}

/** Days until 1 December in Lagos; during December it says so. */
export function useDettyCountdown(): { days: number; inDecember: boolean } {
  const now = lagosNow()
  const inDecember = now.getMonth() === 11
  const year = now.getMonth() === 11 ? now.getFullYear() + 1 : now.getFullYear()
  const start = new Date(year, 11, 1)
  const days = Math.ceil((start.getTime() - new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime()) / 86_400_000)
  return { days, inDecember }
}
