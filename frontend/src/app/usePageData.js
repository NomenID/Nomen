/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 页面数据加载：首屏直接用 PHP 壳注入的 props（免一次请求），
// SPA 内导航时改走 /api/app/* 端点。
import { inject, ref } from 'vue'
import { apiGet } from './api'
import { useT } from '../composables/useT.js'

const { t } = useT()

export function usePageData(pageName, buildPath) {
  const initial = inject('initialPage')
  const data = ref(initial && initial.name === pageName ? initial.data : null)
  const loading = ref(!data.value)
  const error = ref('')

  async function load() {
    loading.value = true
    error.value = ''
    try {
      const res = await apiGet(buildPath ? buildPath() : `/api/app/${pageName}`)
      data.value = res.page.data
    } catch (err) {
      error.value = err.message || t('Load failed')
    } finally {
      loading.value = false
    }
  }

  if (!data.value) load()

  return { data, loading, error, reload: load }
}
