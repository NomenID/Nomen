/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

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
