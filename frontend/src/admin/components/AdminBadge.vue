<script setup>
defineProps({
  tone: { type: String, default: '' },
})
</script>

<template>
  <span class="a-badge" :class="tone ? `a-badge--${tone}` : ''"><slot /></span>
</template>
