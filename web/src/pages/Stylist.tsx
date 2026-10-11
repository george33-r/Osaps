import { useEffect, useRef, useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, type StylistKnown, type StylistQuestion, type StylistResult, type StylistTurn } from '../api'
import { FabricCard, TailorCard } from '../components/cards'
import { DrapedCloth } from '../components/Swatch'
import { Button, ErrorState, Label, SourceNote, errorMessage } from '../components/ui'
import { useSession } from '../lib/session'

type Entry = { question: string; answer: string }

const BASIS: Record<string, string> = {
  'listed × assumption': 'Listed price × typical yardage',
  tailor_from: 'Matched tailors’ starting prices',
  calculated: 'Published fee',
}

function labelFor(q: StylistQuestion, value: string | number | (string | number)[]): string {
  const values = Array.isArray(value) ? value : [value]
  if (values.length === 0) return 'I am open to any colour'
  return values.map((v) => q.choices.find((c) => c.value === v)?.label ?? String(v)).join(', ')
}

function Brief({ known, onChange }: { known: StylistKnown; onChange: (slot: keyof StylistKnown) => void }) {
  const { meta } = useSession()
  const rows: [keyof StylistKnown, string, string | undefined][] = [
    ['occasion', 'Occasion', meta?.occasions.find((o) => o.key === known.occasion)?.label],
    ['garment', 'Outfit', meta?.garment_categories.find((g) => g.key === known.garment)?.label],
    ['colors', 'Colours', known.colors ? (known.colors.length ? known.colors.map((c) => c.replace('_', ' ')).join(', ') : 'Open') : undefined],
    ['budget_naira', 'Budget', known.budget_naira === undefined ? undefined : known.budget_naira ? `About ₦${known.budget_naira.toLocaleString('en-NG')}` : 'Not sure yet'],
    ['needs', 'Needs', known.needs?.replace(/_/g, ' ').replace('and', '&')],
    ['timing', 'Timing', known.timing?.replace('two_weeks', 'within 2 weeks').replace('three_months', 'within 3 months').replace('month', 'within a month').replace('no_rush', 'no rush')],
    ['preferences', 'Notes', known.preferences],
  ]
  const filled = rows.filter(([, , v]) => v)
  return (
    <aside className="brief-card" aria-label="Your brief so far">
      <p className="label">Your brief</p>
      {filled.length === 0 ? (
        <p className="muted">Your answers will gather here.</p>
      ) : (
        <dl>
          {filled.map(([key, label, value]) => (
            <div key={key}>
              <dt>{label}</dt>
              <dd>
                {value}
                {key !== 'preferences' && <button type="button" className="text-link" onClick={() => onChange(key)}>Change</button>}
              </dd>
            </div>
          ))}
        </dl>
      )}
    </aside>
  )
}

function Result({ r, onRestart }: { r: StylistResult; onRestart: () => void }) {
  const navigate = useNavigate()
  const c = r.concept
  return (
    <article className="concept" aria-labelledby="concept-title">
      <header className="concept__head">
        <div className="concept__cloth" aria-hidden>
          {r.fabrics[0] ? <DrapedCloth pattern={r.fabrics[0].pattern} colors={r.fabrics[0].colors} seed={9} scale={1.2} /> : <DrapedCloth pattern="plain" colors={['cream']} />}
        </div>
        <div className="concept__intro">
          <Label>Your concept</Label>
          <h2 id="concept-title" className="concept__title">{c.title}</h2>
          <p className="concept__summary">{c.summary}</p>
          <div className="concept__actions">
            <Button onClick={() => navigate('/requests/new', { state: { draft: r.request_draft } })}>Turn this into a request</Button>
            <Button variant="quiet" onClick={onRestart}>Start again</Button>
          </div>
          <SourceNote source={r.source} notice={r.notice} />
        </div>
      </header>

      <dl className="concept__specs">
        <div><dt>Silhouette</dt><dd>{c.silhouette}</dd></div>
        <div><dt>Colour</dt><dd>{c.colour_direction}</dd></div>
        <div><dt>Fabric</dt><dd>{c.fabric_characteristics}</dd></div>
        <div><dt>Finishing</dt><dd>{c.finishing_details}</dd></div>
      </dl>
      {c.why && <p className="concept__why">{c.why}</p>}

      {r.fabrics.length > 0 && (
        <section className="concept__section" aria-labelledby="concept-fabrics">
          <h3 id="concept-fabrics" className="section-title section-title--small">Fabrics in the catalogue now</h3>
          <div className="grid grid--fabrics">{r.fabrics.map((f) => <FabricCard key={f.id} fabric={f} />)}</div>
        </section>
      )}

      {r.tailors.length > 0 && (
        <section className="concept__section" aria-labelledby="concept-makers">
          <h3 id="concept-makers" className="section-title section-title--small">Makers who fit</h3>
          <div className="grid grid--makers">
            {r.tailors.map((t) => (
              <div key={t.slug} className="concept__maker">
                <TailorCard tailor={t} />
                {t.match && <ul className="reasons">{t.match.reasons.map((x) => <li key={x}>{x}</li>)}</ul>}
              </div>
            ))}
          </div>
        </section>
      )}

      <section className="concept__section budget" aria-labelledby="concept-budget">
        <h3 id="concept-budget" className="section-title section-title--small">Budget</h3>
        {r.budget.lines.length > 0 && (
          <dl className="budget__lines">
            {r.budget.lines.map((l) => (
              <div key={l.key}>
                <dt>{l.label}<span className="budget__basis">{BASIS[l.basis] ?? l.basis}</span></dt>
                <dd>{l.amount.formatted}</dd>
              </div>
            ))}
            {r.budget.total && <div className="budget__total"><dt>Estimated total</dt><dd>{r.budget.total.formatted}</dd></div>}
          </dl>
        )}
        {r.budget.missing.length > 0 && <p className="budget__missing">No total yet: we need {r.budget.missing.join(' and ')} to estimate honestly.</p>}
        {r.budget.within_budget === true && <p className="budget__ok">Within your budget of {r.budget.budget?.formatted}.</p>}
        {r.budget.note && <p className="muted">{r.budget.note}</p>}
      </section>

      {c.questions_for_tailor.length > 0 && (
        <section className="concept__section" aria-labelledby="concept-questions">
          <h3 id="concept-questions" className="section-title section-title--small">Ask your tailor</h3>
          <ul className="concept__questions">{c.questions_for_tailor.map((q) => <li key={q}>{q}</li>)}</ul>
        </section>
      )}
    </article>
  )
}

export default function Stylist() {
  const [params] = useSearchParams()
  const initial: StylistKnown = params.get('occasion') ? { occasion: params.get('occasion')! } : {}
  const [known, setKnown] = useState<StylistKnown>(initial)
  const [turn, setTurn] = useState<StylistTurn | null>(null)
  const [log, setLog] = useState<Entry[]>([])
  const [multi, setMulti] = useState<string[]>([])
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<Error | null>(null)
  const questionRef = useRef<HTMLHeadingElement>(null)

  const run = async (k: StylistKnown, message?: string, slot?: string) => {
    setBusy(true)
    setError(null)
    try {
      const t = await api.stylist(k, message, slot)
      setTurn(t)
      setKnown(t.known)
      setMulti([])
      setText('')
    } catch (e) {
      setError(e instanceof Error ? e : new Error(errorMessage(e)))
    } finally {
      setBusy(false)
    }
  }

  useEffect(() => {
    // Arriving from the floating "Ask the stylist" button: start from the customer's own words.
    const ask = params.get('ask')?.trim()
    if (ask && ask.length >= 2) {
      setLog([{ question: 'Tell me what you would like to wear', answer: ask.slice(0, 500) }])
      void run(initial, ask.slice(0, 500), 'occasion')
    } else {
      void run(initial)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])
  useEffect(() => questionRef.current?.focus(), [turn?.next_question?.slot])

  const q = turn?.next_question ?? null

  const answer = (value: string | number | (string | number)[]) => {
    if (!q) return
    setLog((l) => [...l, { question: q.text, answer: labelFor(q, value) }])
    const k: StylistKnown = { ...known }
    if (q.slot === 'colors') {
      k.colors = value as string[]
      k.colors_open = (value as string[]).length === 0
    } else if (q.slot === 'budget_naira') k.budget_naira = Number(value)
    else (k as Record<string, unknown>)[q.slot] = value
    void run(k)
  }

  const sendText = (e: FormEvent) => {
    e.preventDefault()
    if (!q || text.trim().length < 2) return
    setLog((l) => [...l, { question: q.text, answer: text.trim() }])
    void run(known, text.trim(), q.slot)
  }

  const change = (slot: keyof StylistKnown) => {
    const k = { ...known }
    delete k[slot]
    if (slot === 'colors') delete k.colors_open
    void run(k)
  }

  const restart = () => {
    setLog([])
    void run({})
  }

  return (
    <div className="page stylist">
      <header className="stylist__head">
        <Label>Personal styling</Label>
        <h1 className="page-header__title">Your OSAPS stylist</h1>
        <p className="page-header__intro">A few questions, one at a time. Then a concept with fabrics and makers from our catalogue that fit your occasion and budget.</p>
      </header>

      {turn?.result ? (
        <Result r={turn.result} onRestart={restart} />
      ) : (
        <div className="stylist__body">
          <div className="consultation">
            <ol className="consultation__log">
              {log.map((e, i) => (
                <li key={i}>
                  <p className="consultation__q">{e.question}</p>
                  <p className="consultation__a">{e.answer}</p>
                </li>
              ))}
            </ol>

            {error && <ErrorState error={error} onRetry={() => run(known)} />}

            {q && (
              <div className={`consultation__current ${busy ? 'is-busy' : ''}`} aria-live="polite">
                <h2 className="consultation__question" tabIndex={-1} ref={questionRef}>{q.text}</h2>
                <div className="consultation__choices">
                  {q.choices.map((c) => {
                    const on = q.multiple && multi.includes(String(c.value))
                    return (
                      <button
                        key={String(c.value)}
                        type="button"
                        className="chip chip--large"
                        aria-pressed={q.multiple ? on : undefined}
                        disabled={busy || (q.multiple && !on && multi.length >= 4)}
                        onClick={() => (q.multiple ? setMulti((m) => (on ? m.filter((x) => x !== String(c.value)) : [...m, String(c.value)])) : answer(c.value))}
                      >
                        {c.label}
                      </button>
                    )
                  })}
                </div>
                {q.multiple && (
                  <div className="consultation__multi">
                    <Button disabled={busy || multi.length === 0} onClick={() => answer(multi)}>Continue with {multi.length || 'these'}</Button>
                    <Button variant="quiet" disabled={busy} onClick={() => answer([])}>Skip — I am open</Button>
                  </div>
                )}
                {q.allow_text && (
                  <form className="consultation__text" onSubmit={sendText}>
                    <label htmlFor="stylist-text" className="label">Or tell me in your own words</label>
                    {turn?.source === 'ai' && <p className="privacy-note" id="stylist-privacy">What you type here is sent to our AI provider to understand it. Please leave out phone numbers and addresses.</p>}
                    <div className="consultation__row">
                      <input id="stylist-text" aria-describedby={turn?.source === 'ai' ? 'stylist-privacy' : undefined} className="input" value={text} onChange={(e) => setText(e.target.value)} maxLength={500} placeholder={q.slot === 'occasion' ? 'My sister’s traditional wedding in December' : 'Write your answer'} />
                      <Button type="submit" variant="secondary" busy={busy} disabled={text.trim().length < 2}>Send</Button>
                    </div>
                  </form>
                )}
              </div>
            )}
            {!turn && !error && <p className="muted">Preparing your consultation…</p>}
            {turn && <SourceNote source={turn.source === 'ai' ? 'ai' : 'guided'} notice={turn.notice} />}
          </div>
          <Brief known={known} onChange={change} />
        </div>
      )}
      <p className="stylist__alt muted">
        Prefer to describe it yourself? <Link to="/requests/new">Post a tailoring request</Link>.
      </p>
    </div>
  )
}
