<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
defineProps({
  type: { type: String, default: 'info' },
  message: { type: String, default: '' },
})
</script>

<template>
  <div class="a-alert" :class="`a-alert--${type}`">
    <slot>{{ message }}</slot>
  </div>
</template>
