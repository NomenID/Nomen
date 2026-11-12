<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminField from '../components/AdminField.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminBadge from '../components/AdminBadge.vue'

const { t } = useT()
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)

const form = reactive({
  mode: 'default',
  page_slug: '',
  template: '',
  title: '',
  subtitle: '',
  primary_label: '',
  primary_url: '',
  secondary_label: '',
  secondary_url: '',
  hidden_links: [],
  links: [],
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/homepage')
    Object.assign(form, data.value.settings)
    form.hidden_links = data.value.default_links.filter((link) => link.hidden).map((link) => link.id)
    form.links = (data.value.custom_links || []).map((link) => ({ label: link.label, href: link.href }))
  } catch (err) {
    error.value = t('Could not load the home page settings')
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const result = await apiPost('/admin/homepage', {
      ...form,
      hidden_links: JSON.stringify(form.hidden_links),
      links_json: JSON.stringify(form.links),
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    saving.value = false
  }
}

function toggleDefaultLink(id, event) {
  const hidden = new Set(form.hidden_links)
  if (event.target.checked) {
    hidden.delete(id)
  } else {
    hidden.add(id)
  }
  form.hidden_links = [...hidden]
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Home page')" :description="t('Change the title, the buttons or the whole template without touching a skin.')">
    <template #actions>
      <a class="a-btn a-btn--ghost" href="/" target="_blank" rel="noopener">{{ t('Open the home page') }}</a>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <form v-else-if="data" @submit.prevent="save">
      <AdminCard :title="t('Layout')">
        <div class="a-radios">
          <label v-for="mode in data.modes" :key="mode.value" class="a-radio" :class="{ 'is-active': form.mode === mode.value }">
            <input v-model="form.mode" type="radio" :value="mode.value">
            <span>
              <strong>{{ mode.label }}</strong>
              <span class="a-sub a-block">{{ mode.hint }}</span>
            </span>
          </label>
        </div>

        <div v-if="form.mode === 'page'" class="a-form" style="margin-top:14px">
          <AdminField v-if="data.pages.length" :label="t('Page')" :hint="t('Only published Pages are listed')">
            <select v-model="form.page_slug" class="a-input a-input--narrow">
              <option value="">{{ t('(none, fall back to the default home page)') }}</option>
              <option v-for="page in data.pages" :key="page.slug" :value="page.slug" :disabled="page.status !== 1">
                {{ page.title }} (/{{ page.slug }}{{ page.status === 1 ? '' : t(' · draft') }})
              </option>
            </select>
          </AdminField>
          <AdminAlert v-else type="info">
            {{ t('No page is available yet. Write one under Content, then come back and pick it.') }}
          </AdminAlert>
        </div>

        <div v-if="form.mode === 'template'" class="a-form" style="margin-top:14px">
          <AdminField v-if="data.templates.length" :label="t('Template file')" :hint="t('Files live in config/ and their names start with home')">
            <select v-model="form.template" class="a-input a-input--narrow">
              <option value="">{{ t('(none, fall back to the default home page)') }}</option>
              <option v-for="name in data.templates" :key="name" :value="name">{{ name }}</option>
            </select>
          </AdminField>
          <AdminAlert v-else type="info">
            {{ t('No custom template yet. Copy {:example} to config/home-yourname.php, then refresh this page to pick it.', { example: 'config/home.example.php' }) }}
          </AdminAlert>
        </div>
      </AdminCard>

      <AdminCard :title="t('Copy and buttons')" :description="t('Leave a field empty to use the default: signed in shows Enter console / Account settings, signed out shows Create account / Sign in.')">
        <div class="a-form">
          <div class="a-form__row">
            <AdminField :label="t('Title')" :hint="t('Defaults to the site name')">
              <input v-model="form.title" class="a-input" type="text" maxlength="120">
            </AdminField>
            <AdminField :label="t('Subtitle')">
              <input v-model="form.subtitle" class="a-input" type="text" maxlength="200">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Primary button label')">
              <input v-model="form.primary_label" class="a-input" type="text" maxlength="40">
            </AdminField>
            <AdminField :label="t('Primary button URL')" :hint="t('Site paths start with /; a full https:// address also works')">
              <input v-model="form.primary_url" class="a-input" type="text" placeholder="/register">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField :label="t('Secondary button label')">
              <input v-model="form.secondary_label" class="a-input" type="text" maxlength="40">
            </AdminField>
            <AdminField :label="t('Secondary button URL')">
              <input v-model="form.secondary_url" class="a-input" type="text" placeholder="/login">
            </AdminField>
          </div>
        </div>
      </AdminCard>

      <AdminCard :title="t('Links below')" :description="t('The small row of links under the buttons. The three defaults can be hidden one by one and you can add your own; links added by plugins are read-only here.')">
        <div class="a-form">
          <AdminField :label="t('Default links')" :hint="t('Uncheck to hide. They come from plugin mounts and the core; to rename one, add a custom link instead')">
            <div class="home-links-defaults">
              <label v-for="link in data.default_links" :key="link.id" class="a-check home-links-defaults__item">
                <input type="checkbox" :checked="!form.hidden_links.includes(link.id)" @change="toggleDefaultLink(link.id, $event)">
                <span>
                  <strong>{{ link.label }}</strong>
                  <span class="a-sub a-block">{{ link.href }} ({{ link.source }})</span>
                </span>
              </label>
            </div>
          </AdminField>

          <AdminField :label="t('Custom links')" :hint="t('Up to 20, shown in the order you add them')">
            <div v-for="(link, index) in form.links" :key="index" class="a-form__row home-links-custom">
              <input v-model="link.label" class="a-input" type="text" maxlength="40" :placeholder="t('Label')">
              <input v-model="link.href" class="a-input" type="text" placeholder="/w or https://…">
              <button class="a-btn a-btn--ghost" type="button" @click="form.links.splice(index, 1)">{{ t('Delete') }}</button>
            </div>
            <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="form.links.push({ label: '', href: '' })">{{ t('Add link') }}</button>
          </AdminField>

          <AdminField :label="t('Plugin links')" :hint="t('Added by plugins at runtime; they cannot be changed here')">
            <p v-if="!data.plugin_links.length" class="a-text a-text--muted">{{ t('No plugin adds a link here right now.') }}</p>
            <div v-else class="home-links-defaults">
              <div v-for="(link, index) in data.plugin_links" :key="index" class="home-links-defaults__item">
                <strong>{{ link.label }}</strong>
                <span class="a-sub a-block a-mono">{{ link.href }}</span>
                <AdminBadge tone="info">{{ link.by ? t('Provided by {:name}', { name: link.by }) : t('Provided by a plugin') }}</AdminBadge>
              </div>
            </div>
          </AdminField>
        </div>
      </AdminCard>

      <div class="a-form__actions">
        <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? t('Saving…') : t('Save home page settings') }}</button>
      </div>
    </form>
  </AdminPage>
</template>
