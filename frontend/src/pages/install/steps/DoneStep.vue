<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed } from 'vue'
import StepHead from '../StepHead.vue'
import CheckRow from '../CheckRow.vue'
import { t } from '../i18n'
import { wizard } from '../wizard'

const done = computed(() => wizard.done || {})
const siteUrl = computed(() => (done.value.base_url || '').replace(/\/+$/, ''))
const loginUrl = computed(() => siteUrl.value + '/login?username=' + encodeURIComponent(done.value.username || ''))

function downloadConfig() {
  const blob = new Blob([done.value.content || ''], { type: 'text/plain' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'installed.php'
  link.click()
  URL.revokeObjectURL(link.href)
}
</script>

<template>
  <StepHead icon="check" tone="done" :title="t('Installed')"
    :lead="t('{:name} is up and running.', { name: done.site_name || 'Nomen' })" />

  <div v-if="done.written" class="alert alert--ok" role="status">
    {{ t('The database, admin account and config file are all in place.') }}
  </div>
  <div v-else class="alert alert--warn" role="alert">
    {{ t('The database and admin account are ready, but PHP could not write the config file config/installed.php. Save the content below as that file inside config/ for the site to start.') }}
  </div>

  <div class="actions">
    <template v-if="done.written">
      <a class="btn btn-primary btn-lg" :href="siteUrl">{{ t('Open the site') }}</a>
      <a class="btn btn-ghost" :href="loginUrl">{{ t('Sign in as the admin') }}</a>
    </template>
    <template v-else>
      <button class="btn btn-primary" type="button" @click="downloadConfig">{{ t('Download installed.php') }}</button>
      <textarea class="code" :value="done.content" readonly></textarea>
      <p class="fine">{{ t('This content contains the database password and secrets — do not paste it into chats or tickets. Once the file is in place, open the site home page again.') }}</p>
    </template>
  </div>

  <div style="margin-top:22px">
    <ul class="checks">
      <CheckRow :label="done.username || ''" :hint="done.email || ''" state="pass" :state-text="t('Created')" />
      <CheckRow :label="t('Config file')" :hint="done.written ? t('Written to config/installed.php') : t('PHP cannot write to config/ — place the file manually')"
        :state="done.written ? 'pass' : 'fail'" :state-text="done.written ? t('Done') : t('Needs attention')" />
      <CheckRow :label="t('Install directory')" :hint="t('public/nomen-install/ is still there — delete it once the site is verified')"
        state="off" :state-text="t('Should be deleted')" />
    </ul>
  </div>

  <div v-if="done.plugins_note" class="alert alert--warn" style="margin-top:18px">
    {{ done.plugins_note }}
  </div>

  <p class="fine">{{ t('After the first sign-in you can create OAuth apps and change site settings in the admin area. Without SMTP, sign-up codes and password recovery only work after email is configured.') }}
    <template v-if="done.written">{{ t('If the PHP process and the website run as different users, make sure the site can read config/installed.php.') }}</template>
  </p>
</template>
