import { useCallback, useEffect, useRef, useState } from 'react'

export type AsyncState<T> =
  | { status: 'loading'; data?: T; error?: undefined }
  | { status: 'ready'; data: T; error?: undefined }
  | { status: 'error'; data?: T; error: Error }

/**
 * Loads data and keeps the last good value while reloading (so filters don't flash
 * empty on slow connections). Stale responses from earlier calls are ignored.
 */
export function useAsync<T>(load: () => Promise<T>, deps: unknown[]): AsyncState<T> & { reload: () => void } {
  const [state, setState] = useState<AsyncState<T>>({ status: 'loading' })
  const [tick, setTick] = useState(0)
  const seq = useRef(0)

  useEffect(() => {
    const id = ++seq.current
    setState((s) => ({ status: 'loading', data: s.data }))
    load().then(
      (data) => id === seq.current && setState({ status: 'ready', data }),
      (error: Error) => id === seq.current && setState((s) => ({ status: 'error', error, data: s.data })),
    )
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [...deps, tick])

  const reload = useCallback(() => setTick((t) => t + 1), [])
  return { ...state, reload }
}
