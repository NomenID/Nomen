import { useT } from '../composables/useT.js'

const { t } = useT()

export async function parseJsonResponse(response) {
  const text = await response.text()
  try {
    return JSON.parse(text)
  } catch (err) {
    throw new Error(t('服务器返回了 {:status}，请稍后重试', { status: response.status }))
  }
}
