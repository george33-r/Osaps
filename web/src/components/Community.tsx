import { useEffect, useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type AdminQuestion, type AdminTailorDirectoryRow, type Moment, type MomentRing } from '../api'
import { Badge, Button, EmptyState, ErrorState, Skeleton, errorMessage } from './ui'
import { StoryCard } from './Stories'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const pct = (r: number | null) => (r === null ? 'New on OSAPS' : `${Math.round(r * 100)}% on time`)

/** Tailor of the week: picked from reviews and on-time delivery, or chosen by the OSAPS team. */
export function TailorOfWeekCard() {
  const res = useAsync(() => api.tailorOfWeek(), [])
  if (!res.data) return null
  const t = res.data.tailor
  return (
    <section className="sh-section" aria-labelledby="tow-title">
      <header className="sh-section__head">
        <h2 id="tow-title" className="sh-section__title">Tailor of the week</h2>
      </header>
      <Link to={`/tailors/${t.slug}`} className="tow-card">
        {t.photo_url ? <img src={t.photo_url} alt="" loading="lazy" /> : <span className="tow-card__initial" aria-hidden>{t.name.charAt(0)}</span>}
        <span className="tow-card__body">
          <strong>{t.name}</strong>
          <span className="muted">{t.city}</span>
          <span>
            {t.average !== null ? `${t.average.toFixed(1)} from ${t.review_count} reviews` : 'Reviews coming in'} · {pct(t.on_time_rate)} · {t.completed_orders} completed
          </span>
          <span className="tow-card__source">{res.data.source_label}</span>
        </span>
      </Link>
    </section>
  )
}

/** Circles like social stories. Each person has one circle; tapping opens their moments. */
export function MomentsRail() {
  const { user } = useSession()
  const res = useAsync(() => api.moments(), [])
  const [open, setOpen] = useState<{ ring: MomentRing; index: number } | null>(null)
  const [posting, setPosting] = useState(false)
  const rings = res.data ?? []

  if (res.status === 'error' && !res.data) return null
  if (rings.length === 0 && !user) return null

  return (
    <section className="moments" aria-label="Moments from the workshop">
      <ul className="moments__rail">
        {user && (
          <li>
            <button type="button" className="moment-ring moment-ring--add" onClick={() => setPosting(true)} aria-label="Add a moment">
              <span className="moment-ring__face"><span aria-hidden>+</span></span>
              <span className="moment-ring__name">Add</span>
            </button>
          </li>
        )}
        {rings.map((r) => (
          <li key={r.author_id}>
            <button type="button" className="moment-ring" onClick={() => setOpen({ ring: r, index: 0 })} aria-label={`${r.label}, ${r.moments.length} moment${r.moments.length > 1 ? 's' : ''}`}>
              <span className="moment-ring__face">
                {r.avatar_url ? <img src={r.avatar_url} alt="" loading="lazy" /> : <span aria-hidden>{r.label.charAt(0)}</span>}
              </span>
              <span className="moment-ring__name">{r.label.split(' ')[0]}</span>
            </button>
          </li>
        ))}
      </ul>

      {posting && (
        <MomentComposer
          onDone={() => {
            setPosting(false)
            res.reload()
          }}
          onCancel={() => setPosting(false)}
        />
      )}
      {open && <MomentViewer ring={open.ring} index={open.index} setIndex={(i) => setOpen({ ...open, index: i })} onClose={() => setOpen(null)} />}
    </section>
  )
}

function MomentViewer({ ring, index, setIndex, onClose }: { ring: MomentRing; index: number; setIndex: (i: number) => void; onClose: () => void }) {
  const m = ring.moments[index]
  const last = ring.moments.length - 1
  useEffect(() => {
    const key = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      if (e.key === 'ArrowRight' && index < last) setIndex(index + 1)
      if (e.key === 'ArrowLeft' && index > 0) setIndex(index - 1)
    }
    window.addEventListener('keydown', key)
    return () => window.removeEventListener('keydown', key)
  }, [index, last, onClose, setIndex])

  if (!m) return null
  return (
    <div className="moment-viewer" role="dialog" aria-modal="true" aria-label={`${ring.label} moments`}>
      <button type="button" className="moment-viewer__close" onClick={onClose} aria-label="Close">×</button>
      <div className="moment-viewer__bars" aria-hidden>
        {ring.moments.map((_, i) => <span key={i} className={i <= index ? 'is-on' : ''} />)}
      </div>
      <div className="moment-viewer__stage">
        {m.type === 'video' ? (
          <video key={m.id} src={m.url} controls autoPlay playsInline muted className="moment-viewer__media" />
        ) : (
          <img key={m.id} src={m.url} alt={m.caption ?? ''} className="moment-viewer__media" />
        )}
      </div>
      <div className="moment-viewer__foot">
        <strong>{ring.label}</strong>
        {m.caption && <p>{m.caption}</p>}
        {ring.tailor && <Link to={`/tailors/${ring.tailor.slug}`}>View their profile</Link>}
      </div>
      <button type="button" className="moment-viewer__nav moment-viewer__nav--prev" onClick={() => setIndex(index - 1)} disabled={index === 0} aria-label="Previous">‹</button>
      <button type="button" className="moment-viewer__nav moment-viewer__nav--next" onClick={() => (index < last ? setIndex(index + 1) : onClose())} aria-label={index < last ? 'Next' : 'Close'}>›</button>
    </div>
  )
}

function MomentComposer({ onDone, onCancel }: { onDone: () => void; onCancel: () => void }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const media = f.get('media')
    if (!(media instanceof File) || media.size === 0) return setError('Choose a photo or a video.')
    const form = new FormData()
    form.append('media', media)
    const caption = String(f.get('caption') ?? '').trim()
    if (caption) form.append('caption', caption)
    setBusy(true)
    setError(null)
    try {
      await api.postMoment(form)
      toast('Thanks. Your moment will show once checked.', 'good')
      onDone()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }
  return (
    <form className="admin-card story-form" onSubmit={submit} aria-label="Add a moment">
      <h2 className="section-title section-title--small">Add a moment</h2>
      <p className="muted">A photo or a short video (up to 30 MB) that shows in a circle for 24 hours. OSAPS checks it before it shows.</p>
      <label className="field__label">
        Photo or video
        <input name="media" type="file" accept="image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime" required />
      </label>
      <label className="field__label">
        Caption <span className="muted">(optional)</span>
        <input name="caption" className="input" maxLength={140} />
      </label>
      {error && <p className="notice" role="alert">{error}</p>}
      <div className="story-form__actions">
        <Button type="submit" busy={busy}>Send for a check</Button>
        <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>
      </div>
    </form>
  )
}

/** Save or unsave a story. Only shown to signed-in people. */
export function SaveStoryButton({ storyId }: { storyId: number }) {
  const { user } = useSession()
  const toast = useToast()
  const ids = useAsync(() => (user ? api.savedStoryIds() : Promise.resolve([] as number[])), [user?.id])
  const [busy, setBusy] = useState(false)
  if (!user || !ids.data) return null
  const saved = ids.data.includes(storyId)
  const toggle = async () => {
    setBusy(true)
    try {
      if (saved) await api.unsaveStory(storyId)
      else await api.saveStory(storyId)
      ids.reload()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(false)
    }
  }
  return (
    <button type="button" className="link-button" onClick={toggle} disabled={busy} aria-pressed={saved}>
      {saved ? 'Saved' : 'Save for later'}
    </button>
  )
}

/** Account page: stories the customer saved. */
export function SavedStories() {
  const res = useAsync(() => api.savedStories(), [])
  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return <Skeleton lines={2} />
  return (
    <section className="account__saved" aria-labelledby="saved-title">
      <h2 id="saved-title" className="section-title section-title--small">Saved stories</h2>
      {res.data.length === 0 ? (
        <EmptyState title="Nothing saved yet">Tap "Save for later" on a story on the home page to find it here.</EmptyState>
      ) : (
        <ul className="stories__rail">
          {res.data.map((s) => (
            <li key={s.id}>
              <StoryCard story={s} />
            </li>
          ))}
        </ul>
      )}
    </section>
  )
}

/** Ask a tailor: the customer's form, their own questions, and the published answers. */
export function AskPanel() {
  const { user } = useSession()
  const toast = useToast()
  const published = useAsync(() => api.publicQuestions(), [])
  const mine = useAsync(() => (user ? api.myQuestions() : Promise.resolve([])), [user?.id])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const q = String(new FormData(e.currentTarget).get('question') ?? '').trim()
    setBusy(true)
    setError(null)
    try {
      await api.askQuestion(q)
      e.currentTarget.reset()
      toast('Thank you. OSAPS checks your question before tailors see it.', 'good')
      mine.reload()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div>
      {user ? (
        <form className="story-form" onSubmit={submit}>
          <label className="field__label">
            Your question for our tailors
            <textarea name="question" className="input" required minLength={10} maxLength={300} rows={3} placeholder="How long does a senator take, and what does the fitting cost?" />
          </label>
          {error && <p className="notice" role="alert">{error}</p>}
          <div><Button type="submit" busy={busy}>Ask</Button></div>
        </form>
      ) : (
        <p className="muted"><Link to="/signin">Sign in</Link> to ask a question.</p>
      )}

      {user && mine.data && mine.data.length > 0 && (
        <section className="my-stories" aria-labelledby="my-q">
          <h2 id="my-q" className="section-title section-title--small">Your questions</h2>
          {mine.data.map((q) => (
            <article key={q.id} className="my-story">
              <div className="my-story__head">
                <strong>{q.question}</strong>
                <Badge tone={q.status === 'published' ? 'good' : q.status === 'hidden' ? 'alert' : 'waiting'}>
                  {({ pending: 'Waiting for a check', open: 'Tailors can answer', answered: 'Answer being checked', published: 'Answered', hidden: 'Not published' } as Record<string, string>)[q.status]}
                </Badge>
              </div>
              {q.status === 'published' && q.answer && <p>{q.answer}</p>}
              {q.status === 'hidden' && q.hidden_reason && <p className="notice">{q.hidden_reason}</p>}
            </article>
          ))}
        </section>
      )}

      <section className="sh-section" aria-labelledby="answers-title">
        <h2 id="answers-title" className="section-title section-title--small">Answers from tailors</h2>
        {published.status === 'error' && !published.data ? (
          <ErrorState error={published.error} onRetry={published.reload} />
        ) : !published.data ? (
          <Skeleton lines={3} />
        ) : published.data.length === 0 ? (
          <EmptyState title="No answers yet">Answers appear here once a tailor replies and OSAPS checks it.</EmptyState>
        ) : (
          published.data.map((q) => (
            <article key={q.id} className="review">
              <p><strong>{q.question}</strong></p>
              <p>{q.answer}</p>
              {q.answered_by && <p className="muted">Answered by <Link to={`/tailors/${q.answered_by.slug}`}>{q.answered_by.name}</Link></p>}
            </article>
          ))
        )}
      </section>
    </div>
  )
}

/** Tailor: questions open for an answer. The first verified answer goes forward for an admin check. */
export function OpenQuestions() {
  const toast = useToast()
  const res = useAsync(() => api.openQuestions(), [])
  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return null
  if (res.data.length === 0) return null
  return (
    <section className="tailor-reviews" aria-labelledby="open-q">
      <h2 id="open-q" className="section-title section-title--small">Customers are asking</h2>
      <p className="muted">Answer what you can. A good answer can be shared on the OSAPS home page as a tip, credited to you.</p>
      {res.data.map((q) => (
        <article key={q.id} className="my-story">
          <p><strong>{q.question}</strong></p>
          <form
            className="story-form"
            onSubmit={async (e) => {
              e.preventDefault()
              const answer = String(new FormData(e.currentTarget).get('answer') ?? '').trim()
              try {
                await api.answerQuestion(q.id, answer)
                toast('Answer sent for an OSAPS check.', 'good')
                res.reload()
              } catch (err) {
                toast(errorMessage(err), 'neutral')
              }
            }}
          >
            <textarea name="answer" className="input" required minLength={10} maxLength={600} rows={3} />
            <div><Button type="submit" variant="secondary">Send answer</Button></div>
          </form>
        </article>
      ))}
    </section>
  )
}

/** Admin: tailor of the week, questions, and moments. */
export function AdminCommunity() {
  const toast = useToast()
  const [qStatus, setQStatus] = useState<'pending' | 'open' | 'answered' | 'published' | 'hidden'>('pending')
  const [mStatus, setMStatus] = useState<'pending' | 'published' | 'hidden'>('pending')
  const questions = useAsync<AdminQuestion[]>(() => api.adminQuestions(qStatus), [qStatus])
  const moments = useAsync<Moment[]>(() => api.adminMoments(mStatus), [mStatus])
  const directory = useAsync<AdminTailorDirectoryRow[]>(() => api.adminTailorDirectory('verified'), [])
  const [busy, setBusy] = useState<string | null>(null)

  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    setBusy(key)
    try {
      await fn()
      toast(done, 'good')
      questions.reload()
      moments.reload()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(null)
    }
  }
  const note = (text: string): string | null => {
    const v = window.prompt(text, '')
    if (v === null) return null
    if (v.trim().length < 5) {
      toast('Give a reason of at least 5 characters.', 'neutral')
      return null
    }
    return v.trim()
  }

  return (
    <div>
      <section className="admin-card" aria-labelledby="tow-admin">
        <h2 id="tow-admin" className="section-title section-title--small">Tailor of the week</h2>
        <p className="muted">OSAPS picks the tailor automatically each week. Choose someone here to replace this week's pick. A reason is required and is kept in the log.</p>
        <form
          className="story-form"
          onSubmit={async (e) => {
            e.preventDefault()
            const f = new FormData(e.currentTarget)
            const id = Number(f.get('tailor_profile_id'))
            const reason = String(f.get('reason') ?? '').trim()
            if (!id) return toast('Choose a verified tailor.', 'neutral')
            try {
              const r = await api.adminSetTailorOfWeek(id, reason)
              toast(`${r.tailor} is tailor of the week.`, 'good')
              e.currentTarget.reset()
            } catch (err) {
              toast(errorMessage(err), 'neutral')
            }
          }}
        >
          <label className="field__label">
            Verified tailor
            <select name="tailor_profile_id" className="input" defaultValue="">
              <option value="">Choose…</option>
              {(directory.data ?? []).filter((t) => t.profile_id !== null).map((t) => (
                <option key={t.profile_id!} value={t.profile_id!}>{t.business_name ?? t.name}</option>
              ))}
            </select>
          </label>
          <label className="field__label">
            Reason
            <textarea name="reason" className="input" rows={2} minLength={10} maxLength={300} required />
          </label>
          <div><Button type="submit" variant="secondary">Set tailor of the week</Button></div>
        </form>
      </section>

      <section aria-labelledby="qa-admin">
        <h2 id="qa-admin" className="section-title section-title--small">Questions for tailors</h2>
        <div className="vtabs" role="tablist" aria-label="Question status">
          {(['pending', 'open', 'answered', 'published', 'hidden'] as const).map((k) => (
            <button key={k} role="tab" aria-selected={qStatus === k} className="vtabs__tab" onClick={() => setQStatus(k)}>{k}</button>
          ))}
        </div>
        {!questions.data ? <Skeleton lines={2} /> : questions.data.length === 0 ? <EmptyState title="Nothing here" /> : questions.data.map((q) => (
          <article key={q.id} className="admin-card">
            <p><strong>{q.question}</strong></p>
            <p className="muted">Asked by {q.asker_name ?? 'a customer'}{q.answered_by ? ` · answer by ${q.answered_by}` : ''}</p>
            {q.answer && <p>{q.answer}</p>}
            <div className="admin-card__actions">
              {q.status === 'pending' && <Button busy={busy === `qa${q.id}`} onClick={() => void act(`qa${q.id}`, () => api.adminApproveQuestion(q.id), 'Question approved.')}>Approve</Button>}
              {q.status === 'answered' && <Button busy={busy === `qp${q.id}`} onClick={() => void act(`qp${q.id}`, () => api.adminPublishQuestion(q.id), 'Answer published as a tip.')}>Publish as tip</Button>}
              {(q.status === 'pending' || q.status === 'open' || q.status === 'answered') && (
                <Button variant="secondary" busy={busy === `qh${q.id}`} onClick={() => { const n = note('Why is this question hidden? (kept in the log)'); if (n) void act(`qh${q.id}`, () => api.adminHideQuestion(q.id, n), 'Hidden.') }}>Hide</Button>
              )}
            </div>
          </article>
        ))}
      </section>

      <section aria-labelledby="mom-admin">
        <h2 id="mom-admin" className="section-title section-title--small">Moments</h2>
        <div className="vtabs" role="tablist" aria-label="Moment status">
          {(['pending', 'published', 'hidden'] as const).map((k) => (
            <button key={k} role="tab" aria-selected={mStatus === k} className="vtabs__tab" onClick={() => setMStatus(k)}>{k}</button>
          ))}
        </div>
        {!moments.data ? <Skeleton lines={2} /> : moments.data.length === 0 ? <EmptyState title="Nothing here" /> : moments.data.map((m) => (
          <article key={m.id} className="admin-card">
            <p>{m.type === 'video' ? 'Video' : 'Photo'} by {m.author_name ?? 'a member'}{m.caption ? ` — ${m.caption}` : ''} · <a href={m.url} target="_blank" rel="noopener noreferrer">Open</a></p>
            <div className="admin-card__actions">
              {m.status !== 'published' && <Button busy={busy === `mp${m.id}`} onClick={() => void act(`mp${m.id}`, () => api.adminMomentStatus(m.id, 'published', null), 'Published.')}>Publish</Button>}
              {m.status !== 'hidden' && <Button variant="secondary" busy={busy === `mh${m.id}`} onClick={() => { const n = note('Why is this moment hidden? (shown to the poster)'); if (n) void act(`mh${m.id}`, () => api.adminMomentStatus(m.id, 'hidden', n), 'Hidden.') }}>Hide</Button>}
            </div>
          </article>
        ))}
      </section>
    </div>
  )
}
