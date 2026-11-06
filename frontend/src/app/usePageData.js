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
      error.value = err.message || t('加载失败')
    } finally {
      loading.value = false
    }
  }

  if (!data.value) load()

  return { data, loading, error, reload: load }
}
