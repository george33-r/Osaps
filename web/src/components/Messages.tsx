import { useEffect, useState, type FormEvent } from 'react'
import { api, type OfferMessage } from '../api'
import { formatDate } from '../lib/format'
import { Button, errorMessage } from './ui'

/**
 * Questions and answers between the customer and one tailor about one offer.
 * Suggested questions (from the rule-based comparison) become one-tap prompts.
 */
export function MessageThread({
  offerId,
  otherParty,
  suggestions = [],
  open: startOpen = false,
  closed = false,
}: {
  offerId: number
  otherParty: string
  suggestions?: string[]
  open?: boolean
  closed?: boolean
}) {
  const [open, setOpen] = useState(startOpen)
  const [items, setItems] = useState<OfferMessage[] | null>(null)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    if (!open || items) return
    api.messages(offerId).then(setItems, (e) => setError(errorMessage(e)))
  }, [open, offerId, items])

  const send = async (body: string) => {
    if (body.trim().length < 2) return
    setBusy(true)
    setError(null)
    try {
      const m = await api.sendMessage(offerId, body.trim())
      setItems((list) => [...(list ?? []), m])
      setText('')
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  if (!open) {
    return (
      <button type="button" className="thread-toggle" onClick={() => setOpen(true)} aria-expanded={false}>
        {closed ? `Messages with ${otherParty}` : `Ask ${otherParty} a question`}
      </button>
    )
  }

  const unasked = suggestions.filter((s) => !items?.some((m) => m.body === s))

  return (
    <section className="thread" aria-label={`Messages with ${otherParty}`}>
      <ol className="thread__list">
        {items === null && !error && <li className="muted">Loading messages</li>}
        {items?.length === 0 && <li className="muted">No messages yet. Questions you ask here are visible only to you and {otherParty}.</li>}
        {items?.map((m) => (
          <li key={m.id} className={`thread__msg ${m.mine ? 'is-mine' : ''}`}>
            <p>{m.body}</p>
            <span className="thread__when">{m.mine ? 'You' : otherParty}, {formatDate(m.created_at, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</span>
          </li>
        ))}
      </ol>
      {!closed && (
        <>
          {unasked.length > 0 && (
            <div className="thread__suggest">
              {unasked.map((s) => (
                <button key={s} type="button" className="chip" onClick={() => send(s)} disabled={busy}>{s}</button>
              ))}
            </div>
          )}
          <form className="thread__form" onSubmit={(e: FormEvent) => { e.preventDefault(); void send(text) }}>
            <label htmlFor={`msg-${offerId}`} className="sr-only">Message</label>
            <input id={`msg-${offerId}`} className="input" value={text} onChange={(e) => setText(e.target.value)} maxLength={1000} placeholder="Write a message" />
            <Button type="submit" variant="secondary" busy={busy} disabled={text.trim().length < 2}>Send</Button>
          </form>
        </>
      )}
      {error && <p className="field__error" role="alert">{error}</p>}
    </section>
  )
}
