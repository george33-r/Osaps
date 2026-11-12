import { useState, type ChangeEvent } from 'react'
import { api, type TailorDocKind, type TailorVerificationDoc, type TailorVerificationState } from '../api'
import { Badge, Button, ButtonLink, EmptyState, ErrorState, PageHeader, Skeleton, errorMessage, type Tone } from '../components/ui'
import { SelfieCapture } from '../components/SelfieCapture'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

/** Documents that show a person's face or ID need explicit consent before they are taken. */
const BIOMETRIC: TailorDocKind[] = ['id_document', 'selfie']

const STATUS_TONE: Record<string, Tone> = {
  unverified: 'muted',
  pending: 'waiting',
  verified: 'good',
  rejected: 'alert',
  suspended: 'alert',
}

const DOC_TONE: Record<string, { tone: Tone; text: string }> = {
  submitted: { tone: 'waiting', text: 'Waiting for review' },
  approved: { tone: 'good', text: 'Approved' },
  rejected: { tone: 'alert', text: 'Needs a new upload' },
}

function DocRow({ doc, consentGiven, disabled, onUploaded }: { doc: TailorVerificationDoc; consentGiven: boolean; disabled: boolean; onUploaded: () => void }) {
  const toast = useToast()
  const [file, setFile] = useState<File | null>(null)
  const [consent, setConsent] = useState(consentGiven)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [camera, setCamera] = useState(doc.kind === 'selfie')
  const needsConsent = BIOMETRIC.includes(doc.kind)
  const state = doc.status ? DOC_TONE[doc.status] : null
  // The camera opens only after consent is ticked: a selfie is biometric data.
  const cameraReady = doc.kind === 'selfie' && consent

  async function upload() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      await api.uploadTailorDocument(doc.kind, file, needsConsent ? consent : false)
      toast('Uploaded. Send for review when every document is in.', 'good')
      setFile(null)
      onUploaded()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <li className="rows__item" style={{ display: 'block' }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'baseline', flexWrap: 'wrap' }}>
        <strong>{doc.label}</strong>
        {state ? <Badge tone={state.tone}>{state.text}</Badge> : <Badge tone="muted">{doc.required ? 'Required' : 'Optional'}</Badge>}
      </div>
      {doc.status !== 'approved' && !disabled && (
        <div style={{ marginTop: 10, display: 'grid', gap: 10 }}>
          {needsConsent && (
            <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 14 }}>
              <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
              <span>
                I agree that OSAPS takes this photo to check my identity. It is stored encrypted, kept apart from my public profile,
                and deleted if I ask.
              </span>
            </label>
          )}
          {doc.kind === 'selfie' && camera && cameraReady && (
            <SelfieCapture
              onCapture={(captured) => {
                setFile(captured)
                setCamera(false)
              }}
            />
          )}
          {doc.kind === 'selfie' && camera && !cameraReady && (
            <p className="field__hint">Tick the consent box above to open the camera, or choose a photo below.</p>
          )}
          {doc.kind === 'selfie' && !camera && file && (
            <p className="field__hint">Photo ready: {file.name}. Upload it, or retake it with the camera.</p>
          )}
          {doc.kind === 'selfie' && !camera && (
            <div>
              <Button variant="secondary" onClick={() => { setFile(null); setCamera(true) }}>Retake with camera</Button>
            </div>
          )}
          <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            aria-label={`Choose a photo for ${doc.label}`}
            disabled={needsConsent && !consent}
            onChange={(e: ChangeEvent<HTMLInputElement>) => setFile(e.target.files?.[0] ?? null)}
          />
          {error && <p role="alert" className="field__error">{error}</p>}
          <div>
            <Button onClick={upload} busy={busy} disabled={!file || (needsConsent && !consent)} variant="secondary">
              Upload
            </Button>
          </div>
        </div>
      )}
    </li>
  )
}

export function TailorVerify() {
  const res = useAsync(() => api.tailorVerification(), [])
  const toast = useToast()
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  if (res.status === 'error' && !res.data) return <div className="page page--narrow"><ErrorState error={res.error} onRetry={res.reload} /></div>
  if (!res.data) return <div className="page page--narrow"><Skeleton lines={6} /></div>
  const v: TailorVerificationState | null = res.data
  if (v === null) {
    return (
      <div className="page page--narrow">
        <EmptyState title="Set up your tailor profile first" action={<ButtonLink to="/tailor/profile">Set up profile</ButtonLink>}>
          Verification is for your tailor profile. Save it, then come back here to add your documents.
        </EmptyState>
      </div>
    )
  }

  const verified = v.status === 'verified'
  const pending = v.status === 'pending'
  const canSubmit = !verified && !pending && v.missing.length === 0

  async function submit() {
    setSubmitting(true)
    setError(null)
    try {
      await api.submitTailorVerification()
      toast('Sent for review. OSAPS checks each document before your badges show.', 'good')
      res.reload()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSubmitting(false)
    }
  }

  return (
    <div className="page page--narrow">
      <PageHeader
        title="Verify your profile"
        intro="Buyers see a badge only after OSAPS has checked each document. Nothing is shown as verified before that."
        aside={<ButtonLink to="/tailor/profile" variant="secondary">Edit profile</ButtonLink>}
      />

      <div style={{ display: 'flex', gap: 10, alignItems: 'center', marginBottom: 16 }}>
        <Badge tone={STATUS_TONE[v.status] ?? 'muted'}>{v.label}</Badge>
      </div>

      {v.status === 'rejected' && v.note && (
        <div className="match-note" role="status">
          <span>OSAPS asked for changes: {v.note}</span>
        </div>
      )}
      {verified && <p>Your identity and workshop checks are approved. Your badges are on your public profile.</p>}
      {pending && <p>Your documents are with the OSAPS team. You will get a notification when it is decided.</p>}

      <ul className="rows" aria-label="Documents">
        {v.documents.map((doc) => (
          <DocRow key={doc.kind} doc={doc} consentGiven={v.consent_given} disabled={verified || pending} onUploaded={res.reload} />
        ))}
      </ul>

      {!verified && !pending && (
        <div style={{ marginTop: 20 }}>
          {v.missing.length > 0 && (
            <p className="field__hint">Still needed before you can send for review: {v.missing.join(', ').replace(/_/g, ' ')}.</p>
          )}
          {error && <p role="alert" className="field__error">{error}</p>}
          <Button onClick={submit} busy={submitting} disabled={!canSubmit}>
            Send for review
          </Button>
        </div>
      )}
    </div>
  )
}
