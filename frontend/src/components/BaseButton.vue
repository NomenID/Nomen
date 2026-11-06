<script setup>
defineProps({
  variant: { type: String, default: 'primary' },
  type: { type: String, default: 'button' },
  block: { type: Boolean, default: false },
  size: { type: String, default: '' },
})
</script>

<template>
  <button
    :type="type"
    :class="['btn', 'btn-' + variant, { 'btn-block': block, 'btn-sm': size === 'sm' }]"
  >
    <slot />
  </button>
</template>
