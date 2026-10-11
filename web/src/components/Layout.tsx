import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '../lib/session'

function PreviewBanner() {
  const { user, refresh } = useSession()
  const navigate = useNavigate()
  if (import.meta.env.MODE !== 'preview') return null
  const switchTo = async (role: 'customer' | 'tailor' | null) => {
    if (import.meta.env.MODE === 'preview') {
      const { previewSwitchUser } = await import('../api/preview')
      previewSwitchUser(role)
    }
    await refresh()
    navigate(role === 'tailor' ? '/tailor/inbox' : role === 'customer' ? '/requests' : '/')
  }
  return (
    <div className="preview-banner" role="note">
      <span>Design preview with demonstration data. Nothing here is saved to a server.</span>
      <span className="preview-banner__switch">
        View as:
        <button aria-pressed={user?.role === 'customer'} onClick={() => switchTo('customer')}>Customer</button>
        <button aria-pressed={user?.role === 'tailor'} onClick={() => switchTo('tailor')}>Tailor</button>
        <button aria-pressed={!user} onClick={() => switchTo(null)}>Visitor</button>
      </span>
    </div>
  )
}

export function Logo({ large = false }: { large?: boolean }) {
  return (
    <Link to="/" className={`logo ${large ? 'logo--large' : ''}`} aria-label="OSAPS home">
      <picture>
        <source srcSet={`${import.meta.env.BASE_URL}brand/osaps-logo-night.webp`} type="image/webp" />
        <img src={`${import.meta.env.BASE_URL}brand/osaps-logo-night.png`} alt="OSAPS" width={243} height={160} />
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
  const location = useLocation()
  const navigate = useNavigate()

  useEffect(() => {
    setMenu(false)
    setSearch(false)
  }, [location.pathname, location.search])

  useEffect(() => {
    const onScroll = () => setScrolled(window.scrollY > 8)
    onScroll()
    window.addEventListener('scroll', onScroll, { passive: true })
    return () => window.removeEventListener('scroll', onScroll)
  }, [])

  const primary = [
    { to: '/fabrics', label: 'Fabrics' },
    { to: '/tailors', label: 'Tailors' },
    ...(user?.role === 'tailor' ? [{ to: '/tailor/inbox', label: 'Job inbox' }] : [{ to: '/requests/new', label: 'Create an outfit' }]),
  ]
  const account = user
    ? [
        ...(user.role === 'customer' ? [{ to: '/requests', label: 'My requests' }] : []),
        ...(user.role === 'tailor' ? [{ to: '/tailor/profile', label: 'My profile' }] : []),
        { to: '/orders', label: 'Orders' },
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
            {account.map((l) => (
              <li key={l.to}>
                <NavLink
                  to={l.to}
                  className={({ isActive }) => `site-nav__link site-nav__link--quiet ${isActive && !location.pathname.endsWith('/new') ? 'active' : 'not-active'}`}
                >
                  {l.label}
                </NavLink>
              </li>
            ))}
            <li>
              {user ? (
                <button
                  className="site-nav__link site-nav__link--quiet site-nav__button"
                  onClick={async () => {
                    await signOut()
                    navigate('/')
                  }}
                >
                  Sign out
                </button>
              ) : (
                <NavLink to="/signin" className="site-nav__link site-nav__link--quiet">Sign in</NavLink>
              )}
            </li>
          </ul>
        </nav>
        <div className="site-header__tools">
          <button className="icon-btn" onClick={() => setSearch((s) => !s)} aria-expanded={search} aria-label="Search fabric">
            <SearchIcon />
          </button>
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
            <Link to="/fabrics">Fabrics</Link>
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
      </div>
    </footer>
  )
}

export function Layout() {
  const { pathname } = useLocation()
  return (
    <>
      <a href="#main" className="skip-link">Skip to content</a>
      <PreviewBanner />
      <Header />
      <main id="main" className={`site-main ${pathname === '/' ? 'site-main--flush' : ''}`}>
        <Outlet />
      </main>
      <Footer />
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
