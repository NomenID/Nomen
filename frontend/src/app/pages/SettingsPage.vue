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
