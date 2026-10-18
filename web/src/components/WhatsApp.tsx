import type { ReactNode } from 'react'
import { useSession } from '../lib/session'

/** wa.me link with a ready-made message. Renders nothing until a support WhatsApp number is set. */
export function whatsappHref(number: string | undefined, text: string): string | null {
  const digits = (number ?? '').replace(/\D/g, '')
  if (digits.length < 8) return null
  const intl = digits.startsWith('0') ? '234' + digits.slice(1) : digits
  return `https://wa.me/${intl}?text=${encodeURIComponent(text)}`
}

export function WhatsAppLink({ text, children, className = '' }: { text: string; children: ReactNode; className?: string }) {
  const { meta } = useSession()
  const href = whatsappHref(meta?.support?.whatsapp, text)
  if (!href) return null
  return (
    <a href={href} target="_blank" rel="noopener noreferrer" className={`wa-link ${className}`}>
      <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2Zm0 18.2a8.2 8.2 0 0 1-4.2-1.1l-.3-.2-3 .8.8-2.9-.2-.3A8.2 8.2 0 1 1 12 20.2Zm4.5-6.1c-.2-.1-1.5-.7-1.7-.8s-.4-.1-.6.1-.7.8-.8 1-.3.2-.5.1a6.7 6.7 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3a.4.4 0 0 0 0-.4l-.8-1.9c-.2-.5-.4-.4-.6-.4h-.5a1 1 0 0 0-.7.3 3 3 0 0 0-.9 2.2 5.2 5.2 0 0 0 1.1 2.8 11.9 11.9 0 0 0 4.6 4c1.7.7 2.4.8 3.2.6a2.8 2.8 0 0 0 1.8-1.3 2.3 2.3 0 0 0 .2-1.3c-.1-.1-.2-.2-.5-.3Z" /></svg>
      <span>{children}</span>
    </a>
  )
}
