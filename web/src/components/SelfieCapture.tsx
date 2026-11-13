import { useEffect, useRef, useState } from 'react'
import { Button } from './ui'
import { BLINK_CHECK_ON, BlinkWatcher, eyeAspectRatio, loadFaceMesh, type Landmarks, type FaceMeshLike } from '../lib/blink'

type Box = { x: number; y: number; width: number; height: number }
type FaceDetectorLike = { detect(source: CanvasImageSource): Promise<Array<{ boundingBox: Box }>> }
type FaceDetectorCtor = new (options?: { fastMode?: boolean; maxDetectedFaces?: number }) => FaceDetectorLike

/** Browsers with the Shape Detection API (mostly Chrome on Android) can find the face; others take a manual photo. */
function faceDetectorCtor(): FaceDetectorCtor | null {
  const w = window as unknown as { FaceDetector?: FaceDetectorCtor }
  return typeof w.FaceDetector === 'function' ? w.FaceDetector : null
}

/** True when the face sits in the middle of the frame and fills a sensible part of it. */
function inGuide(box: Box, video: HTMLVideoElement): boolean {
  const cx = (box.x + box.width / 2) / video.videoWidth
  const cy = (box.y + box.height / 2) / video.videoHeight
  const size = box.width / video.videoWidth
  return Math.abs(cx - 0.5) < 0.15 && Math.abs(cy - 0.5) < 0.15 && size > 0.3 && size < 0.8
}

type Props = {
  /** Called with the JPEG the tailor chose to keep. */
  onCapture: (file: File) => void
};

/**
 * Live selfie from the front camera. Auto-captures after the face has been steady in the guide
 * for about a second when the browser can detect faces; the Take photo button always works.
 * This is guided capture, not liveness: it does not prove a real person is present.
 */
export function SelfieCapture({ onCapture }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null)
  const [status, setStatus] = useState<'starting' | 'ready' | 'error'>('starting')
  const [error, setError] = useState<string | null>(null)
  const [faceFound, setFaceFound] = useState(false)
  const [blob, setBlob] = useState<Blob | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const detectorAvailable = faceDetectorCtor() !== null
  const [blinked, setBlinked] = useState(false)
  const [meshError, setMeshError] = useState<string | null>(null)
  const [noBlinkYet, setNoBlinkYet] = useState(false)

  // Open the camera once, and release it when the component leaves the page.
  useEffect(() => {
    let cancelled = false
    let stream: MediaStream | null = null
    async function start() {
      if (!navigator.mediaDevices?.getUserMedia) {
        setStatus('error')
        setError('This browser cannot open the camera. Choose a photo instead.')
        return
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({
          video: { facingMode: 'user', width: { ideal: 720 }, height: { ideal: 960 } },
          audio: false,
        })
        if (cancelled) {
          stream.getTracks().forEach((t) => t.stop())
          return
        }
        if (videoRef.current) {
          videoRef.current.srcObject = stream
          await videoRef.current.play()
        }
        setStatus('ready')
      } catch {
        if (!cancelled) {
          setStatus('error')
          setError('We could not open your camera. Allow camera access, or choose a photo instead.')
        }
      }
    }
    start()
    return () => {
      cancelled = true
      stream?.getTracks().forEach((t) => t.stop())
    }
  }, [])

  // Free the preview address when it changes or the page closes.
  useEffect(() => () => { if (preview) URL.revokeObjectURL(preview) }, [preview])

  function snap() {
    const video = videoRef.current
    if (!video || video.videoWidth === 0) return
    const canvas = document.createElement('canvas')
    canvas.width = video.videoWidth
    canvas.height = video.videoHeight
    canvas.getContext('2d')?.drawImage(video, 0, 0, canvas.width, canvas.height)
    canvas.toBlob(
      (b) => {
        if (!b) return
        setBlob(b)
        setPreview(URL.createObjectURL(b))
      },
      'image/jpeg',
      0.92,
    )
  }

  // Blink check: the face must be in the guide and the tailor must blink before a photo is taken.
  useEffect(() => {
    if (!BLINK_CHECK_ON || status !== 'ready' || blob) return
    let alive = true
    let steady = 0
    let timer = 0
    const watcher = new BlinkWatcher()
    let mesh: FaceMeshLike | null = null
    let latest: Landmarks | null = null
    const startedAt = Date.now()
    const frame = (lm: Landmarks | null) => {
      const video = videoRef.current
      if (!lm || !video || video.videoWidth === 0) return false
      const xs = lm.map((p) => p.x), ys = lm.map((p) => p.y)
      const box = {
        x: Math.min(...xs) * video.videoWidth,
        y: Math.min(...ys) * video.videoHeight,
        width: (Math.max(...xs) - Math.min(...xs)) * video.videoWidth,
        height: (Math.max(...ys) - Math.min(...ys)) * video.videoHeight,
      }
      return inGuide(box, video)
    }
    const run = async () => {
      try {
        mesh = await loadFaceMesh()
      } catch {
        if (alive) setMeshError('The face check could not load. Check your connection and reload this page.')
        return
      }
      if (!alive || !mesh) return
      mesh.onResults((r) => {
        latest = r.multiFaceLandmarks?.[0] ?? null
        if (latest) {
          watcher.push(eyeAspectRatio(latest))
          if (watcher.blinks > 0 && alive) setBlinked(true)
        }
      })
      const tick = async () => {
        if (!alive || !mesh) return
        const video = videoRef.current
        if (video && video.readyState >= 2 && video.videoWidth > 0) {
          try {
            await mesh.send({ image: video })
          } catch {
            // A dropped frame is not an error: try again.
          }
          const ok = frame(latest)
          setFaceFound(ok)
          steady = ok ? steady + 1 : 0
          if (steady >= 5 && watcher.blinks > 0) {
            snap()
            return
          }
          if (watcher.blinks === 0 && Date.now() - startedAt > 15000) setNoBlinkYet(true)
        }
        timer = window.setTimeout(tick, 200)
      }
      tick()
    }
    run()
    return () => {
      alive = false
      window.clearTimeout(timer)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, blob])

  // Auto-capture: when the face has been in the guide for about 1.25 seconds.
  useEffect(() => {
    const Ctor = faceDetectorCtor()
    if (BLINK_CHECK_ON || !Ctor || status !== 'ready' || blob) return
    const detector = new Ctor({ fastMode: true, maxDetectedFaces: 1 })
    let alive = true
    let steady = 0
    let timer = 0
    const tick = async () => {
      const video = videoRef.current
      if (!alive) return
      if (video && video.readyState >= 2 && video.videoWidth > 0) {
        try {
          const faces = await detector.detect(video)
          const face = faces.length === 1 ? faces[0] : undefined
          const ok = face !== undefined && inGuide(face.boundingBox, video)
          setFaceFound(ok)
          steady = ok ? steady + 1 : 0
          if (steady >= 5) {
            snap()
            return
          }
        } catch {
          // A dropped frame is not an error: try again.
        }
      }
      timer = window.setTimeout(tick, 250)
    }
    tick()
    return () => {
      alive = false
      window.clearTimeout(timer)
    }
    // snap reads refs and state setters only, so it does not need to be a dependency.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [status, blob])

  function retake() {
    setBlob(null)
    setPreview(null)
    setFaceFound(false)
    setBlinked(false)
    setNoBlinkYet(false)
  }

  function keep() {
    if (!blob) return
    onCapture(new File([blob], 'selfie.jpg', { type: 'image/jpeg' }))
  }

  const hint =
    status === 'starting' ? 'Opening your camera…'
    : status === 'error' ? error
    : meshError ? meshError
    : blob ? 'Check the photo: your face should be clear and fully visible. Send it, or retake.'
    : BLINK_CHECK_ON
      ? !blinked
        ? noBlinkYet ? 'No blink yet. Blink once, slowly, while looking at the camera.' : 'Look at the camera, then blink once, slowly.'
        : faceFound ? 'Blink seen. Hold still.' : 'Move your face into the circle.'
    : detectorAvailable
      ? faceFound ? 'Face found. Hold still.' : 'Move your face into the circle. Good light on your face. Take off caps and dark glasses.'
      : 'Look straight at the camera in good light. Take off caps and dark glasses, then tap Take photo.'

  return (
    <div style={{ display: 'grid', gap: 12, justifyItems: 'center' }}>
      {preview ? (
        <img
          src={preview}
          alt="Your selfie, ready to send"
          style={{ width: 'min(320px, 100%)', aspectRatio: '3 / 4', objectFit: 'cover', borderRadius: 16 }}
        />
      ) : (
        status !== 'error' && (
          <div style={{ position: 'relative', width: 'min(320px, 100%)', aspectRatio: '3 / 4', borderRadius: 16, overflow: 'hidden', background: '#000' }}>
            <video
              ref={videoRef}
              playsInline
              muted
              autoPlay
              aria-label="Camera preview"
              style={{ width: '100%', height: '100%', objectFit: 'cover', transform: 'scaleX(-1)' }}
            />
            <div
              aria-hidden
              style={{
                position: 'absolute', inset: '12% 18%', borderRadius: '50%',
                border: `3px dashed ${faceFound ? '#2e7d32' : '#ffffff'}`,
              }}
            />
          </div>
        )
      )}
      <p className="field__hint" role="status" style={{ textAlign: 'center' }}>{hint}</p>
      {status === 'ready' && !preview && (
        <Button onClick={snap} variant="secondary" disabled={BLINK_CHECK_ON && !blinked}>
          {BLINK_CHECK_ON && !blinked ? 'Blink first to take the photo' : 'Take photo'}
        </Button>
      )}
      {preview && (
        <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap', justifyContent: 'center' }}>
          <Button onClick={retake} variant="secondary">Retake</Button>
          <Button onClick={keep}>Use this photo</Button>
        </div>
      )}
    </div>
  )
}
