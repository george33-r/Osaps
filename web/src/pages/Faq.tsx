import { Link } from 'react-router-dom'
import { WhatsAppLink } from '../components/WhatsApp'
import { PageHeader } from '../components/ui'

/**
 * Plain answers to the questions people ask most. Each answer describes how OSAPS works today;
 * where something is not switched on yet, the answer says so.
 */
const groups: { title: string; items: [string, string][] }[] = [
  {
    title: 'Getting started',
    items: [
      ['Do I need an account to look around?', 'No. You can browse fabrics, tailors and shops without signing up. You need an account to buy, send a request, or chat with a tailor or shop.'],
      ['How do I create an account?', 'Tap Sign in, then Create an account. Use the email you check most often, because order updates and password resets go there.'],
      ['I forgot my password. What now?', 'Tap "Forgot your password?" on the sign-in page. We email you a link to choose a new one. The link expires, so ask for a new one if it stops working.'],
      ['Can I see my password while I type it?', 'Yes. Tap Show next to the password box to see what you typed, and Hide to hide it again.'],
    ],
  },
  {
    title: 'Buying fabric',
    items: [
      ['How do I pay?', 'Payment is through Paystack (card, bank transfer or USSD). If online payment is not switched on yet, you can order on WhatsApp and we will confirm the payment with you.'],
      ['Is my money safe?', 'OSAPS holds the money until your fabric arrives and you confirm it. The vendor is paid last, after the problem-report window has passed. See Buyer protection for the details.'],
      ['What is the delivery code?', 'After you pay, your order page shows a 6-digit code. Give it to the rider (or the vendor at pickup) only when the fabric is in your hands and you have checked it. Never share it before then.'],
      ['How long do I have to report a problem?', 'Seven days after the fabric arrives, unless a shop\'s listing says otherwise. Tell us what is wrong: not received, wrong fabric or colour, damaged, short on yards, or not as described.'],
      ['Can I get a refund?', 'If we agree the order went wrong, the refund goes back the way you paid. Your order page shows its progress.'],
    ],
  },
  {
    title: 'Tailors and shops',
    items: [
      ['How do I know a tailor or shop is genuine?', 'Each one goes through a check. Tailors upload an ID, a selfie and a photo of their workshop. Shops upload their CAC certificate and the ID of the owner. Our team checks these before the badges appear.'],
      ['What do the badges mean?', 'A badge shows only after our team has checked that item. If a check is out of date, the badges are hidden until it is renewed.'],
      ['Why do tailors and shops have to renew their checks?', 'Every six weeks we ask for a current ID and CAC certificate, so the checks stay true. You will get a reminder before the date.'],
      ['How many requests can a tailor take?', 'Each tailor can have a limited number of open offers at a time. The limit grows with the weekly capacity they set on their profile.'],
    ],
  },
  {
    title: 'Tailor requests and offers',
    items: [
      ['How do I get a tailor to make something?', 'Post a request describing what you want, the fabric, your budget and your date. Tailors reply with offers. You compare them and accept the one you prefer.'],
      ['Can I ask questions before I accept?', 'Yes. You can message the tailor about an offer before you accept it. Once an offer is closed, the messages stay for your record.'],
    ],
  },
  {
    title: 'Problems and help',
    items: [
      ['Who do I contact if something goes wrong?', 'Use the chat on your order, or message us on WhatsApp. For a problem with an order, give your order number so we can find it quickly.'],
      ['Will you ever ask for my password or my full card number?', 'No. Never share your password, your card PIN, or your OTP with anyone, including people who say they are from OSAPS.'],
    ],
  },
]

export default function Faq() {
  return (
    <div className="page page--narrow faq-page">
      <PageHeader label="Help" title="Questions people ask" intro="Short, plain answers. If yours is not here, message us on WhatsApp." />

      {groups.map((g) => (
        <section key={g.title} className="faq-group" aria-labelledby={`faq-${g.title.replace(/\W+/g, '-').toLowerCase()}`}>
          <h2 id={`faq-${g.title.replace(/\W+/g, '-').toLowerCase()}`} className="section-title section-title--small">{g.title}</h2>
          {g.items.map(([q, a]) => (
            <details key={q} className="faq-item">
              <summary>{q}</summary>
              <p>{a}</p>
            </details>
          ))}
        </section>
      ))}

      <p className="faq-more">
        Still stuck? <WhatsAppLink text="Hello OSAPS, I need help">Message us on WhatsApp</WhatsAppLink> or read <Link to="/buyer-protection" className="text-link">how you are protected</Link>.
      </p>
    </div>
  )
}
