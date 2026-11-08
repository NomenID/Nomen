<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
defineProps({
  tone: { type: String, default: '' },
})
</script>

<template>
  <span class="a-badge" :class="tone ? `a-badge--${tone}` : ''"><slot /></span>
</template>
