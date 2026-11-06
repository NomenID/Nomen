<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminAlert from '../components/AdminAlert.vue'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)
const form = reactive({ login_2fa: 'off', cache_backend: 'auto' })

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/settings')
    form.login_2fa = data.value.policy
    form.cache_backend = data.value.backend
  } catch (err) {
    error.value = '设置加载失败'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const result = await apiPost('/admin/settings', {
      login_2fa: form.login_2fa,
      cache_backend: form.cache_backend,
    })
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || '保存失败', 'error')
    }
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminPage title="设置" description="站点级的安全与缓存策略。">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <AdminAlert v-if="!data.settingsReady" type="warning">
        数据库还没升级（缺 site_settings 表），先跑 <code>php bin/nomen upgrade</code>。
      </AdminAlert>

      <form @submit.prevent="save">
        <AdminCard title="登录两步验证">
          <p class="a-card__desc">在密码之外，要求用户再做一次验证。这里是站点默认策略，用户自己的设置会覆盖它。</p>
          <div class="a-radios">
            <label v-for="(label, value) in data.login2faOptions" :key="value" class="a-radio" :class="{ 'is-active': form.login_2fa === value }">
              <input v-model="form.login_2fa" type="radio" :value="value">
              <span>{{ label }}</span>
            </label>
          </div>
        </AdminCard>

        <AdminCard title="缓存">
          <p class="a-card__desc">
            当前实际使用：<AdminBadge tone="primary">{{ data.effectiveBackend }}</AdminBadge>
            <span v-if="data.backend === 'auto'" class="a-text--muted">（自动模式优先 Redis，不可用时退回文件）</span>
          </p>
          <select v-model="form.cache_backend" class="a-input a-input--narrow">
            <option v-for="(label, value) in data.cacheOptions" :key="value" :value="value">{{ label }}</option>
          </select>
        </AdminCard>

        <AdminCard title="邮件">
          <p class="a-text">
            发信配置：<AdminBadge :tone="data.mailConfigured ? 'success' : 'danger'">{{ data.mailConfigured ? '已配置' : '未配置' }}</AdminBadge>
          </p>
          <p class="a-text a-text--muted">邮件配置在 config/email.php 和 .env 里，改完直接生效，不用在这里保存。</p>
        </AdminCard>

        <div class="a-form__actions">
          <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? '保存中…' : '保存设置' }}</button>
        </div>
      </form>
    </template>
  </AdminPage>
</template>
