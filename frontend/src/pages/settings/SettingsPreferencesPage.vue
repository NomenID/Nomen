<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, inject, reactive, ref } from 'vue'
import { apiPost } from '../../app/api'
import { toast } from '../../lib/toast.js'
import { url } from '../../lib/url.js'
import { useT } from '../../composables/useT.js'

const { t } = useT()
const data = inject('settingsData')
const csrf = inject('csrf')

const locales = computed(() => data.value.locales || [])
const currentLocale = computed(() => data.value.currentLocale || '')
const skins = computed(() => data.value.skins || [])
const currentSkin = computed(() => data.value.currentSkin || '')

const notif = reactive({
  system_important: !!data.value.notifSettings?.notify_system_important,
  system_general: !!data.value.notifSettings?.notify_system_general,
  system_interact: !!data.value.notifSettings?.notify_system_interact,
})
const notifSaving = ref(false)

async function saveNotif() {
  if (notifSaving.value) return
  notifSaving.value = true
  try {
    const result = await apiPost('/settings/notifications', {
      notify_system_important: notif.system_important ? '1' : '',
      notify_system_general: notif.system_general ? '1' : '',
      notify_system_interact: notif.system_interact ? '1' : '',
    })
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
  } catch (err) {
    toast(t('Network error'), 'error')
  } finally {
    notifSaving.value = false
  }
}
</script>

<template>
  <div class="settings-section" id="sec-locale" v-if="locales.length">
    <h2>{{ t('Interface language') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Language for the {:sitename} interface and system emails. Signed-out devices follow the browser language') }}</p>
    <form method="POST" :action="url('/settings/locale')">
      <input type="hidden" name="_csrf" :value="csrf">
      <div class="form-group" style="margin-bottom:16px;">
        <label for="locale-select">{{ t('Language') }}</label>
        <select id="locale-select" name="locale">
          <option v-for="entry in locales" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentLocale">
            {{ entry.name }} ({{ entry.english }})
          </option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">{{ t('Save') }}</button>
    </form>
  </div>

  <div class="settings-section" id="sec-skin">
    <h2>{{ t('Appearance') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Choose how the site looks. Devices that are not signed in use the site default skin.') }}</p>
    <form method="POST" :action="url('/settings/skin')">
      <input type="hidden" name="_csrf" :value="csrf">
      <div class="form-group" style="margin-bottom:16px;">
        <label for="skin-select">{{ t('Skins') }}</label>
        <select id="skin-select" name="skin">
          <option v-for="entry in skins" :key="entry.slug" :value="entry.slug" :selected="entry.slug === currentSkin">
            {{ entry.name }}
          </option>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">{{ t('Save') }}</button>
    </form>
  </div>

  <div class="settings-section" id="sec-notifications">
    <h2>{{ t('Email notification preferences') }}</h2>
    <p class="form-hint" style="margin-bottom:16px;">{{ t('Checked categories are also emailed to you. In-site messages (the bell, top right) always arrive regardless of these switches') }}</p>
    <form @submit.prevent="saveNotif">
      <label class="notif-pref-item">
        <input v-model="notif.system_important" type="checkbox" value="1">
        <span class="notif-pref-item__text">
          <strong>{{ t('Important system notice') }}</strong>
          <span class="form-hint">{{ t('Security events that need immediate attention (keep this on)') }}</span>
        </span>
      </label>
      <label class="notif-pref-item">
        <input v-model="notif.system_general" type="checkbox" value="1">
        <span class="notif-pref-item__text">
          <strong>{{ t('General system notice') }}</strong>
          <span class="form-hint">{{ t('General notices: security advice, feature updates') }}</span>
        </span>
      </label>
      <label class="notif-pref-item">
        <input v-model="notif.system_interact" type="checkbox" value="1">
        <span class="notif-pref-item__text">
          <strong>{{ t('System interaction') }}</strong>
          <span class="form-hint">{{ t('Interactions related to your account') }}</span>
        </span>
      </label>
      <button type="submit" class="btn btn-primary" style="margin-top:12px;" :disabled="notifSaving">{{ notifSaving ? t('Saving...') : t('Save') }}</button>
    </form>
  </div>
</template>
