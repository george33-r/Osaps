import { useState, type FormEvent } from 'react'
import { Link } from 'react-router-dom'
import { api, type Story, type AdminTailorDirectoryRow } from '../api'
import { Badge, Button, EmptyState, ErrorState, Skeleton, errorMessage } from './ui'
import { useToast } from '../lib/toast'
import { SaveStoryButton } from './Community'
import { useAsync } from '../lib/useAsync'

const STATUS_LABEL: Record<string, string> = { pending: 'Waiting for an OSAPS check', published: 'Live on the home page', hidden: 'Hidden' }
const STATUS_TONE: Record<string, 'waiting' | 'good' | 'alert'> = { pending: 'waiting', published: 'good', hidden: 'alert' }
const FILTER_LABEL: Record<'all' | 'pending' | 'published' | 'hidden', string> = { all: 'All', pending: 'Waiting', published: 'Live', hidden: 'Hidden' }

/** Home page: between the trust cards and the fabric categories. Renders nothing until a story is live. */
export function HomeStories() {
  const res = useAsync(() => api.stories(), [])
  if (!res.data || res.data.length === 0) return null

  return (
    <section className="sh-section stories" aria-labelledby="stories-title">
      <header className="sh-section__head">
        <h2 id="stories-title" className="sh-section__title">From the workshop</h2>
        <Link to="/ask" className="text-link">Ask a tailor</Link>
      </header>
      <ul className="stories__rail">
        {res.data.map((s) => (
          <li key={s.id}>
            <StoryCard story={s} />
          </li>
        ))}
      </ul>
    </section>
  )
}

export function StoryCard({ story }: { story: Story }) {
  const internal = story.link_url
  return (
    <article className={`story-card story-card--${story.kind}`}>
      {story.photo_url && <img src={story.photo_url} alt="" loading="lazy" decoding="async" />}
      <div className="story-card__body">
        <span className="story-card__kind">{story.kind_label}</span>
        <h3>{story.title}</h3>
        <p>{story.body}</p>
        <p className="story-card__by">
          {story.tailor ? <Link to={`/tailors/${story.tailor.slug}`}>{story.byline}</Link> : story.byline}
        </p>
        <div className="story-card__actions">
          {internal && <Link to={internal} className="text-link">Read more</Link>}
          <SaveStoryButton storyId={story.id} />
        </div>
      </div>
    </article>
  )
}

/** Shared by tailors and admins. Admins also choose the kind and the tailor being featured. */
export function StoryForm({
  initial,
  admin,
  tailors = [],
  onSave,
  onCancel,
}: {
  initial?: Story
  admin: boolean
  tailors?: AdminTailorDirectoryRow[]
  onSave: (form: FormData) => Promise<void>
  onCancel?: () => void
}) {
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    const form = new FormData()
    form.append('title', String(f.get('title') ?? '').trim())
    form.append('body', String(f.get('body') ?? '').trim())
    const link = String(f.get('link_url') ?? '').trim()
    if (link) form.append('link_url', link)
    if (admin) {
      form.append('kind', String(f.get('kind') ?? 'story'))
      const profile = String(f.get('tailor_profile_id') ?? '')
      if (profile) form.append('tailor_profile_id', profile)
    }
    const photo = f.get('photo')
    if (photo instanceof File && photo.size > 0) form.append('photo', photo)

    setBusy(true)
    setError(null)
    try {
      await onSave(form)
      e.currentTarget.reset()
    } catch (err) {
      setError(errorMessage(err))
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="story-form" onSubmit={submit}>
      {admin && (
        <div className="story-form__row">
          <label className="field__label">
            Kind
            <select name="kind" className="input" defaultValue={initial?.kind ?? 'story'}>
              <option value="story">Story</option>
              <option value="tip">Tip</option>
              <option value="spotlight">Tailor spotlight</option>
            </select>
          </label>
          <label className="field__label">
            Featured tailor <span className="muted">(optional)</span>
            <select name="tailor_profile_id" className="input" defaultValue={initial?.tailor_profile_id ?? ''}>
              <option value="">None</option>
              {tailors.filter((t) => t.profile_id !== null).map((t) => (
                <option key={t.profile_id!} value={t.profile_id!}>{t.business_name ?? t.name}</option>
              ))}
            </select>
          </label>
        </div>
      )}
      <label className="field__label">
        Title
        <input name="title" className="input" required minLength={3} maxLength={80} defaultValue={initial?.title ?? ''} />
      </label>
      <label className="field__label">
        Story
        <textarea name="body" className="input" required minLength={10} maxLength={600} rows={4} defaultValue={initial?.body ?? ''} />
      </label>
      <label className="field__label">
        Link inside OSAPS <span className="muted">(optional, e.g. /fabrics or /tailors/your-name)</span>
        <input name="link_url" className="input" maxLength={255} placeholder="/fabrics" defaultValue={initial?.link_url ?? ''} />
      </label>
      <label className="field__label">
        Photo <span className="muted">(optional, JPG, PNG or WebP, up to 5 MB)</span>
        <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" />
      </label>
      {!admin && <p className="muted">Stories are checked by OSAPS before they appear. Editing a live story sends it back for a check.</p>}
      {error && <p className="notice" role="alert">{error}</p>}
      <div className="story-form__actions">
        <Button type="submit" busy={busy}>{initial ? 'Save story' : admin ? 'Publish now' : 'Send for a check'}</Button>
        {onCancel && <Button type="button" variant="secondary" onClick={onCancel}>Cancel</Button>}
      </div>
    </form>
  )
}

/** Tailor: write, edit, delete their own stories and see each one's status. */
export function MyStoriesPanel() {
  const toast = useToast()
  const res = useAsync(() => api.myStories(), [])
  const [writing, setWriting] = useState(false)
  const [editing, setEditing] = useState<number | null>(null)

  if (res.status === 'error' && !res.data) return <ErrorState error={res.error} onRetry={res.reload} />
  if (!res.data) return <Skeleton lines={2} />

  return (
    <section className="my-stories" aria-labelledby="my-stories">
      <div className="my-stories__head">
        <h2 id="my-stories" className="section-title section-title--small">Your stories</h2>
        {!writing && <Button variant="secondary" onClick={() => setWriting(true)}>Write a story</Button>}
      </div>
      <p className="muted">Stories you write appear on the OSAPS home page once checked. Write about a finished piece, a fabric you love, or a tip for customers.</p>

      {writing && (
        <StoryForm
          admin={false}
          onCancel={() => setWriting(false)}
          onSave={async (form) => {
            await api.createMyStory(form)
            toast('Story sent for a check.', 'good')
            setWriting(false)
            res.reload()
          }}
        />
      )}

      {res.data.length === 0 && !writing && <EmptyState title="No stories yet">Your first story goes through a short check before it shows on the home page.</EmptyState>}

      {res.data.map((s) => (
        <article key={s.id} className="my-story">
          <div className="my-story__head">
            <strong>{s.title}</strong>
            <Badge tone={STATUS_TONE[s.status ?? 'pending'] ?? 'waiting'}>{STATUS_LABEL[s.status ?? 'pending']}</Badge>
          </div>
          {s.status === 'hidden' && s.hidden_reason && <p className="notice">Why it was hidden: {s.hidden_reason}</p>}
          {editing === s.id ? (
            <StoryForm
              initial={s}
              admin={false}
              onCancel={() => setEditing(null)}
              onSave={async (form) => {
                await api.updateMyStory(s.id, form)
                toast('Saved. It will show again once checked.', 'good')
                setEditing(null)
                res.reload()
              }}
            />
          ) : (
            <div className="my-story__actions">
              <Button variant="secondary" onClick={() => setEditing(s.id)}>Edit</Button>
              <Button
                variant="quiet"
                onClick={async () => {
                  if (!window.confirm('Delete this story?')) return
                  try {
                    await api.deleteMyStory(s.id)
                    toast('Story deleted.', 'good')
                    res.reload()
                  } catch (err) {
                    toast(errorMessage(err), 'neutral')
                  }
                }}
              >
                Delete
              </Button>
            </div>
          )}
        </article>
      ))}
    </section>
  )
}

/** Admin: write stories (published at once), feature tailors, publish or hide anything. */
export function AdminStories() {
  const toast = useToast()
  const [status, setStatus] = useState<'all' | 'pending' | 'published' | 'hidden'>('all')
  const res = useAsync(() => api.adminStories(status === 'all' ? undefined : status), [status])
  const tailors = useAsync(() => api.adminTailorDirectory(), [])
  const [editing, setEditing] = useState<number | null>(null)
  const [busy, setBusy] = useState<string | null>(null)

  const act = async (key: string, fn: () => Promise<unknown>, done: string) => {
    setBusy(key)
    try {
      await fn()
      toast(done, 'good')
      res.reload()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(null)
    }
  }

  return (
    <div>
      <section className="admin-card" aria-labelledby="new-story">
        <h2 id="new-story" className="section-title section-title--small">Write a story or tip</h2>
        <p className="muted">Admin stories go live straight away. Use a spotlight to feature a tailor.</p>
        <StoryForm
          admin
          tailors={tailors.data ?? []}
          onSave={async (form) => {
            await api.adminCreateStory(form)
            toast('Published to the home page.', 'good')
            res.reload()
          }}
        />
      </section>

      <div className="vtabs" role="tablist" aria-label="Filter stories">
        {(['all', 'pending', 'published', 'hidden'] as const).map((k) => (
          <button key={k} role="tab" aria-selected={status === k} className="vtabs__tab" onClick={() => setStatus(k)}>
            {FILTER_LABEL[k]}
          </button>
        ))}
      </div>

      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <Skeleton lines={3} />
      ) : res.data.length === 0 ? (
        <EmptyState title="No stories here" />
      ) : (
        res.data.map((s) => (
          <article key={s.id} className="admin-card">
            <div className="my-story__head">
              <strong>{s.title}</strong>
              <Badge tone={STATUS_TONE[s.status ?? 'pending'] ?? 'waiting'}>{STATUS_LABEL[s.status ?? 'pending']}</Badge>
            </div>
            <p className="muted">{s.kind_label} · {s.byline} · by {s.author_name ?? 'unknown'}</p>
            {editing === s.id ? (
              <StoryForm
                initial={s}
                admin
                tailors={tailors.data ?? []}
                onCancel={() => setEditing(null)}
                onSave={async (form) => {
                  await api.adminUpdateStory(s.id, form)
                  toast('Story saved.', 'good')
                  setEditing(null)
                  res.reload()
                }}
              />
            ) : (
              <div className="admin-card__actions">
                {s.status !== 'published' && (
                  <Button busy={busy === `p${s.id}`} onClick={() => void act(`p${s.id}`, () => api.adminSetStoryStatus(s.id, 'published', null), 'Published.')}>Publish</Button>
                )}
                {s.status !== 'hidden' && (
                  <Button
                    variant="secondary"
                    busy={busy === `h${s.id}`}
                    onClick={() => {
                      const note = window.prompt('Why is this story hidden? (shown to the author)', '')
                      if (note && note.trim().length >= 5) void act(`h${s.id}`, () => api.adminSetStoryStatus(s.id, 'hidden', note.trim()), 'Hidden.')
                      else if (note !== null) toast('Give a reason of at least 5 characters.', 'neutral')
                    }}
                  >
                    Hide
                  </Button>
                )}
                <Button variant="secondary" onClick={() => setEditing(s.id)}>Edit</Button>
                <Button
                  variant="quiet"
                  busy={busy === `d${s.id}`}
                  onClick={() => {
                    if (window.confirm('Delete this story for good?')) void act(`d${s.id}`, () => api.adminDeleteStory(s.id), 'Deleted.')
                  }}
                >
                  Delete
                </Button>
              </div>
            )}
          </article>
        ))
      )}
    </div>
  )
}
