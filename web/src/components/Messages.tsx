import { useEffect, useRef, useState, type FormEvent } from 'react'
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
  const [photo, setPhoto] = useState<File | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const fileInput = useRef<HTMLInputElement>(null)

  useEffect(() => {
    if (!photo) { setPreview(null); return }
    const u = URL.createObjectURL(photo)
    setPreview(u)
    return () => URL.revokeObjectURL(u)
  }, [photo])

  const pick = (file: File | undefined) => {
    setError(null)
    if (!file) return
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { setError('Choose a JPG, PNG or WebP photo.'); return }
    if (file.size > 8 * 1024 * 1024) { setError('That photo is over 8 MB. Choose a smaller one.'); return }
    setPhoto(file)
  }

  useEffect(() => {
    if (!open || items) return
    api.messages(offerId).then(setItems, (e) => setError(errorMessage(e)))
  }, [open, offerId, items])

  const send = async (body: string, withPhoto: File | null = null) => {
    if (body.trim().length < 2 && !withPhoto) return
    setBusy(true)
    setError(null)
    try {
      const m = await api.sendMessage(offerId, body.trim(), withPhoto)
      setItems((list) => [...(list ?? []), m])
      setText('')
      setPhoto(null)
      if (fileInput.current) fileInput.current.value = ''
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
            {m.photo_url && (
              <a href={m.photo_url} target="_blank" rel="noopener noreferrer" className="thread__photo">
                <img src={m.photo_url} alt={m.mine ? 'Photo you sent' : `Photo from ${otherParty}`} loading="lazy" />
              </a>
            )}
            {m.body && <p>{m.body}</p>}
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
          {preview && (
            <div className="thread__pending">
              <img src={preview} alt="Photo to send" />
              <button type="button" className="text-link" onClick={() => { setPhoto(null); if (fileInput.current) fileInput.current.value = '' }}>Remove photo</button>
            </div>
          )}
          <form className="thread__form" onSubmit={(e: FormEvent) => { e.preventDefault(); void send(text, photo) }}>
            <label className="thread__attach" title="Add a photo">
              <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" className="sr-only" onChange={(e) => pick(e.currentTarget.files?.[0])} aria-label="Add a photo, for example the style you want" />
              <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinejoin="round"><rect x="3" y="5" width="18" height="14" rx="2" /><circle cx="9" cy="10" r="1.6" /><path d="m4 18 5-5 4 4 3-3 4 4" /></svg>
            </label>
            <label htmlFor={`msg-${offerId}`} className="sr-only">Message</label>
            <input id={`msg-${offerId}`} className="input" value={text} onChange={(e) => setText(e.target.value)} maxLength={1000} placeholder={photo ? 'Add a note (optional)' : 'Write a message'} />
            <Button type="submit" variant="secondary" busy={busy} disabled={text.trim().length < 2 && !photo}>Send</Button>
          </form>
          <p className="thread__hint">You can send photos: a style you like, your measurements written down, or the fabric you have.</p>
        </>
      )}
      {error && <p className="field__error" role="alert">{error}</p>}
    </section>
  )
}
