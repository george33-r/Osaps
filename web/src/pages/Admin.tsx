import { useState, type ReactNode } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { api, type AdminOrderRow, type AdminTailorQueueRow, type AdminVendor, type VendorVerificationStatus } from '../api'
import { Badge, Button, EmptyState, ErrorState, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { formatDate } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

type Tab = 'overview' | 'vendors' | 'tailors' | 'disputes' | 'refunds' | 'payouts' | 'stuck' | 'log'

const when = (iso: string | null | undefined) => (iso ? formatDate(iso, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—')

/**
 * The OSAPS back office. Every action here is checked again on the server and written to the
 * admin log. Payouts take two different admins: one approves, the other records the transfer.
 */
export function AdminPage() {
  const { user } = useSession()
  const [params, setParams] = useSearchParams()
  const tab = (params.get('tab') as Tab) || 'overview'
  const overview = useAsync(() => (user?.role === 'admin' ? api.adminOverview() : Promise.resolve(null)), [user?.role, tab])

  if (user?.role !== 'admin') {
    return <div className="page page--narrow"><EmptyState title="Admins only">This page is for the OSAPS team.</EmptyState></div>
  }
  const o = overview.data
  const tabs: { key: Tab; label: string; n?: number }[] = [
    { key: 'overview', label: 'Overview' },
    { key: 'vendors', label: 'Shops to verify', n: o?.vendors_pending },
    { key: 'tailors', label: 'Tailors to verify' },
    { key: 'disputes', label: 'Problems', n: o?.disputes_open },
    { key: 'refunds', label: 'Refunds', n: (o?.refunds_owed ?? 0) + (o?.refunds_unconfirmed ?? 0) },
    { key: 'payouts', label: 'Payouts', n: (o?.payouts_releasable ?? 0) + (o?.payouts_approved ?? 0) },
    { key: 'stuck', label: 'Stuck orders', n: o?.stuck },
    { key: 'log', label: 'Admin log' },
  ]
  return (
    <div className="page">
      <PageHeader label="OSAPS team" title="Back office" intro="Verify shops, settle problems, send refunds and pay vendors. Every action is logged with your name." />
      <div className="vtabs" role="tablist">
        {tabs.map((t) => (
          <button key={t.key} role="tab" aria-selected={tab === t.key} className="vtabs__tab" onClick={() => setParams({ tab: t.key }, { replace: true })}>
            {t.label}{t.n ? <span className="vtabs__n">{t.n}</span> : null}
          </button>
        ))}
      </div>
      {tab === 'overview' && <Overview state={overview} go={(t) => setParams({ tab: t }, { replace: true })} />}
      {tab === 'vendors' && <Vendors onChange={overview.reload} />}
      {tab === 'tailors' && <TailorChecks />}
      {tab === 'disputes' && <Disputes onChange={overview.reload} />}
      {tab === 'refunds' && <Refunds onChange={overview.reload} />}
      {tab === 'payouts' && <Payouts onChange={overview.reload} />}
      {tab === 'stuck' && <Stuck />}
      {tab === 'log' && <Log />}
    </div>
  )
}

function Overview({ state, go }: { state: ReturnType<typeof useAsync<Awaited<ReturnType<typeof api.adminOverview>> | null>>; go: (t: Tab) => void }) {
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  const o = state.data
  if (!o) return <Skeleton lines={4} />
  const cronAgeMin = o.cron_last_run ? Math.round((Date.now() - Date.parse(o.cron_last_run)) / 60_000) : null
  const cronOk = cronAgeMin !== null && cronAgeMin <= 10
  const card = (t: Tab, n: number, label: string) => (
    <button className={`admin-card ${n ? 'admin-card--due' : ''}`} onClick={() => go(t)}>
      <strong>{n}</strong><span>{label}</span>
    </button>
  )
  return (
    <>
      <div className="admin-cards">
        {card('vendors', o.vendors_pending, 'shops waiting for review')}
        {card('disputes', o.disputes_open, 'open problem reports')}
        {card('refunds', o.refunds_owed, 'refunds owed')}
        {card('refunds', o.refunds_unconfirmed, 'refunds Paystack did not confirm')}
        {card('payouts', o.payouts_releasable, 'payouts to approve')}
        {card('payouts', o.payouts_approved, 'approved payouts to send')}
        {card('stuck', o.stuck, 'orders with no handover for a week')}
      </div>
      <h2 className="section-title section-title--small">System checks</h2>
      <ul className="admin-checks">
        <Check ok={o.payments_enabled} label="Paystack" good="Switched on" bad="Off: set PAYSTACK_SECRET_KEY in .env" />
        <Check ok={cronOk} label="Cron job (expiry, auto-complete, WhatsApp)"
          good={`Last ran ${cronAgeMin} min ago`}
          bad={o.cron_last_run ? `Last ran ${when(o.cron_last_run)}. Check the hPanel cron job.` : 'Has never run. Add the hPanel cron job (see UPDATE-STEPS).'} />
        <Check ok={o.queue === 'sync' || (o.queued_jobs ?? 0) < 50} label="WhatsApp queue" good={o.queue === 'sync' ? 'Sends straight away (sync)' : `${o.queued_jobs ?? 0} waiting`} bad={`${o.queued_jobs} waiting: the cron job may not be running`} />
        <Check ok={o.mail !== 'log' && o.mail !== 'array'} label="Email (password reset)" good={`Sending by ${o.mail}`} bad="Not set up: reset emails are only written to the log. Set MAIL_* in .env." />
        <Check ok={o.two_person_payouts} label="Two-person payouts" good="On: one admin approves, another sends" bad="Off: one admin can approve and send. Turn on once you have two admins." />
      </ul>
    </>
  )
}

function Check({ ok, label, good, bad }: { ok: boolean; label: string; good: string; bad: string }) {
  return <li className={`admin-check admin-check--${ok ? 'ok' : 'bad'}`}><Badge tone={ok ? 'good' : 'alert'}>{ok ? 'OK' : 'Fix'}</Badge><strong>{label}</strong><span>{ok ? good : bad}</span></li>
}

/** One small decision form: a note and two or three buttons. */
function Decide({ actions, noteLabel, noteRequired, onDone }: {
  actions: { key: string; label: string; variant?: 'primary' | 'secondary' | 'danger'; needsNote?: boolean }[]
  noteLabel: string
  noteRequired?: boolean
  onDone: (key: string, note: string) => Promise<void>
}) {
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const id = `note-${Math.random().toString(36).slice(2, 8)}`
  return (
    <div className="decide">
      <label className="field__label" htmlFor={id}>{noteLabel}</label>
      <textarea id={id} className="input" rows={2} maxLength={1000} value={note} onChange={(e) => setNote(e.target.value)} />
      <div className="decide__actions">
        {actions.map((a) => (
          <Button key={a.key} variant={a.variant ?? 'secondary'} busy={busy === a.key}
            disabled={(noteRequired || a.needsNote) && note.trim().length < 5}
            onClick={async () => { setBusy(a.key); setError(null); try { await onDone(a.key, note.trim()); setNote('') } catch (e) { setError(errorMessage(e)) } finally { setBusy(null) } }}>
            {a.label}
          </Button>
        ))}
      </div>
      {error && <p className="field__error" role="alert">{error}</p>}
    </div>
  )
}

function OrderHead({ o, extra }: { o: AdminOrderRow; extra?: ReactNode }) {
  return (
    <header className="vorder__head">
      <strong><Link to={`/shop/orders/${o.reference}`}>{o.reference}</Link></strong>
      <Badge tone="neutral">{o.status_label}</Badge>
      <span>{o.total.formatted}</span>
      {extra}
      <span className="vorder__when">{when(o.created_at)}</span>
    </header>
  )
}

function People({ o }: { o: AdminOrderRow }) {
  return (
    <dl className="admin-people">
      <div><dt>Buyer</dt><dd>{o.buyer?.name ?? '—'}{o.buyer?.phone ? <> · <a href={`tel:${o.buyer.phone}`}>{o.buyer.phone}</a></> : null}</dd></div>
      <div><dt>Vendor</dt><dd>{o.vendor?.business_name ?? '—'}{o.vendor?.phone ? <> · <a href={`tel:${o.vendor.phone}`}>{o.vendor.phone}</a></> : null}</dd></div>
      {o.items && <div><dt>Items</dt><dd>{o.items}</dd></div>}
    </dl>
  )
}

function Vendors({ onChange }: { onChange: () => void }) {
  const [status, setStatus] = useState<VendorVerificationStatus>('pending')
  const state = useAsync(() => api.adminVendors(status), [status])
  const toast = useToast()
  return (
    <>
      <div className="chips" role="group" aria-label="Show shops">
        {(['pending', 'verified', 'rejected', 'suspended', 'unverified'] as const).map((s) => (
          <button key={s} className="chip" aria-pressed={status === s} onClick={() => setStatus(s)}>{{ pending: 'Waiting', verified: 'Verified', rejected: 'Changes needed', suspended: 'Suspended', unverified: 'Not submitted' }[s]}</button>
        ))}
      </div>
      {state.status === 'error' && <ErrorState error={state.error} onRetry={state.reload} />}
      {state.status === 'loading' && <Skeleton lines={4} />}
      {state.data?.length === 0 && <EmptyState title="Nothing here">No shops with this status.</EmptyState>}
      <div className="vorders">
        {state.data?.map((v: AdminVendor) => (
          <article key={v.id} className="vorder">
            <header className="vorder__head">
              <strong>{v.business_name}</strong>
              <Badge tone={v.status === 'verified' ? 'good' : v.status === 'pending' ? 'waiting' : 'alert'}>{v.label}</Badge>
              {v.is_demo && <Badge tone="muted">Demo</Badge>}
              <span className="vorder__when">Asked {when(v.requested_at)}</span>
            </header>
            <dl className="admin-people">
              <div><dt>Where</dt><dd>{[v.market, v.city].filter(Boolean).join(', ') || '—'}</dd></div>
              <div><dt>Owner</dt><dd>{v.owner.name} · {v.owner.email} · joined {v.owner.joined ?? '—'}</dd></div>
              <div><dt>Phone</dt><dd>{v.phone ? <a href={`tel:${v.phone}`}>{v.phone}</a> : '—'}{v.whatsapp ? ` · WhatsApp ${v.whatsapp}` : ''}</dd></div>
              <div><dt>Bank</dt><dd>{v.bank.name ?? '—'} · {v.bank.account_name ?? '—'} · <code>{v.bank.account_number ?? '—'}</code></dd></div>
              <div><dt>Listings</dt><dd>{v.fabrics}</dd></div>
              {v.note && <div><dt>Last note</dt><dd>{v.note}</dd></div>}
            </dl>
            <p className="muted">Before verifying: call the phone number, and check the account name matches the shop or owner (most banking apps show the name when you start a transfer).</p>
            <Decide
              noteLabel="Note to the vendor (needed to reject or suspend)"
              actions={[
                ...(v.status === 'pending' ? [{ key: 'verified', label: 'Verify shop', variant: 'primary' as const }, { key: 'rejected', label: 'Needs changes', needsNote: true }] : []),
                ...(v.status === 'verified' ? [{ key: 'suspended', label: 'Suspend', variant: 'danger' as const, needsNote: true }] : []),
                ...(v.status === 'suspended' ? [{ key: 'verified', label: 'Reinstate', variant: 'primary' as const }] : []),
              ]}
              onDone={async (to, note) => {
                await api.adminReviewVendor(v.id, to as 'verified' | 'rejected' | 'suspended', note || null)
                toast(`${v.business_name}: ${to}.`, 'good'); state.reload(); onChange()
              }}
            />
          </article>
        ))}
      </div>
    </>
  )
}

function Disputes({ onChange }: { onChange: () => void }) {
  const state = useAsync(() => api.adminDisputes(), [])
  const toast = useToast()
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!state.data) return <Skeleton lines={4} />
  if (state.data.length === 0) return <EmptyState title="No open problems">When a buyer reports a problem, it lands here. The order and payout are paused until you settle it.</EmptyState>
  return (
    <div className="vorders">
      {state.data.map((o) => (
        <article key={o.reference} className="vorder needs-action">
          <OrderHead o={o} extra={<Badge tone="alert">{o.reason}</Badge>} />
          <p>“{o.details}” <small className="muted">· reported {when(o.opened_at)}</small></p>
          <People o={o} />
          <p className="muted">Call both sides. Refund cancels the order and sends the full amount back through Paystack. Release completes it and the vendor can be paid.</p>
          <Decide noteLabel="How it was settled (both sides see this)" noteRequired
            actions={[{ key: 'refund', label: 'Refund buyer', variant: 'danger' }, { key: 'release', label: 'Release to vendor' }]}
            onDone={async (key, note) => { await api.adminResolveDispute(o.reference, key as 'refund' | 'release', note); toast('Settled.', 'good'); state.reload(); onChange() }} />
        </article>
      ))}
    </div>
  )
}

function Refunds({ onChange }: { onChange: () => void }) {
  const state = useAsync(() => api.adminRefunds(), [])
  const toast = useToast()
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!state.data) return <Skeleton lines={4} />
  const { owed, recent } = state.data
  return (
    <>
      <h2 className="section-title section-title--small">Owed to buyers</h2>
      {owed.length === 0 ? <p className="muted">Nothing owed. Cancelled paid orders show up here until they are refunded.</p> : (
        <div className="vorders">
          {owed.map((o) => (
            <article key={o.reference} className="vorder needs-action">
              <OrderHead o={o} extra={<span>Still owed <strong>{o.remaining?.formatted}</strong></span>} />
              <People o={o} />
              {o.checkout_status === 'needs_refund' && <p className="muted">Paid after the fabric sold out.</p>}
              <Decide noteLabel="Reason (kept in the log)" noteRequired actions={[{ key: 'refund', label: `Refund ${o.remaining?.formatted ?? ''}`, variant: 'danger' }]}
                onDone={async (_k, note) => {
                  const r = await api.adminRefund(o.reference, note)
                  toast(r.failure ? r.failure : r.status === 'failed' ? 'Paystack refused the refund.' : 'Refund sent to Paystack.', r.status === 'failed' || r.failure ? 'alert' : 'good')
                  state.reload(); onChange()
                }} />
            </article>
          ))}
        </div>
      )}
      <h2 className="section-title section-title--small">Recent refunds</h2>
      {recent.length === 0 ? <p className="muted">No refunds yet.</p> : (
        <div className="table-wrap">
          <table className="table">
            <thead><tr><th>Order</th><th>Amount</th><th>Status</th><th>When</th><th>Note</th><th><span className="sr-only">Action</span></th></tr></thead>
            <tbody>
              {recent.map((r) => (
                <tr key={r.id}>
                  <td>{r.order}</td><td>{r.amount.formatted}</td>
                  <td><Badge tone={r.status === 'processed' ? 'good' : r.status === 'failed' ? 'alert' : 'waiting'}>{r.label}</Badge></td>
                  <td>{when(r.at)}</td><td className="muted">{r.failure ?? r.reason}</td>
                  <td>{r.status === 'pending' && r.failure && <MarkFailed id={r.id} onDone={() => { state.reload(); onChange() }} />}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </>
  )
}

function MarkFailed({ id, onDone }: { id: number; onDone: () => void }) {
  const [busy, setBusy] = useState(false)
  return (
    <Button variant="quiet" busy={busy} onClick={async () => {
      const note = window.prompt('Checked the Paystack dashboard and no refund was sent? Say what you checked:')
      if (!note || note.trim().length < 5) return
      setBusy(true)
      try { await api.adminMarkRefundFailed(id, note.trim()); onDone() } catch (e) { window.alert(errorMessage(e)) } finally { setBusy(false) }
    }}>Not sent: mark failed</Button>
  )
}

function Payouts({ onChange }: { onChange: () => void }) {
  const state = useAsync(() => api.adminPayouts(), [])
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [refs, setRefs] = useState<Record<string, string>>({})
  const toast = useToast()
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!state.data) return <Skeleton lines={4} />
  const run = async (key: string, fn: () => Promise<void>, msg: string) => {
    setBusy(key); setError(null)
    try { await fn(); toast(msg, 'good'); state.reload(); onChange() } catch (e) { setError(errorMessage(e)) } finally { setBusy(null) }
  }
  return (
    <>
      <p className="muted">
        {state.data.two_person
          ? 'Two people: one admin approves after checking the order and bank details; a different admin sends the bank transfer and records its reference.'
          : 'Two-person payouts are off: one admin can approve and record. Turn SHOP_TWO_PERSON_PAYOUTS back on once you have two admins.'}
      </p>
      {error && <p className="field__error" role="alert">{error}</p>}
      {state.data.data.length === 0 && <EmptyState title="No payouts due">Orders appear here once they complete with no open problem.</EmptyState>}
      <div className="vorders">
        {state.data.data.map((g) => (
          <article key={g.vendor.id ?? g.vendor.business_name} className="vorder">
            <header className="vorder__head">
              <strong>{g.vendor.business_name}</strong>
              <Badge tone={g.vendor.verified ? 'good' : 'alert'}>{g.vendor.verified ? 'Verified' : 'Not verified'}</Badge>
              <span>Total due <strong>{g.amount.formatted}</strong></span>
            </header>
            <p>{g.vendor.bank_name} · {g.vendor.bank_account_name} · <code>{g.vendor.bank_account_number}</code></p>
            <ul className="admin-payouts">
              {g.orders.map((o) => (
                <li key={o.reference}>
                  <span><Link to={`/shop/orders/${o.reference}`}>{o.reference}</Link> · {o.amount.formatted} <small className="muted">completed {when(o.completed_at)}</small></span>
                  {o.payout_status === 'releasable' && (
                    <Button busy={busy === o.reference} onClick={() => run(o.reference, () => api.adminApprovePayout(o.reference), 'Approved. Another admin sends it.')}>Approve</Button>
                  )}
                  {o.payout_status === 'approved' && (o.approved_by_me && state.data!.two_person ? (
                    <small className="muted">You approved this {when(o.approved_at)}. Another admin must send it.</small>
                  ) : (
                    <span className="admin-pay">
                      <label className="sr-only" htmlFor={`tr-${o.reference}`}>Bank transfer reference</label>
                      <input id={`tr-${o.reference}`} className="input" placeholder="Transfer reference" value={refs[o.reference] ?? ''} onChange={(e) => setRefs({ ...refs, [o.reference]: e.target.value })} />
                      <Button busy={busy === o.reference} disabled={(refs[o.reference] ?? '').trim().length < 4}
                        onClick={() => run(o.reference, () => api.adminMarkPaid(o.reference, refs[o.reference]!.trim()), 'Recorded as paid. The vendor is told.')}>Sent: record it</Button>
                    </span>
                  ))}
                </li>
              ))}
            </ul>
          </article>
        ))}
      </div>
    </>
  )
}

function Stuck() {
  const state = useAsync(() => api.adminStuck(), [])
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!state.data) return <Skeleton lines={4} />
  if (state.data.length === 0) return <EmptyState title="Nothing stuck">Paid orders with no handover for a week show up here.</EmptyState>
  return (
    <div className="vorders">
      {state.data.map((o) => (
        <article key={o.reference} className="vorder">
          <OrderHead o={o} extra={<small className="muted">no change since {when(o.since)}</small>} />
          <People o={o} />
          <p className="muted">Call both sides. Open the order to confirm the handover or cancel it (then refund it under Refunds).</p>
        </article>
      ))}
    </div>
  )
}

function Log() {
  const state = useAsync(() => api.adminActions(), [])
  if (state.status === 'error') return <ErrorState error={state.error} onRetry={state.reload} />
  if (!state.data) return <Skeleton lines={4} />
  if (state.data.length === 0) return <EmptyState title="No admin actions yet">Every verification, refund, problem settlement and payout is recorded here.</EmptyState>
  return (
    <div className="table-wrap">
      <table className="table">
        <thead><tr><th>When</th><th>Admin</th><th>Action</th><th>On</th><th>Note</th></tr></thead>
        <tbody>
          {state.data.map((a) => <tr key={a.id}><td>{when(a.at)}</td><td>{a.admin}</td><td>{a.action.replace(/_/g, ' ')}</td><td>{a.subject}</td><td className="muted">{a.note}</td></tr>)}
        </tbody>
      </table>
    </div>
  )
}

/**
 * Tailor identity checks. Each document is opened here and approved or rejected one at a time.
 * A profile can be verified only once every required document is approved; the server checks again.
 */
function TailorChecks() {
  const toast = useToast()
  const [status, setStatus] = useState<'pending' | 'verified' | 'rejected' | 'suspended'>('pending')
  const queue = useAsync(() => api.adminTailorQueue(status), [status])
  const [busy, setBusy] = useState<string | null>(null)
  const [noteFor, setNoteFor] = useState<string | null>(null)
  const [note, setNote] = useState('')

  async function act(key: string, fn: () => Promise<unknown>, done: string) {
    setBusy(key)
    try {
      await fn()
      toast(done, 'good')
      setNoteFor(null)
      setNote('')
      queue.reload()
    } catch (e) {
      toast(errorMessage(e), 'alert')
    } finally {
      setBusy(null)
    }
  }

  const rows: AdminTailorQueueRow[] = queue.data ?? []

  return (
    <div>
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {(['pending', 'verified', 'rejected', 'suspended'] as const).map((s) => (
          <Button key={s} variant={status === s ? 'primary' : 'secondary'} onClick={() => setStatus(s)}>
            {s}
          </Button>
        ))}
      </div>
      {queue.status === 'error' && !queue.data ? (
        <ErrorState error={queue.error} onRetry={queue.reload} />
      ) : !queue.data ? (
        <Skeleton lines={5} />
      ) : rows.length === 0 ? (
        <EmptyState title="Nothing here">No tailor profiles are in this state.</EmptyState>
      ) : (
        <ul className="rows">
          {rows.map((p) => {
            const requiredApproved = ['id_document', 'selfie', 'workshop_photo'].every((k) =>
              p.documents.some((d) => d.kind === k && d.status === 'approved'),
            )
            return (
              <li key={p.id} className="rows__item" style={{ display: 'block' }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap' }}>
                  <div>
                    <strong>{p.business_name}</strong>
                    <p className="field__hint">
                      {p.city} · {p.owner.name ?? 'No name'} · {p.owner.phone ?? 'no phone'} · {p.owner.email ?? 'no email'}
                    </p>
                  </div>
                  <Badge tone={p.status === 'verified' ? 'good' : p.status === 'pending' ? 'waiting' : 'alert'}>{p.status}</Badge>
                </div>

                <ul style={{ marginTop: 10, display: 'grid', gap: 8 }}>
                  {p.documents.map((d) => (
                    <li key={d.id} style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap', alignItems: 'center' }}>
                      <span>
                        {d.label} <Badge tone={d.status === 'approved' ? 'good' : d.status === 'rejected' ? 'alert' : 'waiting'}>{d.status}</Badge>
                      </span>
                      <span style={{ display: 'flex', gap: 8 }}>
                        <a href={d.file_url} target="_blank" rel="noopener noreferrer">Open file</a>
                        {d.status !== 'approved' && (
                          <Button
                            variant="secondary"
                            busy={busy === `doc-${d.id}`}
                            onClick={() => act(`doc-${d.id}`, () => api.adminReviewTailorDocument(d.id, 'approved'), 'Document approved.')}
                          >
                            Approve
                          </Button>
                        )}
                        <Button variant="secondary" onClick={() => setNoteFor(`doc-${d.id}`)}>Reject</Button>
                      </span>
                      {noteFor === `doc-${d.id}` && (
                        <div style={{ width: '100%', display: 'flex', gap: 8 }}>
                          <input className="input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="What is wrong with this document?" aria-label="Reason" />
                          <Button
                            busy={busy === `doc-${d.id}`}
                            disabled={note.trim().length < 5}
                            onClick={() => act(`doc-${d.id}`, () => api.adminReviewTailorDocument(d.id, 'rejected', note), 'Document rejected.')}
                          >
                            Send
                          </Button>
                        </div>
                      )}
                    </li>
                  ))}
                </ul>

                <div style={{ display: 'flex', gap: 8, marginTop: 12, flexWrap: 'wrap' }}>
                  <Button
                    busy={busy === `p-${p.id}`}
                    disabled={!requiredApproved || p.status === 'verified'}
                    onClick={() => act(`p-${p.id}`, () => api.adminReviewTailorProfile(p.id, 'verified'), 'Profile verified. Badges are live.')}
                  >
                    Verify profile
                  </Button>
                  <Button variant="secondary" onClick={() => setNoteFor(`p-${p.id}`)}>Ask for changes</Button>
                  {p.status === 'verified' && (
                    <Button variant="secondary" onClick={() => setNoteFor(`s-${p.id}`)}>Suspend</Button>
                  )}
                </div>
                {(noteFor === `p-${p.id}` || noteFor === `s-${p.id}`) && (
                  <div style={{ display: 'flex', gap: 8, marginTop: 8 }}>
                    <input className="input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="Say what the tailor needs to fix" aria-label="Reason" />
                    <Button
                      busy={busy === `p-${p.id}`}
                      disabled={note.trim().length < 5}
                      onClick={() =>
                        act(
                          `p-${p.id}`,
                          () => api.adminReviewTailorProfile(p.id, noteFor?.startsWith('s-') ? 'suspended' : 'rejected', note),
                          'Saved.',
                        )
                      }
                    >
                      Send
                    </Button>
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
    </div>
  )
}
