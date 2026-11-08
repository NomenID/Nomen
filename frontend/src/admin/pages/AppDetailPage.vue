<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { apiGet, apiPost, apiPostForm } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AppForm from '../components/AppForm.vue'
import { copyText } from '../clipboard'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)
const newSecret = ref('')
const clientId = computed(() => String(route.params.clientId))

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

function fillForm(payload) {
  form.name = payload.app?.name || ''
  form.description = payload.app?.description || ''
  form.homepage = payload.app?.homepage_url || ''
  form.redirect_uris = (payload.redirectUris || []).join('\n')
  form.client_type = payload.app?.client_type || 'confidential'
  form.pkce_required = Number(payload.app?.pkce_required) === 1
  form.scopes = payload.appScopes || ['openid']
  form.iconFile = null
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/apps/${clientId.value}`)
    fillForm(data.value)
  } catch (err) {
    error.value = '应用不存在或加载失败'
  } finally {
    loading.value = false
  }
}

async function save() {
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

    const result = await apiPostForm(`/admin/apps/${clientId.value}/update`, fd)
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      error.value = result.message || '保存失败'
    }
  } finally {
    saving.value = false
  }
}

async function resetSecret() {
  const ok = await window.Nomen.confirm(
    '重置密钥',
    '重置后旧密钥和所有已签发的令牌立即失效，接入方必须更新配置。确定继续？',
    { confirmText: '重置', cancelText: '取消' },
  )
  if (!ok) return
  const result = await apiPost(`/admin/apps/${clientId.value}/reset-secret`, {})
  if (result.success) {
    newSecret.value = result.client_secret
    window.Nomen.toast(result.message, 'success')
  } else {
    window.Nomen.toast(result.message || '重置失败', 'error')
  }
}

async function remove() {
  const ok = await window.Nomen.confirm(
    '删除应用',
    `删除「${data.value?.app?.name || clientId.value}」后不可恢复，所有已授权令牌都会失效。`,
    { confirmText: '删除', cancelText: '取消' },
  )
  if (!ok) return
  const result = await apiPost(`/admin/apps/${clientId.value}/delete`, {})
  if (result.success) {
    window.Nomen.toast(result.message, 'success')
    router.push('/admin/apps')
  } else {
    window.Nomen.toast(result.message || '删除失败', 'error')
  }
}

async function toggleStatus() {
  const result = await apiPost(`/admin/apps/${clientId.value}/toggle`, {})
  if (result.success) {
    window.Nomen.toast(result.message, 'success')
    await load()
  } else {
    window.Nomen.toast(result.message || '操作失败', 'error')
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? `${label}已复制` : '复制失败，请手动选中', ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage :title="data ? `应用 · ${data.app.name}` : '应用详情'" description="配置回调地址、授权范围，查看接入示例。">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/apps">返回列表</router-link>
      <button v-if="data" class="a-btn a-btn--ghost" type="button" @click="toggleStatus">
        {{ Number(data.app.status) === 1 ? '停用应用' : '启用应用' }}
      </button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <div class="a-profile">
        <img v-if="data.app.icon" :src="`/assets/develops/${data.app.icon}`" alt="" class="a-profile__avatar a-profile__avatar--img">
        <div v-else class="a-profile__avatar">{{ (data.app.name || '?').slice(0, 1).toUpperCase() }}</div>
        <div>
          <h2>{{ data.app.name }}</h2>
          <p class="a-text a-text--muted">{{ data.app.description || '没有填写简介' }}</p>
          <div class="a-badges">
            <AdminBadge :tone="Number(data.app.status) === 1 ? 'success' : 'danger'">{{ Number(data.app.status) === 1 ? '在架' : '已停用' }}</AdminBadge>
            <AdminBadge>{{ data.isPublic ? '公共客户端' : '机密客户端' }}</AdminBadge>
            <AdminBadge v-if="data.pkceOn" tone="primary">PKCE</AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-statgrid a-statgrid--compact">
        <div class="a-stat"><div class="a-stat__value">{{ data.stats.authorized_users }}</div><div class="a-stat__label">授权用户</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.stats.active_tokens }}</div><div class="a-stat__label">有效令牌</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.app.created_at }}</div><div class="a-stat__label">创建时间</div></div>
        <div class="a-stat"><div class="a-stat__value">{{ data.app.owner_username || data.app.developer_name || '—' }}</div><div class="a-stat__label">归属</div></div>
      </div>

      <AdminCard title="凭据">
        <dl class="a-kv">
          <dt>Client ID</dt>
          <dd><code>{{ data.app.client_id }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.app.client_id, 'Client ID')">复制</button></dd>
          <dt>Client Secret</dt>
          <dd>
            <span v-if="newSecret"><code>{{ newSecret }}</code> <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(newSecret, 'Client Secret')">复制</button></span>
            <span v-else class="a-text--muted">已保存（不会再显示）</span>
            <button v-if="!data.isPublic" class="a-btn a-btn--ghost a-btn--sm" type="button" @click="resetSecret">重置密钥</button>
          </dd>
        </dl>
        <AdminAlert v-if="newSecret" type="warning">新密钥只显示这一次，关闭页面后无法再查看。</AdminAlert>
      </AdminCard>

      <AdminCard title="应用信息">
        <form class="a-form" @submit.prevent="save">
          <AppForm v-model="form" :available-scopes="data.availableScopes" :current-icon="data.app.icon || ''" />
          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? '保存中…' : '保存修改' }}</button>
          </div>
        </form>
      </AdminCard>

      <AdminCard title="接入示例">
        <p class="a-text a-text--muted">授权地址</p>
        <pre class="a-code">{{ data.authorizeSample }}</pre>
        <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.authorizeSample, '授权地址')">复制</button>

        <p class="a-text a-text--muted">换取令牌</p>
        <pre class="a-code">{{ data.tokenSample }}</pre>
        <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.tokenSample, '令牌请求')">复制</button>
      </AdminCard>

      <AdminCard title="危险操作">
        <p class="a-text">删除应用会同时撤销所有用户授权和令牌，且不可恢复。</p>
        <button class="a-btn a-btn--danger" type="button" @click="remove">删除应用</button>
      </AdminCard>
    </template>
  </AdminPage>
</template>
