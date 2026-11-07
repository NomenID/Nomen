<script setup>import { useT } from '../../composables/useT.js'
const { t } = useT()

defineProps({
  current: { type: Number, required: true },
  total: { type: Number, default: 5 },
  from: { type: Number, default: 3 },
})
</script>

<template>
  <div class="register-progress" :hidden="current < from">
    <div
      class="register-progress__bar"
      role="progressbar"
      :aria-label="t('Registration progress')"
      :aria-valuemin="1"
      :aria-valuemax="total"
      :aria-valuenow="current"
    >
      <span class="register-progress__fill" :style="{ width: (current / total * 100) + '%' }"></span>
    </div>
    <span class="register-progress__count">{{ current }} / {{ total }}</span>
  </div>
</template>
