export function toast(message, type = 'info', duration) {
  if (window.Nomen && typeof window.Nomen.toast === 'function') {
    return window.Nomen.toast(message, type, duration)
  }
  if (type === 'error' || type === 'warning') console.warn('[toast]', message)
}
