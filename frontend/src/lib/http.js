import { useT } from '../composables/useT.js'

const { t } = useT()

export async function parseJsonResponse(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch (err) {
    throw new Error(t('Server returned {:status} — try again later', { status: response.status }))
  }
}
