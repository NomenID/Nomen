<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'

const route = useRoute()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const busy = ref(false)

const userId = computed(() => Number(route.params.id))

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/users/${userId.value}`)
  } catch (err) {
    error.value = '用户不存在或加载失败'
  } finally {
    loading.value = false
  }
}

async function toggleStatus() {
  const user = data.value.user
  const disabling = Number(user.status) === 1
  const ok = await window.Nomen.confirm(
    disabling ? '禁用账户' : '启用账户',
    disabling ? `禁用后 ${user.username} 将无法登录，确定继续？` : `重新允许 ${user.username} 登录？`,
    { confirmText: disabling ? '禁用' : '启用', cancelText: '取消' },
  )
  if (!ok) return
  busy.value = true
  try {
    const result = await apiPost('/admin/users/toggle-status', { user_id: user.id })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = false
  }
}

async function toggleAdmin() {
  const user = data.value.user
  const granting = user.role !== 'admin'
  const ok = await window.Nomen.confirm(
    granting ? '设为管理员' : '撤销管理员',
    granting ? `把 ${user.username} 设为管理员？` : `撤销 ${user.username} 的管理员身份？`,
    { confirmText: granting ? '设为管理员' : '撤销', cancelText: '取消' },
  )
  if (!ok) return
  busy.value = true
  try {
    const result = await apiPost('/admin/users/toggle-admin', { user_id: user.id })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = false
  }
}

const methodEntries = computed(() => Object.entries(data.value?.methods || {}))
const assistEntries = computed(() => Object.entries(data.value?.assistMethods || {}))

onMounted(load)
</script>

<template>
  <AdminPage :title="data ? `用户 · ${data.user.username}` : '用户详情'" description="账号资料、登录方式、授权与最近活动。">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/users">返回列表</router-link>
      <template v-if="data">
        <button class="a-btn a-btn--ghost" type="button" :disabled="busy" @click="toggleStatus">
          {{ Number(data.user.status) === 1 ? '禁用账户' : '启用账户' }}
        </button>
        <button class="a-btn a-btn--ghost" type="button" :disabled="busy || data.isSelf" @click="toggleAdmin">
          {{ data.user.role === 'admin' ? '撤销管理员' : '设为管理员' }}
        </button>
      </template>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <div class="a-profile">
        <div class="a-profile__avatar">{{ (data.user.username || '?').slice(0, 1).toUpperCase() }}</div>
        <div>
          <h2>{{ data.user.display_name || data.user.username }}</h2>
          <p class="a-text a-text--muted">@{{ data.user.username }} · ID {{ data.user.id }}</p>
          <div class="a-badges">
            <AdminBadge :tone="data.user.role === 'admin' ? 'primary' : ''">{{ data.user.role === 'admin' ? '管理员' : '普通用户' }}</AdminBadge>
            <AdminBadge :tone="Number(data.user.status) === 1 ? 'success' : 'danger'">{{ Number(data.user.status) === 1 ? '正常' : '已禁用' }}</AdminBadge>
            <AdminBadge :tone="Number(data.user.email_verified) === 1 ? 'success' : 'muted'">
              {{ Number(data.user.email_verified) === 1 ? '邮箱已验证' : '邮箱未验证' }}
            </AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard title="账号信息">
          <dl class="a-kv">
            <dt>邮箱</dt><dd>{{ data.user.email || '—' }}</dd>
            <dt>国家 / 地区</dt><dd>{{ data.user.country || '—' }}</dd>
            <dt>出生日期</dt><dd>{{ data.user.birth_date || '—' }}</dd>
            <dt>性别</dt><dd>{{ data.user.gender || '—' }}</dd>
            <dt>两步验证</dt><dd>{{ data.user.two_factor_policy || 'off' }}</dd>
            <dt>注册时间</dt><dd>{{ data.user.created_at }}</dd>
            <dt>最近登录</dt><dd>{{ data.user.last_login_at || '从未登录' }}<template v-if="data.user.last_login_ip"> · <span class="a-mono">{{ data.user.last_login_ip }}</span></template></dd>
          </dl>
        </AdminCard>

        <AdminCard title="登录方式">
          <dl class="a-kv">
            <template v-for="[key, method] in methodEntries" :key="key">
              <dt>{{ method.label }}</dt>
              <dd>
                <AdminBadge :tone="method.present ? 'success' : 'muted'">{{ method.present ? '已设置' : '未设置' }}</AdminBadge>
              </dd>
            </template>
          </dl>
          <template v-if="assistEntries.length">
            <p class="a-text a-text--muted">快速辅助验证方式</p>
            <dl class="a-kv">
              <template v-for="[key, method] in assistEntries" :key="key">
                <dt>{{ method.label }}</dt>
                <dd>
                  <AdminBadge :tone="method.present ? 'success' : 'muted'">
                    {{ method.present ? (method.on || '已绑定') : (method.off || '未绑定') }}
                  </AdminBadge>
                </dd>
              </template>
            </dl>
          </template>
        </AdminCard>
      </div>

      <AdminCard title="已授权应用">
        <AdminEmpty v-if="!data.authorizations.length" text="没有授权任何应用。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>应用</th><th>Client ID</th><th>授权范围</th><th>授权时间</th></tr></thead>
            <tbody>
              <tr v-for="auth in data.authorizations" :key="auth.client_id + auth.created_at">
                <td>{{ auth.client_name || '（已删除的应用）' }}</td>
                <td class="a-mono">{{ auth.client_id }}</td>
                <td>{{ auth.scopes }}</td>
                <td class="a-nowrap">{{ auth.created_at }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard title="账号恢复记录">
        <AdminEmpty v-if="!data.recoveryHistory.length" text="没有恢复工单。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>#</th><th>状态</th><th>联系邮箱</th><th>提交时间</th><th>审核时间</th></tr></thead>
            <tbody>
              <tr v-for="req in data.recoveryHistory" :key="req.id">
                <td><router-link class="a-link" :to="`/admin/recovery/${req.id}`">#{{ req.id }}</router-link></td>
                <td><AdminBadge>{{ req.status }}</AdminBadge></td>
                <td>{{ req.contact_email }}</td>
                <td class="a-nowrap">{{ req.created_at }}</td>
                <td class="a-nowrap">{{ req.reviewed_at || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard v-if="data.membership || data.ownedGroups.length" title="家庭 / 监护">
        <dl v-if="data.membership" class="a-kv">
          <dt>所在家庭</dt><dd>#{{ data.membership.group_id }}（{{ data.membership.status }}）</dd>
          <dt>监护人</dt><dd>{{ (data.familyGuardians || []).map(g => g.email || g.username || g.id).join('、') || '—' }}</dd>
        </dl>
        <div v-if="data.ownedGroups.length">
          <p class="a-text a-text--muted">作为监护人的家庭：</p>
          <ul class="a-list">
            <li v-for="group in data.ownedGroups" :key="group.id">
              #{{ group.id }} · {{ group.status }}
              <span v-if="data.ownedGroupsChildren[group.id]">
                · 孩子 {{ data.ownedGroupsChildren[group.id].length }} 人
              </span>
            </li>
          </ul>
        </div>
      </AdminCard>

      <AdminCard title="最近活动">
        <AdminEmpty v-if="!data.recentLogs.length" text="没有活动记录。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>时间</th><th>分类</th><th>动作</th><th>说明</th><th>设备</th></tr></thead>
            <tbody>
              <tr v-for="log in data.recentLogs" :key="log.id">
                <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.id}`">{{ log.created_at }}</router-link></td>
                <td>{{ log.category_label }}</td>
                <td>{{ log.action_label }}</td>
                <td class="a-cell-summary">{{ log.summary }}</td>
                <td>{{ log.device_name || '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
