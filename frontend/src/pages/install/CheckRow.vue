<script setup>
const props = defineProps({
  label: { type: String, default: '' },
  hint: { type: String, default: '' },
  state: { type: String, default: 'off' },
  stateText: { type: String, default: '' },
})

const marks = {
  pass: '<path d="m3.5 8.5 3 3 6-6.5"/>',
  fail: '<path d="M4.5 4.5l7 7M11.5 4.5l-7 7"/>',
  off: '<path d="M4 8h8"/>',
  pending: '<path d="M4 8h8"/>',
}
</script>

<template>
  <li class="check" :class="['is-' + props.state]">
    <span class="check__icon" aria-hidden="true">
      <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
        stroke-linejoin="round" v-html="marks[props.state] || marks.off"></svg>
    </span>
    <span class="check__body">
      <span class="check__label">{{ props.label }}</span>
      <span v-if="props.hint" class="check__hint">{{ props.hint }}</span>
    </span>
    <span class="check__state">{{ props.stateText }}</span>
  </li>
</template>
