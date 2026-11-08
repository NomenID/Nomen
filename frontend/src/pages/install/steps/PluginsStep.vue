<script setup>
import { reactive, ref } from 'vue'
import StepHead from '../StepHead.vue'
import { t } from '../i18n'
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
  <StepHead icon="plugins" :title="t('Choose extensions')"
    :lead="t('The defaults are enough to start; you can change them later in the admin area or the config file.')" />

  <form @submit.prevent="save">
    <div class="fields">
      <label v-for="plugin in wizard.plugins" :key="plugin.slug" class="choice">
        <input v-model="selected" type="checkbox" :value="plugin.slug">
        <span>
          <strong>{{ plugin.name }}</strong>
          <small>{{ t(plugin.description) }}</small>
        </span>
      </label>
    </div>

    <h2 class="subhead">{{ t('Optional features') }}</h2>
    <p class="sublabel">{{ t('Off by default; you can turn them on later in the config file.') }}</p>
    <div class="fields">
      <label class="choice">
        <input v-model="features.guardians" type="checkbox">
        <span><strong>{{ t('Family and minor guardianship') }}</strong>
          <small>{{ t('Parental consent, parent email and the family page; minors are determined by the age of consent.') }}</small></span>
      </label>
      <label class="choice">
        <input v-model="features.country" type="checkbox">
        <span><strong>{{ t('Ask for country / region at registration') }}</strong>
          <small>{{ t('Pre-filled from the IP address, with the local age of consent applied.') }}</small></span>
      </label>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        {{ t('Continue') }}
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('admin')">← {{ t('Back') }}</button>
      <span></span>
    </div>
  </form>
</template>
