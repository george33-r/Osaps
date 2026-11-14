import { useState, type FormEvent, type ReactNode } from 'react'
import { api, type AdminReviewQueue, type ReviewInput, type ReviewSummary, type TailorReview } from '../api'
import { Button, EmptyState, ErrorState, Skeleton, errorMessage } from './ui'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const SCORES = [
  { key: 'quality', label: 'Stitching quality' },
  { key: 'communication', label: 'Communication' },
  { key: 'delivery_time', label: 'Delivery time' },
  { key: 'value_for_money', label: 'Value for money' },
] as const

const REPORT_REASONS: [string, string][] = [
  ['abusive', 'Abusive or offensive'],
  ['fake', 'Fake or not from a real order'],
  ['personal_information', 'Shares personal information'],
  ['not_my_order', 'Not about my order'],
  ['other', 'Something else'],
]

const dateText = (iso: string | null) =>
  iso ? new Date(iso).toLocaleDateString('en-NG', { day: 'numeric', month: 'short', year: 'numeric' }) : ''

export function Stars({ value, label, onChange }: { value: number | null; label: string; onChange?: (n: number) => void }) {
  if (!onChange) {
    return <span className="stars" aria-label={value === null ? `${label}: not rated` : `${label}: ${value} out of 5`}>{'★'.repeat(value ?? 0)}{'☆'.repeat(5 - (value ?? 0))}</span>
  }
  return (
    <div className="stars-input" role="radiogroup" aria-label={label}>
      {[1, 2, 3, 4, 5].map((n) => (
        <button
          key={n}
          type="button"
          role="radio"
          aria-checked={value === n}
          aria-label={`${n} star${n > 1 ? 's' : ''}`}
          className={`stars-input__star${value !== null && n <= value ? ' is-on' : ''}`}
          onClick={() => onChange(n)}
        >
          ★
        </button>
      ))}
    </div>
  )
}

/** Average, count and the four categories. No reviews is shown as unrated, not as a low rating. */
export function RatingSummary({ summary }: { summary: ReviewSummary }) {
  if (summary.count === 0 || summary.average === null) {
    return (
      <div className="rating-summary">
        <p className="rating-summary__none">No reviews yet</p>
        <p className="muted">Ratings come from customers whose completed, paid order has been reviewed. Not having reviews yet does not count against a tailor.</p>
      </div>
    )
  }
  const b = summary.breakdown
  return (
    <div className="rating-summary">
      <p className="rating-summary__headline">
        <span className="rating-summary__avg">{summary.average.toFixed(1)}</span> out of 5 · {summary.count} {summary.count === 1 ? 'review' : 'reviews'}
      </p>
      {b && (
        <dl className="rating-summary__breakdown">
          {SCORES.map((s) => (
            <div key={s.key}>
              <dt>{s.label}</dt>
              <dd>{b[s.key] === null ? '—' : `${b[s.key]!.toFixed(1)} / 5`}</dd>
            </div>
          ))}
        </dl>
      )}
    </div>
  )
}

function ReviewCard({ review, children }: { review: TailorReview; children?: ReactNode }) {
  return (
    <article className="review">
      <header className="review__head">
        <Stars value={review.stars} label="Overall rating" />
        <span className="review__who">{review.reviewer_label}</span>
        {review.submitted_at && <time dateTime={review.submitted_at} className="muted">{dateText(review.submitted_at)}</time>}
        {review.corrected && <span className="muted"> · edited once</span>}
      </header>
      {review.body && <p className="review__body">{review.body}</p>}
      {review.photos.length > 0 && (
        <ul className="review__photos">
          {review.photos.map((p) => (
            <li key={p.id}>
              <a href={p.url} target="_blank" rel="noopener noreferrer">Photo of the finished piece</a>
            </li>
          ))}
        </ul>
      )}
      {review.response && (
        <div className="review__reply">
          <p className="review__reply-label">Reply from the tailor</p>
          <p>{review.response.body}</p>
        </div>
      )}
      {children}
    </article>
  )
}

/** Public: report link appears only for signed-in people. */
function ReportReview({ review }: { review: TailorReview }) {
  const { user } = useSession()
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  if (!user) return null
  if (!open) {
    return <button type="button" className="link-button" onClick={() => setOpen(true)}>Report this review</button>
  }
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    try {
      await api.reportReview(review.id, String(f.get('reason')), String(f.get('details') ?? '').trim() || null)
      toast('Thank you. The OSAPS team will look at this review.', 'good')
      setOpen(false)
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(false)
    }
  }
  return (
    <form className="review__report" onSubmit={submit}>
      <label className="field__label">
        Why are you reporting it?
        <select name="reason" className="input" defaultValue="abusive">
          {REPORT_REASONS.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
        </select>
      </label>
      <textarea name="details" className="input" rows={2} maxLength={1000} placeholder="Optional details" />
      <Button type="submit" variant="secondary" busy={busy}>Send report</Button>
    </form>
  )
}

/** Public list for a tailor's profile page. */
export function TailorReviewsBlock({ slug, summary }: { slug: string; summary: ReviewSummary }) {
  const res = useAsync(() => api.tailorReviews(slug), [slug])
  return (
    <section className="maker__reviews" aria-labelledby="reviews-title">
      <h2 id="reviews-title" className="section-title section-title--small">Customer reviews</h2>
      <RatingSummary summary={summary} />
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <Skeleton lines={2} />
      ) : res.data.reviews.length === 0 ? (
        <EmptyState title="No written reviews yet" />
      ) : (
        res.data.reviews.map((r) => (
          <ReviewCard key={r.id} review={r}>
            <ReportReview review={r} />
          </ReviewCard>
        ))
      )}
    </section>
  )
}

/** Shared form for a new review and for the one allowed correction. */
function ReviewEditor({
  initial,
  submitLabel,
  withPhotos,
  onSubmit,
}: {
  initial?: TailorReview
  submitLabel: string
  withPhotos: boolean
  onSubmit: (input: ReviewInput, photos: File[]) => Promise<void>
}) {
  const [stars, setStars] = useState<number | null>(initial?.stars ?? null)
  const [scores, setScores] = useState<Record<string, number | null>>({
    quality: initial?.ratings.quality ?? null,
    communication: initial?.ratings.communication ?? null,
    delivery_time: initial?.ratings.delivery_time ?? null,
    value_for_money: initial?.ratings.value_for_money ?? null,
  })
  const [body, setBody] = useState(initial?.body ?? '')
  const [photos, setPhotos] = useState<File[]>([])
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    if (stars === null) return setError('Choose an overall rating.')
    setBusy(true)
    setError(null)
    try {
      await onSubmit({ stars, ...scores, body: body.trim() || null }, photos)
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="review-form" onSubmit={submit}>
      <div className="review-form__row">
        <span className="field__label">Overall</span>
        <Stars value={stars} label="Overall rating" onChange={setStars} />
      </div>
      {SCORES.map((s) => (
        <div key={s.key} className="review-form__row">
          <span className="field__label">{s.label} <span className="muted">(optional)</span></span>
          <Stars value={scores[s.key] ?? null} label={s.label} onChange={(n) => setScores((c) => ({ ...c, [s.key]: n }))} />
        </div>
      ))}
      <label className="field__label">
        What was it like? <span className="muted">(optional)</span>
        <textarea className="input" rows={4} maxLength={1000} value={body} onChange={(e) => setBody(e.target.value)} />
      </label>
      {withPhotos && (
        <label className="field__label">
          Photos of the finished piece <span className="muted">(up to 3, shown only after an OSAPS check)</span>
          <input type="file" accept="image/jpeg,image/png,image/webp" multiple onChange={(e) => setPhotos(Array.from(e.target.files ?? []).slice(0, 3))} />
        </label>
      )}
      {error && <p className="notice" role="alert">{error}</p>}
      <Button type="submit" busy={busy}>{submitLabel}</Button>
    </form>
  )
}

/** Customer's review on a completed, paid order. */
export function OrderReviewPanel({ reference }: { reference: string }) {
  const toast = useToast()
  const res = useAsync(() => api.orderReview(reference), [reference])
  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return <Skeleton lines={2} />
  const { review, eligible, window_closes_at } = res.data

  if (review) {
    const first = review.original?.find((v) => v.version === 1)
    const canAppeal = review.status === 'hidden'
    return (
      <div>
        <h2 className="section-title section-title--small">Your review</h2>
        {review.status === 'hidden' && (
          <p className="notice">This review is hidden{review.hidden_reason ? `: ${review.hidden_reason}` : '.'}</p>
        )}
        <ReviewCard review={review}>
          {first && review.corrected && (
            <details className="review__history">
              <summary>Original wording</summary>
              <p>{first.stars} out of 5{first.body ? ` · ${first.body}` : ''}</p>
            </details>
          )}
          {review.can_correct && (
            <details className="review__history">
              <summary>Correct this review (one time, within 24 hours)</summary>
              <ReviewEditor
                initial={review}
                submitLabel="Save correction"
                withPhotos={false}
                onSubmit={async (input) => {
                  await api.correctReview(review.id, input)
                  toast('Correction saved. The original wording is kept for our records.', 'good')
                  res.reload()
                }}
              />
            </details>
          )}
          {canAppeal && <AppealForm reviewId={review.id} onDone={res.reload} />}
        </ReviewCard>
      </div>
    )
  }

  const closed = window_closes_at !== null && new Date(window_closes_at) < new Date()
  if (!eligible) {
    return (
      <p className="muted">
        {closed
          ? 'The 30-day window to review this order has closed.'
          : 'You can review this tailor once the order is completed and paid.'}
      </p>
    )
  }

  return (
    <div>
      <h2 className="section-title section-title--small">Review this tailor</h2>
      <p className="muted">
        Your review is public and shown with your first initial only. It can be corrected once within 24 hours.
        {window_closes_at && <> You can review until {dateText(window_closes_at)}.</>}
      </p>
      <ReviewEditor
        submitLabel="Post review"
        withPhotos
        onSubmit={async (input, photos) => {
          await api.submitOrderReview(reference, input, photos)
          toast('Thank you. Your review is posted.', 'good')
          res.reload()
        }}
      />
    </div>
  )
}

function AppealForm({ reviewId, onDone }: { reviewId: number; onDone: () => void }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const reason = String(new FormData(e.currentTarget).get('reason') ?? '').trim()
    setBusy(true)
    try {
      await api.appealReview(reviewId, reason)
      toast('Your appeal has been sent to the OSAPS team.', 'good')
      onDone()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(false)
    }
  }
  return (
    <form className="review__report" onSubmit={submit}>
      <label className="field__label">
        Ask us to restore this review
        <textarea name="reason" className="input" rows={3} minLength={10} maxLength={1000} required />
      </label>
      <Button type="submit" variant="secondary" busy={busy}>Send appeal</Button>
    </form>
  )
}

/** Tailor's own reviews, with one reply per review. */
export function TailorReviewsPanel() {
  const res = useAsync(() => api.myTailorReviews(), [])
  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return <Skeleton lines={3} />
  return (
    <section className="tailor-reviews" aria-labelledby="my-reviews">
      <h2 id="my-reviews" className="section-title section-title--small">Your reviews</h2>
      <RatingSummary summary={res.data.summary} />
      {res.data.reviews.length === 0 && <EmptyState title="No reviews yet">Reviews appear here after a customer reviews a completed, paid order.</EmptyState>}
      {res.data.reviews.map((r) => (
        <ReviewCard key={r.id} review={r}>
          {!r.response && <ReplyBox reviewId={r.id} onDone={res.reload} />}
        </ReviewCard>
      ))}
    </section>
  )
}

function ReplyBox({ reviewId, onDone }: { reviewId: number; onDone: () => void }) {
  const toast = useToast()
  const [busy, setBusy] = useState(false)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const body = String(new FormData(e.currentTarget).get('body') ?? '').trim()
    setBusy(true)
    try {
      await api.respondToReview(reviewId, body)
      toast('Reply posted.', 'good')
      onDone()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(false)
    }
  }
  return (
    <form className="review__report" onSubmit={submit}>
      <label className="field__label">
        Reply to this review
        <textarea name="body" className="input" rows={3} minLength={10} maxLength={1000} required />
      </label>
      <p className="muted">You get one reply. It cannot be removed, even if the customer later edits the review.</p>
      <Button type="submit" variant="secondary" busy={busy}>Post reply</Button>
    </form>
  )
}

/** Admin queue: reports, appeals and photos waiting for a decision. */
export function ReviewQueue() {
  const toast = useToast()
  const queue = useAsync<AdminReviewQueue>(() => api.adminReviewQueue(), [])
  const [busy, setBusy] = useState<string | null>(null)

  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    setBusy(key)
    try {
      await fn()
      toast(done, 'good')
      queue.reload()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(null)
    }
  }
  const ask = (text: string, required: boolean): string | null => {
    const v = window.prompt(text, '')
    if (v === null) return null
    if (required && v.trim().length < 5) {
      toast('Give a reason of at least 5 characters.', 'neutral')
      return null
    }
    return v.trim() || null
  }

  if (queue.status === 'error' && !queue.data) return <ErrorState error={queue.error} onRetry={queue.reload} />
  if (!queue.data) return <Skeleton lines={4} />
  const { reports, appeals, photos } = queue.data
  const nothing = reports.length + appeals.length + photos.length === 0

  return (
    <div>
      {nothing && <EmptyState title="Nothing waiting">Reports, appeals and review photos appear here for a decision.</EmptyState>}

      {reports.length > 0 && <h2 className="section-title section-title--small">Reported reviews</h2>}
      {reports.map((r) => (
        <div key={`r${r.id}`} className="admin-card">
          <p><strong>{r.reason.replace(/_/g, ' ')}</strong>{r.details ? ` — ${r.details}` : ''}</p>
          <p className="muted">Review #{r.review.id} · {r.review.stars}/5 · {r.review.body ?? 'no text'} · {r.review.status}</p>
          <div className="admin-card__actions">
            <Button busy={busy === `r${r.id}u`} onClick={() => { const n = ask('Why is the review hidden? (shown to the reviewer)', true); if (n) void act(`r${r.id}u`, () => api.adminDecideReport(r.id, 'upheld', n), 'Review hidden.') }}>Uphold and hide</Button>
            <Button variant="secondary" busy={busy === `r${r.id}d`} onClick={() => void act(`r${r.id}d`, () => api.adminDecideReport(r.id, 'dismissed', null), 'Report dismissed.')}>Dismiss</Button>
          </div>
        </div>
      ))}

      {appeals.length > 0 && <h2 className="section-title section-title--small">Appeals</h2>}
      {appeals.map((a) => (
        <div key={`a${a.id}`} className="admin-card">
          <p><strong>Appeal:</strong> {a.reason}</p>
          <p className="muted">Review #{a.review.id} · {a.review.stars}/5 · {a.review.body ?? 'no text'} · hidden because: {a.review.hidden_reason ?? '—'}</p>
          <div className="admin-card__actions">
            <Button busy={busy === `a${a.id}r`} onClick={() => void act(`a${a.id}r`, () => api.adminDecideAppeal(a.id, 'restored', null), 'Review restored.')}>Restore review</Button>
            <Button variant="secondary" busy={busy === `a${a.id}u`} onClick={() => { const n = ask('Why is the appeal refused? (shown to the reviewer)', true); if (n) void act(`a${a.id}u`, () => api.adminDecideAppeal(a.id, 'upheld', n), 'Appeal refused.') }}>Refuse</Button>
          </div>
        </div>
      ))}

      {photos.length > 0 && <h2 className="section-title section-title--small">Review photos</h2>}
      {photos.map((p) => (
        <div key={`p${p.id}`} className="admin-card">
          <p>Photo for review #{p.review_id} <span className="muted">(review {p.review_status})</span> · <a href={p.file_url} target="_blank" rel="noopener noreferrer">Open photo</a></p>
          <div className="admin-card__actions">
            <Button busy={busy === `p${p.id}a`} disabled={p.review_status !== 'published'} onClick={() => void act(`p${p.id}a`, () => api.adminDecidePhoto(p.id, 'approved'), 'Photo approved.')}>Approve</Button>
            <Button variant="secondary" busy={busy === `p${p.id}x`} onClick={() => void act(`p${p.id}x`, () => api.adminDecidePhoto(p.id, 'rejected'), 'Photo rejected.')}>Reject</Button>
          </div>
        </div>
      ))}
    </div>
  )
}
