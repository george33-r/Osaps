import { formatNaira } from '../lib/format'
import { Approx } from '../lib/fx'
import { Link } from 'react-router-dom'
import type { Fabric, Tailor } from '../api'
import { Swatch } from './Swatch'
import { Badge } from './ui'

const STOCK: Record<Fabric['stock_status'], string | null> = {
  in_stock: null,
  low_stock: 'Few left',
  out_of_stock: 'Sold out',
}

/** The fabric leads; text stays quiet underneath. `feature` gives a taller, editorial crop. */
export function FabricCard({ fabric, feature = false }: { fabric: Fabric; feature?: boolean }) {
  const stock = STOCK[fabric.stock_status]
  return (
    <Link to={`/fabrics/${fabric.slug}`} data-tilt className={`fabric-card ${feature ? 'fabric-card--feature' : ''} ${fabric.stock_status === 'out_of_stock' ? 'is-soldout' : ''}`}>
      <div className="fabric-card__cloth">
        {fabric.image_url ? (
          <img src={fabric.image_url} alt={fabric.name} loading="lazy" decoding="async" />
        ) : (
          <Swatch pattern={fabric.pattern} colors={fabric.colors} scale={feature ? 1.5 : 1.15} />
        )}
        {stock && <span className="fabric-card__flag">{stock}</span>}
      </div>
      <div className="fabric-card__text">
        <span className="label">{fabric.category_label}</span>
        <h3 className="fabric-card__name">{fabric.name}</h3>
        <p className="fabric-card__meta">{[fabric.material, fabric.vendor?.business_name].filter(Boolean).join(' · ')}</p>
        <p className="fabric-card__price">
          {fabric.price.formatted}
          <span> per {fabric.unit}</span>
          <Approx minor={fabric.price.amount_minor} />
        </p>
        {fabric.piece_yards && fabric.unit === 'yard' ? <p className="fabric-card__piece">{fabric.piece_yards} yards: {formatNaira(fabric.price.amount_minor * fabric.piece_yards)}</p> : null}
        {fabric.has_video && <span className="fabric-card__video">▶ Real video</span>}
      </div>
    </Link>
  )
}

export function TrustLine({ tailor }: { tailor: Tailor }) {
  const r = tailor.track_record
  return (
    <p className="trust">
      {tailor.verified && <Badge tone="good">Verified by OSAPS</Badge>}
      <span className="trust__record">
        {r.is_new
          ? 'New maker on OSAPS'
          : `${r.completed_orders} completed orders${r.on_time_rate !== null ? `, ${Math.round(r.on_time_rate * 100)}% on time` : ''}`}
      </span>
    </p>
  )
}

export function Monogram({ name, large = false }: { name: string; large?: boolean }) {
  const letter = name.normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^A-Za-z]/g, '').slice(0, 1).toUpperCase()
  return (
    <span className={`monogram ${large ? 'monogram--large' : ''}`} aria-hidden>
      {letter}
    </span>
  )
}

/** A maker, presented by craft first: what they make, where, and an honest track record. */
export function TailorCard({ tailor }: { tailor: Tailor }) {
  return (
    <Link to={`/tailors/${tailor.slug}`} data-tilt className="maker-card">
      <Monogram name={tailor.business_name} />
      <div className="maker-card__text">
        <span className="label">{tailor.specialties.slice(0, 3).map((s) => s.label).join(', ')}</span>
        <h3 className="maker-card__name">{tailor.business_name}</h3>
        <p className="maker-card__where">{tailor.service_cities.join(', ')}</p>
        {tailor.bio && <p className="maker-card__bio">{tailor.bio}</p>}
        <div className="maker-card__foot">
          <TrustLine tailor={tailor} />
          {tailor.starting_price && <span className="maker-card__from">From {tailor.starting_price.formatted}</span>}
        </div>
      </div>
    </Link>
  )
}
