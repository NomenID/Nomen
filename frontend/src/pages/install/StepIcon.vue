<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
const props = defineProps({
  name: { type: String, default: 'brand' },
  logo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})

const paths = {
  env:
    '<rect x="4" y="4" width="16" height="16" rx="3.5"/><path d="m8 12.2 2.4 2.4 5-5.2"/>',
  db:
    '<ellipse cx="12" cy="5.5" rx="6.5" ry="2.8"/><path d="M5.5 5.5v6c0 1.55 2.91 2.8 6.5 2.8s6.5-1.25 6.5-2.8v-6"/><path d="M5.5 11.5v6c0 1.55 2.91 2.8 6.5 2.8s6.5-1.25 6.5-2.8v-6"/>',
  site:
    '<rect x="3.5" y="4.5" width="17" height="15" rx="2.5"/><path d="M3.5 9h17"/><circle cx="12" cy="14.2" r="3.1"/><path d="M8.9 14.2h6.2M12 11.1c.9 1.1.9 5.1 0 6.2M12 11.1c-.9 1.1-.9 5.1 0 6.2"/>',
  admin:
    '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 19.5c.7-3.4 3.3-5.2 6.5-5.2s5.8 1.8 6.5 5.2"/>',
  plugins:
    '<path d="M9.5 4.8a2.2 2.2 0 0 1 4.4 0v1.4h2.6c.55 0 1 .45 1 1v2.6h1.4a2.2 2.2 0 0 1 0 4.4h-1.4v2.6c0 .55-.45 1-1 1h-2.6v1.4a2.2 2.2 0 0 1-4.4 0v-1.4H6.9c-.55 0-1-.45-1-1v-2.6H4.5a2.2 2.2 0 0 1 0-4.4h1.4V7.2c0-.55.45-1 1-1h2.6V4.8z"/>',
  mail:
    '<rect x="3" y="5.5" width="18" height="13" rx="2.5"/><path d="m4 7.5 7 5.2c.6.45 1.4.45 2 0l7-5.2"/>',
  review:
    '<path d="M4.5 6.5h8M4.5 12h5.5M4.5 17.5h4"/><circle cx="16.5" cy="15.5" r="4.2"/><path d="m14.6 15.5 1.4 1.4 2.6-2.8"/>',
  check: '<path d="m5 12.5 4.2 4.2L19 6.8"/>',
  alert:
    '<path d="M10.2 4.4 3.4 17a2 2 0 0 0 1.8 3h13.6a2 2 0 0 0 1.8-3L13.8 4.4a2 2 0 0 0-3.6 0Z"/><path d="M12 9v4"/><circle cx="12" cy="16.5" r=".9" fill="currentColor" stroke="none"/>',
}
</script>

<template>
  <img v-if="props.name === 'brand'" :src="props.logo" alt="" width="64" height="64">
  <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
    stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" v-html="paths[props.name] || ''"></svg>
</template>
