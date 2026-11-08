<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
defineProps({
  variant: { type: String, default: 'primary' },
  type: { type: String, default: 'button' },
  block: { type: Boolean, default: false },
  size: { type: String, default: '' },
})
</script>

<template>
  <button
    :type="type"
    :class="['btn', 'btn-' + variant, { 'btn-block': block, 'btn-sm': size === 'sm' }]"
  >
    <slot />
  </button>
</template>
