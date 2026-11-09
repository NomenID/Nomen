<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
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
const note = ref('')
const busy = ref('')

const statusTone = {
  pending: 'warning',
  approved: 'primary',
  rejected: 'danger',
  completed: 'success',
}
const statusLabel = {
  pending: '待处理',
  approved: '已通过',
  rejected: '已拒绝',
  completed: '已完成',
}

const requestId = computed(() => Number(route.params.id))

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet(`/api/admin/recovery/${requestId.value}`)
    note.value = data.value.request.admin_note || ''
  } catch (err) {
    error.value = '工单不存在或加载失败'
  } finally {
    loading.value = false
  }
}

async function act(mode) {
  if (mode === 'reject' && !note.value.trim()) {
    window.Nomen.toast('拒绝时要填写原因（记录在工单内）', 'error')
    return
  }
  const labels = { approve: '通过申诉', reject: '拒绝申诉', resend: '重发链接' }
  const ok = await window.Nomen.confirm(
    labels[mode],
    mode === 'reject' ? '确定拒绝这条申诉？' : '确定继续？会向申请人发送邮件。',
    { confirmText: labels[mode], cancelText: '取消' },
  )
  if (!ok) return

  busy.value = mode
  try {
    const result = await apiPost(`/admin/recovery/${mode}`, {
      request_id: requestId.value,
      admin_note: note.value,
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    busy.value = ''
  }
}

function matchLabel(value) {
  if (value === null || value === undefined || value === '') return '账号无记录'
  return Number(value) === 1 ? '匹配' : '不匹配'
}

function matchTone(value) {
  if (value === null || value === undefined || value === '') return 'muted'
  return Number(value) === 1 ? 'success' : 'danger'
}

function humanSize(bytes) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

onMounted(load)
</script>

<template>
  <AdminPage :title="`工单 #${requestId}`" description="核对信息、查看证明材料和账号时间线。">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/recovery">返回列表</router-link>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <div class="a-profile">
        <div class="a-profile__avatar">#</div>
        <div>
          <h2>{{ data.request.username }}</h2>
          <p class="a-text a-text--muted">提交于 {{ data.request.created_at }} · 联系邮箱 {{ data.request.contact_email }}</p>
          <div class="a-badges">
            <AdminBadge :tone="statusTone[data.request.status] || ''">{{ statusLabel[data.request.status] || data.request.status }}</AdminBadge>
            <AdminBadge v-if="data.request.reviewed_at" tone="muted">审核于 {{ data.request.reviewed_at }}</AdminBadge>
          </div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard title="申请信息">
          <dl class="a-kv">
            <dt>账号</dt><dd><router-link class="a-link" :to="`/admin/users/${data.request.user_id}`">{{ data.request.username }}</router-link></dd>
            <dt>账号邮箱</dt><dd>{{ data.request.account_email || '—' }}</dd>
            <dt>联系邮箱</dt><dd>{{ data.request.contact_email }}</dd>
            <dt>出生日期</dt>
            <dd>{{ data.request.birth_date_input || '—' }} <AdminBadge :tone="matchTone(data.request.birth_date_match)">{{ matchLabel(data.request.birth_date_match) }}</AdminBadge></dd>
            <dt>性别</dt>
            <dd>{{ data.request.gender_input || '—' }} <AdminBadge :tone="matchTone(data.request.gender_match)">{{ matchLabel(data.request.gender_match) }}</AdminBadge></dd>
            <dt>提交 IP</dt><dd class="a-mono">{{ data.request.ip_address || '—' }}</dd>
            <dt>用户代理</dt><dd class="a-sub">{{ data.request.user_agent || '—' }}</dd>
          </dl>
          <p class="a-text a-text--muted">申请人描述</p>
          <p class="a-text a-preline">{{ data.request.description || '（没有填写）' }}</p>
        </AdminCard>

        <AdminCard title="账号信息">
          <dl class="a-kv">
            <dt>用户名</dt><dd>{{ data.user.username || '—' }}</dd>
            <dt>邮箱</dt><dd>{{ data.user.email || '—' }} <AdminBadge :tone="Number(data.user.email_verified) === 1 ? 'success' : 'muted'">{{ Number(data.user.email_verified) === 1 ? '已验证' : '未验证' }}</AdminBadge></dd>
            <dt>状态</dt><dd><AdminBadge :tone="Number(data.user.status) === 1 ? 'success' : 'danger'">{{ Number(data.user.status) === 1 ? '正常' : '已禁用' }}</AdminBadge></dd>
            <dt>注册时间</dt><dd>{{ data.user.created_at || '—' }}</dd>
            <dt>最近登录</dt><dd>{{ data.user.last_login_at || '从未登录' }}</dd>
            <dt>链接有效期</dt><dd>{{ data.linkTtlDays }} 天</dd>
            <template v-if="data.request.recovery_token_expires_at">
              <dt>当前链接</dt><dd>{{ data.request.recovery_token_expires_at }}<template v-if="data.request.recovery_token_used_at">（已使用）</template></dd>
            </template>
          </dl>
        </AdminCard>
      </div>

      <AdminCard title="审核">
        <label class="a-field">
          <span class="a-field__label">管理员备注</span>
          <textarea v-model="note" class="a-input a-textarea" rows="3" placeholder="拒绝时必须填写原因（不会发给用户）"></textarea>
        </label>
        <div class="a-form__actions">
          <button class="a-btn a-btn--primary" type="button" :disabled="!!busy" @click="act('approve')">{{ busy === 'approve' ? '处理中…' : '通过申诉' }}</button>
          <button class="a-btn a-btn--ghost" type="button" :disabled="!!busy" @click="act('resend')">{{ busy === 'resend' ? '处理中…' : '重发链接' }}</button>
          <button class="a-btn a-btn--danger" type="button" :disabled="!!busy" @click="act('reject')">{{ busy === 'reject' ? '处理中…' : '拒绝申诉' }}</button>
        </div>
      </AdminCard>

      <AdminCard title="证明材料">
        <AdminEmpty v-if="!data.attachments.length" text="没有上传证明材料。" />
        <div v-else class="a-attachments">
          <a v-for="file in data.attachments" :key="file.name" :href="file.url" target="_blank" rel="noopener" class="a-attachment">
            <img :src="file.url" :alt="file.name" loading="lazy">
            <span>{{ file.seq }} · {{ humanSize(file.size) }} · {{ file.mime }}</span>
          </a>
        </div>
      </AdminCard>

      <AdminCard title="账号最近活动">
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
