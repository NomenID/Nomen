<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
defineProps({
  text: { type: String, default: '暂无数据' },
})
</script>

<template>
  <div class="a-empty">{{ text }}</div>
</template>
