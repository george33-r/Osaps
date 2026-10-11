import { createContext, useCallback, useContext, useRef, useState, type ReactNode } from 'react'

type Tone = 'good' | 'alert' | 'neutral'
interface ToastItem {
  id: number
  message: string
  tone: Tone
}

const Ctx = createContext<(message: string, tone?: Tone) => void>(() => undefined)

export function ToastProvider({ children }: { children: ReactNode }) {
  const [items, setItems] = useState<ToastItem[]>([])
  const seq = useRef(0)

  const push = useCallback((message: string, tone: Tone = 'neutral') => {
    const id = ++seq.current
    setItems((list) => [...list.slice(-2), { id, message, tone }])
    window.setTimeout(() => setItems((list) => list.filter((t) => t.id !== id)), 5000)
  }, [])

  return (
    <Ctx.Provider value={push}>
      {children}
      <div className="toasts" role="status" aria-live="polite">
        {items.map((t) => (
          <div key={t.id} className={`toast toast--${t.tone}`}>
            {t.message}
            <button className="toast__close" aria-label="Dismiss" onClick={() => setItems((l) => l.filter((x) => x.id !== t.id))}>
              ×
            </button>
          </div>
        ))}
      </div>
    </Ctx.Provider>
  )
}

export const useToast = () => useContext(Ctx)
