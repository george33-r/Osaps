import { useEffect, useState, type FormEvent } from 'react'
import { api, type Announcement, type AnnouncementAudience, type Story } from '../api'
import { Button, errorMessage, Field } from './ui'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const AUDIENCE_LABEL: Record<AnnouncementAudience, string> = {
  everyone: 'Everyone who opted in',
  tailors: 'Tailors who opted in',
  customers: 'Customers who opted in',
}

/** Admin: email announcements. Only people who opted in are counted and sent to; every send is logged. */
export function AdminAnnouncements() {
  const toast = useToast()
  const history = useAsync<Announcement[]>(() => api.adminAnnouncements(), [])
  const published = useAsync<Story[]>(() => api.adminStories('published'), [])
  const [audience, setAudience] = useState<AnnouncementAudience>('everyone')
  const [recipients, setRecipients] = useState<number | null>(null)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [storyId, setStoryId] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    let live = true
    setRecipients(null)
    api.announcementAudience(audience)
      .then((r) => { if (live) setRecipients(r.recipients) })
      .catch(() => { if (live) setRecipients(0) })
    return () => { live = false }
  }, [audience])

  const valid = subject.trim().length >= 3 && body.trim().length >= 10

  const send = async (e: FormEvent) => {
    e.preventDefault()
    if (!recipients) {
      toast('Nobody has opted in to this group yet.', 'neutral')
      return
    }
    const ok = window.confirm(`Send "${subject.trim()}" to ${recipients} ${recipients === 1 ? 'person' : 'people'}? This cannot be undone.`)
    if (!ok) return
    setBusy(true)
    try {
      const r = await api.sendAnnouncement({
        audience, subject: subject.trim(), body: body.trim(), story_id: storyId ? Number(storyId) : null,
      })
      toast(`Queued for ${r.recipients} ${r.recipients === 1 ? 'person' : 'people'}. Emails go out over the next few minutes.`, 'good')
      setSubject('')
      setBody('')
      setStoryId('')
      history.reload()
    } catch (err) {
      toast(errorMessage(err), 'neutral')
    } finally {
      setBusy(false)
    }
  }

  return (
    <section className="admin-section" aria-labelledby="announce-title">
      <header className="sh-section__head">
        <h2 id="announce-title" className="sh-section__title">Email announcements</h2>
      </header>
      <p className="muted">Only people who turned on OSAPS news in their account receive these. Every email has an unsubscribe link. One announcement can go out every 10 minutes.</p>

      <form className="new-listing" onSubmit={send}>
        <Field label="Who should receive it">
          {(p) => (
            <select {...p} className="input" value={audience} onChange={(e) => setAudience(e.target.value as AnnouncementAudience)}>
              {(Object.keys(AUDIENCE_LABEL) as AnnouncementAudience[]).map((k) => <option key={k} value={k}>{AUDIENCE_LABEL[k]}</option>)}
            </select>
          )}
        </Field>
        <p className="muted" aria-live="polite">
          {recipients === null ? 'Counting recipients…' : `${recipients} ${recipients === 1 ? 'person' : 'people'} will receive this.`}
        </p>
        <Field label="Subject">
          {(p) => <input {...p} className="input" value={subject} maxLength={150} onChange={(e) => setSubject(e.target.value)} />}
        </Field>
        <Field label="Message" hint="Plain text. Keep it short and clear.">
          {(p) => <textarea {...p} className="input" rows={6} maxLength={3000} value={body} onChange={(e) => setBody(e.target.value)} />}
        </Field>
        <Field label="Attach a published story" optional>
          {(p) => (
            <select {...p} className="input" value={storyId} onChange={(e) => setStoryId(e.target.value)}>
              <option value="">No story</option>
              {(published.data ?? []).map((s) => <option key={s.id} value={s.id}>{s.title}</option>)}
            </select>
          )}
        </Field>
        <Button type="submit" busy={busy} disabled={!valid || !recipients}>Send announcement</Button>
      </form>

      <h3 className="sh-section__title">Sent</h3>
      {history.data && history.data.length === 0 && <p className="muted">Nothing sent yet.</p>}
      {history.data && history.data.length > 0 && (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Sent</th><th>To</th><th>Subject</th><th>Recipients</th><th>By</th></tr></thead>
            <tbody>
              {history.data.map((a) => (
                <tr key={a.id}>
                  <td>{a.sent_at ? new Date(a.sent_at).toLocaleString('en-NG', { dateStyle: 'medium', timeStyle: 'short' }) : '—'}</td>
                  <td>{AUDIENCE_LABEL[a.audience]}</td>
                  <td>{a.subject}</td>
                  <td>{a.recipients}</td>
                  <td>{a.sent_by ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </section>
  )
}
