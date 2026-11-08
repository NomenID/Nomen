<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { apiGet } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import { copyText } from '../clipboard'

const route = useRoute()
const loading = ref(true)
const error = ref('')
const log = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await apiGet(`/api/admin/logs/${Number(route.params.id)}`)
    log.value = data.log
  } catch (err) {
    error.value = '日志不存在或加载失败'
  } finally {
    loading.value = false
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? `${label}已复制` : '复制失败，请手动选中', ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage title="日志详情" description="这条操作的完整记录。">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/logs">返回列表</router-link>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="log">
      <AdminCard :title="`#${log.id}`">
        <template #actions>
          <AdminBadge>{{ log.category_label }}</AdminBadge>
          <AdminBadge tone="muted">{{ log.action }}</AdminBadge>
        </template>
        <dl class="a-kv">
          <dt>时间</dt><dd>{{ log.created_at }}</dd>
          <dt>操作者</dt>
          <dd>
            <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || '系统' }}</router-link>
            <span v-else>{{ log.actor_username || '系统' }}</span>
            <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
          </dd>
          <dt>动作</dt><dd>{{ log.action_label }} <code class="a-mono">{{ log.action }}</code></dd>
          <dt>目标</dt>
          <dd>
            <router-link v-if="log.target_url" class="a-link" :to="log.target_url">{{ log.target_name || log.target_id }}</router-link>
            <span v-else>{{ log.target_name || log.target_id || '—' }}</span>
            <span v-if="log.target_type_label" class="a-text--muted">（{{ log.target_type_label }}）</span>
          </dd>
          <dt>说明</dt><dd>{{ log.summary || '—' }}</dd>
          <dt>IP</dt>
          <dd>
            <span class="a-mono">{{ log.ip_address || '—' }}</span>
            <button v-if="log.ip_address" class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(log.ip_address, 'IP')">复制</button>
          </dd>
          <dt>设备</dt><dd>{{ log.device_name || '—' }}</dd>
          <dt>User-Agent</dt><dd class="a-sub a-break">{{ log.user_agent || '—' }}</dd>
        </dl>
      </AdminCard>

      <AdminCard title="变更明细">
        <AdminEmpty v-if="!log.changes.length" text="这条操作没有字段变更。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>字段</th><th>旧值</th><th>新值</th></tr></thead>
            <tbody>
              <tr v-for="row in log.changes" :key="row.field">
                <td>{{ row.label }}</td>
                <td class="a-diff-old a-preline">{{ row.old }}</td>
                <td class="a-diff-new a-preline">{{ row.new }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
