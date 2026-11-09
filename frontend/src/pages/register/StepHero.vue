<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import Icon from '../../components/Icon.vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()

defineProps({
  icon: { type: String, required: true },
  title: { type: String, required: true },
  desc: { type: String, default: '' },
})
</script>

<template>
  <div class="step-hero">
    <div class="step-icon-wrapper">
      <span class="step-icon"><Icon :name="icon" /></span>
    </div>
    <h2 class="step-title">{{ title }}</h2>
    <p class="step-desc">{{ desc }}</p>
  </div>
</template>
