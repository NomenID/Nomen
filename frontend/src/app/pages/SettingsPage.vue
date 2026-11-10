<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { inject } from 'vue'
import SettingsApp from '../../pages/settings/SettingsApp.vue'
import { usePageData } from '../usePageData.js'

const { data } = usePageData('settings')
const csrf = inject('csrf')
</script>

<template>
  <div v-if="data" class="container">
    <SettingsApp :csrf="csrf" v-bind="data" />
  </div>
</template>
