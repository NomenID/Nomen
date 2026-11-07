<script setup>
import { reactive, ref, watch } from 'vue'
import StepHead from '../StepHead.vue'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.smtp || {}
const mode = ref(saved.host ? 'form' : 'choice')

const form = reactive({
  host: saved.host || '',
  port: saved.port || 587,
  encryption: saved.encryption ?? 'tls',
  username: saved.username || '',
  password: saved.password || '',
  from_email: saved.from_email || '',
})

watch(() => form.encryption, (value) => {
  const port = Number(form.port)
  if (port !== 587 && port !== 465) return
  form.port = value === 'ssl' ? 465 : (value === 'tls' ? 587 : form.port)
})

function skip() {
  submit('mail', { mode: 'skip' })
}

function save() {
  submit('mail', { mode: 'save', ...form })
}

function back() {
  if (mode.value === 'form' && !saved.host) {
    mode.value = 'choice'
    return
  }
  go('plugins')
}
</script>

<template>
  <template v-if="mode === 'choice'">
    <StepHead icon="mail" title="需要现在配置邮箱么？"
      lead="注册验证码、找回密码和通知都走 SMTP。现在不配也能装，装完在 config/installed.php 里补。" />

    <div class="actions">
      <button class="btn btn-primary" type="button" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy"
        @click="skip">暂时不</button>
      <button class="btn btn-ghost" type="button" :disabled="wizard.busy" @click="mode = 'form'">现在就配置</button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('plugins')">← 上一步</button>
      <span></span>
    </div>
  </template>

  <template v-else>
    <StepHead icon="mail" title="配置发信" lead="注册验证码、找回密码和通知都走 SMTP。" />

    <form @submit.prevent="save">
      <div class="fields">
        <div class="field-row">
          <div class="field">
            <label for="smtp_host">SMTP 主机</label>
            <input id="smtp_host" v-model="form.host" class="input" placeholder="smtp.example.com" autocomplete="off">
          </div>
          <div class="field">
            <label for="smtp_port">端口</label>
            <input id="smtp_port" v-model="form.port" class="input" inputmode="numeric">
          </div>
        </div>
        <div class="field">
          <label for="smtp_encryption">加密</label>
          <select id="smtp_encryption" v-model="form.encryption" class="input">
            <option value="tls">STARTTLS（587）</option>
            <option value="ssl">SSL（465）</option>
            <option value="">不加密</option>
          </select>
        </div>
        <div class="field">
          <label for="smtp_username">用户名</label>
          <input id="smtp_username" v-model="form.username" class="input" autocomplete="off">
          <div class="hint">需要认证的邮箱填上；本机中继可以留空。</div>
        </div>
        <div class="field">
          <label for="smtp_password">密码</label>
          <input id="smtp_password" v-model="form.password" class="input" type="password" autocomplete="new-password">
        </div>
        <div class="field">
          <label for="smtp_from">发件地址</label>
          <input id="smtp_from" v-model="form.from_email" class="input" type="email" placeholder="no-reply@example.com">
        </div>
      </div>

      <div class="actions">
        <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
          保存并继续
        </button>
      </div>

      <div class="nav-links">
        <button class="linkish" type="button" @click="back">← 上一步</button>
        <button class="linkish" type="button" :disabled="wizard.busy" @click="skip">暂时跳过</button>
      </div>
    </form>
  </template>
</template>
