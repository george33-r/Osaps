/**
 * A blink check for the selfie. It asks the tailor to blink once while the camera is on.
 * A still photo held up to the camera cannot blink, so it fails. A video of a real person blinking
 * can still pass, so this is one layer of checking, not full proof of a live person.
 *
 * Off unless VITE_BLINK_CHECK=1 is set at build time, so it can be tested on a phone before it is used.
 */
export const BLINK_CHECK_ON = import.meta.env.VITE_BLINK_CHECK === '1'

// Pinned MediaPipe Face Mesh release. Loaded from jsdelivr, which the site's script policy allows.
const FACE_MESH_VERSION = '0.4.1633559619'
const BASE = `https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh@${FACE_MESH_VERSION}/`

export type Point = { x: number; y: number }
export type Landmarks = Point[]

// Eye outline points from the 468-point face mesh: outer corner, two upper lid, inner corner, two lower lid.
const LEFT_EYE = [33, 160, 158, 133, 153, 144]
const RIGHT_EYE = [362, 385, 387, 263, 373, 380]

const dist = (a: Point, b: Point) => Math.hypot(a.x - b.x, a.y - b.y)

/** Eye aspect ratio: about 0.3 with the eyes open, close to 0 when they are shut. */
export function eyeAspectRatio(lm: Landmarks): number {
  const ear = (idx: number[]) => {
    const pts = idx.map((i) => lm[i])
    if (pts.some((p) => p === undefined)) return 1 // missing points count as open eyes
    const q = (k: number) => pts[k] as Point
    return (dist(q(1), q(5)) + dist(q(2), q(4))) / (2 * dist(q(0), q(3)))
  }
  return (ear(LEFT_EYE) + ear(RIGHT_EYE)) / 2
}

/**
 * Counts a blink. Thresholds are relative to how open this person's eyes are, so narrow and wide
 * eyes both work: a blink closes the eyes below 65% of their open level and opens them above 85%.
 */
export class BlinkWatcher {
  blinks = 0
  private open = 0 // highest recent eye-openness, decays slowly so it follows the person
  private closed = false

  push(ear: number) {
    this.open = Math.max(this.open * 0.995, ear)
    if (this.open < 0.1) return // no usable eye reading yet
    if (ear < this.open * 0.65) {
      this.closed = true
    } else if (this.closed && ear > this.open * 0.85) {
      this.closed = false
      this.blinks += 1
    }
  }
}

export type FaceMeshResults = { multiFaceLandmarks?: Landmarks[] }
export type FaceMeshLike = {
  setOptions(o: Record<string, unknown>): void
  onResults(cb: (r: FaceMeshResults) => void): void
  initialize(): Promise<void>
  send(input: { image: CanvasImageSource }): Promise<void>
}
type FaceMeshCtor = new (opts: { locateFile: (file: string) => string }) => FaceMeshLike

let loading: Promise<FaceMeshLike> | null = null

/** Loads the face mesh once per page. Rejects if the script or model cannot be fetched. */
export function loadFaceMesh(): Promise<FaceMeshLike> {
  if (loading) return loading
  loading = new Promise<FaceMeshLike>((resolve, reject) => {
    const w = window as unknown as { FaceMesh?: FaceMeshCtor }
    const start = () => {
      if (!w.FaceMesh) return reject(new Error('face mesh missing'))
      const mesh = new w.FaceMesh({ locateFile: (file) => BASE + file })
      mesh.setOptions({ maxNumFaces: 1, refineLandmarks: false, minDetectionConfidence: 0.5, minTrackingConfidence: 0.5 })
      mesh.initialize().then(() => resolve(mesh), reject)
    }
    if (w.FaceMesh) return start()
    const s = document.createElement('script')
    s.src = BASE + 'face_mesh.js'
    s.crossOrigin = 'anonymous'
    s.onload = start
    s.onerror = () => reject(new Error('script failed'))
    document.head.appendChild(s)
  }).catch((err) => {
    loading = null // allow a retry after a failed load
    throw err
  })
  return loading
}
