import { reactive } from 'vue'
import { request, setCsrf } from './api'

export const wizard = reactive({
  step: 'start',
  resume: 'env',
  started: false,
  busy: false,
  error: '',
  state: {},
  languages: [],
  plugins: [],
  env: { required: [], optional: [], all: true },
  upload: { enabled: false, reason: '' },
  baseUrl: '',
  defaultLogo: '/assets/brand/nomen-icon.svg',
  done: null,
})

export function initWizard(payload) {
  setCsrf(payload.csrf)
  wizard.step = payload.step || 'start'
  wizard.resume = payload.resume || 'env'
  wizard.started = !!payload.started
  wizard.state = payload.state || {}
  wizard.languages = payload.languages || []
  wizard.plugins = payload.plugins || []
  wizard.env = payload.env || { required: [], optional: [], all: true }
  wizard.upload = payload.upload || { enabled: false, reason: '' }
  wizard.baseUrl = payload.baseUrl || ''
  wizard.defaultLogo = payload.defaultLogo || '/assets/brand/nomen-icon.svg'
  wizard.done = payload.done || null
}

export function go(step) {
  wizard.error = ''
  wizard.step = step
  if (typeof window !== 'undefined') {
    window.scrollTo({ top: 0, behavior: 'smooth' })
  }
}

export async function submit(action, payload) {
  wizard.busy = true
  wizard.error = ''
  try {
    const data = await request(action, payload ?? {})
    if (data.state !== undefined) {
      wizard.state = data.state
    }
    if (data.step) {
      wizard.step = data.step
    }
    if (data.done) {
      wizard.done = data.done
    }
    return data
  } catch (err) {
    wizard.error = err.message
    if (err.step) {
      wizard.resume = err.step
    }
    return null
  } finally {
    wizard.busy = false
  }
}

export async function refreshEnv() {
  wizard.busy = true
  wizard.error = ''
  try {
    const data = await request('env', null, { method: 'GET' })
    wizard.env = data.env
    wizard.upload = data.upload
    return data
  } catch (err) {
    wizard.error = err.message
    return null
  } finally {
    wizard.busy = false
  }
}

export async function uploadLogo(file) {
  const form = new FormData()
  form.append('logo', file)
  const data = await request('upload-logo', form)
  return data.url
}

export async function resetStep() {
  const data = await submit('reset')
  if (data) {
    wizard.started = false
    wizard.state = {}
    wizard.resume = 'env'
    go('start')
  }
}

export function languageName(slug) {
  const language = wizard.languages.find((item) => item.slug === slug)
  return language ? language.name : slug
}

export function pluginName(slug) {
  const plugin = wizard.plugins.find((item) => item.slug === slug)
  return plugin ? plugin.name : slug
}
