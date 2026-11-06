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
