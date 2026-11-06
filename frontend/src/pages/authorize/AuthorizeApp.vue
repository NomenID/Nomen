<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import Icon from '../../components/Icon.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  client: { type: Object, default: () => ({}) },
  user: { type: Object, default: () => ({}) },
  scope: { type: String, default: 'openid' },
  state: { type: String, default: '' },
  scopeDescriptions: { type: Object, default: () => ({}) },
  requestedScopeList: { type: Array, default: () => [] },
  redirectUri: { type: String, default: '' },
  clientId: { type: String, default: '' },
  responseType: { type: String, default: 'code' },
  isFirstParty: { type: Boolean, default: false },
  quickLogin: { type: Boolean, default: false },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  authorize: props.urls?.authorize || '/oauth/authorize',
  deny: props.urls?.deny || '/oauth/deny',
}

const formEl = ref(null)
const checked = reactive({
  profile: props.requestedScopeList.includes('profile'),
  email: false,
})

const scopeValue = computed(() => {
  const scopes = ['openid']
  if (props.requestedScopeList.includes('profile') && checked.profile) scopes.push('profile')
  if (props.requestedScopeList.includes('email') && checked.email) scopes.push('email')
  return scopes.join(' ')
})

const mediumRiskScopes = computed(() => (
  props.requestedScopeList.includes('email') && checked.email ? [scopeLabel('email')] : []
))

const userInitial = computed(() => {
  const name = props.user.display_name || props.user.username || ''
  return Array.from(name)[0]?.toUpperCase() || '?'
})

const denyUrl = computed(() => (
  urls.deny
  + '?client_id=' + encodeURIComponent(props.clientId)
  + '&state=' + encodeURIComponent(props.state)
  + '&redirect_uri=' + encodeURIComponent(props.redirectUri)
))

const autoText = ref(t('正在为 {:name} 授权…', { name: props.client.name }))
let autoTimer = null
let progressEl = null

function scopeIcon(scope) {
  return { openid: 'fingerprint', profile: 'user', email: 'mail' }[scope] || 'key'
}

function scopeLabel(scope) {
  if (scope === 'profile') return t('用户名、昵称、头像')
  if (scope === 'email') return t('已验证邮箱')
  return props.scopeDescriptions[scope] ? t(props.scopeDescriptions[scope]) : scope
}

function escapeHtml(value) {
  return String(value == null ? '' : value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  }[char]))
}

function confirmModalContent(appName, scopes) {
  return '<div class="nomen-confirm-app">' + escapeHtml(appName) + '</div>'
    + '<div class="nomen-confirm-scopes">'
    + scopes.map((scope) => '<span class="nomen-confirm-scope-tag"><span class="icon icon-user"></span> ' + escapeHtml(scope) + '</span>').join('')
    + '</div>'
    + '<p class="nomen-modal-box__desc">' + t('授权后，该应用将可以访问以上信息。<br>您可以随时在仪表盘中撤销授权。') + '</p>'
}

function showConfirmModal(appName, scopes) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return Promise.resolve(true)
  return window.Nomen.confirm(t('确认授权'), '', {
    icon: 'lock',
    variant: 'confirm',
    confirmText: t('确认授权'),
    content: confirmModalContent(appName, scopes),
  })
}

function submitForm() {
  const form = formEl.value
  if (!form) return
  if (window.Nomen && typeof window.Nomen.beginSubmit === 'function') {
    window.Nomen.beginSubmit(form)
  }
  form.submit()
}

function onSubmit(event) {
  event.preventDefault()
  if (props.quickLogin) return

  if (mediumRiskScopes.value.length > 0) {
    showConfirmModal(props.client.name, mediumRiskScopes.value).then((confirmed) => {
      if (confirmed) submitForm()
    })
    return
  }

  submitForm()
}

onMounted(() => {
  if (!props.quickLogin) return

  progressEl = document.createElement('div')
  progressEl.className = 'nomen-top-progress'
  progressEl.innerHTML = '<div class="nomen-top-progress__bar"></div>'
  document.body.appendChild(progressEl)

  autoTimer = setTimeout(() => {
    progressEl.classList.add('nomen-top-progress--active')
    autoText.value = t('授权完成，正在返回 {:name}…', { name: props.client.name })
    formEl.value?.submit()
  }, 1200)
})

onBeforeUnmount(() => {
  if (autoTimer) clearTimeout(autoTimer)
  if (progressEl) progressEl.remove()
})
</script>

<template>
  <div class="auth-card auth-card--wide">
    <div class="authorize-header">
      <div class="authorize-app">
        <div v-if="client.developer_icon" class="authorize-logo-wrap">
          <img v-if="client.icon" :src="'/assets/develops/' + client.icon" alt="" class="authorize-logo" width="56" height="56">
          <img v-if="client.icon" :src="'/assets/develops/' + client.developer_icon" alt="" class="authorize-developer-icon" width="22" height="22">
          <img v-else :src="'/assets/develops/' + client.developer_icon" alt="" class="authorize-logo" width="56" height="56">
        </div>
        <div class="authorize-app-info">
          <h2>
            {{ client.name }}
            <span v-if="isFirstParty" class="authorize-first-party">{{ t('官方应用') }}</span>
          </h2>
          <span v-if="client.developer_name" class="authorize-developer">by {{ client.developer_name }}</span>
          <a v-if="client.homepage_url" :href="client.homepage_url" target="_blank" rel="noopener" class="authorize-url">{{ client.homepage_url }}</a>
        </div>
      </div>
    </div>

    <div class="authorize-body">
      <div class="authorize-arrow">↓</div>
      <div class="authorize-user">
        <img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="authorize-user-avatar">
        <div v-else class="authorize-user-avatar-placeholder">{{ userInitial }}</div>
        <span class="authorize-user-name">{{ user.display_name || user.username }}</span>
      </div>

      <p class="authorize-desc"><strong>{{ client.name }}</strong> {{ t('请求访问您的 Nomen 账户：') }}</p>

      <form
        ref="formEl"
        method="POST"
        :action="urls.authorize"
        class="authorize-actions"
        id="authorizeForm"
        @submit.prevent="onSubmit"
      >
        <input type="hidden" name="_csrf" :value="csrf">
        <input type="hidden" name="client_id" :value="clientId">
        <input type="hidden" name="redirect_uri" :value="redirectUri">
        <input type="hidden" name="scope" :value="quickLogin ? scope : scopeValue">
        <input type="hidden" name="state" :value="state">
        <input type="hidden" name="response_type" :value="responseType">
        <input type="hidden" name="action" value="allow">

        <template v-if="quickLogin">
          <div class="authorize-scopes">
            <ul class="scope-list">
              <li v-for="item in requestedScopeList" :key="item" class="scope-item">
                <span :class="['scope-icon', 'icon', 'icon-' + scopeIcon(item)]"></span>
                <span class="scope-name">{{ scopeLabel(item) }}</span>
                <span class="scope-hint scope-hint--granted">{{ t('已授予') }}</span>
              </li>
            </ul>
          </div>

          <div class="authorize-auto" id="authorizeAuto">
            <svg class="authorize-check" viewBox="0 0 52 52" aria-hidden="true">
              <circle class="authorize-check__circle" cx="26" cy="26" r="23"></circle>
              <path class="authorize-check__mark" d="M14.5 27 L22.5 35.5 L38 19"></path>
            </svg>
            <p class="authorize-auto-text" id="authorizeAutoText">{{ autoText }}</p>
            <p class="authorize-auto-hint">{{ t('不想每次自动授权？可在') }}<a href="/settings">{{ t('设置') }}</a>{{ t('中关闭「Nomen启航计划项目快速中央登录」') }}</p>
          </div>
        </template>

        <template v-else>
          <div class="authorize-scopes">
            <ul class="scope-list">
              <template v-for="item in requestedScopeList" :key="item">
                <li v-if="item === 'openid'" class="scope-item">
                  <span class="scope-icon icon icon-fingerprint"></span>
                  <span class="scope-name">{{ scopeLabel(item) }}</span>
                  <span class="scope-hint scope-hint--required">{{ t('必需') }}</span>
                </li>

                <li v-else-if="item === 'profile'" class="scope-item scope-item--has-checkbox" :class="{ denied: !checked.profile }">
                  <label class="scope-checkbox">
                    <input v-model="checked.profile" type="checkbox" name="scopes[]" value="profile">
                    <span class="checkmark"></span>
                    <span class="scope-icon icon icon-user"></span>
                    <span class="scope-name">{{ t('用户名、昵称、头像') }}</span>
                  </label>
                  <span class="scope-risk scope-risk--low"><span class="icon icon-info"></span> {{ t('低危权限：将授予该应用访问您的用户名、昵称和头像') }}</span>
                </li>

                <li v-else-if="item === 'email'" class="scope-item scope-item--has-checkbox scope-item--medium-risk" :class="{ denied: !checked.email }">
                  <label class="scope-checkbox">
                    <input v-model="checked.email" type="checkbox" name="scopes[]" value="email">
                    <span class="checkmark"></span>
                    <span class="scope-icon icon icon-mail"></span>
                    <span class="scope-name">{{ t('已验证邮箱') }}</span>
                  </label>
                  <span class="scope-risk scope-risk--medium"><span class="icon icon-warning"></span> {{ t('中危权限：将授予该应用访问您已验证的邮箱地址') }}</span>
                </li>

                <li v-else class="scope-item">
                  <span class="scope-icon icon icon-key"></span>
                  <span class="scope-name">{{ scopeLabel(item) }}</span>
                </li>
              </template>
            </ul>
          </div>

          <div class="authorize-buttons">
            <a :href="denyUrl" class="btn btn-secondary">{{ t('拒绝') }}</a>
            <button type="submit" name="action" value="allow" class="btn btn-primary">{{ t('授权') }}</button>
          </div>
        </template>
      </form>
    </div>

    <div class="authorize-footer">
      <p>{{ t('授权即表示您同意将您的 Nomen 信息共享给此应用。您可以随时在') }}<a href="/dashboard">{{ t('仪表盘') }}</a>{{ t('中撤销授权。') }}</p>
    </div>
  </div>
</template>
