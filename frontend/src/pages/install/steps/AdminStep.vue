<script setup>
import { reactive } from 'vue'
import StepHead from '../StepHead.vue'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.admin || {}
const hasPassword = saved.has_password === true

const form = reactive({
  username: saved.username || '',
  email: saved.email || '',
  password: '',
  password2: '',
})

async function save() {
  await submit('admin', { ...form })
}
</script>

<template>
  <StepHead icon="admin" title="建管理员账号" lead="装完用这个账号登录。以后可以在后台再建别的账号。" />

  <form @submit.prevent="save">
    <div class="fields">
      <div class="field">
        <label for="admin_username">用户名</label>
        <input id="admin_username" v-model="form.username" class="input" required minlength="3" maxlength="32"
          pattern="[a-z0-9_]+" autocomplete="username">
        <div class="hint">3-32 位小写字母、数字或下划线。</div>
      </div>
      <div class="field">
        <label for="admin_email">邮箱</label>
        <input id="admin_email" v-model="form.email" class="input" type="email" required autocomplete="email">
        <div class="hint">用来找回密码和接收管理员通知，装完就已经是已验证状态。</div>
      </div>
      <div class="field">
        <label for="admin_password">密码</label>
        <input id="admin_password" v-model="form.password" class="input" type="password"
          :required="!hasPassword" minlength="8" autocomplete="new-password">
        <div class="hint">{{ hasPassword ? '留空表示沿用刚才输入的密码。' : '至少 8 位，要有字母和数字。' }}</div>
      </div>
      <div class="field">
        <label for="admin_password2">再输一次</label>
        <input id="admin_password2" v-model="form.password2" class="input" type="password"
          :required="!hasPassword" minlength="8" autocomplete="new-password">
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        继续
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('site')">← 上一步</button>
      <span></span>
    </div>
  </form>
</template>
