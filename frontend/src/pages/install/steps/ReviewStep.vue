<script setup>
import { computed } from 'vue'
import StepHead from '../StepHead.vue'
import { go, languageName, pluginName, resetStep, submit, wizard } from '../wizard'

const site = computed(() => wizard.state.site || {})
const db = computed(() => wizard.state.db || {})
const admin = computed(() => wizard.state.admin || {})
const smtp = computed(() => wizard.state.smtp || {})
const features = computed(() => wizard.state.features || {})
const selectedPlugins = computed(() => Array.isArray(wizard.state.plugins) ? wizard.state.plugins : [])

const dbLabel = computed(() => {
  if (db.value.driver === 'sqlite') {
    return 'SQLite · ' + (db.value.path || '')
  }
  return `${db.value.user || ''}@${db.value.host || ''}:${db.value.port || ''}/${db.value.name || ''}`
})

const pluginLabel = computed(() => selectedPlugins.value.length
  ? selectedPlugins.value.map(pluginName).join('、')
  : '不启用')

const featureLabel = computed(() => {
  const names = []
  if (features.value.guardians) names.push('家庭与未成年监护')
  if (features.value.country) names.push('注册时填写国家 / 地区')
  return names.length ? names.join('、') : '都不开启'
})

const logoLabel = computed(() => {
  const logo = site.value.logo || ''
  if (logo === '') return '默认 Nomen 图标'
  return logo.startsWith('/assets/develops/') ? '已上传到站点' : logo
})

async function install() {
  const data = await submit('install', {})
  if (data) {
    go('done')
  }
}
</script>

<template>
  <StepHead icon="review" title="确认一下" lead="最后看一眼。数据库密码不会显示在这里。" />

  <dl class="summary">
    <div><dt>站点</dt><dd>{{ site.name }} · {{ site.base_url }}</dd></div>
    <div><dt>站点图标</dt><dd>{{ logoLabel }}</dd></div>
    <div><dt>数据库</dt><dd>{{ dbLabel }}</dd></div>
    <div><dt>管理员</dt><dd>{{ admin.username }} · {{ admin.email }}</dd></div>
    <div>
      <dt>默认语言</dt>
      <dd>{{ languageName(site.language_default) }}{{ site.language_allow_switch ? '（允许访客切换）' : '（固定）' }}</dd>
    </div>
    <div><dt>扩展</dt><dd>{{ pluginLabel }}</dd></div>
    <div><dt>可选功能</dt><dd>{{ featureLabel }}</dd></div>
    <div><dt>邮件</dt><dd>{{ smtp.host ? smtp.host : '暂不配置' }}</dd></div>
  </dl>

  <div class="actions">
    <button class="btn btn-primary btn-lg" type="button" :class="{ 'is-busy': wizard.busy }"
      :disabled="wizard.busy" @click="install">开始安装</button>
  </div>

  <div class="nav-links">
    <button class="linkish" type="button" :disabled="wizard.busy" @click="go('mail')">← 上一步</button>
    <template v-if="wizard.error">
      <button class="linkish" type="button" :disabled="wizard.busy" @click="go('db')">换个空库</button>
      <button class="linkish" type="button" :disabled="wizard.busy" @click="resetStep">从头再填</button>
    </template>
    <span v-else></span>
  </div>

  <p class="fine">页脚会显示 Powered by Nomen，并链到版本页。这是 GPLv3 第 7 条 (b) 款的署名要求，
    详见 <code>NOTICE</code>。</p>
</template>
