import '@fontsource-variable/anybody/standard.css'
import '@fontsource-variable/hanken-grotesk'
import './styles/tokens.css'
import './styles/app.css'

import { StrictMode, lazy, Suspense, type ReactNode } from 'react'
import { createRoot } from 'react-dom/client'
import { createBrowserRouter, Link, RouterProvider, ScrollRestoration, Outlet } from 'react-router-dom'
import { Layout, RequireRole } from './components/Layout'
import { Skeleton } from './components/ui'
import Home from './pages/Home'
import { SessionProvider } from './lib/session'
import { ToastProvider } from './lib/toast'

// Route-level code splitting keeps the first load small on mobile data.
const FabricCatalogue = lazy(() => import('./pages/Fabrics').then((m) => ({ default: m.FabricCatalogue })))
const FabricDetail = lazy(() => import('./pages/Fabrics').then((m) => ({ default: m.FabricDetail })))
const TailorDirectory = lazy(() => import('./pages/Tailors').then((m) => ({ default: m.TailorDirectory })))
const TailorProfilePage = lazy(() => import('./pages/Tailors').then((m) => ({ default: m.TailorProfilePage })))
const SignIn = lazy(() => import('./pages/Auth').then((m) => ({ default: m.SignIn })))
const Register = lazy(() => import('./pages/Auth').then((m) => ({ default: m.Register })))
const PostRequest = lazy(() => import('./pages/PostRequest'))
const Stylist = lazy(() => import('./pages/Stylist'))
const MyRequests = lazy(() => import('./pages/Requests').then((m) => ({ default: m.MyRequests })))
const RequestDetail = lazy(() => import('./pages/Requests').then((m) => ({ default: m.RequestDetail })))
const TailorInbox = lazy(() => import('./pages/Tailor').then((m) => ({ default: m.TailorInbox })))
const TailorJob = lazy(() => import('./pages/Tailor').then((m) => ({ default: m.TailorJob })))
const TailorProfileEdit = lazy(() => import('./pages/Tailor').then((m) => ({ default: m.TailorProfileEdit })))
const OrderList = lazy(() => import('./pages/Orders').then((m) => ({ default: m.OrderList })))
const OrderDetail = lazy(() => import('./pages/Orders').then((m) => ({ default: m.OrderDetail })))

const page = (el: ReactNode) => <Suspense fallback={<div className="page"><Skeleton lines={6} /></div>}>{el}</Suspense>
const customer = (el: ReactNode) => page(<RequireRole role="customer">{el}</RequireRole>)
const tailor = (el: ReactNode) => page(<RequireRole role="tailor">{el}</RequireRole>)
const signedIn = (el: ReactNode) => page(<RequireRole>{el}</RequireRole>)

function NotFound() {
  return (
    <div className="page page--narrow">
      <h1 className="page-header__title">This page does not exist</h1>
      <p>
        The link may be old. <Link to="/">Go to the homepage</Link> or <Link to="/fabrics">browse fabric</Link>.
      </p>
    </div>
  )
}

function Root() {
  return (
    <>
      <ScrollRestoration />
      <Layout />
    </>
  )
}

const router = createBrowserRouter(
  [
    {
      element: <Root />,
      children: [
        {
          element: <Outlet />,
          children: [
            { index: true, element: <Home /> },
            { path: 'fabrics', element: page(<FabricCatalogue />) },
            { path: 'fabrics/:slug', element: page(<FabricDetail />) },
            { path: 'tailors', element: page(<TailorDirectory />) },
            { path: 'tailors/:slug', element: page(<TailorProfilePage />) },
            { path: 'stylist', element: page(<Stylist />) },
            { path: 'signin', element: page(<SignIn />) },
            { path: 'register', element: page(<Register />) },
            { path: 'requests', element: customer(<MyRequests />) },
            { path: 'requests/new', element: customer(<PostRequest />) },
            { path: 'requests/:id', element: customer(<RequestDetail />) },
            { path: 'tailor/inbox', element: tailor(<TailorInbox />) },
            { path: 'tailor/requests/:id', element: tailor(<TailorJob />) },
            { path: 'tailor/profile', element: tailor(<TailorProfileEdit />) },
            { path: 'orders', element: signedIn(<OrderList />) },
            { path: 'orders/:reference', element: signedIn(<OrderDetail />) },
            { path: '*', element: <NotFound /> },
          ],
        },
      ],
    },
  ],
  // Assets are served from /spa/, but app URLs live at the site root (Laravel serves index.html for them).
)

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <SessionProvider>
      <ToastProvider>
        <RouterProvider router={router} />
      </ToastProvider>
    </SessionProvider>
  </StrictMode>,
)
