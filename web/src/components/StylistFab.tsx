import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useLocation, useNavigate } from 'react-router-dom'
import { useSession } from '../lib/session'

/**
 * The always-visible way into the AI stylist: a floating button on every page except the
 * stylist itself. It opens a small panel where the customer can type what they want or pick
 * an occasion; both start the full consultation.
 */
export function StylistFab() {
  const { meta } = useSession()
  const { pathname } = useLocation()
  const navigate = useNavigate()
  const [open, setOpen] = useState(false)
  const [text, setText] = useState('')
  const input = useRef<HTMLInputElement>(null)
  const button = useRef<HTMLButtonElement>(null)

  useEffect(() => setOpen(false), [pathname])
  useEffect(() => {
    if (open) input.current?.focus()
  }, [open])

  if (pathname.startsWith('/stylist')) return null
  // Out of the way while buying or running a shop; just an icon on fabric pages so it never covers the buy box.
  if (/^\/(cart|checkout|vendor|offers|shop|aso-ebi|account)(\/|$)/.test(pathname)) return null
  const compact = pathname.startsWith('/fabrics/')

  const close = () => {
    setOpen(false)
    button.current?.focus()
  }
  const submit = (e: FormEvent) => {
    e.preventDefault()
    const q = text.trim()
    navigate(q.length >= 2 ? `/stylist?ask=${encodeURIComponent(q)}` : '/stylist')
    setText('')
  }
  const occasions = meta?.occasions.filter((o) => ['wedding_guest', 'traditional_wedding', 'own_wedding', 'festival', 'december', 'family_event'].includes(o.key)) ?? []

  return (
    <div className={`fab ${open ? 'is-open' : ''} ${compact && !open ? 'fab--compact' : ''}`} onKeyDown={(e) => e.key === 'Escape' && open && close()}>
      {open && (
        <section className="fab__panel" role="dialog" aria-modal="false" aria-labelledby="fab-title">
          <header className="fab__head">
            <p id="fab-title" className="fab__title">Your stylist</p>
            <button type="button" className="fab__close" onClick={close} aria-label="Close the stylist">×</button>
          </header>
          <p className="fab__intro">Tell me the occasion and what you love. I will suggest a look, fabrics from the catalogue and tailors who make it.</p>
          <form onSubmit={submit} className="fab__form">
            <label htmlFor="fab-input" className="sr-only">What would you like to wear?</label>
            <input
              ref={input}
              id="fab-input"
              className="input"
              value={text}
              maxLength={500}
              onChange={(e) => setText(e.target.value)}
              placeholder="An aṣọ-òkè look for my sister's wedding"
              autoComplete="off"
            />
            <button type="submit" className="btn btn--primary">Ask</button>
          </form>
          {occasions.length > 0 && (
            <div className="fab__chips" aria-label="Or start from an occasion">
              {occasions.map((o) => (
                <Link key={o.key} to={`/stylist?occasion=${o.key}`} className="chip">{o.label}</Link>
              ))}
            </div>
          )}
          <p className="fab__note">{meta?.features.ai_assistant ? 'Powered by AI. What you type is sent to our AI provider.' : 'Guided mode: answers come from our style guide.'}</p>
        </section>
      )}
      <button
        ref={button}
        type="button"
        className="fab__button"
        aria-expanded={open}
        aria-haspopup="dialog"
        onClick={() => (open ? close() : setOpen(true))}
        data-magnetic
      >
        <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden>
          <path d="M12 2.5l1.9 5.1 5.1 1.9-5.1 1.9L12 16.5l-1.9-5.1L5 9.5l5.1-1.9z" fill="currentColor" />
          <path d="M18.5 14l.9 2.4 2.4.9-2.4.9-.9 2.4-.9-2.4-2.4-.9 2.4-.9z" fill="currentColor" opacity="0.8" />
        </svg>
        <span className={`fab__label ${compact && !open ? 'sr-only' : ''}`}>{open ? 'Close' : 'Ask the stylist'}</span>
      </button>
    </div>
  )
}
