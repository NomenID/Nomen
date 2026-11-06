<script setup>
defineProps({
  text: { type: String, default: '暂无数据' },
})
</script>

<template>
  <div class="a-empty">{{ text }}</div>
</template>
