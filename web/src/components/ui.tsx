import { useEffect, useId, useRef, type ButtonHTMLAttributes, type ReactNode } from 'react'
import { Link, type LinkProps } from 'react-router-dom'
import { ApiError } from '../api'
import type { MoneyValue } from '../api'

type Variant = 'primary' | 'secondary' | 'quiet' | 'danger'

export function Button({
  variant = 'primary',
  busy = false,
  className = '',
  children,
  ...rest
}: ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant; busy?: boolean }) {
  return (
    <button {...rest} className={`btn btn--${variant} ${className}`} disabled={rest.disabled || busy} aria-busy={busy || undefined}>
      {busy && <span className="btn__spinner" aria-hidden />}
      {children}
    </button>
  )
}

export function ButtonLink({ variant = 'primary', className = '', ...rest }: LinkProps & { variant?: Variant }) {
  return <Link {...rest} className={`btn btn--${variant} ${className}`} />
}

export function Field({
  label,
  hint,
  error,
  children,
  optional,
}: {
  label: string
  hint?: string
  error?: string
  optional?: boolean
  children: (props: { id: string; 'aria-describedby'?: string; 'aria-invalid'?: boolean }) => ReactNode
}) {
  const id = useId()
  const describedBy = [hint && `${id}-hint`, error && `${id}-err`].filter(Boolean).join(' ') || undefined
  return (
    <div className={`field ${error ? 'field--error' : ''}`}>
      <label htmlFor={id} className="field__label">
        {label}
        {optional && <span className="field__optional"> (optional)</span>}
      </label>
      {children({ id, 'aria-describedby': describedBy, 'aria-invalid': error ? true : undefined })}
      {hint && !error && (
        <p id={`${id}-hint`} className="field__hint">
          {hint}
        </p>
      )}
      {error && (
        <p id={`${id}-err`} className="field__error">
          {error}
        </p>
      )}
    </div>
  )
}

export type Tone = 'waiting' | 'good' | 'alert' | 'neutral' | 'muted'

export function Badge({ tone, children }: { tone: Tone; children: ReactNode }) {
  return <span className={`badge badge--${tone}`}>{children}</span>
}

const OFFER_STATUS: Record<string, [string, Tone]> = {
  awaiting_customer: ['Waiting for you', 'waiting'],
  awaiting_tailor: ['Waiting for tailor', 'neutral'],
  accepted: ['Accepted', 'good'],
  declined: ['Declined', 'muted'],
  withdrawn: ['Withdrawn', 'muted'],
  expired: ['Expired', 'muted'],
  not_selected: ['Not selected', 'muted'],
}

/** Offer status worded from the viewer's side. */
export function OfferStatusBadge({ status, viewer }: { status: string; viewer: 'customer' | 'tailor' }) {
  let [label, tone] = OFFER_STATUS[status] ?? [status, 'muted']
  if (viewer === 'tailor' && status === 'awaiting_customer') [label, tone] = ['Waiting for customer', 'neutral']
  if (viewer === 'tailor' && status === 'awaiting_tailor') [label, tone] = ['Customer countered', 'waiting']
  return <Badge tone={tone}>{label}</Badge>
}

const REQUEST_STATUS: Record<string, [string, Tone]> = {
  open: ['Open for offers', 'neutral'],
  awarded: ['Tailor chosen', 'good'],
  cancelled: ['Cancelled', 'muted'],
  expired: ['Closed', 'muted'],
}
export function RequestStatusBadge({ status }: { status: string }) {
  const [label, tone] = REQUEST_STATUS[status] ?? [status, 'muted']
  return <Badge tone={tone}>{label}</Badge>
}

const ORDER_TONE: Record<string, Tone> = {
  confirmed: 'neutral', in_progress: 'neutral', fitting: 'waiting', ready: 'waiting', delivered: 'good',
  completed: 'good', disputed: 'alert', cancelled: 'muted',
}
export function OrderStatusBadge({ status, label }: { status: string; label: string }) {
  return <Badge tone={ORDER_TONE[status] ?? 'muted'}>{label}</Badge>
}

export function Price({ value, unit, large }: { value: MoneyValue; unit?: string; large?: boolean }) {
  return (
    <span className={`price ${large ? 'price--large' : ''}`}>
      {value.formatted}
      {unit && <span className="price__unit"> / {unit}</span>}
    </span>
  )
}

export function EmptyState({ title, children, action }: { title: string; children?: ReactNode; action?: ReactNode }) {
  return (
    <div className="empty">
      <div className="empty__selvedge" aria-hidden />
      <h3 className="empty__title">{title}</h3>
      {children && <div className="empty__body">{children}</div>}
      {action && <div className="empty__action">{action}</div>}
    </div>
  )
}

export function ErrorState({ error, onRetry }: { error: Error; onRetry?: () => void }) {
  const notFound = error instanceof ApiError && error.status === 404
  const forbidden = error instanceof ApiError && error.status === 403
  return (
    <div className="error-state" role="alert">
      <h3 className="error-state__title">{notFound ? 'This page does not exist' : forbidden ? 'You do not have access to this' : 'This did not load'}</h3>
      <p>{notFound || forbidden ? 'Check the link, or go back to where you were.' : error.message}</p>
      {onRetry && !notFound && !forbidden && (
        <Button variant="secondary" onClick={onRetry}>
          Try again
        </Button>
      )}
    </div>
  )
}

export function Skeleton({ lines = 3, block }: { lines?: number; block?: boolean }) {
  return (
    <div className="skeleton" aria-busy="true" aria-label="Loading">
      {block && <div className="skeleton__block" />}
      {Array.from({ length: lines }, (_, i) => (
        <div key={i} className="skeleton__line" style={{ width: `${90 - i * 17}%` }} />
      ))}
    </div>
  )
}

/** Native <dialog>: focus trapping, Escape to close and backdrop come from the browser. */
export function Dialog({ open, onClose, title, children }: { open: boolean; onClose: () => void; title: string; children: ReactNode }) {
  const ref = useRef<HTMLDialogElement>(null)
  const titleId = useId()
  useEffect(() => {
    const el = ref.current
    if (!el) return
    if (open && !el.open) el.showModal()
    if (!open && el.open) el.close()
  }, [open])
  return (
    <dialog ref={ref} className="dialog" onClose={onClose} aria-labelledby={titleId}>
      <div className="dialog__head">
        <h2 id={titleId} className="dialog__title">
          {title}
        </h2>
        <button className="dialog__close" onClick={onClose} aria-label="Close">
          ×
        </button>
      </div>
      {open && children}
    </dialog>
  )
}

/** Small uppercase editorial label, used sparingly above titles and in captions. */
export function Label({ children, as: Tag = 'p', className = '' }: { children: ReactNode; as?: 'p' | 'span' | 'h2' | 'h3'; className?: string }) {
  return <Tag className={`label ${className}`}>{children}</Tag>
}

export function PageHeader({ title, intro, aside, label }: { title: ReactNode; intro?: ReactNode; aside?: ReactNode; label?: string }) {
  return (
    <header className="page-header">
      <div className="page-header__text">
        {label && <Label>{label}</Label>}
        <h1 className="page-header__title">{title}</h1>
        {intro && <p className="page-header__intro">{intro}</p>}
      </div>
      {aside && <div className="page-header__aside">{aside}</div>}
    </header>
  )
}

/** Says where AI-assisted content came from: the model, or the rule-based fallback. */
export function SourceNote({ source, notice }: { source: string; notice?: string | null }) {
  const text =
    source === 'ai'
      ? 'Suggested by the OSAPS AI stylist from our live catalogue. Check details with your vendor and tailor.'
      : source === 'keywords'
        ? 'Basic search: matched by keywords.'
        : 'Guided mode: written from our style guide, without AI.'
  return (
    <p className={`source-note source-note--${source === 'ai' ? 'ai' : 'rules'}`}>
      <span className="source-note__mark" aria-hidden />
      {notice ? `${notice} ` : ''}
      {text}
    </p>
  )
}

export function errorMessage(e: unknown): string {
  return e instanceof Error ? e.message : 'Something went wrong.'
}
