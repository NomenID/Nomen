<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import FamilyApp from '../../pages/family/FamilyApp.vue'
import { usePageData } from '../usePageData.js'

const { data } = usePageData('family')
</script>

<template>
  <div v-if="data" class="container">
    <FamilyApp v-bind="data" />
  </div>
</template>
