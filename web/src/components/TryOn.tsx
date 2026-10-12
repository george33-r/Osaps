import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router-dom'
import { api, ApiError, type Fabric } from '../api'
import { useSession } from '../lib/session'
import { Button, Dialog, errorMessage } from './ui'

const GARMENTS = [
  ['gown', 'Gown'], ['iro_buba', 'Ìró & bùbá with gèlè'], ['wrapper', 'Blouse & double wrapper'], ['skirt_blouse', 'Skirt & blouse'],
  ['agbada', 'Agbada'], ['senator', 'Senator'], ['kaftan', 'Kaftan'],
] as const

/** "See it on me": your own photo, in an outfit drawn by AI from this fabric. Nothing is stored. */
export function TryOn({ fabric }: { fabric: Fabric }) {
  const { meta, user } = useSession()
  const [open, setOpen] = useState(false)
  const [file, setFile] = useState<File | null>(null)
  const [garment, setGarment] = useState<string>('gown')
  const [self, setSelf] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ image: string; notice: string; left: number } | null>(null)
  const preview = useMemo(() => (file ? URL.createObjectURL(file) : null), [file])
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])
  if (!meta?.features.try_on) return null

  return (
    <>
      <Button variant="secondary" className="tryon__open" onClick={() => setOpen(true)}>
        <span aria-hidden>✨</span> See it on me
      </Button>
      <Dialog open={open} onClose={() => setOpen(false)} title="See it on me">
        {!user ? (
          <p><Link to={`/signin?next=${encodeURIComponent(`/fabrics/${fabric.slug}`)}`}>Sign in</Link> to try this fabric on your own photo.</p>
        ) : result ? (
          <div className="tryon__result">
            <img src={result.image} alt={`AI preview of you wearing ${fabric.name}`} />
            <p className="tryon__notice">{result.notice}</p>
            <div className="paid__actions">
              <a className="btn btn--secondary" href={result.image} download={`osaps-${fabric.slug}.png`}>Save picture</a>
              <Button variant="quiet" onClick={() => { setResult(null); setFile(null) }}>Try another</Button>
            </div>
            <p className="muted">{result.left} left today.</p>
          </div>
        ) : (
          <form className="tryon" onSubmit={async (e) => {
            e.preventDefault()
            if (!file) return
            setBusy(true); setError(null)
            try { const r = await api.tryOn(file, fabric.id, garment); setResult({ image: r.image, notice: r.notice, left: r.left_today }) }
            catch (err) { setError(err instanceof ApiError ? err.message : errorMessage(err)) }
            finally { setBusy(false) }
          }}>
            <p className="muted">Upload a clear, well-lit photo of yourself, standing. An image AI draws you wearing an outfit in <strong>{fabric.name}</strong>.</p>
            <label className="tryon__drop">
              {preview ? <img src={preview} alt="Your photo" /> : <span>Choose a photo</span>}
              <input type="file" accept="image/jpeg,image/png,image/webp" onChange={(e) => setFile(e.target.files?.[0] ?? null)} />
            </label>
            <div className="name-price__chips" role="radiogroup" aria-label="Outfit">
              {GARMENTS.map(([k, label]) => <button key={k} type="button" className="chip" aria-pressed={garment === k} onClick={() => setGarment(k)}>{label}</button>)}
            </div>
            <label className="check">
              <input type="checkbox" checked={self} onChange={(e) => setSelf(e.target.checked)} />
              This is a photo of me. I understand it is sent to an AI service to make the picture, and OSAPS does not keep it.
            </label>
            <Button type="submit" busy={busy} disabled={!file || !self}>Make my picture</Button>
            {busy && <p className="muted">This can take up to a minute.</p>}
            {error && <p className="field__error" role="alert">{error}</p>}
          </form>
        )}
      </Dialog>
    </>
  )
}
