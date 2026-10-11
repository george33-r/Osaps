import { useId, type ReactNode } from 'react'
import { FabricPattern } from './Swatch'

/**
 * Fashion illustrations: faceless croquis figures wearing Nigerian attire, filled with a real
 * catalogue fabric's pattern and colours. Drawings, not photographs, and always captioned so.
 */
export type Look =
  | 'agbada'        // Yorùbá man: agbada over bùbá and ṣòkòtò, fila cap
  | 'iro_gele'      // Yorùbá woman: ìró and bùbá, ìpèlé over the shoulder, gèlè
  | 'igbo_woman'    // Igbo woman: blouse, double wrapper, coral beads, head tie
  | 'isiagu'        // Igbo man: isiagu top, trousers, red cap, walking stick
  | 'babban_riga'   // Hausa man: babban riga with embroidered chest, hula cap
  | 'hausa_woman'   // Hausa woman: long embroidered gown with a veil

export interface FigureFabric {
  pattern: string | null
  colors: string[]
}

const SKIN: Record<Look, string> = {
  agbada: '#5b3a25', iro_gele: '#7a4b2f', igbo_woman: '#6a4128', isiagu: '#4a2d1c', babban_riga: '#6e4630', hausa_woman: '#80522f',
}

export function FashionFigure({
  look,
  fabric,
  bridal = false,
  className,
  title,
}: {
  look: Look
  fabric: FigureFabric
  bridal?: boolean
  className?: string
  title: string
}) {
  const uid = useId().replace(/:/g, '')
  const fab = `f${uid}`
  const shade = `s${uid}`
  const skin = SKIN[look]
  const F = `url(#${fab})`

  // A garment: the pattern, then soft shading in the same outline, then a fine edge.
  const garment = (d: string, key: string, fill = F, opacity = 1) => (
    <g key={key} opacity={opacity}>
      <path d={d} fill={fill} />
      <path d={d} fill={`url(#${shade})`} />
      <path d={d} fill="none" stroke="rgba(0,0,0,0.35)" strokeWidth="1" />
    </g>
  )
  const folds = (ds: string[]) => (
    <g fill="none" stroke="rgba(0,0,0,0.28)" strokeWidth="1.6" strokeLinecap="round">
      {ds.map((d) => <path key={d} d={d} />)}
    </g>
  )
  const head = <><rect x="112" y="84" width="16" height="22" rx="6" fill={skin} /><ellipse cx="120" cy="70" rx="17" ry="21" fill={skin} /></>
  const hands = (pts: [number, number][]) => pts.map(([x, y]) => <ellipse key={`${x}${y}`} cx={x} cy={y} rx="6.5" ry="8" fill={skin} />)
  const feet = (y = 494) => <><ellipse cx="106" cy={y} rx="12" ry="5" fill="#1d130d" /><ellipse cx="134" cy={y} rx="12" ry="5" fill="#1d130d" /></>
  const gold = '#f2be45'

  let body: ReactNode = null
  switch (look) {
    case 'agbada':
      body = (
        <>
          <rect x="98" y="420" width="19" height="72" fill={F} /><rect x="123" y="420" width="19" height="72" fill={F} />
          <rect x="98" y="420" width="44" height="72" fill={`url(#${shade})`} />
          {feet()}
          {head}
          {garment('M84 104 Q120 95 156 104 L214 272 Q206 294 182 286 L190 440 L50 440 L58 286 Q34 294 26 272 Z', 'robe')}
          {folds(['M96 112 L68 432', 'M144 112 L172 432', 'M120 124 L120 440', 'M62 290 Q120 312 178 290'])}
          <rect x="105" y="110" width="30" height="58" rx="6" fill="none" stroke={gold} strokeWidth="2" />
          <path d="M120 116 V160 M110 128 H130 M110 146 H130" stroke={gold} strokeWidth="1.4" />
          {hands([[30, 280], [210, 280]])}
          {garment('M100 56 Q116 34 142 44 L148 58 Q124 50 100 62 Z', 'fila')}
        </>
      )
      break
    case 'iro_gele':
      body = (
        <>
          {feet(492)}
          {head}
          {garment('M80 226 L160 226 Q166 360 172 486 L68 486 Q74 360 80 226 Z', 'iro')}
          {folds(['M100 236 Q96 360 92 484', 'M140 236 Q144 360 150 484', 'M80 232 Q120 252 160 232'])}
          {garment('M88 104 Q120 96 152 104 L190 196 L168 208 L156 168 L156 232 L84 232 L84 168 L72 208 L50 196 Z', 'buba')}
          {garment('M146 102 L162 110 L102 240 L86 232 Z', 'ipele')}
          {hands([[58, 208], [182, 208]])}
          {garment(bridal
            ? 'M66 70 Q62 4 120 2 Q182 4 176 68 Q152 46 120 48 Q90 48 66 70 Z'
            : 'M76 66 Q76 18 120 14 Q166 16 168 62 Q148 46 120 48 Q96 48 76 66 Z', 'gele')}
          {garment(bridal ? 'M164 34 Q206 22 200 72 Q184 58 170 60 Z' : 'M158 40 Q190 30 186 66 Q174 56 164 58 Z', 'gele-wing')}
          {folds(bridal
            ? ['M80 54 Q120 18 166 52', 'M88 40 Q120 10 158 38', 'M100 26 Q122 8 146 24']
            : ['M86 52 Q120 26 160 50', 'M96 38 Q120 20 150 36'])}
        </>
      )
      break
    case 'igbo_woman':
      body = (
        <>
          {feet(492)}
          {head}
          {garment('M82 212 L158 212 L168 488 L72 488 Z', 'wrapper')}
          {folds(['M104 222 L98 486', 'M138 222 L146 486'])}
          {garment('M78 208 L162 208 L168 292 Q120 304 72 292 Z', 'wrapper-top', '#7a1f35')}
          {garment('M90 106 Q120 98 150 106 L172 124 Q178 152 160 160 L152 142 L152 214 L88 214 L88 142 L80 160 Q62 152 68 124 Z', 'blouse')}
          {hands([[160, 168], [80, 168]])}
          {/* coral beads */}
          <g fill="#d2462f" stroke="#7a1d12" strokeWidth="0.6">
            {Array.from({ length: bridal ? 4 : 2 }, (_, r) => Array.from({ length: 11 }, (__, i) => {
              const t = (i / 10) * Math.PI
              return <circle key={`${r}-${i}`} cx={120 - Math.cos(t) * (16 + r * 5)} cy={102 + Math.sin(t) * (10 + r * 6)} r="2.6" />
            }))}
            {[80, 160].map((x) => <rect key={x} x={x - 8} y={156} width="16" height="5" rx="2.5" />)}
          </g>
          {bridal
            ? <g fill="#d2462f" stroke="#7a1d12" strokeWidth="0.6">{Array.from({ length: 9 }, (_, i) => <circle key={i} cx={102 + i * 4.5} cy={50 - Math.sin((i / 8) * Math.PI) * 10} r="3" />)}</g>
            : garment('M98 58 Q120 34 142 58 Q148 70 140 74 Q120 62 100 74 Q92 70 98 58 Z', 'ichafu')}
        </>
      )
      break
    case 'isiagu':
      body = (
        <>
          <rect x="96" y="286" width="22" height="206" fill="#17120f" /><rect x="122" y="286" width="22" height="206" fill="#17120f" />
          {feet()}
          <path d="M188 214 L190 496" stroke="#6b4428" strokeWidth="4" strokeLinecap="round" />
          {head}
          {garment('M86 104 Q120 95 154 104 L180 212 L160 218 L154 168 L156 292 L84 292 L86 168 L80 218 L60 212 Z', 'top')}
          {folds(['M100 116 L98 290', 'M140 116 L142 290'])}
          {hands([[66, 222], [176, 222]])}
          <rect x="104" y="38" width="32" height="22" rx="5" fill="#b8141f" /><rect x="104" y="38" width="32" height="22" rx="5" fill={`url(#${shade})`} />
          {bridal && <path d="M120 106 L120 150" stroke={gold} strokeWidth="2.5" />}
        </>
      )
      break
    case 'babban_riga':
      body = (
        <>
          <rect x="98" y="424" width="19" height="68" fill="#efe7d6" /><rect x="123" y="424" width="19" height="68" fill="#efe7d6" />
          {feet()}
          {head}
          {garment('M82 104 Q120 94 158 104 L222 286 Q212 306 186 298 L194 446 L46 446 L54 298 Q28 306 18 286 Z', 'riga')}
          {folds(['M94 114 L62 440', 'M146 114 L178 440', 'M56 300 Q120 322 184 300'])}
          {/* Embroidered chest panel with a knot inspired by the Dagin Arewa */}
          <g transform="translate(120 150)" fill="none" stroke={gold} strokeWidth="2">
            <rect x="-20" y="-20" width="40" height="40" />
            <path d="M0 -28.3 L28.3 0 L0 28.3 L-28.3 0 Z" />
            <circle r="5" />
          </g>
          <path d="M108 110 Q120 122 132 110" fill="none" stroke={gold} strokeWidth="2" />
          {hands([[24, 294], [216, 294]])}
          {bridal
            ? <>{garment('M92 64 Q88 22 120 20 Q152 22 148 64 Q120 54 92 64 Z', 'rawani', '#f6f1e7')}
                {folds(['M94 50 Q120 36 146 50', 'M96 38 Q120 26 144 38'])}
                {garment('M142 56 Q160 90 150 130 L140 128 Q148 94 134 62 Z', 'rawani-tail', '#f6f1e7')}</>
            : <>{garment('M102 58 Q100 30 120 28 Q140 30 138 58 Z', 'hula')}
                <path d="M106 50 H134 M108 42 H132" stroke={gold} strokeWidth="1.2" /></>}
        </>
      )
      break
    case 'hausa_woman':
      body = (
        <>
          {feet(492)}
          {head}
          {garment('M86 104 Q120 96 154 104 L178 240 L162 246 L156 190 L166 488 L74 488 L84 190 L78 246 L62 240 Z', 'gown')}
          {folds(['M104 120 L96 486', 'M136 120 L144 486'])}
          <path d="M104 112 Q120 132 136 112 M110 130 Q120 146 130 130" fill="none" stroke={gold} strokeWidth="1.8" />
          {hands([[70, 250], [170, 250]])}
          {bridal && <g fill="#7a2a12">{([[66, 252], [70, 256], [174, 252], [170, 256]] as const).map(([x, y]) => <circle key={`${x}${y}`} cx={x} cy={y} r="1.6" />)}</g>}
          {garment('M88 72 Q88 26 120 24 Q152 26 152 72 L180 186 Q120 206 60 186 Z', 'mayafi', bridal ? '#f6f1e7' : F, 0.92)}
          {folds(['M100 80 L76 184', 'M140 80 L164 184'])}
          <ellipse cx="120" cy="72" rx="15" ry="18" fill={skin} />
        </>
      )
      break
  }

  return (
    <svg className={className} viewBox="0 0 240 520" role="img" aria-label={title}>
      <defs>
        <FabricPattern id={fab} pattern={fabric.pattern} colors={fabric.colors} scale={0.55} />
        <linearGradient id={shade} x1="0" x2="1" y1="0" y2="0.15">
          <stop offset="0" stopColor="#000" stopOpacity="0.38" />
          <stop offset="0.38" stopColor="#fff" stopOpacity="0.1" />
          <stop offset="0.62" stopColor="#000" stopOpacity="0.05" />
          <stop offset="1" stopColor="#000" stopOpacity="0.42" />
        </linearGradient>
      </defs>
      <ellipse cx="120" cy="500" rx="78" ry="9" fill="rgba(0,0,0,0.35)" />
      {body}
    </svg>
  )
}
