import { useState, type FormEvent } from 'react'
import { api, type DisputeReason, type FabricOrder } from '../api'
import { DISPUTE_REASONS } from '../api/types'
import { Button, Field, errorMessage } from './ui'
import { formatDate } from '../lib/format'

/**
 * Buyer protection, as the buyer sees it: the delivery code they hand over only when the fabric
 * is in their hands, a way to report a problem, and any refund on its way.
 */
export function DeliveryCodeCard({ order }: { order: FabricOrder }) {
  if (!order.delivery_code) return null
  const pickup = order.fulfilment === 'pickup'
  return (
    <section className="protect protect--code" aria-labelledby="dc-title">
      <p id="dc-title" className="label">Your delivery code</p>
      <p className="protect__code" aria-label={`Delivery code ${order.delivery_code.split('').join(' ')}`}>
        {order.delivery_code.slice(0, 3)}<span aria-hidden> </span>{order.delivery_code.slice(3)}
      </p>
      <p className="protect__text">
        {pickup
          ? 'Give this to the vendor when you collect your fabric, after you have checked it.'
          : 'Give this to the rider only when the fabric is in your hands and you have checked it.'}{' '}
        It tells OSAPS you have your order. Never share it before then, not even on the phone.
      </p>
    </section>
  )
}

export function ProtectionNote({ order }: { order: FabricOrder }) {
  const days = order.protection_days ?? 7
  if (order.status === 'cancelled' || order.status === 'pending_payment') return null
  if (order.status === 'completed') return <p className="protect__note">Completed. The vendor is paid for this order.</p>
  return (
    <p className="protect__note">
      <strong>Protected.</strong> Your money stays with OSAPS until you have the fabric. After you receive it, you have {days} days to report a problem before the vendor is paid.
    </p>
  )
}

export function ProblemReport({ order, onChange }: { order: FabricOrder; onChange: (o: FabricOrder) => void }) {
  const [open, setOpen] = useState(false)
  const [reason, setReason] = useState<DisputeReason>('not_received')
  const [details, setDetails] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const d = order.dispute

  if (d?.status) {
    return (
      <section className={`protect protect--dispute protect--${d.status}`} aria-live="polite">
        <p className="label">Problem report</p>
        <p><strong>{d.reason_label}</strong>{d.opened_at ? ` · reported ${formatDate(d.opened_at, { day: 'numeric', month: 'short' })}` : ''}</p>
        {d.details && <p className="muted">“{d.details}”</p>}
        {d.status === 'open' && <p>OSAPS support is looking into it and will contact you and the vendor. The vendor is not paid until this is settled.</p>}
        {d.status !== 'open' && (
          <p>
            <strong>{d.status === 'refunded' ? 'Settled with a refund.' : 'Closed after checking with both sides.'}</strong>
            {d.resolution_note ? ` ${d.resolution_note}` : ''}
          </p>
        )}
      </section>
    )
  }
  if (!d?.can_open) return null

  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try {
      onChange(await api.reportProblem(order.reference, reason, details))
      setOpen(false)
    } catch (err) { setError(errorMessage(err)) } finally { setBusy(false) }
  }

  if (!open) {
    return (
      <p className="protect__report">
        <Button variant="quiet" onClick={() => setOpen(true)}>Report a problem</Button>
      </p>
    )
  }
  return (
    <form className="protect protect--form" onSubmit={submit}>
      <p className="label">Report a problem</p>
      <Field label="What went wrong?">
        {(p) => (
          <select {...p} className="input" value={reason} onChange={(e) => setReason(e.target.value as DisputeReason)}>
            {Object.entries(DISPUTE_REASONS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
          </select>
        )}
      </Field>
      <Field label="Tell us more" hint="What you expected, what happened, and anything the vendor said. At least 10 characters.">
        {(p) => <textarea {...p} className="input" rows={4} minLength={10} maxLength={2000} required value={details} onChange={(e) => setDetails(e.target.value)} />}
      </Field>
      {error && <p className="field__error" role="alert">{error}</p>}
      <div className="protect__actions">
        <Button type="submit" busy={busy}>Send to OSAPS support</Button>
        <Button type="button" variant="quiet" onClick={() => setOpen(false)}>Cancel</Button>
      </div>
      <p className="muted">This pauses the order and the vendor's payment until OSAPS settles it.</p>
    </form>
  )
}

export function RefundList({ order }: { order: FabricOrder }) {
  if (!order.refunds?.length) return null
  return (
    <section className="protect protect--refund">
      <p className="label">Refund</p>
      <ul className="protect__refunds">
        {order.refunds.map((r) => (
          <li key={r.id}>
            <strong>{r.amount.formatted}</strong> · <span className={`refund refund--${r.status}`}>{r.label}</span>
            {r.at ? <small> · {formatDate(r.at, { day: 'numeric', month: 'short' })}</small> : null}
            {r.failure ? <small className="muted"> · {r.failure}</small> : null}
          </li>
        ))}
      </ul>
      <p className="muted">Refunds go back to the card or account you paid with. Banks usually take a few working days.</p>
    </section>
  )
}

/** The vendor's "received" step: the buyer's 6-digit code proves the handover. */
export function DeliveredWithCode({ order, onDone }: { order: FabricOrder; onDone: (o: FabricOrder) => void }) {
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  if (order.delivery_code_locked) {
    return <p className="field__error">Too many wrong codes. Ask the buyer to confirm in the app, or contact OSAPS support.</p>
  }
  const submit = async (e: FormEvent) => {
    e.preventDefault()
    setBusy(true); setError(null)
    try { onDone(await api.transitionFabricOrder(order.reference, 'delivered', null, code)) } catch (err) { setError(errorMessage(err)) } finally { setBusy(false) }
  }
  const pickup = order.fulfilment === 'pickup'
  return (
    <form className="code-entry" onSubmit={submit}>
      <label className="code-entry__label" htmlFor={`code-${order.reference}`}>
        {pickup ? 'Buyer collected it? Enter their code' : 'Delivered? Enter the buyer’s code'}
      </label>
      <span className="code-entry__row">
        <input
          id={`code-${order.reference}`} className="input code-entry__input" inputMode="numeric" autoComplete="one-time-code"
          pattern="[0-9 ]{6,7}" maxLength={7} placeholder="000 000" value={code} onChange={(e) => setCode(e.target.value)} required
          aria-describedby={error ? `code-err-${order.reference}` : undefined}
        />
        <Button type="submit" busy={busy}>Confirm</Button>
      </span>
      {error && <span id={`code-err-${order.reference}`} className="field__error" role="alert">{error}</span>}
    </form>
  )
}
