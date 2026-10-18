import { useState } from 'react'
import { api, type DesignPhoto } from '../api'
import { useToast } from '../lib/toast'
import { errorMessage } from './ui'

export const MAX_DESIGN_PHOTOS = 4

export function checkPhoto(file: File): string | null {
  if (!/^image\/(jpeg|png|webp)$/.test(file.type)) return 'Choose a JPG, PNG or WebP photo.'
  if (file.size > 8 * 1024 * 1024) return 'That photo is over 8 MB. Choose a smaller one.'
  return null
}

/**
 * Sample photos of the design the customer wants: a style from Instagram, a picture of a friend's
 * outfit, a sketch. Only the customer and tailors who can see the request can open them.
 */
export function DesignPhotos({ requestId, photos: initial, editable, who = 'Tailors' }: { requestId: string; photos: DesignPhoto[]; editable: boolean; who?: string }) {
  const toast = useToast()
  const [photos, setPhotos] = useState(initial)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const room = MAX_DESIGN_PHOTOS - photos.length

  const add = async (files: FileList | null) => {
    setError(null)
    const list = Array.from(files ?? []).slice(0, room)
    for (const f of list) {
      const bad = checkPhoto(f)
      if (bad) { setError(bad); return }
    }
    setBusy(true)
    try {
      for (const f of list) {
        const p = await api.addDesignPhoto(requestId, f)
        setPhotos((x) => [...x, p])
      }
      if (list.length) toast(list.length === 1 ? 'Photo added.' : `${list.length} photos added.`, 'good')
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const remove = async (p: DesignPhoto) => {
    setError(null)
    try {
      await api.removeDesignPhoto(requestId, p.id)
      setPhotos((x) => x.filter((y) => y.id !== p.id))
    } catch (e) {
      setError(errorMessage(e))
    }
  }

  if (!editable && photos.length === 0) return null

  return (
    <section className="design-photos" aria-labelledby={`dp-${requestId}`}>
      <h3 id={`dp-${requestId}`} className="design-photos__title">Design photos</h3>
      {photos.length > 0 ? (
        <ul className="design-photos__grid">
          {photos.map((p) => (
            <li key={p.id}>
              <a href={p.url} target="_blank" rel="noopener noreferrer"><img src={p.url} alt={p.caption ?? 'Sample of the design'} loading="lazy" /></a>
              {editable && <button type="button" className="design-photos__remove" onClick={() => remove(p)} aria-label="Remove this photo">×</button>}
            </li>
          ))}
        </ul>
      ) : (
        <p className="muted">Add a picture of the style you want, so {who.toLowerCase()} can see it.</p>
      )}
      {editable && room > 0 && (
        <label className={`btn btn--secondary design-photos__add ${busy ? 'is-busy' : ''}`}>
          <input type="file" accept="image/jpeg,image/png,image/webp" multiple className="sr-only" disabled={busy} onChange={(e) => { void add(e.currentTarget.files); e.currentTarget.value = '' }} />
          {busy ? 'Uploading…' : photos.length ? 'Add another photo' : 'Add design photos'}
        </label>
      )}
      {editable && <p className="design-photos__note">Up to {MAX_DESIGN_PHOTOS} photos. {who} who can see your request can see them. Photo details like location are removed.</p>}
      {error && <p className="field__error" role="alert">{error}</p>}
    </section>
  )
}
