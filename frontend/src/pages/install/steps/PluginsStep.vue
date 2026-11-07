<script setup>
import { reactive, ref } from 'vue'
import StepHead from '../StepHead.vue'
import { go, submit, wizard } from '../wizard'

const defaults = wizard.plugins
  .filter((plugin) => plugin.slug === 'open-platform' || plugin.slug === 'pages')
  .map((plugin) => plugin.slug)

const savedPlugins = wizard.state.plugins
const selected = ref(Array.isArray(savedPlugins) ? [...savedPlugins] : defaults)

const savedFeatures = wizard.state.features || {}
const features = reactive({
  guardians: savedFeatures.guardians === true,
  country: savedFeatures.country === true,
})

async function save() {
  await submit('plugins', {
    plugins: selected.value,
    features: { ...features },
  })
}
</script>

<template>
  <StepHead icon="plugins" title="选择扩展" lead="默认两项够用，装完之后在后台或配置文件里还能改。" />

  <form @submit.prevent="save">
    <div class="fields">
      <label v-for="plugin in wizard.plugins" :key="plugin.slug" class="choice">
        <input v-model="selected" type="checkbox" :value="plugin.slug">
        <span>
          <strong>{{ plugin.name }}</strong>
          <small>{{ plugin.description }}</small>
        </span>
      </label>
    </div>

    <h2 class="subhead">可选功能</h2>
    <p class="sublabel">默认关闭，以后也能在配置文件里打开。</p>
    <div class="fields">
      <label class="choice">
        <input v-model="features.guardians" type="checkbox">
        <span><strong>家庭与未成年监护</strong>
          <small>家长同意、家长邮箱、家庭页；按同意年龄判断未成年账号。</small></span>
      </label>
      <label class="choice">
        <input v-model="features.country" type="checkbox">
        <span><strong>注册时填写国家 / 地区</strong>
          <small>按 IP 预填，并按当地同意年龄判断。</small></span>
      </label>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        继续
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('admin')">← 上一步</button>
      <span></span>
    </div>
  </form>
</template>
