<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed } from 'vue'
import AdminField from './AdminField.vue'

const props = defineProps({
  availableScopes: { type: Object, default: () => ({}) },
  currentIcon: { type: String, default: '' },
})

const form = defineModel({ type: Object, required: true })

const isPublic = computed(() => form.value.client_type === 'public')

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
    <AdminField label="应用名称" hint="2-128 个字符，会显示在授权页上">
      <input v-model="form.name" class="a-input" type="text" maxlength="128" required>
    </AdminField>

    <AdminField label="简介" hint="一句话说明这个应用是做什么的">
      <textarea v-model="form.description" class="a-input a-textarea" rows="2" maxlength="500"></textarea>
    </AdminField>

    <AdminField label="主页地址" hint="选填，用户可以在授权页点进去">
      <input v-model="form.homepage" class="a-input" type="url" placeholder="https://example.com">
    </AdminField>

    <AdminField label="回调地址" hint="每行一个，必须与 OAuth 请求里的 redirect_uri 逐字一致">
      <textarea v-model="form.redirect_uris" class="a-input a-textarea a-mono" rows="3" placeholder="https://example.com/oauth/callback" required></textarea>
    </AdminField>

    <div class="a-form__row">
      <AdminField label="客户端类型">
        <select v-model="form.client_type" class="a-input">
          <option value="confidential">机密客户端（有 Client Secret）</option>
          <option value="public">公共客户端（无 Secret，强制 PKCE）</option>
        </select>
      </AdminField>

      <AdminField label="图标" :hint="currentIcon ? `当前：${currentIcon}` : '选填，PNG / JPG / WebP'">
        <input class="a-input a-input--file" type="file" accept="image/png,image/jpeg,image/webp" @change="onIconChange">
      </AdminField>
    </div>

    <AdminField label="强制 PKCE" hint="公共客户端始终启用；机密客户端可选">
      <label class="a-check">
        <input type="checkbox" :checked="isPublic || form.pkce_required" :disabled="isPublic" @change="form.pkce_required = $event.target.checked">
        <span>要求 code_challenge（S256）</span>
      </label>
    </AdminField>

    <AdminField label="授权范围">
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
