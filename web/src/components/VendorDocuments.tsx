import { useState, type ChangeEvent } from 'react'
import { api, type VendorDocKind, type VendorDocumentState } from '../api'
import { Badge, Button, errorMessage, type Tone } from './ui'
import { useToast } from '../lib/toast'
import { useAsync } from '../lib/useAsync'

const DOC_TONE: Record<string, { tone: Tone; text: string }> = {
  submitted: { tone: 'waiting', text: 'Waiting for review' },
  approved: { tone: 'good', text: 'Approved' },
  rejected: { tone: 'alert', text: 'Needs a new upload' },
}

type Doc = VendorDocumentState['documents'][number]

function DocRow({ doc, consentGiven, locked, onUploaded }: { doc: Doc; consentGiven: boolean; locked: boolean; onUploaded: () => void }) {
  const toast = useToast()
  const [file, setFile] = useState<File | null>(null)
  const [consent, setConsent] = useState(consentGiven)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const state = doc.status ? DOC_TONE[doc.status] : null
  const needsConsent = doc.biometric

  async function upload() {
    if (!file) return
    setBusy(true)
    setError(null)
    try {
      await api.uploadVendorDocument(doc.kind, file, needsConsent ? consent : false)
      toast('Uploaded. Ask for review when every document is in.', 'good')
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
        {state ? <Badge tone={state.tone}>{state.text}</Badge> : <Badge tone="muted">Required</Badge>}
      </div>
      {doc.status === 'rejected' && doc.review_note && <p className="field__error" style={{ marginTop: 6 }}>OSAPS note: {doc.review_note}</p>}
      {doc.status !== 'approved' && !locked && (
        <div style={{ marginTop: 10, display: 'grid', gap: 8 }}>
          {needsConsent && (
            <label style={{ display: 'flex', gap: 8, alignItems: 'flex-start', fontSize: 14 }}>
              <input type="checkbox" checked={consent} onChange={(e) => setConsent(e.target.checked)} />
              <span>
                I agree that OSAPS takes a copy of my ID to check who owns this shop. It is stored encrypted, kept apart from public
                pages, and deleted if I ask.
              </span>
            </label>
          )}
          <input
            type="file"
            accept="application/pdf,image/jpeg,image/png,image/webp"
            aria-label={`Choose a file for ${doc.label}`}
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

/** The owner's shop documents. Uploads close while OSAPS is reviewing them and once the shop is verified. */
export function VendorDocuments({ locked, onChange }: { locked: boolean; onChange?: () => void }) {
  const res = useAsync(() => api.vendorDocuments(), [])
  if (!res.data) return null
  const v = res.data
  const allIn = v.missing.length === 0

  return (
    <section aria-label="Shop documents" style={{ marginBottom: 20 }}>
      <h3 className="checkout__h">Shop documents</h3>
      <p className="muted">
        OSAPS checks your CAC registration certificate and the owner's ID before your shop goes live. Send a PDF, JPG, PNG or WebP
        file, up to 8 MB.
      </p>
      {locked && <p className="field__hint">Your documents are with OSAPS or already approved, so you can't change them here.</p>}
      <ul className="rows" aria-label="Shop documents list">
        {v.documents.map((doc) => (
          <DocRow
            key={doc.kind}
            doc={doc}
            consentGiven={v.consent_given}
            locked={locked}
            onUploaded={() => { res.reload(); onChange?.() }}
          />
        ))}
      </ul>
      {!allIn && !locked && (
        <p className="field__hint" style={{ marginTop: 10 }}>
          Still needed before you can ask for review: {v.missing.map((k: VendorDocKind) => (k === 'cac_certificate' ? 'CAC certificate' : 'owner ID')).join(', ')}.
        </p>
      )}
    </section>
  )
}
