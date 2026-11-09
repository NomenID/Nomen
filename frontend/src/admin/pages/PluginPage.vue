<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminAlert from '../components/AdminAlert.vue'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const bodyEl = ref(null)

function apiUrl() {
  const params = new URLSearchParams()
  for (const [key, value] of Object.entries(route.query)) {
    if (value !== undefined && value !== null && value !== '') params.set(key, String(value))
  }
  const base = `/api/admin/plugin/${encodeURIComponent(route.params.slug)}`
  return params.toString() ? `${base}?${params}` : base
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(apiUrl())
  } catch (err) {
    error.value = '插件页面加载失败，确认插件已启用。'
  } finally {
    loading.value = false
  }
}

function pickTab(id) {
  router.replace({ query: { ...route.query, tab: id } })
}

async function submitForm(form) {
  const fd = new FormData(form)
  const action = form.getAttribute('action') || window.location.pathname
  try {
    const response = await fetch(action, {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    })
    const type = response.headers.get('content-type') || ''
    if (type.includes('application/json')) {
      const result = await response.json()
      if (result.message) window.Nomen.toast(result.message, result.success === false ? 'error' : 'success')
    }
  } catch (err) {
    window.Nomen.toast('提交失败，请重试', 'error')
  }

  const redirect = form.getAttribute('data-admin-redirect')
  if (redirect) {
    router.push(redirect)
    return
  }
  await load()
}

function onClick(event) {
  const anchor = event.target.closest('a')
  if (!anchor) return
  const href = anchor.getAttribute('href') || ''
  if (href.startsWith('/admin/') && !anchor.hasAttribute('target')) {
    event.preventDefault()
    router.push(href)
  }
}

function onSubmit(event) {
  const form = event.target.closest('form[data-admin-ajax]')
  if (!form) return
  event.preventDefault()
  const message = form.getAttribute('data-admin-confirm')
  if (message) {
    window.Nomen.confirm('确认操作', message, { confirmText: '确定', cancelText: '取消' }).then((ok) => {
      if (ok) submitForm(form)
    })
    return
  }
  submitForm(form)
}

function bindDelegates() {
  const el = bodyEl.value
  if (!el) return
  el.addEventListener('click', onClick)
  el.addEventListener('submit', onSubmit)
}

onMounted(async () => {
  await load()
  await nextTick()
  bindDelegates()
})

watch(() => route.query, () => load())
</script>

<template>
  <AdminPage :title="data?.title || '插件页面'" :description="data?.description || ''">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <div v-if="data.tabs.length" class="a-tabs a-tabs--card">
        <button
          v-for="tab in data.tabs"
          :key="tab.id"
          class="a-tabs__item"
          :class="{ 'is-active': data.activeTab === tab.id }"
          type="button"
          @click="pickTab(tab.id)"
        >{{ tab.label }}</button>
      </div>
      <div ref="bodyEl" class="a-plugin-body" v-html="data.body"></div>
    </template>
  </AdminPage>
</template>
