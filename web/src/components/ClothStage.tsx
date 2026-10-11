import { useEffect, useRef, useState } from 'react'
import { DrapedCloth, PatternTile } from './Swatch'

/**
 * Live cloth: a catalogue listing's real pattern and colours rendered on a hanging, moving
 * piece of fabric in WebGL2. It lifts under the pointer and ripples where you tap.
 * An illustration, never a photograph — callers caption it with the listing it was drawn from.
 * Falls back to the static DrapedCloth SVG when WebGL2 is unavailable, and holds still when the
 * visitor prefers reduced motion.
 */

export interface ClothFabric {
  id: number | string
  pattern: string | null
  colors: string[]
  category: string
}

const SHEEN: Record<string, number> = { aso_oke: 1, george: 0.9, damask: 0.8, lace: 0.55, senator: 0.35, ankara: 0.22, adire: 0.15 }

const VERT = `#version 300 es
precision highp float;
in vec2 aUv;
uniform float uTime, uHover, uIntro;
uniform vec2 uRes, uPointer;
uniform vec4 uRip[6];
out vec2 vUv;
out vec3 vPos;
out float vH;
float folds(vec2 p, float t) {
  float h = sin(p.x * 26.0 + sin(p.y * 2.0 + t * 0.4) * 1.6 + t * 0.3) * 0.6;
  h += sin(p.x * 11.0 - t * 0.25 + p.y * 1.3) * 0.9;
  h += sin(p.x * 53.0 + p.y * 4.0 - t * 0.5) * 0.12;
  return h;
}
void main() {
  vec2 p = aUv;
  float aspect = uRes.x / uRes.y;
  float hang = mix(0.45, 1.0, smoothstep(0.0, 1.0, p.y));
  float h = folds(vec2(p.x * aspect, p.y), uTime) * hang;
  vec2 d = (p - uPointer) * vec2(aspect, 1.0);
  h += exp(-dot(d, d) * 10.0) * 0.9 * uHover;
  for (int i = 0; i < 6; i++) {
    vec4 r = uRip[i];
    if (r.w <= 0.0) continue;
    float age = uTime - r.z;
    float dist = length((p - r.xy) * vec2(aspect, 1.0));
    float front = 1.0 - smoothstep(age * 0.9 - 0.06, age * 0.9 + 0.02, dist);
    h += sin(dist * 30.0 - age * 11.0) * exp(-age * 1.5) * exp(-dist * 3.5) * r.w * front;
  }
  float edge = uIntro * 1.3 - 0.15;
  float billow = smoothstep(edge - 0.35, edge, p.y) * (1.0 - uIntro);
  h += billow * 2.4;
  vH = h;
  vUv = p;
  vPos = vec3(p.x * aspect, p.y, h * 0.03);
  gl_Position = vec4(p.x * 2.0 - 1.0 + h * 0.006, 1.0 - p.y * 2.0, 0.0, 1.0);
}`

const FRAG = `#version 300 es
precision highp float;
in vec2 vUv;
in vec3 vPos;
in float vH;
uniform sampler2D uTexA, uTexB;
uniform vec2 uRepA, uRepB, uRes;
uniform float uSheenA, uSheenB, uMix, uIntro, uTime;
out vec4 o;
float hash(vec2 p) { return fract(sin(dot(p, vec2(12.9898, 78.233))) * 43758.5453); }
void main() {
  vec3 n = normalize(cross(dFdx(vPos), dFdy(vPos)));
  if (n.z < 0.0) n = -n;
  vec2 uv = vUv + vec2(vH * 0.006, 0.0);
  vec3 a = texture(uTexA, uv * uRepA).rgb;
  vec3 b = texture(uTexB, uv * uRepB).rgb;
  float wipe = 1.0 - smoothstep(uMix * 1.5 - 0.3, uMix * 1.5 - 0.05, vUv.x * 0.8 + (1.0 - vUv.y) * 0.2 + vH * 0.04);
  vec3 tex = mix(a, b, wipe);
  float sheen = mix(uSheenA, uSheenB, wipe);
  vec3 L = normalize(vec3(-0.78, -0.32, 0.42));
  float diff = clamp(dot(n, L), 0.0, 1.0);
  vec3 H = normalize(L + vec3(0.0, 0.0, 1.0));
  float spec = pow(max(dot(n, H), 0.0), mix(14.0, 38.0, sheen)) * (0.08 + sheen * 0.45);
  float ao = 0.62 + 0.38 * smoothstep(-1.5, 1.2, vH);
  vec3 col = tex * (0.16 + 1.25 * diff) * ao + spec * vec3(1.0, 0.9, 0.72);
  // Deepen toward the lower left where the headline sits, and under the header.
  vec3 night = vec3(0.051, 0.086, 0.251);
  float shade = smoothstep(0.3, 1.05, (1.0 - vUv.x) * 0.78 + vUv.y * 0.62);
  col = mix(col, night, shade * 0.86);
  col = mix(col, night, (1.0 - smoothstep(0.0, 0.16, vUv.y)) * 0.55);
  col += (hash(vUv * uRes + fract(uTime)) - 0.5) * 0.035;
  float edge = uIntro * 1.3 - 0.15;
  float alpha = 1.0 - smoothstep(edge - 0.04, edge, vUv.y);
  o = vec4(col, alpha);
}`

function compile(gl: WebGL2RenderingContext, type: number, src: string) {
  const s = gl.createShader(type)!
  gl.shaderSource(s, src)
  gl.compileShader(s)
  if (!gl.getShaderParameter(s, gl.COMPILE_STATUS)) throw new Error(gl.getShaderInfoLog(s) ?? 'shader')
  return s
}

function tileToImage(svg: SVGSVGElement): Promise<HTMLImageElement> {
  const src = 'data:image/svg+xml;charset=utf-8,' + encodeURIComponent(new XMLSerializer().serializeToString(svg))
  return new Promise((resolve, reject) => {
    const img = new Image()
    img.onload = () => resolve(img)
    img.onerror = reject
    img.src = src
  })
}

type Ripple = [number, number, number, number]

export function ClothStage({ fabric, className, label }: { fabric: ClothFabric; className?: string; label: string }) {
  const canvasRef = useRef<HTMLCanvasElement>(null)
  const tileRef = useRef<HTMLDivElement>(null)
  const api = useRef<{ setTexture: (img: TexImageSource, tileSize: number, sheen: number) => void } | null>(null)
  const [failed, setFailed] = useState(false)

  // Renderer: set up once.
  useEffect(() => {
    const canvas = canvasRef.current
    if (!canvas) return
    const gl = canvas.getContext('webgl2', { antialias: true, alpha: true, premultipliedAlpha: false })
    if (!gl) {
      setFailed(true)
      return
    }
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches

    let prog: WebGLProgram
    try {
      prog = gl.createProgram()!
      gl.attachShader(prog, compile(gl, gl.VERTEX_SHADER, VERT))
      gl.attachShader(prog, compile(gl, gl.FRAGMENT_SHADER, FRAG))
      gl.linkProgram(prog)
      if (!gl.getProgramParameter(prog, gl.LINK_STATUS)) throw new Error(gl.getProgramInfoLog(prog) ?? 'link')
    } catch (err) {
      if (import.meta.env.DEV) console.warn('ClothStage: falling back to SVG', err)
      setFailed(true)
      return
    }
    gl.useProgram(prog)

    // A grid of vertices; folds are computed in the vertex shader.
    const COLS = 180, ROWS = 110
    const verts = new Float32Array((COLS + 1) * (ROWS + 1) * 2)
    for (let y = 0, i = 0; y <= ROWS; y++) for (let x = 0; x <= COLS; x++) { verts[i++] = x / COLS; verts[i++] = y / ROWS }
    const idx = new Uint32Array(COLS * ROWS * 6)
    for (let y = 0, i = 0; y < ROWS; y++) for (let x = 0; x < COLS; x++) {
      const a = y * (COLS + 1) + x, b = a + 1, c = a + COLS + 1, d = c + 1
      idx.set([a, c, b, b, c, d], i)
      i += 6
    }
    gl.bindBuffer(gl.ARRAY_BUFFER, gl.createBuffer())
    gl.bufferData(gl.ARRAY_BUFFER, verts, gl.STATIC_DRAW)
    const loc = gl.getAttribLocation(prog, 'aUv')
    gl.enableVertexAttribArray(loc)
    gl.vertexAttribPointer(loc, 2, gl.FLOAT, false, 0, 0)
    gl.bindBuffer(gl.ELEMENT_ARRAY_BUFFER, gl.createBuffer())
    gl.bufferData(gl.ELEMENT_ARRAY_BUFFER, idx, gl.STATIC_DRAW)
    gl.enable(gl.BLEND)
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA)

    const u = (name: string) => gl.getUniformLocation(prog, name)
    const U = {
      time: u('uTime'), hover: u('uHover'), intro: u('uIntro'), res: u('uRes'), pointer: u('uPointer'), rip: u('uRip'),
      texA: u('uTexA'), texB: u('uTexB'), repA: u('uRepA'), repB: u('uRepB'), sheenA: u('uSheenA'), sheenB: u('uSheenB'), mix: u('uMix'),
    }
    const aniso = gl.getExtension('EXT_texture_filter_anisotropic')
    const makeTex = () => {
      const t = gl.createTexture()!
      gl.bindTexture(gl.TEXTURE_2D, t)
      gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, 1, 1, 0, gl.RGBA, gl.UNSIGNED_BYTE, new Uint8Array([13, 22, 64, 255]))
      return t
    }
    const tex = [makeTex(), makeTex()]
    // tex[cur] is the cloth being shown (B); tex[1 - cur] is the one it is replacing (A).
    const slot = { cur: 0, tileA: 120, tileB: 120, sheenA: 0.3, sheenB: 0.3, mixStart: -1e9, loaded: false }

    let w = 1, h = 1
    const resize = () => {
      const dpr = Math.min(window.devicePixelRatio || 1, 1.75)
      const r = canvas.getBoundingClientRect()
      w = Math.max(1, r.width); h = Math.max(1, r.height)
      canvas.width = Math.round(w * dpr); canvas.height = Math.round(h * dpr)
      gl.viewport(0, 0, canvas.width, canvas.height)
      if (still) frame(performance.now())
    }

    const pointer = { x: 0.7, y: 0.4, tx: 0.7, ty: 0.4, hover: 0, thover: 0, lastRip: 0 }
    const ripples: Ripple[] = []
    const start = performance.now()
    const introMs = still ? 0 : 1700
    let raf = 0, visible = true

    const frame = (now: number) => {
      const t = still ? 14 : (now - start) / 1000
      pointer.x += (pointer.tx - pointer.x) * 0.08
      pointer.y += (pointer.ty - pointer.y) * 0.08
      pointer.hover += (pointer.thover - pointer.hover) * 0.06
      const introT = introMs ? Math.min(1, (now - start) / introMs) : 1
      const intro = 1 - Math.pow(1 - introT, 3)
      const mix = Math.min(1, Math.max(0, (now - slot.mixStart) / 1100))

      gl.clearColor(0, 0, 0, 0)
      gl.clear(gl.COLOR_BUFFER_BIT)
      gl.uniform1f(U.time, t)
      gl.uniform1f(U.hover, pointer.hover)
      gl.uniform1f(U.intro, intro)
      gl.uniform2f(U.res, w, h)
      gl.uniform2f(U.pointer, pointer.x, pointer.y)
      const rip = new Float32Array(24)
      ripples.forEach((r, i) => rip.set([r[0], r[1], (r[2] - start) / 1000, r[3]], i * 4))
      gl.uniform4fv(U.rip, rip)
      gl.activeTexture(gl.TEXTURE0); gl.bindTexture(gl.TEXTURE_2D, tex[1 - slot.cur]!)
      gl.activeTexture(gl.TEXTURE1); gl.bindTexture(gl.TEXTURE_2D, tex[slot.cur]!)
      gl.uniform1i(U.texA, 0); gl.uniform1i(U.texB, 1)
      gl.uniform2f(U.repA, w / slot.tileA, h / slot.tileA)
      gl.uniform2f(U.repB, w / slot.tileB, h / slot.tileB)
      gl.uniform1f(U.sheenA, slot.sheenA); gl.uniform1f(U.sheenB, slot.sheenB)
      gl.uniform1f(U.mix, mix)
      gl.drawElements(gl.TRIANGLES, idx.length, gl.UNSIGNED_INT, 0)
      while (ripples.length && now - ripples[0]![2] > 3500) ripples.shift()
    }
    const loop = (now: number) => {
      frame(now)
      raf = visible && !document.hidden ? requestAnimationFrame(loop) : 0
    }
    const wake = () => { if (!raf && !still && visible && !document.hidden) raf = requestAnimationFrame(loop) }

    api.current = {
      setTexture: (img, tileSize, sheen) => {
        if (slot.loaded) {
          slot.cur = 1 - slot.cur
          slot.tileA = slot.tileB
          slot.sheenA = slot.sheenB
          slot.mixStart = still ? -1e9 : performance.now()
        } else {
          slot.tileA = tileSize
          slot.sheenA = sheen
          slot.mixStart = -1e9
        }
        gl.activeTexture(gl.TEXTURE1)
        gl.bindTexture(gl.TEXTURE_2D, tex[slot.cur]!)
        gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img)
        gl.generateMipmap(gl.TEXTURE_2D)
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.REPEAT)
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.REPEAT)
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
        gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR)
        if (aniso) gl.texParameterf(gl.TEXTURE_2D, aniso.TEXTURE_MAX_ANISOTROPY_EXT, 8)
        slot.tileB = tileSize
        slot.sheenB = sheen
        if (!slot.loaded) {
          // Copy into the other slot too, so the first frame has nothing to wipe from.
          gl.bindTexture(gl.TEXTURE_2D, tex[1 - slot.cur]!)
          gl.texImage2D(gl.TEXTURE_2D, 0, gl.RGBA, gl.RGBA, gl.UNSIGNED_BYTE, img)
          gl.generateMipmap(gl.TEXTURE_2D)
          gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR_MIPMAP_LINEAR)
          slot.loaded = true
        }
        if (still) frame(performance.now())
        else wake()
      },
    }

    const host = canvas.parentElement!
    const onMove = (e: PointerEvent) => {
      const r = canvas.getBoundingClientRect()
      pointer.tx = (e.clientX - r.left) / r.width
      pointer.ty = (e.clientY - r.top) / r.height
      pointer.thover = 1
      const now = performance.now()
      const speed = Math.hypot(e.movementX, e.movementY)
      if (speed > 28 && now - pointer.lastRip > 260) {
        pointer.lastRip = now
        ripples.push([pointer.tx, pointer.ty, now, 0.35])
        if (ripples.length > 6) ripples.shift()
      }
    }
    const onLeave = () => { pointer.thover = 0 }
    const onDown = (e: PointerEvent) => {
      const r = canvas.getBoundingClientRect()
      ripples.push([(e.clientX - r.left) / r.width, (e.clientY - r.top) / r.height, performance.now(), 0.9])
      if (ripples.length > 6) ripples.shift()
    }
    if (!still) {
      host.addEventListener('pointermove', onMove)
      host.addEventListener('pointerleave', onLeave)
      host.addEventListener('pointerdown', onDown)
    }
    const ro = new ResizeObserver(resize)
    ro.observe(canvas)
    resize()
    const io = new IntersectionObserver(([en]) => { visible = !!en?.isIntersecting; wake() })
    io.observe(canvas)
    const onVis = () => wake()
    document.addEventListener('visibilitychange', onVis)
    wake()

    return () => {
      cancelAnimationFrame(raf)
      ro.disconnect(); io.disconnect()
      document.removeEventListener('visibilitychange', onVis)
      host.removeEventListener('pointermove', onMove)
      host.removeEventListener('pointerleave', onLeave)
      host.removeEventListener('pointerdown', onDown)
      api.current = null
    }
  }, [])

  // Texture: redraw whenever the shown fabric changes.
  useEffect(() => {
    if (failed) return
    const svg = tileRef.current?.querySelector('svg')
    if (!svg) return
    let live = true
    const size = Number(svg.getAttribute('data-size')) || 48
    tileToImage(svg).then((img) => {
      if (!live) return
      const c = document.createElement('canvas')
      c.width = c.height = 512
      c.getContext('2d')!.drawImage(img, 0, 0, 512, 512)
      api.current?.setTexture(c, size * 3.6, SHEEN[fabric.category] ?? 0.3)
    }, () => setFailed(true))
    return () => { live = false }
  }, [fabric.id, failed])

  if (failed) {
    return (
      <div className={className}>
        <DrapedCloth pattern={fabric.pattern} colors={fabric.colors} seed={4} scale={1.4} title={label} />
      </div>
    )
  }

  return (
    <div className={className}>
      <canvas ref={canvasRef} role="img" aria-label={label} />
      <div ref={tileRef} hidden>
        <PatternTile pattern={fabric.pattern} colors={fabric.colors} />
      </div>
    </div>
  )
}
