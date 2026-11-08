<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
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
    <span class="a-pagination__info">第 {{ page }} / {{ pages }} 页 · 共 {{ total }} 条</span>
    <div class="a-pagination__buttons">
      <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="page <= 1" @click="go(page - 1)">上一页</button>
      <button class="a-btn a-btn--ghost a-btn--sm" type="button" :disabled="page >= pages" @click="go(page + 1)">下一页</button>
    </div>
  </div>
</template>
