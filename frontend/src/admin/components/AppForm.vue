<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed } from 'vue'
import AdminField from './AdminField.vue'
import { useT } from '../../composables/useT.js'

const { t } = useT()

const props = defineProps({
  availableScopes: { type: Object, default: () => ({}) },
  currentIcon: { type: String, default: '' },
})

const form = defineModel({ type: Object, required: true })

const isPublic = computed(() => form.value.client_type === 'public')
const iconHint = computed(() =>
  props.currentIcon
    ? t('Current: {:value}', { value: props.currentIcon })
    : t('Optional, PNG / JPG / WebP'),
)

function toggleScope(scope, checked) {
  const list = new Set(form.value.scopes || [])
  if (checked) list.add(scope)
  else list.delete(scope)
  if (scope === 'openid') list.add('openid')
  form.value.scopes = [...list]
}

function onIconChange(event) {
  form.value.iconFile = event.target.files?.[0] || null
}
</script>

<template>
  <div class="a-form">
    <AdminField :label="t('Application name')" :hint="t('2-128 characters, shown on the consent page')">
      <input v-model="form.name" class="a-input" type="text" maxlength="128" required>
    </AdminField>

    <AdminField :label="t('Description')" :hint="t('One line about what this app does')">
      <textarea v-model="form.description" class="a-input a-textarea" rows="2" maxlength="500"></textarea>
    </AdminField>

    <AdminField :label="t('Homepage')" :hint="t('Optional, users can open it from the consent page')">
      <input v-model="form.homepage" class="a-input" type="url" placeholder="https://example.com">
    </AdminField>

    <AdminField :label="t('Redirect URIs')" :hint="t('One per line, must match the redirect_uri in the OAuth request exactly')">
      <textarea v-model="form.redirect_uris" class="a-input a-textarea a-mono" rows="3" placeholder="https://example.com/oauth/callback" required></textarea>
    </AdminField>

    <div class="a-form__row">
      <AdminField :label="t('Client type')">
        <select v-model="form.client_type" class="a-input">
          <option value="confidential">{{ t('Confidential client (has a client secret)') }}</option>
          <option value="public">{{ t('Public client (no secret, PKCE required)') }}</option>
        </select>
      </AdminField>

      <AdminField :label="t('Icon')" :hint="iconHint">
        <input class="a-input a-input--file" type="file" accept="image/png,image/jpeg,image/webp" @change="onIconChange">
      </AdminField>
    </div>

    <AdminField :label="t('Require PKCE')" :hint="t('Always on for public clients; optional for confidential ones')">
      <label class="a-check">
        <input type="checkbox" :checked="isPublic || form.pkce_required" :disabled="isPublic" @change="form.pkce_required = $event.target.checked">
        <span>{{ t('Require code_challenge (S256)') }}</span>
      </label>
    </AdminField>

    <AdminField :label="t('Scopes')">
      <div class="a-checkgrid">
        <label v-for="(desc, scope) in availableScopes" :key="scope" class="a-check">
          <input
            type="checkbox"
            :checked="scope === 'openid' || form.scopes.includes(scope)"
            :disabled="scope === 'openid'"
            @change="toggleScope(scope, $event.target.checked)"
          >
          <span><code>{{ scope }}</code> {{ desc }}</span>
        </label>
      </div>
    </AdminField>
  </div>
</template>
