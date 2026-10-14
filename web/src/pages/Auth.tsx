import { useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../api'
import { DrapedCloth } from '../components/Swatch'
import { Button, Field, Label } from '../components/ui'
import { useSession } from '../lib/session'

function AuthFrame({ title, intro, children }: { title: string; intro: string; children: ReactNode }) {
  return (
    <div className="auth">
      <aside className="auth__art" aria-hidden>
        <DrapedCloth pattern="stripes" colors={['burgundy', 'gold', 'cream']} seed={6} scale={1.3} />
        <p className="auth__statement">Discover the fabric. Imagine the outfit. Meet your maker. Wear your story.</p>
      </aside>
      <div className="auth__panel">
        <Label>OSAPS</Label>
        <h1 className="page-header__title">{title}</h1>
        <p className="page-header__intro">{intro}</p>
        {children}
      </div>
    </div>
  )
}

function safeNext(next: string | null, fallback: string) {
  // Only same-site relative paths — never redirect to another origin.
  return next && next.startsWith('/') && !next.startsWith('//') ? next : fallback
}

export function SignIn() {
  const { setUser } = useSession()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const form = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const user = await api.login(String(form.get('email')), String(form.get('password')))
      setUser(user)
      navigate(safeNext(params.get('next'), user.role === 'tailor' ? '/tailor/inbox' : user.role === 'fabric_vendor' ? '/vendor' : user.role === 'admin' ? '/admin' : '/fabrics'), { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Could not sign in.', 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthFrame title="Welcome back" intro="Sign in to follow your requests, offers and orders.">
      <form className="form" onSubmit={submit} noValidate>
        {params.get('reset') && !error && <p className="form__ok" role="status">Password changed. Sign in with your new password.</p>}
        {error && !error.field('email') && <p className="form__error" role="alert">{error.message}</p>}
        <Field label="Email" error={error?.field('email')}>
          {(p) => <input {...p} name="email" type="email" autoComplete="email" required className="input" />}
        </Field>
        <Field label="Password">
          {(p) => <input {...p} name="password" type="password" autoComplete="current-password" required className="input" />}
        </Field>
        <p className="form__aside"><Link to="/forgot-password">Forgot your password?</Link></p>
        <Button type="submit" busy={busy}>
          Sign in
        </Button>
        <p className="muted">
          New to OSAPS? <Link to={`/register${params.get('next') ? `?next=${encodeURIComponent(params.get('next')!)}` : ''}`}>Create an account</Link>
        </p>
      </form>
    </AuthFrame>
  )
}

export function Register() {
  const { setUser } = useSession()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const initialRole = params.get('role')
  const [role, setRole] = useState(initialRole === 'tailor' || initialRole === 'fabric_vendor' ? initialRole : 'customer')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)

  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true)
    setError(null)
    try {
      const user = await api.register({
        name: String(f.get('name')),
        email: String(f.get('email')),
        password: String(f.get('password')),
        password_confirmation: String(f.get('password_confirmation')),
        city: String(f.get('city') || ''),
        role,
      })
      setUser(user)
      navigate(safeNext(params.get('next'), role === 'tailor' ? '/tailor/inbox' : role === 'fabric_vendor' ? '/vendor' : '/fabrics'), { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Could not create the account.', 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <AuthFrame title="Create your account" intro="Shop fabric, name your price, have outfits made, or sell as a vendor or tailor.">
      <form className="form" onSubmit={submit} noValidate>
        <fieldset className="segmented" aria-label="I want to">
          <legend className="field__label">I want to</legend>
          <label className={`segmented__option ${role === 'customer' ? 'is-on' : ''}`}>
            <input type="radio" name="role" value="customer" checked={role === 'customer'} onChange={() => setRole('customer')} />
            Shop and get outfits made
          </label>
          <label className={`segmented__option ${role === 'tailor' ? 'is-on' : ''}`}>
            <input type="radio" name="role" value="tailor" checked={role === 'tailor'} onChange={() => setRole('tailor')} />
            Take tailoring work
          </label>
          <label className={`segmented__option ${role === 'fabric_vendor' ? 'is-on' : ''}`}>
            <input type="radio" name="role" value="fabric_vendor" checked={role === 'fabric_vendor'} onChange={() => setRole('fabric_vendor')} />
            Sell fabric
          </label>
        </fieldset>
        {error && Object.keys(error.fieldErrors).length === 0 && <p className="form__error" role="alert">{error.message}</p>}
        <Field label={role !== 'customer' ? 'Your name or business name' : 'Your name'} error={error?.field('name')}>
          {(p) => <input {...p} name="name" autoComplete="name" required className="input" />}
        </Field>
        <Field label="Email" error={error?.field('email')}>
          {(p) => <input {...p} name="email" type="email" autoComplete="email" required className="input" />}
        </Field>
        <Field label="City" optional>
          {(p) => <input {...p} name="city" autoComplete="address-level2" className="input" placeholder="e.g. Abuja" />}
        </Field>
        <Field label="Password" hint="At least 8 characters, with letters and numbers." error={error?.field('password')}>
          {(p) => <input {...p} name="password" type="password" autoComplete="new-password" required className="input" />}
        </Field>
        <Field label="Confirm password">
          {(p) => <input {...p} name="password_confirmation" type="password" autoComplete="new-password" required className="input" />}
        </Field>
        <Button type="submit" busy={busy}>
          Create account
        </Button>
        <p className="muted">
          Already have an account? <Link to="/signin">Sign in</Link>
        </p>
      </form>
    </AuthFrame>
  )
}

export function ForgotPassword() {
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<ApiError | null>(null)
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const email = String(new FormData(e.currentTarget).get('email') ?? '').trim()
    setBusy(true); setError(null)
    try { setDone(await api.forgotPassword(email)) } catch (err) { setError(err instanceof ApiError ? err : new ApiError('Could not send the link.', 0, 'unknown')) } finally { setBusy(false) }
  }
  return (
    <AuthFrame title="Reset your password" intro="Enter the email you signed up with. We will send a link to choose a new password.">
      {done ? (
        <div className="form">
          <p className="form__ok" role="status">{done}</p>
          <p className="muted">No email? Check your spam folder, or wait a minute and try again.</p>
          <Link to="/signin">Back to sign in</Link>
        </div>
      ) : (
        <form className="form" onSubmit={submit} noValidate>
          {error && !error.field('email') && <p className="form__error" role="alert">{error.message}</p>}
          <Field label="Email" error={error?.field('email')}>
            {(p) => <input {...p} name="email" type="email" autoComplete="email" required className="input" />}
          </Field>
          <Button type="submit" busy={busy}>Send reset link</Button>
          <p className="muted"><Link to="/signin">Back to sign in</Link></p>
        </form>
      )}
    </AuthFrame>
  )
}

export function ResetPassword() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<ApiError | null>(null)
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const submit = async (e: FormEvent<HTMLFormElement>) => {
    e.preventDefault()
    const f = new FormData(e.currentTarget)
    setBusy(true); setError(null)
    try {
      await api.resetPassword({ token, email, password: String(f.get('password')), password_confirmation: String(f.get('password_confirmation')) })
      navigate('/signin?reset=1', { replace: true })
    } catch (err) { setError(err instanceof ApiError ? err : new ApiError('Could not change the password.', 0, 'unknown')) } finally { setBusy(false) }
  }
  if (!token || !email) {
    return (
      <AuthFrame title="Link not complete" intro="Open the link from your email again, or ask for a new one.">
        <Link className="btn btn--primary" to="/forgot-password">Ask for a new link</Link>
      </AuthFrame>
    )
  }
  return (
    <AuthFrame title="Choose a new password" intro={`For ${email}.`}>
      <form className="form" onSubmit={submit} noValidate>
        {error && !error.field('password') && <p className="form__error" role="alert">{error.field('email') ?? error.message}</p>}
        <Field label="New password" hint="At least 8 characters, with letters and numbers." error={error?.field('password')}>
          {(p) => <input {...p} name="password" type="password" autoComplete="new-password" required className="input" />}
        </Field>
        <Field label="Confirm new password">
          {(p) => <input {...p} name="password_confirmation" type="password" autoComplete="new-password" required className="input" />}
        </Field>
        <Button type="submit" busy={busy}>Save new password</Button>
      </form>
    </AuthFrame>
  )
}
