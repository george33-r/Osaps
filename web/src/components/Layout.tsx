import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '../lib/session'
import { StylistFab } from './StylistFab'
import { CartIcon } from './ShopKit'
import { NotificationBell } from './Notifications'
import { CurrencyPicker } from '../lib/fx'

function PreviewBanner() {
  const { user, refresh } = useSession()
  const navigate = useNavigate()
  if (import.meta.env.MODE !== 'preview') return null
  const switchTo = async (role: 'customer' | 'tailor' | 'fabric_vendor' | null) => {
    if (import.meta.env.MODE === 'preview') {
      const { previewSwitchUser } = await import('../api/preview')
      previewSwitchUser(role)
    }
    await refresh()
    navigate(role === 'tailor' ? '/tailor/inbox' : role === 'fabric_vendor' ? '/vendor' : role === 'customer' ? '/fabrics' : '/')
  }
  return (
    <div className="preview-banner" role="note">
      <span>Design preview with demonstration data. Nothing here is saved to a server.</span>
      <span className="preview-banner__switch">
        View as:
        <button aria-pressed={user?.role === 'customer'} onClick={() => switchTo('customer')}>Customer</button>
        <button aria-pressed={user?.role === 'tailor'} onClick={() => switchTo('tailor')}>Tailor</button>
        <button aria-pressed={user?.role === 'fabric_vendor'} onClick={() => switchTo('fabric_vendor')}>Vendor</button>
        <button aria-pressed={!user} onClick={() => switchTo(null)}>Visitor</button>
      </span>
    </div>
  )
}

export function Logo({ large = false }: { large?: boolean }) {
  return (
    <Link to="/" className={`logo ${large ? 'logo--large' : ''}`} aria-label="OSAPS home">
      <picture>
        <source srcSet={`${import.meta.env.BASE_URL}brand/${large ? 'osaps-logo-large' : 'osaps-logo'}.webp`} type="image/webp" />
        <img src={`${import.meta.env.BASE_URL}brand/osaps-logo.png`} alt="OSAPS" width={243} height={160} />
      </picture>
    </Link>
  )
}

function SearchIcon() {
  return (
    <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden fill="none" stroke="currentColor" strokeWidth="1.6">
      <circle cx="10.5" cy="10.5" r="6.5" />
      <path d="m15.5 15.5 5 5" />
    </svg>
  )
}

function SearchPanel({ onClose }: { onClose: () => void }) {
  const navigate = useNavigate()
  const ref = useRef<HTMLInputElement>(null)
  useEffect(() => ref.current?.focus(), [])
  const submit = (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const q = String(new FormData(e.currentTarget).get('q') ?? '').trim()
    if (q) {
      navigate(`/fabrics?ask=${encodeURIComponent(q)}`)
      onClose()
    }
  }
  return (
    <div className="search-panel" role="dialog" aria-label="Search fabric" onKeyDown={(e) => e.key === 'Escape' && onClose()}>
      <form className="search-panel__form" onSubmit={submit} role="search">
        <label htmlFor="global-ask" className="label">Describe what you are looking for</label>
        <div className="search-panel__row">
          <input ref={ref} id="global-ask" name="q" className="search-panel__input" placeholder="A wine-coloured Ankara for a wedding guest" autoComplete="off" />
          <button className="btn btn--primary" type="submit">Search</button>
        </div>
      </form>
      <button className="search-panel__close" onClick={onClose} aria-label="Close search">×</button>
    </div>
  )
}

function Header() {
  const { user, signOut } = useSession()
  const [menu, setMenu] = useState(false)
  const [search, setSearch] = useState(false)
  const [scrolled, setScrolled] = useState(false)
  const [acct, setAcct] = useState(false)
  const acctRef = useRef<HTMLLIElement>(null)
  useEffect(() => {
    if (!acct) return
    const close = (e: MouseEvent) => { if (!acctRef.current?.contains(e.target as Node)) setAcct(false) }
    document.addEventListener('mousedown', close)
    return () => document.removeEventListener('mousedown', close)
  }, [acct])
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    setMenu(false)
    setSearch(false)
    setAcct(false)
  }, [location.pathname, location.search])

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  const primary = [
    { to: '/fabrics', label: 'Shop fabric' },
    { to: '/live', label: 'Live' },
    { to: '/tailors', label: 'Tailors' },
    ...(user?.role === 'tailor'
      ? [{ to: '/tailor/inbox', label: 'Job inbox' }]
      : user?.role === 'fabric_vendor'
        ? [{ to: '/vendor', label: 'My shop' }]
        : [{ to: '/requests/new', label: 'Get it made' }]),
  ]
  const account = user
    ? [
        ...(user.role === 'customer' ? [{ to: '/requests', label: 'My requests' }] : []),
        ...(user.role === 'tailor' ? [{ to: '/tailor/profile', label: 'My profile' }] : []),
        { to: '/offers', label: 'My offers' },
        { to: '/aso-ebi', label: 'Aṣọ-ẹbí' },
        { to: '/shop/orders', label: 'Fabric orders' },
        { to: '/orders', label: 'Tailoring orders' },
        { to: '/account', label: 'Account' },
      ]
    : []

  return (
    <header className={`site-header ${scrolled ? 'is-scrolled' : ''} ${location.pathname === '/' ? 'site-header--over' : ''}`}>
      <div className="site-header__inner">
        <Logo />
        <nav className={`site-nav ${menu ? 'is-open' : ''}`} id="site-nav" aria-label="Main">
          <ul className="site-nav__primary">
            {primary.map((l) => (
              <li key={l.to}>
                <NavLink to={l.to} end={l.to === '/requests/new'} className="site-nav__link">{l.label}</NavLink>
              </li>
            ))}
            <li>
              <NavLink to="/stylist" className="site-nav__link site-nav__link--stylist">AI stylist</NavLink>
            </li>
          </ul>
          <ul className="site-nav__account">
            {user ? (
              <li className={`acct ${acct ? 'is-open' : ''}`} ref={acctRef}>
                <button type="button" className="site-nav__link site-nav__link--quiet acct__toggle" aria-expanded={acct} onClick={() => setAcct((a) => !a)}>
                  {user.name.split(' ')[0]} <span aria-hidden>▾</span>
                </button>
                <ul className="acct__menu">
                  {account.map((l) => (
                    <li key={l.to}>
                      <NavLink to={l.to} className={({ isActive }) => `site-nav__link site-nav__link--quiet ${isActive && !location.pathname.endsWith('/new') ? 'active' : 'not-active'}`}>{l.label}</NavLink>
                    </li>
                  ))}
                  <li>
                    <button className="site-nav__link site-nav__link--quiet site-nav__button" onClick={async () => { await signOut(); navigate('/') }}>Sign out</button>
                  </li>
                </ul>
              </li>
            ) : (
              <li><NavLink to="/signin" className="site-nav__link site-nav__link--quiet">Sign in</NavLink></li>
            )}
          </ul>
        </nav>
        <div className="site-header__tools">
          <button className="icon-btn" onClick={() => setSearch((s) => !s)} aria-expanded={search} aria-label="Search fabric">
            <SearchIcon />
          </button>
          <NotificationBell />
          <CartIcon />
          <button className="icon-btn site-header__menu" aria-expanded={menu} aria-controls="site-nav" onClick={() => setMenu((m) => !m)}>
            <span className="sr-only">Menu</span>
            <span className="burger" aria-hidden />
          </button>
        </div>
      </div>
      {search && <SearchPanel onClose={() => setSearch(false)} />}
    </header>
  )
}

function Footer() {
  const { meta } = useSession()
  return (
    <footer className="site-footer">
      <div className="site-footer__inner">
        <div className="site-footer__brand">
          <Logo large />
          <p className="site-footer__statement">Discover the fabric. Imagine the outfit. Meet your maker. Wear your story.</p>
          <p className="site-footer__made">Made in Nigeria, for everyone who dresses for the occasion.</p>
        </div>
        <nav className="site-footer__cols" aria-label="Footer">
          <div>
            <p className="label">Discover</p>
            <Link to="/fabrics">Shop fabric</Link>
            <Link to="/live">Live from the market</Link>
            <Link to="/aso-ebi">Aṣọ-ẹbí group orders</Link>
            <Link to="/tailors">Tailors</Link>
            <Link to="/stylist">AI stylist</Link>
          </div>
          <div>
            <p className="label">Create</p>
            <Link to="/requests/new">Post a tailoring request</Link>
            <Link to="/#process">How it works</Link>
          </div>
          <div>
            <p className="label">For makers</p>
            <Link to="/register?role=tailor">Join as a tailor</Link>
            <Link to="/register?role=fabric_vendor">Sell fabric on OSAPS</Link>
          </div>
          {(meta?.support.email || meta?.support.whatsapp) && (
            <div>
              <p className="label">Support</p>
              {meta.support.email && <a href={`mailto:${meta.support.email}`}>{meta.support.email}</a>}
              {meta.support.whatsapp && <a href={`https://wa.me/${meta.support.whatsapp.replace(/\D/g, '')}`}>WhatsApp support</a>}
            </div>
          )}
        </nav>
      </div>
      <div className="site-footer__base">
        <span>© {new Date().getFullYear()} OSAPS</span>
        <CurrencyPicker compact />
      </div>
    </footer>
  )
}

/**
 * Pointer-driven depth, delegated from the document so every card gets it for free:
 * [data-tilt] elements lean toward the pointer with a moving sheen, and .btn--primary / [data-magnetic]
 * elements drift a little toward it. Off for touch screens and for reduced motion.
 */
function usePointerDepth() {
  useEffect(() => {
    const fine = window.matchMedia('(hover: hover) and (pointer: fine)').matches
    const still = window.matchMedia('(prefers-reduced-motion: reduce)').matches
    if (!fine || still) return
    let tilted: HTMLElement | null = null
    let pulled: HTMLElement | null = null
    const reset = (el: HTMLElement | null, kind: 'tilt' | 'pull') => {
      if (!el) return
      if (kind === 'tilt') { el.style.removeProperty('--rx'); el.style.removeProperty('--ry'); el.classList.remove('is-tilting') }
      else { el.style.removeProperty('--mx'); el.style.removeProperty('--my') }
    }
    const onMove = (e: PointerEvent) => {
      const t = e.target as Element | null
      const tilt = t?.closest<HTMLElement>('[data-tilt]') ?? null
      if (tilt !== tilted) { reset(tilted, 'tilt'); tilted = tilt }
      if (tilt) {
        const r = tilt.getBoundingClientRect()
        const x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height
        tilt.style.setProperty('--rx', `${(0.5 - y) * 10}deg`)
        tilt.style.setProperty('--ry', `${(x - 0.5) * 12}deg`)
        tilt.style.setProperty('--gx', `${x * 100}%`)
        tilt.style.setProperty('--gy', `${y * 100}%`)
        tilt.classList.add('is-tilting')
      }
      const pull = t?.closest<HTMLElement>('.btn--primary, [data-magnetic]') ?? null
      if (pull !== pulled) { reset(pulled, 'pull'); pulled = pull }
      if (pull) {
        const r = pull.getBoundingClientRect()
        pull.style.setProperty('--mx', `${(e.clientX - r.left - r.width / 2) * 0.22}px`)
        pull.style.setProperty('--my', `${(e.clientY - r.top - r.height / 2) * 0.32}px`)
      }
    }
    const onLeave = () => { reset(tilted, 'tilt'); reset(pulled, 'pull'); tilted = pulled = null }
    document.addEventListener('pointermove', onMove, { passive: true })
    document.documentElement.addEventListener('pointerleave', onLeave)
    return () => {
      document.removeEventListener('pointermove', onMove)
      document.documentElement.removeEventListener('pointerleave', onLeave)
    }
  }, [])
}

export function Layout() {
  const { pathname } = useLocation()
  usePointerDepth()
  return (
    <>
      <a href="#main" className="skip-link">Skip to content</a>
      <PreviewBanner />
      <Header />
      <main id="main" className={`site-main ${pathname === '/' ? 'site-main--flush' : ''}`}>
        <Outlet />
      </main>
      <Footer />
      <StylistFab />
    </>
  )
}

export function RequireRole({ role, children }: { role?: 'customer' | 'tailor'; children: ReactNode }) {
  const { user, ready } = useSession()
  const location = useLocation()
  if (!ready) return <div className="page" aria-busy="true" />
  if (!user) return <Navigate to={`/signin?next=${encodeURIComponent(location.pathname + location.search)}`} replace />
  if (role && user.role !== role) {
    return (
      <div className="page page--narrow">
        <h1 className="page-header__title">This area is for {role === 'tailor' ? 'tailors' : 'customers'}</h1>
        <p>You are signed in as a {user.role.replace('_', ' ')}.</p>
      </div>
    )
  }
  return <>{children}</>
}
