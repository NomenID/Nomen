<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  user: { type: Object, default: () => ({}) },
  authorizations: { type: Array, default: () => [] },
  recentActivity: { type: Array, default: () => [] },
  success: { type: String, default: '' },
  error: { type: String, default: '' },
  revokeHandler: { type: Function, default: null },
  revoking: { type: Boolean, default: false },
  urls: { type: Object, default: () => ({}) },
})

const urls = {
  revokeBase: props.urls?.revokeBase || '/dashboard/revoke/',
}

const userInitial = computed(() => {
  const name = props.user.display_name || props.user.username || ''
  return Array.from(name)[0]?.toUpperCase() || '?'
})

function initialOf(name) {
  return Array.from(name || '')[0]?.toUpperCase() || '?'
}
</script>

<template>
  <div class="dashboard">
    <div class="dashboard-header">
      <div class="dashboard-user">
        <img v-if="user.avatar_url" :src="user.avatar_url" alt="" class="dashboard-avatar">
        <div v-else class="dashboard-avatar-placeholder">{{ userInitial }}</div>
        <div>
          <h1>{{ user.display_name || user.username }}</h1>
          <p class="text-muted">@{{ user.username }}</p>
        </div>
      </div>
      <a href="/settings" class="btn btn-secondary">{{ t('Edit profile') }}</a>
    </div>

    <BaseAlert v-if="success" type="success" :message="success" />
    <BaseAlert v-if="error" type="error" :message="error" />

    <div class="dashboard-section">
      <h2>{{ t('Authorized apps') }}</h2>

      <div v-if="!authorizations.length" class="empty-state">
        <div class="empty-state__icon"><span class="icon icon-lock"></span></div>
        <p>{{ t('You haven\'t authorized any third-party apps yet') }}</p>
        <p class="text-muted">{{ t('Apps you sign in to with {:sitename} will show up here') }}</p>
      </div>

      <div v-else class="app-list">
        <div v-for="auth in authorizations" :key="auth.client_id" class="app-card">
          <div class="app-info">
            <div v-if="auth.icon || auth.developer_icon" class="app-logo-wrap">
              <template v-if="auth.icon">
                <img :src="'/assets/develops/' + auth.icon" alt="" class="app-logo" width="40" height="40">
                <img v-if="auth.developer_icon" :src="'/assets/develops/' + auth.developer_icon" alt="" class="app-logo-dev-icon" width="16" height="16">
              </template>
              <img v-else :src="'/assets/develops/' + auth.developer_icon" alt="" class="app-logo" width="40" height="40">
            </div>
            <div v-else class="app-logo-placeholder">{{ initialOf(auth.client_name) }}</div>

            <div>
              <h3>
                <a v-if="auth.homepage_url" :href="auth.homepage_url" target="_blank" rel="noopener">{{ auth.client_name }}</a>
                <template v-else>{{ auth.client_name }}</template>
              </h3>
              <p class="text-muted">{{ t('Scopes: ') + auth.scopes }}</p>
              <p class="text-muted">{{ t('Authorized: ') + auth.updated_at }}</p>
            </div>
          </div>
          <button
            type="button"
            class="btn btn-danger btn-sm"
            :disabled="revoking"
            @click="revokeHandler ? revokeHandler(auth) : null"
          >{{ t('Revoke authorization') }}</button>
        </div>
      </div>
    </div>

    <div class="dashboard-section">
      <h2>{{ t('Recent activity') }}</h2>

      <div v-if="!recentActivity.length" class="empty-state">
        <p>{{ t('No activity yet') }}</p>
      </div>

      <div v-else class="activity-list">
        <div v-for="(item, index) in recentActivity" :key="index" class="activity-item">
          <div class="activity-icon"><span :class="['icon', 'icon-' + item.icon]"></span></div>
          <div class="activity-info">
            <span class="activity-app">{{ item.label }}</span>
            <span class="activity-scope">{{ item.device_name }}</span>
          </div>
          <span class="activity-time">{{ item.created_at }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
