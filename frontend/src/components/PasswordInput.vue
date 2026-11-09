<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed } from 'vue'
import { useT } from '../composables/useT.js'
const { t } = useT()

defineProps({
  id: { type: String, required: true },
  name: { type: String, required: true },
  placeholder: { type: String, default: '' },
  required: { type: Boolean, default: false },
  minlength: { type: [String, Number], default: undefined },
  autocomplete: { type: String, default: undefined },
  showToggle: { type: Boolean, default: true },
})

const model = defineModel({ type: String, default: '' })
const revealed = defineModel('revealed', { type: Boolean, default: false })

const inputType = computed(() => (revealed.value ? 'text' : 'password'))

function onInput(event) {
  model.value = event.target.value
}
</script>

<template>
  <div class="pw-wrap pw-wrap--icon">
    <input
      :id="id"
      :name="name"
      :type="inputType"
      :value="model"
      :placeholder="placeholder"
      :required="required"
      :minlength="minlength"
      :autocomplete="autocomplete"
      @input="onInput"
    >
    <slot name="status" />
    <button
      v-if="showToggle"
      type="button"
      class="toggle-password"
      :aria-label="revealed ? t('Hide password') : t('Show password')"
      @click="revealed = !revealed"
    >
      <span :class="['eye-icon', 'icon', revealed ? 'icon-eye-off' : 'icon-eye']"></span>
    </button>
  </div>
</template>
