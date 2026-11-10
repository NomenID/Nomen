<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>import { useT } from '../../composables/useT.js'
const { t } = useT()

defineProps({
  current: { type: Number, required: true },
  total: { type: Number, default: 5 },
  from: { type: Number, default: 3 },
})
</script>

<template>
  <div class="register-progress" :hidden="current < from">
    <div
      class="register-progress__bar"
      role="progressbar"
      :aria-label="t('Registration progress')"
      :aria-valuemin="1"
      :aria-valuemax="total"
      :aria-valuenow="current"
    >
      <span class="register-progress__fill" :style="{ width: (current / total * 100) + '%' }"></span>
    </div>
    <span class="register-progress__count">{{ current }} / {{ total }}</span>
  </div>
</template>
