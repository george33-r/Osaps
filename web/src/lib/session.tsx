import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { api, type Meta, type User } from '../api'

interface Session {
  user: User | null
  meta: Meta | null
  ready: boolean
  setUser: (u: User | null) => void
  signOut: () => Promise<void>
  refresh: () => Promise<void>
}

const Ctx = createContext<Session | null>(null)

export function SessionProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null)
  const [meta, setMeta] = useState<Meta | null>(null)
  const [ready, setReady] = useState(false)

  const refresh = useCallback(async () => {
    const [me, m] = await Promise.allSettled([api.me(), api.meta()])
    setUser(me.status === 'fulfilled' ? me.value : null)
    if (m.status === 'fulfilled') setMeta(m.value)
    setReady(true)
  }, [])

  useEffect(() => {
    void refresh()
  }, [refresh])

  const signOut = useCallback(async () => {
    await api.logout().catch(() => undefined)
    setUser(null)
  }, [])

  const value = useMemo(() => ({ user, meta, ready, setUser, signOut, refresh }), [user, meta, ready, signOut, refresh])
  return <Ctx.Provider value={value}>{children}</Ctx.Provider>
}

export function useSession(): Session {
  const s = useContext(Ctx)
  if (!s) throw new Error('useSession outside SessionProvider')
  return s
}

export function garmentLabel(meta: Meta | null, key: string): string {
  return meta?.garment_categories.find((g) => g.key === key)?.label ?? key
}
