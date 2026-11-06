<script setup>
import { ref } from 'vue'
import { useT } from '../composables/useT.js'
const { t } = useT()

const props = defineProps({
  url: { type: String, default: '/captcha' },
  name: { type: String, default: 'captcha' },
  inputId: { type: String, default: 'captcha' },
  imgId: { type: String, default: 'captcha-img' },
  maxlength: { type: [String, Number], default: 6 },
  required: { type: Boolean, default: true },
})

const model = defineModel({ type: String, default: '' })
const src = ref(props.url)

function refresh() {
  if (props.url.startsWith('data:')) return
  src.value = props.url + '?t=' + Date.now()
}

function onInput(event) {
  model.value = event.target.value
}
</script>

<template>
  <div class="input-with-button">
    <input
      :id="inputId"
      :name="name"
      type="text"
      :value="model"
      :required="required"
      :maxlength="maxlength"
      :placeholder="t('输入验证码')"
      autocomplete="off"
      @input="onInput"
    >
    <img
      :id="imgId"
      :src="src"
      :alt="t('验证码')"
      class="captcha-img"
      :title="t('点击刷新')"
      @click="refresh"
    >
  </div>
</template>
