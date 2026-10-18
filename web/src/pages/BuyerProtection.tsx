import { Link } from 'react-router-dom'
import { WhatsAppLink } from '../components/WhatsApp'
import { PageHeader } from '../components/ui'
import { formatNaira } from '../lib/format'
import { useSession } from '../lib/session'

/**
 * How buying on OSAPS protects the buyer. Every sentence here describes how the site actually
 * works (see docs/TRUST.md); nothing is a promise the code does not keep.
 */
export default function BuyerProtection() {
  const { meta } = useSession()
  const days = meta?.shop?.protection_days ?? 7
  const zones = meta?.shop?.delivery?.zones ?? []
  const pickup = meta?.shop?.pickup_enabled ?? false
  const steps = [
    ['You pay OSAPS, not the vendor', 'Pay with card, bank transfer or USSD through Paystack. OSAPS never sees your card details. The money is held by OSAPS, not sent to the vendor.'],
    ['Only checked shops can sell', 'A shop\'s fabrics appear on OSAPS only after OSAPS has checked the shop.'],
    ['You get a delivery code', `After you pay, your order page shows a 6-digit code. Give it to the rider${pickup ? ' or the vendor at pickup' : ''} only once the fabric is in your hands and you have checked it. Never share it before then, not even on the phone.`],
    [`${days} days to report a problem`, `After your fabric arrives you have ${days} days to tell us if something is wrong: not received, wrong fabric or colour, damaged, fewer yards than you paid for, or not as described. While your report is open, the vendor is not paid.`],
    ['Refunds go back the way you paid', 'If OSAPS agrees the order went wrong, the refund goes back through Paystack to the card or account you paid with. Your order page shows the refund and its progress.'],
    ['The vendor is paid last', `The vendor is paid only after you confirm you have the fabric and the ${days} days pass without a problem being reported.`],
  ] as const
  return (
    <div className="page page--narrow protection-page">
      <PageHeader label="Buyer protection" title="Your money is safe until your fabric arrives" intro="How paying, delivery and problems work on OSAPS." />

      <ol className="pp-steps">
        {steps.map(([t, b], i) => (
          <li key={t}>
            <span className="pp-steps__n" aria-hidden>{i + 1}</span>
            <div><h2>{t}</h2><p>{b}</p></div>
          </li>
        ))}
      </ol>

      {zones.length > 0 && (
        <section id="delivery" className="pp-delivery" aria-labelledby="pp-del-title">
          <h2 id="pp-del-title" className="section-title section-title--small">Delivery prices and times</h2>
          <p>You pay one delivery price per shop in your order, however many yards you buy. Times are a guide and count working days.</p>
          <ul className="pp-zones">
            {zones.map((z) => (
              <li key={z.key}>
                <span className="pp-zones__where">
                  <strong>{z.label}</strong>
                  {z.states.length > 1 && z.states.length <= 8 && z.states.join(', ') !== z.label ? <span>{z.states.join(', ')}</span> : null}
                </span>
                <span className="pp-zones__price"><strong>{formatNaira(z.fee_minor)}</strong><span>{z.days}</span></span>
              </li>
            ))}
          </ul>
          {!pickup && <p className="muted">Pickup is not available yet. Every order is delivered.</p>}
        </section>
      )}

      <section className="pp-help" aria-labelledby="pp-help-title">
        <h2 id="pp-help-title" className="section-title section-title--small">Need help?</h2>
        <p>To report a problem with an order, open the order from <Link to="/shop/orders">My fabric orders</Link> and choose "Report a problem". For anything else, talk to us.</p>
        <p className="pp-help__actions">
          <WhatsAppLink text="Hello OSAPS, I need help with buying fabric." className="btn btn--secondary">Chat with OSAPS on WhatsApp</WhatsAppLink>
          {meta?.support.email && <a className="btn btn--quiet" href={`mailto:${meta.support.email}`}>{meta.support.email}</a>}
        </p>
      </section>
    </div>
  )
}
