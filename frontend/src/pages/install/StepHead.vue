<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import StepIcon from './StepIcon.vue'

const props = defineProps({
  icon: { type: String, default: 'brand' },
  title: { type: String, default: '' },
  lead: { type: String, default: '' },
  tone: { type: String, default: '' },
  logo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})
</script>

<template>
  <div class="head">
    <div class="art" :class="[props.tone ? 'art--' + props.tone : '', props.icon === 'brand' ? 'art--brand' : '']">
      <StepIcon :name="props.icon" :logo="props.logo" />
    </div>
    <h1>{{ props.title }}</h1>
    <p v-if="props.lead" class="lead">{{ props.lead }}</p>
  </div>
</template>
