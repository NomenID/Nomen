/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

export function toast(message, type = 'info', duration) {
  if (window.Nomen && typeof window.Nomen.toast === 'function') {
    return window.Nomen.toast(message, type, duration)
  }
  if (type === 'error' || type === 'warning') console.warn('[toast]', message)
}
