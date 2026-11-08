<script setup>
import { computed, reactive, ref } from 'vue'
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

function scopeIcon(scope) {
  return { openid: 'fingerprint', profile: 'user', email: 'mail' }[scope] || 'key'
}

function scopeLabel(scope) {
  if (scope === 'profile') return t('username, display name, avatar')
  if (scope === 'email') return t('Verified email')
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
    + '<p class="nomen-modal-box__desc">' + t('After authorizing, the app can access the information above.<br>You can revoke it any time from your dashboard.') + '</p>'
}

function showConfirmModal(appName, scopes) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return Promise.resolve(true)
  return window.Nomen.confirm(t('Confirm authorization'), '', {
    icon: 'lock',
    variant: 'confirm',
    confirmText: t('Confirm authorization'),
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

  if (mediumRiskScopes.value.length > 0) {
    showConfirmModal(props.client.name, mediumRiskScopes.value).then((confirmed) => {
      if (confirmed) submitForm()
    })
    return
  }

  submitForm()
}
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
            <span v-if="isFirstParty" class="authorize-first-party">{{ t('Official app') }}</span>
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

      <p class="authorize-desc"><strong>{{ client.name }}</strong> {{ t('wants to access your Nomen account:') }}</p>

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
        <input type="hidden" name="scope" :value="scopeValue">
        <input type="hidden" name="state" :value="state">
        <input type="hidden" name="response_type" :value="responseType">
        <input type="hidden" name="action" value="allow">

        <div class="authorize-scopes">
          <ul class="scope-list">
            <template v-for="item in requestedScopeList" :key="item">
              <li v-if="item === 'openid'" class="scope-item">
                <span class="scope-icon icon icon-fingerprint"></span>
                <span class="scope-name">{{ scopeLabel(item) }}</span>
                <span class="scope-hint scope-hint--required">{{ t('Required') }}</span>
              </li>

              <li v-else-if="item === 'profile'" class="scope-item scope-item--has-checkbox" :class="{ denied: !checked.profile }">
                <label class="scope-checkbox">
                  <input v-model="checked.profile" type="checkbox" name="scopes[]" value="profile">
                  <span class="checkmark"></span>
                  <span class="scope-icon icon icon-user"></span>
                  <span class="scope-name">{{ t('username, display name, avatar') }}</span>
                </label>
                <span class="scope-risk scope-risk--low"><span class="icon icon-info"></span> {{ t('Low risk: lets the app access your username, display name and avatar') }}</span>
              </li>

              <li v-else-if="item === 'email'" class="scope-item scope-item--has-checkbox scope-item--medium-risk" :class="{ denied: !checked.email }">
                <label class="scope-checkbox">
                  <input v-model="checked.email" type="checkbox" name="scopes[]" value="email">
                  <span class="checkmark"></span>
                  <span class="scope-icon icon icon-mail"></span>
                  <span class="scope-name">{{ t('Verified email') }}</span>
                </label>
                <span class="scope-risk scope-risk--medium"><span class="icon icon-warning"></span> {{ t('Medium risk: lets the app access your verified email address') }}</span>
              </li>

              <li v-else class="scope-item">
                <span class="scope-icon icon icon-key"></span>
                <span class="scope-name">{{ scopeLabel(item) }}</span>
              </li>
            </template>
          </ul>
        </div>

        <div class="authorize-buttons">
          <a :href="denyUrl" class="btn btn-secondary">{{ t('Deny') }}</a>
          <button type="submit" name="action" value="allow" class="btn btn-primary">{{ t('Authorize') }}</button>
        </div>
      </form>
    </div>

    <div class="authorize-footer">
      <p>{{ t('By authorizing, you agree to share your Nomen information with this app. You can revoke access at any time from your ') }}<a href="/dashboard">{{ t('Dashboard') }}</a>{{ t(' at any time.') }}</p>
    </div>
  </div>
</template>
