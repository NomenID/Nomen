<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { onMounted, ref } from 'vue'
import { useT } from '../composables/useT.js'
const { t } = useT()

const props = defineProps({
  url: { type: String, default: '/captcha' },
  name: { type: String, default: 'captcha' },
  inputId: { type: String, default: 'captcha' },
  imgId: { type: String, default: 'captcha-img' },
  maxlength: { type: [String, Number], default: 3 },
  required: { type: Boolean, default: true },
})

const model = defineModel({ type: String, default: '' })
const question = ref('')

async function refresh() {
  if (props.url.startsWith('data:')) return
  try {
    const sep = props.url.includes('?') ? '&' : '?'
    const res = await fetch(props.url + sep + 't=' + Date.now(), {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    })
    const data = await res.json()
    if (data && data.question) question.value = data.question
  } catch (e) {
    question.value = ''
  }
}

function onInput(event) {
  model.value = event.target.value
}

onMounted(refresh)
</script>

<template>
  <div class="input-with-button">
    <input
      :id="inputId"
      :name="name"
      type="text"
      :value="model"
      :required="required"
      :maxlength="maxlength"
      inputmode="numeric"
      :placeholder="t('Enter verification code')"
      autocomplete="off"
      @input="onInput"
    >
    <button
      :id="imgId"
      type="button"
      class="captcha-question"
      dir="ltr"
      :title="t('Click to refresh')"
      :aria-label="t('Click to refresh')"
      @click="refresh"
    >{{ question || '···' }}</button>
  </div>
</template>