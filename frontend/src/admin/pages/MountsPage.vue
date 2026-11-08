<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import { copyText } from '../clipboard'

const loading = ref(true)
const error = ref('')
const data = ref(null)
const paths = reactive({})
const savingKey = ref('')

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/mounts')
    for (const mount of data.value.mounts) {
      paths[mountKey(mount)] = mount.path
    }
  } catch (err) {
    error.value = '接入点加载失败'
  } finally {
    loading.value = false
  }
}

function mountKey(mount) {
  return `${mount.plugin}:${mount.id}`
}

async function save(mount) {
  const key = mountKey(mount)
  savingKey.value = key
  try {
    const result = await apiPost(`/admin/mounts/${encodeURIComponent(mount.plugin)}`, {
      mount_id: mount.id,
      config_key: mount.config,
      path: paths[key],
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) {
      mount.path = result.path
      paths[key] = result.path
    }
  } finally {
    savingKey.value = ''
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? `${label}已复制` : '复制失败，请手动选中', ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage title="接入点" description="站点对外提供的 OAuth 端点，以及插件挂载的公开路径。">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <AdminCard title="OAuth 端点">
        <div class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>用途</th><th>路径</th><th>完整地址</th><th>说明</th></tr></thead>
            <tbody>
              <tr v-for="endpoint in data.endpoints" :key="endpoint.path">
                <td>{{ endpoint.label }}</td>
                <td class="a-mono">{{ endpoint.path }}</td>
                <td class="a-mono">
                  {{ data.base }}{{ endpoint.path }}
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(data.base + endpoint.path, '地址')">复制</button>
                </td>
                <td class="a-cell-summary">{{ endpoint.description }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard title="插件挂载点" description="挂载点是插件对外的公开路径，改完下一次请求生效。">
        <AdminEmpty v-if="!data.mounts.length" text="没有启用带挂载点的插件。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>插件</th><th>挂载</th><th>路径</th><th>访问</th><th class="a-td-actions">操作</th></tr></thead>
            <tbody>
              <tr v-for="mount in data.mounts" :key="mountKey(mount)">
                <td>{{ mount.plugin }}</td>
                <td>
                  <strong>{{ mount.title }}</strong>
                  <div v-if="mount.description" class="a-sub">{{ mount.description }}</div>
                </td>
                <td>
                  <input
                    v-if="mount.config"
                    v-model="paths[mountKey(mount)]"
                    class="a-input a-input--inline a-mono"
                    type="text"
                  >
                  <span v-else class="a-mono">{{ mount.path }}</span>
                </td>
                <td><AdminBadge :tone="mount.access === 'admin' ? 'warning' : ''">{{ mount.access === 'admin' ? '仅管理员' : '公开' }}</AdminBadge></td>
                <td class="a-td-actions">
                  <button
                    v-if="mount.config"
                    class="a-btn a-btn--ghost a-btn--sm"
                    type="button"
                    :disabled="savingKey === mountKey(mount) || paths[mountKey(mount)] === mount.path"
                    @click="save(mount)"
                  >保存路径</button>
                  <span v-else class="a-text--muted">不可改</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
