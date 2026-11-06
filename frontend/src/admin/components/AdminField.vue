<script setup>
defineProps({
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  error: { type: String, default: '' },
})
</script>

<template>
  <label class="a-field">
    <span class="a-field__label">{{ label }}</span>
    <slot />
    <span v-if="error" class="a-field__error">{{ error }}</span>
    <span v-else-if="hint" class="a-field__hint">{{ hint }}</span>
  </label>
</template>
