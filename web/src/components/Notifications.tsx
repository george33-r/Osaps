import { useEffect, useRef, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'
import { api, type AppNotification } from '../api'
import { formatDate } from '../lib/format'
import { useSession } from '../lib/session'

/** The bell: offers, payments, dispatch and aṣọ-ẹbí news, checked every 30 seconds. */
export function NotificationBell() {
  const { user } = useSession()
  const { pathname } = useLocation()
  const [list, setList] = useState<AppNotification[]>([])
  const [unread, setUnread] = useState(0)
  const [open, setOpen] = useState(false)
  const box = useRef<HTMLDivElement>(null)

  useEffect(() => {
    if (!user) return
    let live = true
    const load = () => api.notifications().then((r) => { if (live) { setList(r.data); setUnread(r.unread) } }).catch(() => undefined)
    load()
    const t = window.setInterval(load, 30_000)
    return () => { live = false; window.clearInterval(t) }
  }, [user, pathname])

  useEffect(() => {
    if (!open) return
    const close = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node)) setOpen(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [open])
  useEffect(() => setOpen(false), [pathname])

  if (!user) return null
  return (
    <div className="bell" ref={box}>
      <button className="icon-btn" aria-expanded={open} aria-label={unread ? `Notifications, ${unread} new` : 'Notifications'}
        onClick={async () => {
          setOpen((o) => !o)
          if (!open && unread) { setUnread(0); await api.markNotificationsRead().catch(() => undefined); setList((l) => l.map((n) => ({ ...n, read: true }))) }
        }}>
        <svg viewBox="0 0 24 24" width="21" height="21" aria-hidden fill="none" stroke="currentColor" strokeWidth="1.6" strokeLinejoin="round">
          <path d="M6 16V11a6 6 0 1 1 12 0v5l1.5 2h-15L6 16Z" /><path d="M10 20a2 2 0 0 0 4 0" />
        </svg>
        {unread > 0 && <span className="cart-icon__count">{unread > 9 ? '9+' : unread}</span>}
      </button>
      {open && (
        <div className="bell__panel" role="dialog" aria-label="Notifications">
          <p className="bell__title">Updates</p>
          {list.length === 0 ? <p className="muted bell__empty">Nothing yet. Offers, payments and deliveries show up here.</p> : (
            <ul className="bell__list">
              {list.map((n) => (
                <li key={n.id} className={n.read ? '' : 'is-new'}>
                  <Link to={n.link ?? '/'} onClick={() => setOpen(false)}>
                    <strong>{n.title}</strong>
                    {n.body && <span>{n.body}</span>}
                    {n.at && <small>{formatDate(n.at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</small>}
                  </Link>
                </li>
              ))}
            </ul>
          )}
          <Link to="/account" className="text-link bell__settings" onClick={() => setOpen(false)}>Get these on WhatsApp</Link>
        </div>
      )}
    </div>
  )
}
