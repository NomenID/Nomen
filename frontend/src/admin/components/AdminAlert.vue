<script setup>
defineProps({
  type: { type: String, default: 'info' },
  message: { type: String, default: '' },
})
</script>

<template>
  <div class="a-alert" :class="`a-alert--${type}`">
    <slot>{{ message }}</slot>
  </div>
</template>
