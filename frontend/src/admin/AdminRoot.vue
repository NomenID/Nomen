<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { provide, reactive } from 'vue'
import { RouterView } from 'vue-router'
import { setCsrf } from '../app/api'
import AdminLayout from './AdminLayout.vue'

const props = defineProps({
  viewer: { type: Object, required: true },
  brand: { type: Object, default: () => ({}) },
  csrf: { type: String, required: true },
  menu: { type: Array, default: () => [] },
  pages: { type: Array, default: () => [] },
})

setCsrf(props.csrf)
provide('adminViewer', reactive({ ...props.viewer }))
provide('adminBrand', props.brand || {})
</script>

<template>
  <AdminLayout :viewer="viewer" :brand="brand" :menu="menu">
    <RouterView />
  </AdminLayout>
</template>
