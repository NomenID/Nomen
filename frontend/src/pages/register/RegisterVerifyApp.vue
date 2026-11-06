<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  email: { type: String, default: '' },
  maskedEmail: { type: String, default: '' },
  isMinor: { type: Boolean, default: false },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  resendAfter: { type: Number, default: 0 },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})

const left = ref(Math.max(0, Number(props.resendAfter) || 0))
let timer = null

function render() {
  if (left.value <= 0) {
    if (timer) {
      clearInterval(timer)
      timer = null
    }
    left.value = 0
  }
}

onMounted(() => {
  if (left.value > 0) {
    timer = setInterval(() => {
      left.value -= 1
      render()
    }, 1000)
  }
  document.getElementById('code')?.focus()
})

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo">
        <img :src="brandLogo" alt="" width="48" height="48">
      </div>
      <h1>{{ t('这个邮箱已经被占用了') }}</h1>
      <p>{{ t('验证一下邮箱，就能接着完成注册') }}</p>
    </div>

    <div id="alert-container">
      <BaseAlert v-if="error" type="error" :message="error" />
      <BaseAlert v-if="success" type="success" :message="success" />
    </div>

    <div class="claim-note">
      <p>{{ t('你好，欢迎使用 Nomen。') }}</p>
      <p>{{ t('这个邮箱此前已经有人（或机器人）注册过，但那个账户一直没有完成邮箱验证，属于未正式激活的状态。') }}</p>
      <p>{{ t('请先完成邮箱验证：输入我们刚发到你邮箱的 6 位验证码。验证通过后，系统会自动清理掉那个未验证的账户，并直接用你刚才填写的信息注册并激活现在这个账户。') }}</p>
    </div>

    <div class="activate-info">
      <p>{{ isMinor ? t('验证码已发送至家长邮箱：') : t('验证码已发送至：') }}</p>
      <p class="email-display">{{ maskedEmail }}</p>
    </div>

    <form id="claim-form" method="POST" action="/register/verify" class="auth-form">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="email" :value="email">

      <div class="form-group">
        <label for="code">{{ t('6 位验证码') }}</label>
        <input
          id="code"
          type="text"
          name="code"
          required
          autofocus
          inputmode="numeric"
          autocomplete="one-time-code"
          maxlength="6"
          pattern="[0-9]{6}"
          placeholder="000000"
          class="claim-code-input"
        >
      </div>

      <button type="submit" class="btn btn-primary btn-block">{{ t('验证并完成注册') }}</button>
    </form>

    <form id="resend-form" method="POST" action="/register/verify/resend" class="claim-resend-form">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="email" :value="email">
      <button type="submit" id="resend-btn" class="btn btn-secondary btn-block" :disabled="left > 0">
        <span id="resend-text">{{ left > 0 ? t('重新发送（{:n} 秒）', { n: left }) : t('重新发送验证码') }}</span>
      </button>
    </form>

    <div class="activate-tips">
      <p>{{ t('提示：') }}</p>
      <ul>
        <li>{{ t('请检查收件箱和垃圾邮件文件夹') }}</li>
        <li>{{ t('验证码 10 分钟内有效') }}</li>
        <li v-if="isMinor">{{ t('验证码发到了家长邮箱，记得通知家长查收') }}</li>
      </ul>
    </div>

    <div class="auth-footer">
      <p>{{ t('想换个邮箱？') }} <a href="/register">{{ t('返回注册') }}</a></p>
    </div>
  </div>
</template>
