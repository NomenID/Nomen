import { useT } from './useT.js'

const { t } = useT()

const PW_UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ'
const PW_LOWER = 'abcdefghijkmnopqrstuvwxyz'
const PW_DIGIT = '23456789'
const PW_SYMBOL = '!@#$%^&*-_=+'

function randomIndex(max) {
  const limit = 256 - (256 % max)
  const buf = new Uint8Array(1)
  for (;;) {
    crypto.getRandomValues(buf)
    if (buf[0] < limit) return buf[0] % max
  }
}

function randomChars(alphabet, count) {
  let out = ''
  for (let i = 0; i < count; i++) out += alphabet[randomIndex(alphabet.length)]
  return out
}

export function generateStrongPassword(length = 16) {
  const all = PW_UPPER + PW_LOWER + PW_DIGIT + PW_SYMBOL
  const chars = (randomChars(PW_UPPER, 1) + randomChars(PW_LOWER, 1) + randomChars(PW_DIGIT, 1) +
    randomChars(PW_SYMBOL, 1) + randomChars(all, length - 4)).split('')

  for (let i = chars.length - 1; i > 0; i--) {
    const j = randomIndex(i + 1)
    const tmp = chars[i]
    chars[i] = chars[j]
    chars[j] = tmp
  }
  return chars.join('')
}

export function passwordStrength(password) {
  let score = 0
  if (password.length >= 8) score++
  if (password.length >= 12) score++
  if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++
  if (/[0-9]/.test(password)) score++
  if (/[^a-zA-Z0-9]/.test(password)) score++

  if (score <= 1) return { label: t('弱'), color: 'var(--color-danger)', width: '20%' }
  if (score <= 2) return { label: t('中'), color: 'var(--color-warning)', width: '40%' }
  if (score <= 3) return { label: t('强'), color: 'var(--color-info)', width: '70%' }
  return { label: t('非常强'), color: 'var(--color-success)', width: '100%' }
}

function copyTextFallback(text) {
  const ta = document.createElement('textarea')
  ta.value = text
  ta.setAttribute('readonly', '')
  ta.style.position = 'fixed'
  ta.style.top = '-1000px'
  document.body.appendChild(ta)
  ta.select()
  let ok = false
  try {
    ok = document.execCommand('copy')
  } catch (err) {
    ok = false
  }
  document.body.removeChild(ta)
  return ok
}

export function copyText(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text)
      .then(() => true)
      .catch(() => copyTextFallback(text))
  }
  return Promise.resolve(copyTextFallback(text))
}
