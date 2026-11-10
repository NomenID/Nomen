<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
defineProps({
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
})
</script>

<template>
  <label class="a-field">
    <span class="a-field__label">{{ label }}</span>
    <slot />
    <span v-if="error" class="a-field__error">{{ error }}</span>
    <span v-else-if="hint" class="a-field__hint">{{ hint }}</span>
  </label>
</template>
