<script setup>
defineProps({
  name: { type: String, required: true },
  extra: { type: String, default: '' },
})
</script>

<template>
  <span :class="['icon', 'icon-' + name, extra]"></span>
</template>
