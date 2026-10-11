import { useId, type ReactElement } from 'react'

/**
 * Procedurally drawn textile swatch — an illustration generated from a listing's real
 * pattern type and colours. Shown until a vendor uploads photographs, and always labelled
 * as an illustration by the caller so it is never mistaken for a product photo.
 */

export const COLOR_HEX: Record<string, string> = {
  navy: '#1c2550',
  indigo: '#2a3170',
  gold: '#c49a3a',
  terracotta: '#b5532c',
  emerald: '#1f6b52',
  burgundy: '#7a1f35',
  cream: '#efe4cc',
  black: '#24222b',
  white: '#f8f4ea',
  orange: '#d9772b',
  royal_blue: '#2f4fa8',
  purple: '#5b2a7a',
  green: '#3e7a3a',
  red: '#a8282f',
  brown: '#6b4428',
  pink: '#d77b97',
  silver: '#b9b8bf',
}

const hex = (c: string | undefined, fallback: string) => (c && COLOR_HEX[c]) || fallback

function shade(h: string, amount: number): string {
  const n = parseInt(h.slice(1), 16)
  const ch = (shift: number) => Math.max(0, Math.min(255, ((n >> shift) & 255) + amount))
  return '#' + [16, 8, 0].map((s) => ch(s).toString(16).padStart(2, '0')).join('')
}

type Draw = (a: string, b: string, c: string) => { size: number; body: ReactElement }

const PATTERNS: Record<string, Draw> = {
  sunburst: (a, b, c) => ({
    size: 60,
    body: (
      <>
        <rect width="60" height="60" fill={a} />
        {Array.from({ length: 12 }, (_, i) => (
          <path key={i} d="M30 30 L30 2 L34 4 Z" fill={b} transform={`rotate(${i * 30} 30 30)`} />
        ))}
        <circle cx="30" cy="30" r="11" fill={c} />
        <circle cx="30" cy="30" r="6" fill={a} />
        <circle cx="0" cy="0" r="7" fill={b} />
        <circle cx="60" cy="60" r="7" fill={b} />
        <circle cx="60" cy="0" r="7" fill={b} />
        <circle cx="0" cy="60" r="7" fill={b} />
      </>
    ),
  }),
  circles: (a, b, c) => ({
    size: 44,
    body: (
      <>
        <rect width="44" height="44" fill={a} />
        <circle cx="22" cy="22" r="15" fill="none" stroke={b} strokeWidth="5" />
        <circle cx="22" cy="22" r="5" fill={c} />
        <circle cx="0" cy="0" r="6" fill={c} />
        <circle cx="44" cy="0" r="6" fill={c} />
        <circle cx="0" cy="44" r="6" fill={c} />
        <circle cx="44" cy="44" r="6" fill={c} />
      </>
    ),
  }),
  lattice: (a, b, c) => ({
    size: 36,
    body: (
      <>
        <rect width="36" height="36" fill={a} />
        <path d="M0 18 L18 0 L36 18 L18 36 Z" fill="none" stroke={b} strokeWidth="3" />
        <path d="M18 9 L27 18 L18 27 L9 18 Z" fill={c} />
        <circle cx="18" cy="18" r="2.5" fill={b} />
      </>
    ),
  }),
  resist: (a, b) => ({
    size: 48,
    body: (
      <>
        <rect width="48" height="48" fill={a} />
        <path d="M0 12 Q12 4 24 12 T48 12" fill="none" stroke={b} strokeWidth="2.2" />
        <path d="M0 36 Q12 28 24 36 T48 36" fill="none" stroke={b} strokeWidth="2.2" />
        {[6, 18, 30, 42].map((x) => (
          <circle key={x} cx={x} cy="24" r="1.8" fill={b} />
        ))}
        <path d="M22 18 l2 -4 l2 4 l-2 4 Z" fill={b} />
        <path d="M46 42 l2 -4 l2 4 l-2 4 Z" fill={b} opacity="0.85" />
      </>
    ),
  }),
  tie_dye: (a, b) => ({
    size: 70,
    body: (
      <>
        <rect width="70" height="70" fill={a} />
        {[28, 21, 14, 7].map((r, i) => (
          <circle key={r} cx="35" cy="35" r={r} fill="none" stroke={b} strokeWidth={i % 2 ? 2 : 4} opacity={0.35 + i * 0.18} strokeDasharray={i % 2 ? '3 4' : undefined} />
        ))}
        <circle cx="35" cy="35" r="3" fill={b} />
      </>
    ),
  }),
  stripes: (a, b, c) => ({
    size: 40,
    body: (
      <>
        <rect width="40" height="40" fill={a} />
        <rect x="6" width="7" height="40" fill={b} />
        <rect x="15" width="1.5" height="40" fill={c} />
        <rect x="26" width="3" height="40" fill={shade(a, -28)} />
        <rect x="33" width="1" height="40" fill={b} />
        {/* weft texture */}
        {Array.from({ length: 10 }, (_, i) => (
          <rect key={i} y={i * 4} width="40" height="1" fill={shade(a, 18)} opacity="0.25" />
        ))}
      </>
    ),
  }),
  floral: (a, b) => ({
    size: 40,
    body: (
      <>
        <rect width="40" height="40" fill={a} />
        {[0, 72, 144, 216, 288].map((deg) => (
          <ellipse key={deg} cx="20" cy="12" rx="4" ry="7" fill="none" stroke={shade(b, -40)} strokeWidth="1.2" transform={`rotate(${deg} 20 20)`} />
        ))}
        <circle cx="20" cy="20" r="2.6" fill="none" stroke={shade(b, -40)} strokeWidth="1.2" />
        <circle cx="2" cy="2" r="1.6" fill={shade(a, -30)} />
        <circle cx="38" cy="38" r="1.6" fill={shade(a, -30)} />
        <circle cx="2" cy="38" r="1.6" fill={shade(a, -30)} />
        <circle cx="38" cy="2" r="1.6" fill={shade(a, -30)} />
      </>
    ),
  }),
  paisley: (a, b, c) => ({
    size: 56,
    body: (
      <>
        <rect width="56" height="56" fill={a} />
        <path d="M18 40 C6 36 6 18 20 14 C30 11 36 20 30 26 C26 30 22 26 24 22" fill="none" stroke={b} strokeWidth="2.2" />
        <circle cx="19" cy="31" r="2.5" fill={c} />
        <path d="M46 14 C40 12 40 4 47 2" fill="none" stroke={b} strokeWidth="1.6" transform="translate(0 30)" />
      </>
    ),
  }),
  jacquard: (a) => ({
    size: 48,
    body: (
      <>
        <rect width="48" height="48" fill={a} />
        <path d="M24 6 C30 16 30 16 42 24 C30 32 30 32 24 42 C18 32 18 32 6 24 C18 16 18 16 24 6 Z" fill={shade(a, 22)} />
        <circle cx="24" cy="24" r="5" fill={shade(a, -18)} />
        <circle cx="0" cy="0" r="5" fill={shade(a, 22)} />
        <circle cx="48" cy="48" r="5" fill={shade(a, 22)} />
      </>
    ),
  }),
  feathers: (a, b, c) => ({
    size: 40,
    body: (
      <>
        <rect width="40" height="40" fill={a} />
        <path d="M0 14 L20 4 L40 14" fill="none" stroke={b} strokeWidth="4" />
        <path d="M0 34 L20 24 L40 34" fill="none" stroke={c} strokeWidth="4" />
        <circle cx="20" cy="14" r="2" fill={c} />
      </>
    ),
  }),
  twill: (a) => ({
    size: 12,
    body: (
      <>
        <rect width="12" height="12" fill={a} />
        <path d="M-3 3 L3 -3 M0 12 L12 0 M9 15 L15 9" stroke={shade(a, 16)} strokeWidth="1.6" />
      </>
    ),
  }),
  plain: (a) => ({
    size: 6,
    body: (
      <>
        <rect width="6" height="6" fill={a} />
        <rect width="6" height="1" fill={shade(a, 12)} opacity="0.5" />
        <rect width="1" height="6" fill={shade(a, -10)} opacity="0.4" />
      </>
    ),
  }),
}

export interface SwatchProps {
  pattern: string | null
  colors: string[]
  scale?: number
  className?: string
  title?: string
}

export function Swatch({ pattern, colors, scale = 1, className, title }: SwatchProps) {
  const id = useId().replace(/:/g, '')
  const a = hex(colors[0], '#2a3170')
  const b = hex(colors[1], colors.length === 1 ? shade(a, 40) : '#c49a3a')
  const c = hex(colors[2], colors.length >= 2 ? shade(b, -30) : shade(a, -30))
  const draw = PATTERNS[pattern ?? 'plain'] ?? PATTERNS.plain!
  const { size, body } = draw(a, b, c)

  return (
    <svg className={className} viewBox="0 0 200 200" preserveAspectRatio="xMidYMid slice" role={title ? 'img' : undefined} aria-label={title} aria-hidden={title ? undefined : true}>
      <defs>
        <pattern id={`p${id}`} width={size * scale} height={size * scale} patternUnits="userSpaceOnUse">
          <g transform={`scale(${scale})`}>{body}</g>
        </pattern>
        {/* soft fold shading so the cloth reads as cloth, not a flat tile */}
        <linearGradient id={`f${id}`} x1="0" x2="1" y1="0" y2="0.3">
          <stop offset="0" stopColor="#000" stopOpacity="0.16" />
          <stop offset="0.35" stopColor="#fff" stopOpacity="0.06" />
          <stop offset="0.7" stopColor="#000" stopOpacity="0.08" />
          <stop offset="1" stopColor="#fff" stopOpacity="0.04" />
        </linearGradient>
      </defs>
      <rect width="200" height="200" fill={`url(#p${id})`} />
      <rect width="200" height="200" fill={`url(#f${id})`} />
    </svg>
  )
}

/**
 * A textile pattern rendered as hanging cloth: folds displace the weave and light falls across them.
 * An illustration built from a listing's real pattern and colours — callers caption it as such.
 * Static (no animation); the SVG filter is computed once by the browser.
 */
export function DrapedCloth({
  pattern,
  colors,
  seed = 4,
  scale = 1,
  className,
  title,
}: SwatchProps & { seed?: number }) {
  const id = useId().replace(/:/g, '')
  const a = hex(colors[0], '#2a3170')
  const b = hex(colors[1], colors.length === 1 ? shade(a, 40) : '#c49a3a')
  const c = hex(colors[2], colors.length >= 2 ? shade(b, -30) : shade(a, -30))
  const draw = PATTERNS[pattern ?? 'plain'] ?? PATTERNS.plain!
  const { size, body } = draw(a, b, c)

  return (
    <svg className={className} viewBox="0 0 400 600" preserveAspectRatio="xMidYMid slice" role={title ? 'img' : undefined} aria-label={title} aria-hidden={title ? undefined : true}>
      <defs>
        <pattern id={`dp${id}`} width={size * scale} height={size * scale} patternUnits="userSpaceOnUse">
          <g transform={`scale(${scale})`}>{body}</g>
        </pattern>
        <filter id={`df${id}`} x="-6%" y="-4%" width="112%" height="108%" colorInterpolationFilters="sRGB">
          <feTurbulence type="fractalNoise" baseFrequency="0.0105 0.0009" numOctaves={3} seed={seed} result="n" />
          <feDisplacementMap in="SourceGraphic" in2="n" scale={42} xChannelSelector="R" yChannelSelector="A" result="d" />
          <feDiffuseLighting in="n" surfaceScale={20} diffuseConstant={1.12} lightingColor="#fff" result="light">
            <feDistantLight azimuth={190} elevation={36} />
          </feDiffuseLighting>
          <feComposite in="light" in2="d" operator="in" result="lightc" />
          <feBlend in="d" in2="lightc" mode="multiply" result="shaded" />
          <feSpecularLighting in="n" surfaceScale={20} specularConstant={0.32} specularExponent={22} lightingColor="#fff4e2" result="spec">
            <feDistantLight azimuth={190} elevation={42} />
          </feSpecularLighting>
          <feComposite in="spec" in2="d" operator="in" result="specc" />
          <feComposite in="shaded" in2="specc" operator="arithmetic" k2={1.16} k3={0.26} />
        </filter>
      </defs>
      <rect x="-20" y="-10" width="440" height="620" fill={`url(#dp${id})`} filter={`url(#df${id})`} />
    </svg>
  )
}

/**
 * One seamless repeat of a listing's pattern, used as the texture for the live cloth.
 * `data-size` carries the tile's natural size so the renderer can keep the print to scale.
 */
export function PatternTile({ pattern, colors }: Pick<SwatchProps, 'pattern' | 'colors'>) {
  const a = hex(colors[0], '#2a3170')
  const b = hex(colors[1], colors.length === 1 ? shade(a, 40) : '#c49a3a')
  const c = hex(colors[2], colors.length >= 2 ? shade(b, -30) : shade(a, -30))
  const draw = PATTERNS[pattern ?? 'plain'] ?? PATTERNS.plain!
  const { size, body } = draw(a, b, c)
  return (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox={`0 0 ${size} ${size}`} width="512" height="512" data-size={size} aria-hidden>
      {body}
    </svg>
  )
}
