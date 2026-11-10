<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { apiGet, apiPostForm } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AppForm from '../components/AppForm.vue'
import { copyText } from '../clipboard'

const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const availableScopes = ref({})
const created = ref(null)

const form = reactive({
  name: '',
  description: '',
  homepage: '',
  redirect_uris: '',
  client_type: 'confidential',
  pkce_required: false,
  scopes: ['openid'],
  iconFile: null,
})

async function load() {
  try {
    const data = await apiGet('/api/admin/apps/create')
    availableScopes.value = data.availableScopes || {}
  } catch (err) {
    error.value = '表单配置加载失败'
  } finally {
    loading.value = false
  }
}

async function submit() {
  error.value = ''
  saving.value = true
  try {
    const fd = new FormData()
    fd.set('name', form.name)
    fd.set('description', form.description)
    fd.set('homepage', form.homepage)
    fd.set('redirect_uris', form.redirect_uris)
    fd.set('client_type', form.client_type)
    fd.set('pkce_required', form.pkce_required ? '1' : '')
    for (const scope of form.scopes) fd.append('scopes[]', scope)
    if (form.iconFile) fd.set('icon', form.iconFile)

    const result = await apiPostForm('/admin/apps/create', fd)
    if (result.success) {
      created.value = result
      window.Nomen.toast('应用已创建', 'success')
    } else {
      error.value = result.message || '创建失败'
    }
  } finally {
    saving.value = false
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? `${label}已复制` : '复制失败，请手动选中', ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage title="新建应用" description="创建后 Client Secret 只显示一次。">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/apps">返回列表</router-link>
    </template>

    <div v-if="loading" class="a-loading">加载中…</div>

    <template v-else>
      <AdminCard v-if="created" title="应用已创建">
        <AdminAlert type="warning">Client Secret 只显示这一次，离开页面后无法再查看。</AdminAlert>
        <dl class="a-kv">
          <dt>Client ID</dt>
          <dd><code>{{ created.client_id }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(created.client_id, 'Client ID')">复制</button></dd>
          <dt>Client Secret</dt>
          <dd><code>{{ created.client_secret }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(created.client_secret, 'Client Secret')">复制</button></dd>
        </dl>
        <button class="a-btn a-btn--primary" type="button" @click="router.push(`/admin/apps/${created.client_id}`)">我已保存，查看应用</button>
      </AdminCard>

      <AdminCard v-else title="基本信息">
        <AdminAlert v-if="error" type="danger" :message="error" />
        <form class="a-form" @submit.prevent="submit">
          <AppForm v-model="form" :available-scopes="availableScopes" />
          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? '创建中…' : '创建应用' }}</button>
            <router-link class="a-btn a-btn--ghost" to="/admin/apps">取消</router-link>
          </div>
        </form>
      </AdminCard>
    </template>
  </AdminPage>
</template>
