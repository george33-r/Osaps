/**
 * DESIGN-REVIEW ADAPTER — notifications, account, measurements, live market and try-on (preview builds only).
 */
import type { Api } from './client'
import type { AppNotification, Fabric, LiveSession, Measurements, User } from './types'

interface Ctx {
  fabrics: Fabric[]
  user: () => User | null
  setUser: (u: User) => void
  vendorSlugOf: (u: User) => string | null
  delay: <T>(v: T) => Promise<T>
  fail: (message: string, status: number, code: string, fieldErrors?: Record<string, string[]>) => never
}

const FIELDS: [string, string, number, number][] = [
  ['neck', 'Neck', 25, 60], ['shoulder', 'Shoulder width', 30, 70], ['chest', 'Chest / bust', 60, 180], ['under_bust', 'Under bust', 55, 160],
  ['waist', 'Waist', 45, 180], ['hip', 'Hip', 60, 200], ['sleeve', 'Sleeve length', 20, 90], ['round_sleeve', 'Round sleeve (bicep)', 18, 60],
  ['top_length', 'Top length', 40, 130], ['gown_length', 'Gown / kaftan length', 70, 180], ['trouser_length', 'Trouser length', 60, 130],
  ['thigh', 'Thigh', 35, 90], ['ankle', 'Ankle', 15, 40], ['height', 'Height', 100, 230],
]

export function makePreviewGrowth(ctx: Ctx) {
  const { delay, fail } = ctx
  const notes = new Map<number, AppNotification[]>()
  const measures = new Map<number, Measurements>()
  let seq = 1
  const at = (m: number) => new Date(Date.now() + m * 60_000).toISOString()
  // Two demo sessions: one live now, one later today.
  const lives: LiveSession[] = [
    { id: 1, title: 'New Ankara drop: 40 prints under ₦5k', platform: 'tiktok', url: 'https://www.tiktok.com/@osaps', youtube_id: null, starts_at: at(-20), ends_at: at(70), is_live: true,
      vendor: { business_name: 'OSAPS Store', market: null, city: 'Lagos' }, fabrics: [] },
    { id: 2, title: 'Aṣọ-òkè for December weddings', platform: 'instagram', url: 'https://www.instagram.com/osaps', youtube_id: null, starts_at: at(240), ends_at: at(330), is_live: false,
      vendor: { business_name: 'OSAPS Store', market: null, city: 'Lagos' }, fabrics: [] },
  ]
  const fill = () => {
    lives[0]!.fabrics = ctx.fabrics.filter((f) => f.category === 'ankara').slice(0, 4)
    lives[1]!.fabrics = ctx.fabrics.filter((f) => f.category === 'aso_oke' || f.category === 'adire').slice(0, 3)
  }
  const me = () => ctx.user() ?? fail('Please sign in.', 401, 'unauthenticated')

  const notify = (userId: number, title: string, body: string | null, link: string) => {
    if (!userId) return
    const list = notes.get(userId) ?? []
    list.unshift({ id: seq++, kind: 'update', title, body, link, read: false, at: new Date().toISOString() })
    notes.set(userId, list.slice(0, 30))
  }

  const api: Pick<Api, 'updateMe' | 'notifications' | 'markNotificationsRead' | 'measurements' | 'saveMeasurements' | 'live' | 'myLive' | 'createLive' | 'deleteLive' | 'tryOn'> = {
    updateMe: async (input) => {
      const u = me()
      if (input.whatsapp_opt_in && !(input.phone ?? u.phone)) fail('Add your phone number to get WhatsApp updates.', 422, 'validation_failed', { phone: ['Add your WhatsApp number.'] })
      const next: User = { ...u, ...input, name: input.name ?? u.name, phone: input.phone ?? u.phone, city: input.city ?? u.city }
      ctx.setUser(next)
      return delay(next)
    },
    notifications: () => { const list = notes.get(me().id) ?? []; return delay({ data: list, unread: list.filter((n) => !n.read).length }) },
    markNotificationsRead: async (ids) => { for (const n of notes.get(me().id) ?? []) if (!ids || ids.includes(n.id)) n.read = true },
    measurements: () => delay(measures.get(me().id) ?? { unit: 'cm', values: {}, notes: null, share_with_tailors: true, updated_at: null, fields: FIELDS.map(([key, label]) => ({ key, label })) }),
    saveMeasurements: async (input) => {
      const u = me()
      const values: Record<string, number> = {}
      for (const [k, raw] of Object.entries(input.values)) {
        if (raw === '' || raw === null || raw === undefined) continue
        const f = FIELDS.find((x) => x[0] === k) ?? fail('Unknown measurement.', 422, 'measurement_unknown')
        const n = Number(raw)
        if (!Number.isFinite(n)) fail(`${f[1]} must be a number.`, 422, 'measurement_invalid')
        const cm = Math.round(n * (input.unit === 'in' ? 2.54 : 1) * 10) / 10
        if (cm < f[2] || cm > f[3]) fail(`${f[1]} looks unusual. Check it is in ${input.unit === 'in' ? 'inches' : 'centimetres'}.`, 422, 'measurement_out_of_range')
        values[k] = cm
      }
      const m: Measurements = { unit: input.unit, values, notes: input.notes ?? null, share_with_tailors: input.share_with_tailors, updated_at: new Date().toISOString(), fields: FIELDS.map(([key, label]) => ({ key, label })) }
      measures.set(u.id, m)
      return delay(m)
    },
    live: () => { fill(); return delay(lives) },
    myLive: () => { fill(); const slug = ctx.vendorSlugOf(me()); return delay(lives.filter((l) => slug && l.vendor.business_name === 'OSAPS Store')) },
    createLive: async (input) => {
      const hosts: Record<string, string[]> = { tiktok: ['tiktok.com', 'www.tiktok.com', 'vm.tiktok.com'], instagram: ['instagram.com', 'www.instagram.com'], youtube: ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be'], facebook: ['facebook.com', 'www.facebook.com', 'fb.watch'] }
      let ok = false
      try { const u = new URL(input.url); ok = u.protocol === 'https:' && (hosts[input.platform] ?? []).includes(u.hostname) && !u.username && !u.port } catch { ok = false }
      if (!ok) fail(`Paste the https link to your ${input.platform} live or profile.`, 422, 'url_invalid')
      const start = new Date(input.starts_at)
      const end = input.ends_at ? new Date(input.ends_at) : new Date(start.getTime() + 2 * 3_600_000)
      const yt = input.platform === 'youtube' ? (input.url.match(/(?:v=|youtu\.be\/|\/live\/)([A-Za-z0-9_-]{11})/)?.[1] ?? null) : null
      const s: LiveSession = { id: seq++ + 100, title: input.title, platform: input.platform, url: input.url, youtube_id: yt, starts_at: start.toISOString(), ends_at: end.toISOString(),
        is_live: start.getTime() <= Date.now() && end.getTime() > Date.now(), vendor: { business_name: 'OSAPS Store', market: null, city: 'Lagos' },
        fabrics: ctx.fabrics.filter((f) => input.fabric_ids?.includes(f.id) && f.vendor?.slug === 'osaps-store') }
      lives.push(s)
      lives.sort((a, b) => Date.parse(a.starts_at) - Date.parse(b.starts_at))
      return delay(s)
    },
    deleteLive: async (id) => { const i = lives.findIndex((l) => l.id === id); if (i >= 0) lives.splice(i, 1) },
    // No AI in the preview: the photo comes back with the fabric's colours blended over it, clearly labelled.
    tryOn: async (photo, fabricId) => {
      me()
      const f = ctx.fabrics.find((x) => x.id === fabricId) ?? fail('Not found.', 404, 'not_found')
      const img = await new Promise<HTMLImageElement>((res, rej) => { const i = new Image(); i.onload = () => res(i); i.onerror = rej; i.src = URL.createObjectURL(photo) })
      const c = document.createElement('canvas')
      const w = Math.min(720, img.width), h = Math.round((img.height / img.width) * w)
      c.width = w; c.height = h
      const g = c.getContext('2d')!
      g.drawImage(img, 0, 0, w, h)
      g.globalCompositeOperation = 'soft-light'
      const grad = g.createLinearGradient(0, h * 0.35, 0, h)
      grad.addColorStop(0, 'rgba(0,0,0,0)')
      grad.addColorStop(0.25, f.colors[0] === 'indigo' ? '#3b3fb6' : '#c0392b')
      grad.addColorStop(1, '#f2be45')
      g.fillStyle = grad
      g.fillRect(0, h * 0.35, w, h)
      g.globalCompositeOperation = 'source-over'
      g.fillStyle = 'rgba(13,22,64,0.75)'
      g.fillRect(0, h - 34, w, 34)
      g.fillStyle = '#f6f1e7'
      g.font = '600 14px system-ui'
      g.fillText('DESIGN PREVIEW — no AI. The live site draws the real outfit.', 12, h - 12)
      return delay({ image: c.toDataURL('image/jpeg', 0.85), notice: 'Design preview: colours blended over your photo. On the live site an image AI draws the outfit.', left_today: 4 })
    },
  }
  return { api, notify }
}
