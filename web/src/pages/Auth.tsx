import { useState, type FormEvent } from 'react'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { api, ApiError } from '../api'
import { Button, Field } from '../components/ui'
import { useSession } from '../lib/session'

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
      navigate(safeNext(params.get('next'), user.role === 'tailor' ? '/tailor/inbox' : '/requests'), { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Could not sign in.', 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page page--auth">
      <h1 className="page-header__title">Sign in</h1>
      <form className="form" onSubmit={submit} noValidate>
        {error && !error.field('email') && <p className="form__error" role="alert">{error.message}</p>}
        <Field label="Email" error={error?.field('email')}>
          {(p) => <input {...p} name="email" type="email" autoComplete="email" required className="input" />}
        </Field>
        <Field label="Password">
          {(p) => <input {...p} name="password" type="password" autoComplete="current-password" required className="input" />}
        </Field>
        <Button type="submit" busy={busy}>
          Sign in
        </Button>
        <p className="muted">
          New to Aṣọlà? <Link to={`/register${params.get('next') ? `?next=${encodeURIComponent(params.get('next')!)}` : ''}`}>Create an account</Link>
        </p>
      </form>
    </div>
  )
}

export function Register() {
  const { setUser } = useSession()
  const navigate = useNavigate()
  const [params] = useSearchParams()
  const [role, setRole] = useState(params.get('role') === 'tailor' ? 'tailor' : 'customer')
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
      navigate(safeNext(params.get('next'), role === 'tailor' ? '/tailor/inbox' : '/requests/new'), { replace: true })
    } catch (err) {
      setError(err instanceof ApiError ? err : new ApiError('Could not create the account.', 0, 'unknown'))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="page page--auth">
      <h1 className="page-header__title">Create an account</h1>
      <form className="form" onSubmit={submit} noValidate>
        <fieldset className="segmented" aria-label="I want to">
          <legend className="field__label">I want to</legend>
          <label className={`segmented__option ${role === 'customer' ? 'is-on' : ''}`}>
            <input type="radio" name="role" value="customer" checked={role === 'customer'} onChange={() => setRole('customer')} />
            Get outfits made
          </label>
          <label className={`segmented__option ${role === 'tailor' ? 'is-on' : ''}`}>
            <input type="radio" name="role" value="tailor" checked={role === 'tailor'} onChange={() => setRole('tailor')} />
            Take tailoring work
          </label>
        </fieldset>
        {error && Object.keys(error.fieldErrors).length === 0 && <p className="form__error" role="alert">{error.message}</p>}
        <Field label={role === 'tailor' ? 'Your name or business name' : 'Your name'} error={error?.field('name')}>
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
    </div>
  )
}
