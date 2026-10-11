import { Link } from 'react-router-dom'
import type { Fabric, Tailor } from '../api'
import { Swatch } from './Swatch'
import { Badge, Price } from './ui'

const STOCK: Record<Fabric['stock_status'], [string, 'good' | 'waiting' | 'muted']> = {
  in_stock: ['In stock', 'good'],
  low_stock: ['Low stock', 'waiting'],
  out_of_stock: ['Out of stock', 'muted'],
}

/** A fabric bolt: the swatch is the card. */
export function FabricCard({ fabric }: { fabric: Fabric }) {
  const [stockLabel, stockTone] = STOCK[fabric.stock_status]
  return (
    <Link to={`/fabrics/${fabric.slug}`} className={`bolt ${fabric.stock_status === 'out_of_stock' ? 'bolt--soldout' : ''}`}>
      <div className="bolt__cloth">
        {fabric.image_url ? (
          <img src={fabric.image_url} alt={fabric.name} loading="lazy" decoding="async" />
        ) : (
          <Swatch pattern={fabric.pattern} colors={fabric.colors} />
        )}
        <span className="bolt__tag">{fabric.category_label}</span>
      </div>
      <div className="bolt__label">
        <h3 className="bolt__name">{fabric.name}</h3>
        <p className="bolt__meta">
          {fabric.material}
          {fabric.vendor && <> in {fabric.vendor.city}</>}
        </p>
        <div className="bolt__foot">
          <Price value={fabric.price} unit={fabric.unit} />
          <Badge tone={stockTone}>{stockLabel}</Badge>
        </div>
      </div>
    </Link>
  )
}

export function TrustLine({ tailor }: { tailor: Tailor }) {
  const r = tailor.track_record
  return (
    <p className="trust">
      {tailor.verified ? <Badge tone="good">Verified</Badge> : <Badge tone="muted">Not yet verified</Badge>}
      <span className="trust__record">
        {r.is_new
          ? 'New on Osapa'
          : `${r.completed_orders} completed orders${r.on_time_rate !== null ? `, ${Math.round(r.on_time_rate * 100)}% on time` : ''}`}
      </span>
    </p>
  )
}

export function TailorCard({ tailor }: { tailor: Tailor }) {
  return (
    <Link to={`/tailors/${tailor.slug}`} className="tailor-card">
      <div className="tailor-card__monogram" aria-hidden>
        {tailor.business_name.normalize('NFD').replace(/[̀-ͯ]/g, '').slice(0, 1)}
      </div>
      <div className="tailor-card__body">
        <h3 className="tailor-card__name">{tailor.business_name}</h3>
        <p className="tailor-card__where">{tailor.service_cities.join(', ')}</p>
        <p className="tailor-card__specs">{tailor.specialties.map((s) => s.label).join(', ')}</p>
        <TrustLine tailor={tailor} />
        {tailor.starting_price && (
          <p className="tailor-card__from">
            From <Price value={tailor.starting_price} />
          </p>
        )}
      </div>
    </Link>
  )
}
