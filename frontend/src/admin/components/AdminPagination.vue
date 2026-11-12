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

const props = defineProps({
  page: { type: Number, default: 1 },
  pages: { type: Number, default: 1 },
  total: { type: Number, default: 0 },
})
const emit = defineEmits(['change'])

function go(page) {
  if (page < 1 || page > props.pages || page === props.page) return
  emit('change', page)
}
</script>

<template>
  <div v-if="pages > 1 || total > 0" class="a-pagination">
    <span class="a-pagination__info">{{ t('Page {:page} of {:pages} · {:total} in total', { page, pages, total }) }}</span>
    <div class="a-pagination__buttons">
      <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="page <= 1" @click="go(page - 1)">{{ t('Previous') }}</button>
      <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="page >= pages" @click="go(page + 1)">{{ t('Next') }}</button>
    </div>
  </div>
</template>
