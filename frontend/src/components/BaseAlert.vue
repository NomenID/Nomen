<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '../composables/useT.js'
const { t } = useT()
import Icon from './Icon.vue'

const props = defineProps({
  type: { type: String, default: 'info' },
  message: { type: String, default: '' },
  dismissible: { type: Boolean, default: true },
  autoDismiss: { type: Boolean, default: true },
})

const visible = ref(true)
const closing = ref(false)
let timer = null

const iconName = computed(() => ({
  success: 'check',
  error: 'close',
  warning: 'warning',
  info: 'info',
}[props.type] || 'info'))

function close() {
  if (closing.value) return
  closing.value = true
  setTimeout(() => { visible.value = false }, 300)
}

onMounted(() => {
  if (props.type === 'success' && props.autoDismiss) {
    timer = setTimeout(close, 5000)
  }
})

onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})
</script>

<template>
  <div
    v-if="visible"
    :class="['nomen-alert', 'nomen-alert--' + type]"
    :style="closing ? { opacity: 0, transform: 'translateY(-8px) scale(0.97)', transition: 'opacity .3s ease, transform .3s ease' } : null"
  >
    <span class="nomen-alert__icon" aria-hidden="true"><Icon :name="iconName" /></span>
    {{ message }}
    <button v-if="dismissible" type="button" class="nomen-alert__close" :aria-label="t('Close')" @click="close">
      <Icon name="close" />
    </button>
  </div>
</template>
