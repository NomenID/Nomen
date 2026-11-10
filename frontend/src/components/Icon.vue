<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
defineProps({
  name: { type: String, required: true },
  extra: { type: String, default: '' },
})
</script>

<template>
  <span :class="['icon', 'icon-' + name, extra]"></span>
</template>
