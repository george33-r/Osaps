import { useState } from 'react'
import { Link, useParams } from 'react-router-dom'
import { api, type Order, type OrderStatus } from '../api'
import { Button, Dialog, EmptyState, ErrorState, Field, OrderStatusBadge, PageHeader, Skeleton, errorMessage } from '../components/ui'
import { formatDate, plural } from '../lib/format'
import { useSession } from '../lib/session'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

export function OrderList() {
  const { user } = useSession()
  const res = useAsync(() => api.orders(), [])
  return (
    <div className="page">
      <PageHeader title="Orders" />
      {res.status === 'error' && !res.data ? (
        <ErrorState error={res.error} onRetry={res.reload} />
      ) : !res.data ? (
        <Skeleton lines={5} />
      ) : res.data.data.length === 0 ? (
        <EmptyState title="No orders yet">
          {user?.role === 'tailor' ? 'When a customer accepts one of your offers, the order appears here.' : 'When you accept an offer, your order appears here.'}
        </EmptyState>
      ) : (
        <ul className="rows">
          {res.data.data.map((o) => (
            <li key={o.reference}>
              <Link to={`/orders/${o.reference}`} className="row">
                <div className="row__main">
                  <h2 className="row__title">{o.spec.title}</h2>
                  <p className="row__meta">
                    {o.reference}
                    {o.due_on && <>, due {formatDate(o.due_on)}</>}
                  </p>
                </div>
                <div className="row__side">
                  <OrderStatusBadge status={o.status} label={o.status_label} />
                  <span className="row__count">{o.costs.total.formatted}</span>
                </div>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

function Timeline({ order }: { order: Order }) {
  const reached = new Set(order.events?.map((e) => e.to))
  const offPath = order.status === 'cancelled' || order.status === 'disputed'
  return (
    <ol className="timeline">
      {order.milestones.map((m) => {
        const event = [...(order.events ?? [])].reverse().find((e) => e.to === m.status)
        const finished = order.status === 'completed'
        const state = m.status === order.status && !finished ? 'current' : reached.has(m.status) ? 'done' : 'todo'
        return (
          <li key={m.status} className={`timeline__step timeline__step--${state}`} aria-current={state === 'current' ? 'step' : undefined}>
            <span className="timeline__dot" aria-hidden />
            <span className="timeline__label">{m.label}</span>
            {event && <span className="timeline__when">{formatDate(event.at, { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' })}</span>}
            {event?.note && <span className="timeline__note">{event.note}</span>}
          </li>
        )
      })}
      {offPath && (
        <li className="timeline__step timeline__step--alert" aria-current="step">
          <span className="timeline__dot" aria-hidden />
          <span className="timeline__label">{order.status_label}</span>
          {order.events?.at(-1)?.note && <span className="timeline__note">{order.events.at(-1)!.note}</span>}
        </li>
      )}
    </ol>
  )
}

const ACTION_COPY: Partial<Record<OrderStatus, string>> = {
  in_progress: 'Start work',
  fitting: 'Ready for fitting',
  ready: 'Mark ready',
  delivered: 'Mark delivered',
  completed: 'Confirm I received it',
  cancelled: 'Cancel order',
  disputed: 'Report a problem',
}

export function OrderDetail() {
  const { reference = '' } = useParams()
  const toast = useToast()
  const res = useAsync(() => api.order(reference), [reference])
  const [pending, setPending] = useState<{ status: OrderStatus; label: string } | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const [dialogError, setDialogError] = useState<string | null>(null)

  if (res.status === 'error' && !res.data) return <div className="page"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page"><Skeleton lines={8} /></div>
  const o = res.data
  const t = o.spec.terms

  const move = async (status: OrderStatus, reason: string | null) => {
    setBusy(true)
    setDialogError(null)
    try {
      const updated = await api.transition(o.reference, status, reason)
      toast(`Order is now: ${updated.status_label.toLowerCase()}.`, status === 'disputed' || status === 'cancelled' ? 'neutral' : 'good')
      setPending(null)
      setNote('')
      res.reload()
    } catch (e) {
      if (pending) setDialogError(errorMessage(e))
      else toast(errorMessage(e), 'alert')
      res.reload()
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page">
      <nav className="crumbs" aria-label="Breadcrumb">
        <Link to="/orders">Orders</Link> <span aria-hidden>/</span> <span>{o.reference}</span>
      </nav>
      <PageHeader
        title={o.spec.title}
        intro={
          <>
            Order {o.reference} with {o.viewer_role === 'tailor' ? o.customer?.name : o.tailor?.business_name}
            {o.due_on && <>, due {formatDate(o.due_on)}</>}.
          </>
        }
        aside={<OrderStatusBadge status={o.status} label={o.status_label} />}
      />

      <div className="request-layout">
        <section className="request-layout__offers">
          <h2 className="section-title section-title--small">Progress</h2>
          <Timeline order={o} />
          {o.actions.length > 0 && (
            <div className="offer__actions">
              {o.actions.map((a, i) =>
                a.requires_reason ? (
                  <Button key={a.status} variant={a.status === 'cancelled' ? 'quiet' : 'secondary'} onClick={() => setPending(a)}>
                    {ACTION_COPY[a.status] ?? a.label}
                  </Button>
                ) : (
                  <Button key={a.status} variant={i === 0 ? 'primary' : 'secondary'} busy={busy} onClick={() => move(a.status, null)}>
                    {ACTION_COPY[a.status] ?? a.label}
                  </Button>
                ),
              )}
            </div>
          )}
          {o.status === 'disputed' && <p className="notice">An Osapa team member will review this and contact both of you. Nothing is decided automatically.</p>}
        </section>

        <aside className="request-layout__side">
          <section className="brief">
            <h2 className="brief__title">Agreed terms</h2>
            <p className="muted">Locked when the offer was accepted on {formatDate(o.spec.accepted_at)}. Changes need both of you to agree.</p>
            <dl className="terms terms--compact">
              <div><dt>Ready in</dt><dd>{t.delivery_days ? plural(t.delivery_days, 'day') : 'Not stated'}</dd></div>
              <div><dt>Fitting</dt><dd>{t.includes_fitting === null ? 'Not stated' : t.includes_fitting ? 'Included' : 'Not included'}</dd></div>
              <div><dt>Free alterations</dt><dd>{t.alterations_included ?? 'Not stated'}</dd></div>
              <div><dt>City</dt><dd>{o.spec.city}</dd></div>
            </dl>
            <p className="brief__desc">{o.spec.description}</p>
          </section>

          <section className="brief">
            <h2 className="brief__title">{o.viewer_role === 'tailor' ? 'Your earnings' : 'Cost'}</h2>
            <dl className="terms terms--compact">
              {o.viewer_role === 'tailor' && o.tailor_payout ? (
                <>
                  <div><dt>Tailoring price</dt><dd>{o.tailor_payout.gross.formatted}</dd></div>
                  <div><dt>Osapa commission</dt><dd>−{o.tailor_payout.commission.formatted}</dd></div>
                  <div className="terms__total"><dt>You receive</dt><dd>{o.tailor_payout.net.formatted}</dd></div>
                </>
              ) : (
                <>
                  <div><dt>Tailoring</dt><dd>{o.costs.tailoring.formatted}</dd></div>
                  {o.costs.delivery.amount_minor > 0 && <div><dt>Delivery</dt><dd>{o.costs.delivery.formatted}</dd></div>}
                  <div><dt>Service fee</dt><dd>{o.costs.service_fee.formatted}</dd></div>
                  <div className="terms__total"><dt>Total</dt><dd>{o.costs.total.formatted}</dd></div>
                </>
              )}
            </dl>
            <p className="muted">Payment status: {o.payment_status === 'unpaid' ? 'not paid through Osapa. In-app payment is not available yet.' : o.payment_status}</p>
            {o.viewer_role === 'tailor' && o.customer?.phone && <p>Customer phone: {o.customer.phone}</p>}
          </section>
        </aside>
      </div>

      <Dialog open={pending !== null} onClose={() => setPending(null)} title={pending ? ACTION_COPY[pending.status] ?? pending.label : ''}>
        <form
          className="form"
          noValidate
          onSubmit={(e) => {
            e.preventDefault()
            if (pending) void move(pending.status, note.trim())
          }}
        >
          <p className="muted">
            {pending?.status === 'disputed'
              ? 'Tell us what went wrong. A person on the Osapa team reviews every report.'
              : 'Say why you are cancelling. The other side will see this.'}
          </p>
          <Field label="Reason" error={dialogError ?? undefined}>
            {(p) => <textarea {...p} className="input input--area" rows={4} value={note} onChange={(e) => setNote(e.target.value)} minLength={10} maxLength={1000} autoFocus />}
          </Field>
          <div className="dialog__actions">
            <Button type="submit" variant={pending?.status === 'cancelled' ? 'danger' : 'primary'} busy={busy}>
              {pending ? ACTION_COPY[pending.status] : ''}
            </Button>
            <Button type="button" variant="quiet" onClick={() => setPending(null)}>
              Go back
            </Button>
          </div>
        </form>
      </Dialog>
    </div>
  )
}
