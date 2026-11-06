<script setup>
defineProps({
  title: { type: String, default: '' },
  description: { type: String, default: '' },
})
</script>

<template>
  <section class="a-page">
    <header class="a-page__head">
      <div class="a-page__heading">
        <h1>{{ title }}</h1>
        <p v-if="description">{{ description }}</p>
      </div>
      <div class="a-page__actions"><slot name="actions" /></div>
    </header>
    <slot />
  </section>
</template>
