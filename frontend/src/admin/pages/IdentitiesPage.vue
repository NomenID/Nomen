<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminField from '../components/AdminField.vue'
import { copyText } from '../clipboard'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)
const editing = ref(false)
const editingHasSecret = ref(false)
const busyId = ref(0)

const emptyForm = () => ({
  id: 0,
  slug: '',
  name: '',
  type: 'oauth2',
  client_id: '',
  client_secret: '',
  authorize_url: '',
  token_url: '',
  userinfo_url: '',
  emails_url: '',
  scopes: '',
  subject_field: 'sub',
  email_field: 'email',
  name_field: 'name',
  avatar_field: 'picture',
  email_verified_field: '',
  token_auth: 'post',
  extra_authorize_params: '',
  enabled: true,
  sort_order: 0,
})

const form = reactive(emptyForm())

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/identities')
  } catch (err) {
    error.value = '登录方式加载失败'
  } finally {
    loading.value = false
  }
}

function startCreate() {
  Object.assign(form, emptyForm())
  editing.value = true
}

function startEdit(provider) {
  Object.assign(form, emptyForm(), provider)
  form.client_secret = ''
  form.enabled = Number(provider.enabled) === 1
  editingHasSecret.value = !!provider.has_secret
  editing.value = true
}

function applyPreset() {
  const preset = data.value?.presets?.[form.slug]
  if (!preset) return
  Object.assign(form, preset, {
    slug: form.slug,
    client_id: form.client_id,
    client_secret: form.client_secret,
    enabled: true,
    sort_order: form.sort_order,
  })
  form.enabled = Number(preset.enabled ?? 1) === 1
}

async function save() {
  saving.value = true
  try {
    const payload = { ...form, enabled: form.enabled ? '1' : '0' }
    const result = await apiPost('/admin/identities/save', payload)
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      editing.value = false
      await load()
    } else {
      error.value = result.message || '保存失败'
    }
  } finally {
    saving.value = false
  }
}

async function toggle(provider) {
  busyId.value = provider.id
  try {
    const result = await apiPost('/admin/identities/toggle', { id: provider.id })
    if (result.success) {
      provider.enabled = result.enabled
      window.Nomen.toast(result.message, 'success')
    } else {
      window.Nomen.toast(result.message || '操作失败', 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function remove(provider) {
  const ok = await window.Nomen.confirm(
    '删除登录方式',
    `删除「${provider.name}」后，已经用它绑定的用户将无法再通过它登录。`,
    { confirmText: '删除', cancelText: '取消' },
  )
  if (!ok) return
  busyId.value = provider.id
  try {
    const result = await apiPost('/admin/identities/delete', { id: provider.id })
    if (result.success) {
      window.Nomen.toast(result.message, 'success')
      await load()
    } else {
      window.Nomen.toast(result.message || '删除失败', 'error')
    }
  } finally {
    busyId.value = 0
  }
}

async function copy(value, label) {
  const ok = await copyText(value)
  window.Nomen.toast(ok ? `${label}已复制` : '复制失败，请手动选中', ok ? 'success' : 'error')
}

onMounted(load)
</script>

<template>
  <AdminPage title="登录方式" description="允许用户用 GitHub、Google、Microsoft、CaelLabID 或其它标准 OAuth2 提供方登录。">
    <template #actions>
      <button class="a-btn a-btn--primary" type="button" @click="startCreate">添加登录方式</button>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <AdminCard v-if="editing" :title="form.id > 0 ? '编辑登录方式' : '添加登录方式'">
        <form class="a-form" @submit.prevent="save">
          <div class="a-form__row">
            <AdminField v-if="form.id === 0" label="预设" hint="选一个预设自动填好地址和字段，之后仍可改">
              <select v-model="form.slug" class="a-input" @change="applyPreset">
                <option value="">自定义 OAuth2</option>
                <option v-for="(preset, key) in data.presets" :key="key" :value="key">{{ preset.name }}</option>
              </select>
            </AdminField>
            <AdminField label="标识" hint="用在回调地址里，只能小写字母、数字和连字符">
              <input v-model="form.slug" class="a-input a-mono" type="text" maxlength="32" required>
            </AdminField>
            <AdminField label="显示名称">
              <input v-model="form.name" class="a-input" type="text" maxlength="64" required>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="Client ID">
              <input v-model="form.client_id" class="a-input a-mono" type="text" required>
            </AdminField>
            <AdminField label="Client Secret" :hint="form.id > 0 && editingHasSecret ? '已保存，留空表示不改' : '公共客户端可以留空'">
              <input v-model="form.client_secret" class="a-input a-mono" type="password" autocomplete="new-password">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="授权地址">
              <input v-model="form.authorize_url" class="a-input a-mono" type="url" required>
            </AdminField>
            <AdminField label="令牌地址">
              <input v-model="form.token_url" class="a-input a-mono" type="url" required>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="用户信息地址">
              <input v-model="form.userinfo_url" class="a-input a-mono" type="url" required>
            </AdminField>
            <AdminField label="邮箱列表地址" hint="选填。GitHub 需要它来确认邮箱已验证">
              <input v-model="form.emails_url" class="a-input a-mono" type="url">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="Scope" hint="按提供方要求填，多个用空格分隔">
              <input v-model="form.scopes" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField label="令牌请求方式">
              <select v-model="form.token_auth" class="a-input">
                <option value="post">client_id / secret 放表单体</option>
                <option value="basic">HTTP Basic 认证</option>
              </select>
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="唯一标识字段" hint="用户信息 JSON 里的字段名，支持 a.b">
              <input v-model="form.subject_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField label="邮箱字段">
              <input v-model="form.email_field" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="昵称字段">
              <input v-model="form.name_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField label="头像字段">
              <input v-model="form.avatar_field" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="邮箱验证字段" hint="留空表示不信任提供方返回的验证状态（不会自动绑定同邮箱账号）">
              <input v-model="form.email_verified_field" class="a-input a-mono" type="text">
            </AdminField>
            <AdminField label="额外授权参数" hint="查询串格式，例如 prompt=consent">
              <input v-model="form.extra_authorize_params" class="a-input a-mono" type="text">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="排序">
              <input v-model.number="form.sort_order" class="a-input" type="number" step="1">
            </AdminField>
            <AdminField label="状态">
              <label class="a-check">
                <input v-model="form.enabled" type="checkbox">
                <span>在登录页显示</span>
              </label>
            </AdminField>
          </div>

          <div class="a-form__actions">
            <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? '保存中…' : '保存' }}</button>
            <button class="a-btn a-btn--ghost" type="button" @click="editing = false">取消</button>
          </div>
        </form>
      </AdminCard>

      <AdminCard :padded="false">
        <AdminEmpty v-if="!data.providers.length" text="还没有配置第三方登录。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead><tr><th>名称</th><th>标识</th><th>类型</th><th>回调地址</th><th>状态</th><th class="a-td-actions">操作</th></tr></thead>
            <tbody>
              <tr v-for="provider in data.providers" :key="provider.id">
                <td class="a-strong">{{ provider.name }}</td>
                <td class="a-mono">{{ provider.slug }}</td>
                <td>{{ provider.type }}</td>
                <td>
                  <span class="a-mono a-break">{{ provider.callback_url }}</span>
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="copy(provider.callback_url, '回调地址')">复制</button>
                </td>
                <td>
                  <AdminBadge :tone="Number(provider.enabled) === 1 ? 'success' : 'muted'">{{ Number(provider.enabled) === 1 ? '启用' : '停用' }}</AdminBadge>
                </td>
                <td class="a-td-actions">
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="startEdit(provider)">编辑</button>
                  <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="busyId === provider.id" @click="toggle(provider)">
                    {{ Number(provider.enabled) === 1 ? '停用' : '启用' }}
                  </button>
                  <button class="a-btn a-btn--danger a-btn--sm" type="button" :disabled="busyId === provider.id" @click="remove(provider)">删除</button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>

      <AdminCard title="回调地址怎么填">
        <p class="a-text">把每行「回调地址」原样填到提供方的 OAuth 应用设置里，逐字一致，不要多斜杠或少斜杠。</p>
        <p class="a-text a-text--muted">CaelLabID 的回调白名单要求不带 .php；本程序的路由本来就是无后缀的。</p>
      </AdminCard>
    </template>
  </AdminPage>
</template>
