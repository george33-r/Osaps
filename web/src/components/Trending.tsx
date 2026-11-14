import { api, type TrendingFabric } from '../api'
import { FabricCard } from './cards'
import { useAsync } from '../lib/useAsync'

/** Shown only when real sales have made a fabric trend. Counts come from paid orders; nothing is estimated. */
export function TrendingFabrics() {
  const res = useAsync<TrendingFabric[]>(() => api.trendingFabrics(), [])
  const rows = res.data ?? []
  if (rows.length === 0) return null

  return (
    <section className="sh-section" aria-labelledby="trending-title">
      <header className="sh-section__head">
        <h2 id="trending-title" className="sh-section__title">Trending on OSAPS</h2>
        <span className="muted">From real orders in the last 30 days</span>
      </header>
      <div className="sh-grid">
        {rows.slice(0, 4).map((row) => (
          <div key={row.fabric.id}>
            <FabricCard fabric={row.fabric} />
            <p className="muted">
              {row.trend.orders} {row.trend.orders === 1 ? 'order' : 'orders'} from {row.trend.buyers} {row.trend.buyers === 1 ? 'customer' : 'customers'} in the last 30 days
            </p>
          </div>
        ))}
      </div>
    </section>
  )
}
