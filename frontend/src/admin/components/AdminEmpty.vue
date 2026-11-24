<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { useT } from '../../composables/useT.js'
const { t } = useT()

defineProps({
  // 调用方传翻好的成品文案（t 的结果）；不传就用默认那句
  text: { type: String, default: '' },
})
</script>

<template>
  <div class="a-empty">{{ text || t('No data yet') }}</div>
</template>
