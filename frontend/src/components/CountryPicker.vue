<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useT } from '../composables/useT.js'
const { t } = useT()

const props = defineProps({
  countries: { type: Array, default: () => [] },
  pickerId: { type: String, default: 'country-picker' },
  inputId: { type: String, default: 'country-search' },
  hiddenId: { type: String, default: 'country' },
  listId: { type: String, default: 'country-list' },
  name: { type: String, default: 'country' },
})

const emit = defineEmits(['picked', 'interacted'])
const model = defineModel({ type: String, default: '' })

function foldText(text) {
  let s = (text || '').toLowerCase()
  if (s.normalize) {
    s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '')
  }
  return s
}

const options = computed(() => props.countries.map((country) => {
  const zh = country.zh || country.code
  return {
    code: country.code,
    name: zh,
    hay: foldText([zh, country.en, country.local, country.code].filter(Boolean).join(' ')),
  }
}))

const query = ref(options.value.find((o) => o.code === model.value)?.name || '')
const open = ref(false)
const active = ref(-1)
const root = ref(null)
const input = ref(null)

const matches = computed(() => {
  const q = foldText(query.value.trim())
  return q === '' ? options.value : options.value.filter((o) => o.hay.includes(q))
})

const visibleMatches = computed(() => matches.value.slice(0, 60))

watch(model, (value) => {
  if (!value) return
  const selected = options.value.find((o) => o.code === value)
  if (selected && query.value !== selected.name) query.value = selected.name
})

function openList() {
  open.value = true
}

function closeList() {
  open.value = false
  active.value = -1
}

function pick(option) {
  model.value = option.code
  query.value = option.name
  closeList()
  emit('interacted')
  emit('picked', option.code)
  nextTick(() => input.value?.focus())
}

function onInput(event) {
  model.value = ''
  query.value = event.target.value
  active.value = -1
  openList()
  emit('interacted')
}

function move(step) {
  if (!open.value) {
    openList()
    return
  }
  const total = visibleMatches.value.length
  if (!total) return
  active.value = (active.value + step + total) % total
  nextTick(() => {
    root.value?.querySelector('[aria-selected="true"]')?.scrollIntoView({ block: 'nearest' })
  })
}

function onKeydown(event) {
  if (event.key === 'ArrowDown') {
    event.preventDefault()
    move(1)
  } else if (event.key === 'ArrowUp') {
    event.preventDefault()
    move(-1)
  } else if (event.key === 'Enter') {
    if (open.value && visibleMatches.value[active.value >= 0 ? active.value : 0]) {
      event.preventDefault()
      pick(visibleMatches.value[active.value >= 0 ? active.value : 0])
    }
  } else if (event.key === 'Escape') {
    closeList()
  }
}

function onDocumentClick(event) {
  if (root.value && !root.value.contains(event.target)) closeList()
}

onMounted(() => document.addEventListener('click', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick))
</script>

<template>
  <div :id="pickerId" ref="root" class="country-picker">
    <input
      :id="inputId"
      ref="input"
      :value="query"
      type="text"
      class="country-picker__input"
      :placeholder="t('Chinese / English / native name / code')"
      autocomplete="off"
      role="combobox"
      :aria-expanded="open"
      :aria-controls="listId"
      aria-autocomplete="list"
      @input="onInput"
      @focus="openList"
      @keydown="onKeydown"
    >
    <input :id="hiddenId" type="hidden" :name="name" :value="model">
    <ul :id="listId" class="country-picker__list" role="listbox" :hidden="!open">
      <template v-if="visibleMatches.length">
        <li
          v-for="(option, index) in visibleMatches"
          :id="'country-opt-' + option.code"
          :key="option.code"
          class="country-picker__option"
          role="option"
          :aria-selected="index === active"
          @mousedown.prevent="pick(option)"
        >
          <span class="country-picker__name">{{ option.name }}</span>
          <span class="country-picker__code">{{ option.code }}</span>
        </li>
      </template>
      <li v-else class="country-picker__empty">{{ t('No such country/region — try the English name or code') }}</li>
    </ul>
  </div>
</template>
