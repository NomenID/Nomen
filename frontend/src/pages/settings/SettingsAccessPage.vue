<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, inject, ref } from 'vue'
import { apiPost } from '../../app/api'
import { toast } from '../../lib/toast.js'
import { url } from '../../lib/url.js'
import { useT } from '../../composables/useT.js'
import TempKeysSection from './TempKeysSection.vue'

const { t } = useT()
const data = inject('settingsData')
const csrf = inject('csrf')

const activeTokens = computed(() => data.value.activeTokens || [])
const devices = ref((data.value.trustedDevices || []).map((device) => ({ ...device })))
const linkedIdentities = ref((data.value.identities || []).map((item) => ({ ...item })))
const unlinkedProviders = computed(() =>
  (data.value.identityProviders || []).filter((provider) => !linkedIdentities.value.some((item) => item.provider_slug === provider.slug)),
)

async function unlinkIdentity(item) {
  const ok = await window.Nomen.confirm(
    t('Unlink sign-in method'),
    t('After unlinking, you can no longer sign in with {:name}. Continue?', { name: item.provider_name }),
    { confirmText: t('Unlink'), cancelText: t('Cancel') },
  )
  if (!ok) return
  try {
    const result = await apiPost('/settings/identities/unlink', { provider_id: item.provider_id })
    if (result.success) {
      linkedIdentities.value = linkedIdentities.value.filter((row) => row.provider_id !== item.provider_id)
      toast(result.message, 'success')
    } else {
      toast(result.message || t('Unlink failed'), 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  }
}

async function revokeDevice(device) {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('Revoke device trust'), t('Revoke trust for device "{:name}"? It will need to verify again on its next sign-in.', { name: device.deviceName }), {
    danger: true,
    confirmText: t('Revoke'),
    cancelText: t('Cancel'),
  })
  if (!confirmed) return

  try {
    const result = await apiPost('/settings/devices/revoke', { device_id: device.deviceId })
    if (result.success) {
      toast(result.message, 'success')
      devices.value = devices.value.filter((item) => item.deviceId !== device.deviceId)
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  }
}
</script>

<template>
  <div class="settings-section" id="sec-identities">
    <h2>{{ t('Third-party sign-in') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">
      {{ t('Linked accounts can sign in directly. Make sure you have another sign-in method before unlinking.') }}
    </p>

    <div v-if="linkedIdentities.length" class="identity-list">
      <div v-for="item in linkedIdentities" :key="item.provider_id" class="identity-row">
        <div class="identity-row__info">
          <strong>{{ item.provider_name }}</strong>
          <span class="text-muted">{{ item.display_name || item.email || '' }}</span>
        </div>
        <button type="button" class="btn btn-danger btn-sm" @click="unlinkIdentity(item)">{{ t('Unlink') }}</button>
      </div>
    </div>
    <p v-else class="form-hint">{{ t('No third-party accounts linked yet.') }}</p>

    <div v-if="unlinkedProviders.length" class="identity-links">
      <a
        v-for="provider in unlinkedProviders"
        :key="provider.slug"
        class="btn btn-secondary btn-sm"
        :href="url(`/auth/${provider.slug}/redirect?link=1`)"
      >{{ t('Link {:name}', { name: provider.name }) }}</a>
    </div>
  </div>

  <div class="settings-section" id="sec-active-devices">
    <h2>{{ t('Active devices') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Devices active in the last 30 days — view only; revoke under All devices below') }}</p>
    <p v-if="!activeTokens.length" class="text-muted" style="padding:12px 0;">{{ t('No active devices') }}</p>
    <div v-else class="token-list">
      <div v-for="token in activeTokens" :key="token.deviceId" class="token-item">
        <div class="token-item__info">
          <div class="token-item__name">
            {{ token.deviceName }}
            <span v-if="token.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('Current device') }}</span>
          </div>
          <div class="token-item__meta">{{ token.meta }}</div>
        </div>
        <span class="badge badge--success">{{ t('Active') }}</span>
      </div>
    </div>
  </div>

  <div class="settings-section" id="sec-temp-keys">
    <h2>{{ t('Temp key') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Temp keys let you sign in on untrusted devices (like a school computer) and skip device verification. Valid up to 14 days, 5 at a time.') }}</p>
    <TempKeysSection :csrf="csrf" :urls="data.urls?.tempKeys || {}" />
  </div>

  <div class="settings-section" id="sec-devices">
    <h2>{{ t('All devices') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Trusted devices skip extra verification. After revoking, the device must verify again on next sign-in') }}</p>
    <p v-if="!devices.length" class="text-muted" style="padding:12px 0;">{{ t('No trusted devices') }}</p>
    <div v-else class="device-list" id="device-list">
      <div v-for="device in devices" :key="device.deviceId" class="device-item">
        <div class="device-item__info">
          <div class="device-item__name">
            {{ device.deviceName }}
            <span v-if="device.isCurrent" class="badge badge--info" style="margin-left:8px;font-size:11px;">{{ t('Current device') }}</span>
          </div>
          <div class="device-item__meta">{{ device.meta }}</div>
        </div>
        <button v-if="!device.isCurrent" type="button" class="btn btn-sm btn-danger-outline" @click="revokeDevice(device)">{{ t('Revoke') }}</button>
      </div>
    </div>
  </div>
</template>
