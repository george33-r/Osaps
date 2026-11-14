import { PageHeader } from '../components/ui'
import { AskPanel } from '../components/Community'

export function AskPage() {
  return (
    <div className="page page--narrow">
      <PageHeader
        title="Ask a tailor"
        intro="Ask a question about fabric, fittings, timelines or care. Verified tailors answer. The best answers appear on the OSAPS home page as tips."
      />
      <AskPanel />
    </div>
  )
}
