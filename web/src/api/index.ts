import { httpApi, type Api } from './client'

export const isPreview = import.meta.env.MODE === 'preview'

/**
 * The real HTTP API in every build except `--mode preview`, where an in-memory design-review
 * adapter is used instead. The condition is static, so production bundles drop the adapter.
 */
let impl: Api = httpApi
if (import.meta.env.MODE === 'preview') {
  impl = (await import('./preview')).previewApi
}

export const api: Api = impl
export { ApiError } from './client'
export type * from './types'
