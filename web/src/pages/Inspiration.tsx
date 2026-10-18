import { api } from '../api'
import { PageHeader } from '../components/ui'
import { useAsync } from '../lib/useAsync'
import { CultureBand, Cultures, Diaspora, Festivals } from './HomeCulture'
import { Lookbook, PhotoGallery, StyleWall, Weddings } from './HomeLookbook'

/** Style and culture: the lookbook, wedding wardrobes, festivals and diaspora notes that used to fill the home page. */
export default function Inspiration() {
  const rack = useAsync(() => api.fabrics({ in_stock: true }), [])
  const fabrics = rack.data?.data ?? []
  return (
    <div className="home home--inspo">
      <div className="page">
        <PageHeader label="Style & culture" title="Dress for the occasion" intro="Looks from Yorùbá, Igbo and Hausa celebrations, festivals and owambe, with the fabrics to make them." />
      </div>
      <Lookbook fabrics={fabrics} />
      <StyleWall />
      <Weddings fabrics={fabrics} />
      <CultureBand />
      <Cultures />
      <Festivals />
      <Diaspora fabrics={fabrics} />
      <PhotoGallery />
    </div>
  )
}
