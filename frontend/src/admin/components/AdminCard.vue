<script setup>
defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
  padded: { type: Boolean, default: true },
})
</script>

<template>
  <section class="a-card">
    <header v-if="title || $slots.actions" class="a-card__head">
      <h2 v-if="title">{{ title }}</h2>
      <div class="a-card__actions"><slot name="actions" /></div>
    </header>
    <div class="a-card__body" :class="{ 'a-card__body--flush': !padded }">
      <p v-if="description" class="a-card__desc">{{ description }}</p>
      <slot />
    </div>
  </section>
</template>
