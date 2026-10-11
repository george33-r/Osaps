import { useEffect, useState, type ReactNode } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '../lib/session'
import { ButtonLink } from './ui'

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
      <span>
        Design preview with demonstration data. Nothing here is saved to a server.
      </span>
      <span className="preview-banner__switch">
        View as:
        <button aria-pressed={user?.role === 'customer'} onClick={() => switchTo('customer')}>Customer</button>
        <button aria-pressed={user?.role === 'tailor'} onClick={() => switchTo('tailor')}>Tailor</button>
        <button aria-pressed={!user} onClick={() => switchTo(null)}>Visitor</button>
      </span>
    </div>
  )
}

export function Logo() {
  return (
    <Link to="/" className="logo" aria-label="Osapa home">
      <svg viewBox="0 0 28 28" className="logo__mark" aria-hidden>
        <rect width="28" height="28" rx="3" fill="currentColor" />
        <circle cx="14" cy="14" r="6.6" stroke="var(--gold-500)" strokeWidth="2.4" fill="none" />
      </svg>
      <span className="logo__word">Osapa</span>
    </Link>
  )
}

function Header() {
  const { user, signOut } = useSession()
  const [open, setOpen] = useState(false)
  const location = useLocation()
  const navigate = useNavigate()
  useEffect(() => setOpen(false), [location.pathname])

  const links: { to: string; label: string }[] = [
    { to: '/fabrics', label: 'Fabric' },
    { to: '/tailors', label: 'Tailors' },
  ]
  if (user?.role === 'customer') links.push({ to: '/requests', label: 'My requests' }, { to: '/orders', label: 'Orders' })
  if (user?.role === 'tailor') links.push({ to: '/tailor/inbox', label: 'Job inbox' }, { to: '/orders', label: 'Orders' })
  if (!user) links.push({ to: '/#how', label: 'How it works' })

  return (
    <header className="site-header">
      <div className="site-header__inner">
        <Logo />
        <button className="site-header__toggle" aria-expanded={open} aria-controls="site-nav" onClick={() => setOpen((o) => !o)}>
          <span className="sr-only">Menu</span>
          <span className="burger" aria-hidden />
        </button>
        <nav id="site-nav" className={`site-nav ${open ? 'site-nav--open' : ''}`} aria-label="Main">
          <ul className="site-nav__links">
            {links.map((l) => (
              <li key={l.to}>
                {l.to.includes('#') ? (
                  <Link to={l.to} className="site-nav__link">
                    {l.label}
                  </Link>
                ) : (
                  <NavLink to={l.to} className="site-nav__link">
                    {l.label}
                  </NavLink>
                )}
              </li>
            ))}
          </ul>
          <div className="site-nav__actions">
            {user ? (
              <>
                <span className="site-nav__who">{user.name.split(' ')[0]}</span>
                <button
                  className="btn btn--quiet"
                  onClick={async () => {
                    await signOut()
                    navigate('/')
                  }}
                >
                  Sign out
                </button>
              </>
            ) : (
              <Link to="/signin" className="btn btn--quiet">
                Sign in
              </Link>
            )}
            {user?.role !== 'tailor' && (
              <ButtonLink to="/requests/new" variant="primary">
                Post a request
              </ButtonLink>
            )}
          </div>
        </nav>
      </div>
    </header>
  )
}

function Footer() {
  return (
    <footer className="site-footer">
      <div className="site-footer__inner">
        <div>
          <Logo />
          <p className="site-footer__line">Fabric to finished outfit. Made in Nigeria.</p>
        </div>
        <nav aria-label="Footer" className="site-footer__nav">
          <Link to="/fabrics">Fabric</Link>
          <Link to="/tailors">Tailors</Link>
          <Link to="/register?role=tailor">Join as a tailor</Link>
          <Link to="/#how">How it works</Link>
        </nav>
      </div>
    </footer>
  )
}

export function Layout() {
  return (
    <>
      <a href="#main" className="skip-link">
        Skip to content
      </a>
      <PreviewBanner />
      <Header />
      <main id="main" className="site-main">
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
