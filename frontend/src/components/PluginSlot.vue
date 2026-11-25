<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<!--
 * 通用前端插槽。核心只输出一个空的 [data-nomen-slot] 容器，插件脚本在
 * `nomen:slot` 事件里把内容挂进来；卸载时发 `nomen:slot-unmount`，插件负责清理。
 * 事件挂在 document 上，插件脚本先加载 / 后加载都能工作（后加载的扫一遍 DOM 即可）。
-->
<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'

const props = defineProps({
  name: { type: String, required: true },
})

const el = ref(null)

function announce() {
  if (!el.value) {
    return
  }
  document.dispatchEvent(new CustomEvent('nomen:slot', {
    detail: { name: props.name, element: el.value },
  }))
}

onMounted(announce)

onBeforeUnmount(() => {
  if (!el.value) {
    return
  }
  document.dispatchEvent(new CustomEvent('nomen:slot-unmount', {
    detail: { name: props.name, element: el.value },
  }))
})
</script>

<template>
  <div ref="el" :data-nomen-slot="name" class="nomen-slot"></div>
</template>

<style scoped>
.nomen-slot {
  display: contents;
}
</style>
