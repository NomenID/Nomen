let csrf = ''

export function setCsrf(token) {
  csrf = token || ''
}

export async function request(action, payload, options = {}) {
  const method = options.method || 'POST'
  const init = {
    method,
    credentials: 'same-origin',
    headers: { 'X-CSRF-Token': csrf },
  }

  if (method !== 'GET' && payload !== undefined && payload !== null) {
    if (payload instanceof FormData) {
      init.body = payload
    } else {
      init.headers['Content-Type'] = 'application/json'
      init.body = JSON.stringify(payload)
    }
  }

  let response
  try {
    response = await fetch(`/nomen-install/api.php?action=${encodeURIComponent(action)}`, init)
  } catch (err) {
    throw new Error('连不上服务器，检查网络后再试。')
  }

  let data = {}
  try {
    data = await response.json()
  } catch (err) {
    data = {}
  }

  if (!response.ok || data.error) {
    const error = new Error(data.error || `请求失败（${response.status}）`)
    error.status = response.status
    error.step = data.step || ''
    throw error
  }

  return data
}
