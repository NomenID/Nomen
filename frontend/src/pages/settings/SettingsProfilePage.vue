<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, inject, reactive, ref } from 'vue'
import { apiPost, apiPostForm } from '../../app/api'
import { toast } from '../../lib/toast.js'
import { url } from '../../lib/url.js'
import { isSvgFile, svgToPngFile, SVG_PNG_SIZE } from '../../lib/svg.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()
const data = inject('settingsData')

const user = computed(() => data.value.user)
const features = computed(() => data.value.features || {})
const userInitial = computed(() => Array.from(user.value.displayName || user.value.username || '')[0]?.toUpperCase() || '?')

const profile = reactive({
  display_name: user.value.displayName || '',
  bio: user.value.bio || '',
})

// ---- 头像 ----
const avatarUrl = ref(user.value.avatarUrl || '')
const avatarFile = ref(null)
const avatarFileName = ref('')
const avatarUploading = ref(false)
const avatarInput = ref(null)

async function onAvatarChange(event) {
  const file = event.target.files && event.target.files[0]
  if (!file) return
  if (!isSvgFile(file)) {
    useAvatarFile(file)
    return
  }
  try {
    const png = await svgToPngFile(file, SVG_PNG_SIZE)
    useAvatarFile(png)
    toast(t('SVG converted to a {:n}×{:n} PNG', { n: SVG_PNG_SIZE }), 'success')
  } catch (err) {
    if (avatarInput.value) avatarInput.value.value = ''
    toast(t('Can\'t convert this SVG — try a PNG or JPG'), 'error')
  }
}

function useAvatarFile(file) {
  avatarFile.value = file
  avatarFileName.value = file.name
}

async function uploadAvatar() {
  if (!avatarFile.value || avatarUploading.value) return
  avatarUploading.value = true

  const form = new FormData()
  form.append('avatar', avatarFile.value)

  try {
    const result = await apiPostForm('/settings/avatar', form)
    if (result.success) {
      avatarUrl.value = result.avatar_url
      toast(result.message, 'success')
      avatarFile.value = null
      avatarFileName.value = ''
      if (avatarInput.value) avatarInput.value.value = ''
    } else {
      toast(result.message, 'error')
    }
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    avatarUploading.value = false
  }
}

// ---- 资料表单 ----
const profileSaving = ref(false)

async function saveProfile() {
  if (profileSaving.value) return
  profileSaving.value = true
  try {
    const result = await apiPost('/settings/profile', {
      display_name: profile.display_name,
      bio: profile.bio,
    })
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
  } finally {
    profileSaving.value = false
  }
}
</script>

<template>
  <div class="settings-section" id="sec-avatar">
    <h2>{{ t('Avatar') }}</h2>
    <div style="display:flex;align-items:center;gap:24px;flex-wrap:wrap;">
      <div
        style="width:80px;height:80px;border-radius:50%;overflow:hidden;flex-shrink:0;background:#e9ecef;display:flex;align-items:center;justify-content:center;font-size:32px;font-weight:600;color:#6c757d;"
      >
        <img v-if="avatarUrl" :src="avatarUrl" :alt="t('Avatar')" style="width:100%;height:100%;object-fit:cover;">
        <template v-else>{{ userInitial }}</template>
      </div>
      <div style="flex:1;min-width:240px;">
        <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <input ref="avatarInput" type="file" accept="image/jpeg,image/png,image/gif,image/webp,image/svg+xml" style="display:none;" @change="onAvatarChange">
          <button type="button" class="btn btn-secondary" @click="avatarInput?.click()">{{ t('Choose images') }}</button>
          <button v-show="avatarFile" type="button" class="btn btn-primary" :disabled="avatarUploading" @click="uploadAvatar">{{ avatarUploading ? t('Uploading...') : t('Upload avatar') }}</button>
          <span class="text-muted" style="font-size:13px;">{{ avatarFileName }}</span>
        </div>
        <small class="form-hint" style="margin-top:8px;">{{ t('JPG, PNG, GIF, WebP or SVG, up to 2MB; SVG is auto-converted to a 135×135 PNG. Avatars are cropped to a 135×135 square') }}</small>
      </div>
    </div>
  </div>

  <div class="settings-section" id="sec-profile">
    <h2>{{ t('Profile') }}</h2>
    <form class="settings-form" @submit.prevent="saveProfile">
      <div class="form-group">
        <label>{{ t('Username') }}</label>
        <input type="text" :value="user.username" disabled class="input-disabled">
        <small class="form-hint">{{ t('Username can\'t be changed') }}</small>
      </div>
      <div class="form-group">
        <label>{{ t('Primary email') }}</label>
        <template v-if="user.email">
          <input type="text" :value="user.email" disabled class="input-disabled">
          <small class="form-hint">{{ t('Used for notifications and account recovery. Change it in Email addresses.') }}</small>
        </template>
        <template v-else>
          <input type="text" :value="features.guardians ? t('Not set (mail goes to a parent\'s address)') : t('Not set')" disabled class="input-disabled">
          <small v-if="features.guardians" class="form-hint">{{ t('You can bind your own address in Email addresses.') }}</small>
        </template>
      </div>
      <div class="form-group">
        <label>{{ t('Date of birth') }}</label>
        <input type="text" :value="user.birthDateDisplay" disabled class="input-disabled">
      </div>
      <div class="form-group">
        <label>{{ t('Gender') }}</label>
        <input type="text" :value="user.genderDisplay" disabled class="input-disabled">
      </div>
      <div v-if="features.country" class="form-group">
        <label>{{ t('Country / Region') }}</label>
        <input type="text" :value="user.countryDisplay" disabled class="input-disabled">
      </div>
      <div class="form-group">
        <label for="display_name">{{ t('Display name') }}</label>
        <input id="display_name" v-model="profile.display_name" type="text" :placeholder="t('Preferred display name')">
      </div>
      <div class="form-group">
        <label for="bio">{{ t('Bio') }}</label>
        <textarea id="bio" v-model="profile.bio" rows="3" :placeholder="t('Introduce yourself')"></textarea>
      </div>
      <button type="submit" class="btn btn-primary" :disabled="profileSaving">{{ profileSaving ? t('Saving...') : t('Save changes') }}</button>
    </form>
  </div>

  <div class="settings-section settings-section--danger" id="sec-account">
    <h2>{{ t('Account info') }}</h2>
    <div class="info-grid">
      <div class="info-item">
        <span class="info-label">{{ t('Registered at') }}</span>
        <span class="info-value">{{ user.createdAtDisplay }}</span>
      </div>
      <div class="info-item">
        <span class="info-label">{{ t('Contact address') }}</span>
        <span class="info-value">
          <span v-if="user.contactVerified" class="badge badge--success">{{ t('Verified') }}</span>
          <span v-else class="badge badge--warning">{{ t('Unverified') }}</span>
        </span>
      </div>
    </div>
  </div>

  <div v-if="features.guardians" class="settings-section" id="sec-family">
    <h2>{{ t('Family') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">
      {{ t('Once a parent joins your family, they can reset your password or temporarily lock the account. You can also invite a parent here, or (once you reach the age for your region) end supervision and leave the family.') }}
    </p>
    <a :href="url('/family')" class="btn btn-secondary">{{ t('Open Family') }}</a>
  </div>
</template>
