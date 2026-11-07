<script setup>
import { computed, onMounted, ref } from 'vue'
import StepHead from '../StepHead.vue'
import CheckRow from '../CheckRow.vue'
import { go, refreshEnv, wizard } from '../wizard'

const probing = ref(true)
const probe = ref('pending')

const required = computed(() => wizard.env?.required || [])
const optional = computed(() => wizard.env?.optional || [])
const envOk = computed(() => required.value.every((row) => row.pass))
const canContinue = computed(() => envOk.value && probe.value === 'ok')

const probeState = computed(() => {
  if (probing.value) return 'pending'
  return probe.value === 'ok' ? 'pass' : (probe.value === 'fail' ? 'fail' : 'off')
})
const probeText = computed(() => {
  if (probing.value) return '检测中'
  return probe.value === 'ok' ? '通过' : (probe.value === 'fail' ? '需要处理' : '未知')
})
const probeHint = computed(() => {
  if (probing.value) return '正在检测 /__rewrite_probe…'
  if (probe.value === 'ok') return '/__rewrite_probe 正常'
  if (probe.value === 'fail') return '除首页外的地址会 404，按下面配好再继续'
  return '检测失败，点「重新检查」再试'
})

const snippet = `nginx:

location / {
    try_files $uri $uri/ /index.php$is_args$args;
}

apache（public/.htaccess）:

RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^ index.php [L]`

async function runProbe() {
  probing.value = true
  try {
    const response = await fetch('/__rewrite_probe', {
      cache: 'no-store',
      credentials: 'same-origin',
    })
    const text = response.ok ? (await response.text()).trim() : ''
    probe.value = text === 'ok' ? 'ok' : 'fail'
  } catch (err) {
    probe.value = 'unknown'
  } finally {
    probing.value = false
  }
}

async function recheck() {
  await refreshEnv()
  await runProbe()
}

onMounted(runProbe)
</script>

<template>
  <StepHead icon="env" title="检查环境" lead="先确认这台服务器能跑 Nomen。标红的项目解决后再继续。" />

  <div v-if="!envOk" class="alert alert--error" role="alert">「需要处理」的项目先解决，再刷新本页。</div>

  <ul class="checks">
    <CheckRow v-for="row in required" :key="row.key" :label="row.label" :hint="row.pass ? '' : row.hint"
      :state="row.pass ? 'pass' : 'fail'" :state-text="row.pass ? '通过' : '需要处理'" />
    <CheckRow label="路由与伪静态" :hint="probeHint" :state="probeState" :state-text="probeText" />
  </ul>

  <div v-if="probe === 'fail' && !probing" class="alert alert--error" style="margin-top:18px">
    <strong>伪静态没生效。</strong>除首页外，<code>/login</code>、<code>/special/version</code> 这些地址都会 404。
    加上下面的规则、reload 之后再点「重新检查」。
    <pre class="code">{{ snippet }}</pre>
  </div>

  <div class="actions">
    <button v-if="canContinue" class="btn btn-primary" type="button" @click="go('db')">继续</button>
    <button v-else class="btn btn-ghost" type="button" :class="{ 'is-busy': wizard.busy }"
      :disabled="wizard.busy" @click="recheck">重新检查</button>
  </div>

  <details class="more">
    <summary>可选项（缺了也能装，对应功能会关掉）</summary>
    <ul class="checks">
      <CheckRow v-for="row in optional" :key="row.key" :label="row.label" :hint="row.hint"
        :state="row.pass ? 'pass' : 'off'" :state-text="row.pass ? '可用' : '跳过'" />
    </ul>
  </details>

  <div class="nav-links">
    <button class="linkish" type="button" @click="go('start')">← 上一步</button>
    <span></span>
  </div>
</template>
