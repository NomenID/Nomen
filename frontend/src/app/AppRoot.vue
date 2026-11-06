<script setup>
import { provide, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { setCsrf } from './api'
import AppLayout from './AppLayout.vue'

const props = defineProps({
  viewer: { type: Object, required: true },
  brand: { type: Object, default: () => ({}) },
  footerHtml: { type: String, default: '' },
  csrf: { type: String, required: true },
  locales: { type: Array, default: () => [] },
  page: { type: Object, required: true },
})

setCsrf(props.csrf)

// viewer 是响应式的：消息已读 / 收码等操作后各页面直接改它，头部徽标跟着走
const viewer = reactive({ ...props.viewer })
provide('viewer', viewer)
provide('brand', props.brand || {})
provide('footerHtml', props.footerHtml || '')
provide('csrf', props.csrf)
provide('locales', props.locales || [])
// 首屏数据一次性交给当前路由对应的页面组件
provide('initialPage', { ...props.page, consumed: false })

const router = useRouter()

router.afterEach((to) => {
  const title = to.meta?.title
  document.title = title && title !== 'Nomen' ? `${title} - Nomen` : 'Nomen'
})
</script>

<template>
  <AppLayout>
    <RouterView />
  </AppLayout>
</template>
