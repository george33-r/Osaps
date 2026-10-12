import { Link } from 'react-router-dom'
import { api, type LiveSession } from '../api'
import { Countdown } from '../components/ShopKit'
import { FabricCard } from '../components/cards'
import { EmptyState, ErrorState, PageHeader, Skeleton } from '../components/ui'
import { formatDate } from '../lib/format'
import { useAsync } from '../lib/useAsync'

const PLATFORM: Record<LiveSession['platform'], string> = { tiktok: 'TikTok', instagram: 'Instagram', youtube: 'YouTube', facebook: 'Facebook' }

function Session({ s }: { s: LiveSession }) {
  return (
    <article className={`live ${s.is_live ? 'is-live' : ''}`}>
      <header className="live__head">
        {s.is_live ? <span className="live__badge"><span className="pulse pulse--red" aria-hidden /> LIVE</span> : <span className="live__soon">{formatDate(s.starts_at, { weekday: 'short', hour: '2-digit', minute: '2-digit' })} · <Countdown iso={s.starts_at} prefix="starts" /></span>}
        <span className="live__where">{s.vendor.business_name}{s.vendor.market ? `, ${s.vendor.market}` : ''}</span>
      </header>
      <h2 className="live__title">{s.title}</h2>
      {s.youtube_id && s.is_live ? (
        <div className="live__player">
          <iframe src={`https://www.youtube-nocookie.com/embed/${s.youtube_id}?autoplay=0`} title={s.title} allow="encrypted-media; picture-in-picture" allowFullScreen loading="lazy" />
        </div>
      ) : (
        <a className="btn btn--primary live__watch" href={s.url} target="_blank" rel="noopener noreferrer">{s.is_live ? `Watch on ${PLATFORM[s.platform]}` : `Follow on ${PLATFORM[s.platform]}`}</a>
      )}
      {s.fabrics.length > 0 && (
        <>
          <p className="label">On the table — name your price</p>
          <div className="rail">{s.fabrics.map((f) => <FabricCard key={f.id} fabric={f} />)}</div>
        </>
      )}
    </article>
  )
}

export function LivePage() {
  const res = useAsync(() => api.live(), [])
  return (
    <div className="page">
      <PageHeader label="Live from the market" title="Shop the stalls, live" intro="Vendors unroll their newest bolts on camera. See something you like? Open it here and name your price." />
      {res.status === 'error' ? <ErrorState error={res.error} onRetry={res.reload} /> : !res.data ? <Skeleton lines={5} /> : res.data.length === 0 ? (
        <EmptyState title="Nobody is live right now" action={<Link className="btn btn--secondary" to="/fabrics">Browse fabric</Link>}>Vendors announce sessions here. Check back this evening.</EmptyState>
      ) : <div className="lives">{res.data.map((s) => <Session key={s.id} s={s} />)}</div>}
    </div>
  )
}

/** Homepage strip: only when someone is live. */
export function LiveStrip() {
  const res = useAsync(() => api.live(), [])
  const now = res.data?.filter((s) => s.is_live) ?? []
  if (now.length === 0) return null
  return (
    <Link to="/live" className="live-strip">
      <span className="live__badge"><span className="pulse pulse--red" aria-hidden /> LIVE</span>
      <span><strong>{now[0]!.vendor.business_name}</strong> is live: {now[0]!.title}{now.length > 1 ? ` + ${now.length - 1} more` : ''}</span>
      <span className="live-strip__cta">Watch →</span>
    </Link>
  )
}
