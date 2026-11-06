<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'

const props = defineProps({
  csrf: { type: String, default: '' },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  email: { type: String, default: '' },
  usesGuardianAddress: { type: Boolean, default: false },
})

const sending = ref(false)
const cooldown = ref(0)
let timer = null

const alert = reactive({
  type: props.error ? 'error' : (props.success ? 'success' : ''),
  message: props.error || props.success || '',
})
const alertKey = ref(0)

const countdownText = computed(() => {
  const m = Math.floor(cooldown.value / 60)
  const s = cooldown.value % 60
  return (m > 0 ? m + t('分') : '') + s + t('秒后重试')
})

const deleteForm = ref(null)

function showAlert(type, message) {
  alert.type = type
  alert.message = message
  alertKey.value += 1
}

function startCooldown(seconds) {
  cooldown.value = Math.max(0, Number(seconds) || 0)
  if (timer) clearInterval(timer)
  timer = setInterval(() => {
    cooldown.value -= 1
    if (cooldown.value <= 0) {
      clearInterval(timer)
      timer = null
      cooldown.value = 0
    }
  }, 1000)
}

async function sendVerification() {
  if (sending.value || cooldown.value > 0) return
  sending.value = true

  const body = new URLSearchParams()
  body.set('_csrf', props.csrf)

  try {
    const response = await fetch('/activate/send', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'X-Requested-With': 'XMLHttpRequest',
      },
      body: body.toString(),
    })
    const data = await response.json()
    showAlert(data.success ? 'success' : 'error', data.message || t('请求失败'))
    if (data.success) {
      startCooldown(60)
    } else if (data.retry_after) {
      startCooldown(data.retry_after)
    }
  } catch (err) {
    showAlert('error', t('网络错误'))
  } finally {
    sending.value = false
  }
}

async function confirmDelete() {
  if (!window.Nomen || typeof window.Nomen.confirm !== 'function') return

  const confirmed = await window.Nomen.confirm(t('注销账号'), t('确定要注销账号吗？此操作不可撤销，你的数据将被永久删除。'), {
    confirmText: t('确认注销'),
    cancelText: t('再想想'),
    danger: true,
    icon: 'trash',
  })
  if (!confirmed) return

  const confirmedAgain = await window.Nomen.confirm(t('最后确认'), t('真的要删除吗？删除后无法恢复！'), {
    confirmText: t('确定删除'),
    cancelText: t('取消'),
    danger: true,
    icon: 'warning',
  })
  if (!confirmedAgain) return

  const form = deleteForm.value
  if (!form) return
  window.Nomen.beginSubmit(form)
  form.submit()
}

onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="auth-card">
    <div class="auth-header">
      <div class="auth-logo">
        <img src="/assets/brand/nomen-icon.svg" alt="" width="48" height="48">
      </div>
      <h1>{{ t('邮箱验证') }}</h1>
      <p>{{ usesGuardianAddress ? t('请家长确认这个邮箱') : t('请验证你的邮箱地址以激活账户') }}</p>
    </div>

    <div id="alert-container">
      <BaseAlert v-if="alert.message" :key="alertKey" :type="alert.type" :message="alert.message" />
    </div>

    <div class="activate-info">
      <p>{{ usesGuardianAddress ? t('确认邮件将发送至家长邮箱：') : t('验证邮件将发送至：') }}</p>
      <p class="email-display">{{ email }}</p>
    </div>

    <form id="activate-form" class="auth-form" @submit.prevent="sendVerification">
      <input type="hidden" name="_csrf" :value="csrf">
      <button type="submit" id="send-btn" class="btn btn-primary btn-block" :disabled="sending || cooldown > 0">
        <span v-show="cooldown <= 0" id="btn-text">{{ sending ? t('发送中...') : t('发送验证邮件') }}</span>
        <span v-show="cooldown > 0" id="btn-countdown">{{ countdownText }}</span>
      </button>
    </form>

    <div class="activate-tips">
      <p>{{ t('提示：') }}</p>
      <ul>
        <template v-if="usesGuardianAddress">
          <li>{{ t('家长点确认之前，账号还不能开始使用') }}</li>
          <li>{{ t('请让家长检查收件箱和垃圾邮件文件夹') }}</li>
        </template>
        <li v-else>{{ t('请检查收件箱和垃圾邮件文件夹') }}</li>
        <li>{{ t('验证链接 24 小时内有效') }}</li>
        <li>{{ t('每天最多发送 5 封验证邮件') }}</li>
      </ul>
    </div>

    <div class="activate-warning">
      <p>{{ t('为了防止抢注他人邮箱和批量注册，若注册后超过') }} <strong>{{ t('7 天') }}</strong>{{ t('未进行激活，站点可能会释放您的账号和邮箱。') }}</p>
    </div>

    <div class="auth-footer">
      <p>{{ t('已验证？') }} <a href="/login">{{ t('重新登录') }}</a></p>
    </div>

    <div class="delete-account-section">
      <button type="button" class="btn-link-danger" @click="confirmDelete">{{ t('不想要这个账号了？') }}</button>
      <form ref="deleteForm" method="POST" action="/activate/delete-account" style="display:none;">
        <input type="hidden" name="_csrf" :value="csrf">
      </form>
    </div>
  </div>
</template>
