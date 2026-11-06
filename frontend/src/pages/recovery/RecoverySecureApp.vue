<script setup>
import { onMounted, reactive, ref } from 'vue'
import BaseAlert from '../../components/BaseAlert.vue'
import { useT } from '../../composables/useT.js'
import { toast } from '../../lib/toast.js'
import BiometricsSection from '../settings/BiometricsSection.vue'
import EmailSetSection from '../settings/EmailSetSection.vue'
import RecoveryCodesSection from '../settings/RecoveryCodesSection.vue'
import TotpSection from '../settings/TotpSection.vue'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  username: { type: String, default: '' },
  methods: { type: Array, default: () => [] },
  assistMethods: { type: Array, default: () => [] },
  methodCount: { type: Number, default: 0 },
  requiredCount: { type: Number, default: 3 },
  resetDone: { type: Boolean, default: false },
  canFinish: { type: Boolean, default: false },
  supportEmail: { type: String, default: '' },
  emails: { type: Array, default: () => [] },
  maxEmails: { type: Number, default: 5 },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  assistLabel: { type: String, default: '快速辅助验证方式' },
  emailMissing: { type: Boolean, default: false },
  urls: { type: Object, default: () => ({}) },
})

const passwordForm = reactive({
  next: '',
  confirm: '',
})
const passwordSaving = ref(false)

async function savePassword() {
  if (passwordSaving.value) return
  passwordSaving.value = true
  try {
    const data = new FormData()
    data.append('_csrf', props.csrf)
    data.append('current_password', '')
    data.append('new_password', passwordForm.next)
    data.append('confirm_password', passwordForm.confirm)
    const response = await fetch('/settings/password', {
      method: 'POST',
      body: data,
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
    })
    const result = await response.json()
    toast(result.message || (result.success ? t('已保存') : t('保存失败')), result.success ? 'success' : 'error')
    if (result.success) {
      passwordForm.next = ''
      passwordForm.confirm = ''
    }
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
  } finally {
    passwordSaving.value = false
  }
}

const supportHtml = props.supportEmail.replace('@', '&#64;')

onMounted(() => {
  const hash = window.location.hash
  if (!hash || hash.length < 2) return
  const target = document.getElementById(decodeURIComponent(hash.slice(1)))
  if (!target || !target.classList.contains('settings-section')) return
  target.classList.add('settings-section--flash')
  target.scrollIntoView({ behavior: 'smooth', block: 'start' })
  setTimeout(() => target.scrollIntoView({ behavior: 'smooth', block: 'start' }), 300)
})
</script>

<template>
  <div class="settings">
    <h1>{{ t('恢复账户访问权限') }}</h1>

    <div class="nomen-alert nomen-alert--warning">
      {{ t('您好 {:name}。您正在通过恢复流程登录。在完成下面的安全修复前，账户的其他页面暂不可用。', { name: username }) }}
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />
    <BaseAlert v-if="success" type="success" :message="success" />

    <div class="settings-section" id="sec-recovery-progress">
      <h2>{{ t('修复进度') }}</h2>

      <div class="recovery-check" :class="{ 'recovery-check--done': resetDone }">
        <span class="recovery-check__mark icon" :class="resetDone ? 'icon-check-square icon--success' : 'icon-square'"></span>
        <div>
          <strong>{{ t('修改密码，或添加 / 更换并验证一个邮箱') }}</strong>
          <p class="form-hint">{{ t('这大概率是让您此次无法登录的主要原因。修改密码当场生效；添加或更换邮箱需要到新邮箱点确认链接后才生效。') }}</p>
        </div>
      </div>

      <div class="recovery-check" :class="{ 'recovery-check--done': methodCount >= requiredCount }">
        <span class="recovery-check__mark icon" :class="methodCount >= requiredCount ? 'icon-check-square icon--success' : 'icon-square'"></span>
        <div>
          <strong>{{ t('登录方式至少 {:required} 种（当前 {:current} 种）', { required: requiredCount, current: methodCount }) }}</strong>
          <p class="form-hint">{{ t('账号仅剩 2 种登录方式时，必须再添加第 3 种，否则再次登录不上就只能再来一次申诉。') }}</p>
        </div>
      </div>

      <div class="recovery-methods">
        <span
          v-for="method in methods"
          :key="method.label"
          class="recovery-method"
          :class="{ 'recovery-method--on': method.present }"
        >
          <span class="recovery-method__name">{{ method.label }}</span>
          <span class="recovery-method__state">{{ method.present ? (method.on || t('已拥有')) : (method.off || t('未设置')) }}</span>
        </span>
      </div>

      <p class="form-hint" style="margin-top:16px;">{{ t('{:label}（不计入上面的数量）', { label: t(assistLabel) }) }}</p>
      <div class="recovery-methods" style="margin-top:8px;">
        <span
          v-for="method in assistMethods"
          :key="method.label"
          class="recovery-method"
          :class="{ 'recovery-method--on': method.present }"
        >
          <span class="recovery-method__name">{{ method.label }}</span>
          <span class="recovery-method__state">{{ method.present ? (method.on || t('已拥有')) : (method.off || t('未设置')) }}</span>
        </span>
      </div>

      <form v-if="canFinish" method="POST" action="/recovery/secure/finish" style="margin-top:20px;">
        <input type="hidden" name="_csrf" :value="csrf">
        <button type="submit" class="btn btn-primary">{{ t('完成修复并进入账户') }}</button>
      </form>
      <p v-else class="form-hint" style="margin-top:16px;">{{ t('两条都打勾后，才会出现「完成修复」按钮。') }}</p>
    </div>

    <div class="settings-section" id="sec-password">
      <h2>{{ t('修改密码') }} <span class="form-hint" style="font-weight:400;">{{ t('（推荐，当场生效）') }}</span></h2>
      <form class="settings-form" @submit.prevent="savePassword">
        <div class="form-group">
          <label for="new_password">{{ t('新密码') }}</label>
          <div class="pw-wrap">
            <input id="new_password" v-model="passwordForm.next" type="password" required minlength="8" :placeholder="t('至少8位，包含字母和数字')">
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">{{ t('确认新密码') }}</label>
          <input id="confirm_password" v-model="passwordForm.confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="passwordSaving">{{ passwordSaving ? t('保存中...') : t('修改密码') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-emails">
      <h2>{{ t('邮箱地址') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('添加一个属于你的邮箱，点邮件里的链接验证通过，就算完成了一项安全修复。账号还没有首选邮箱时，验证通过后它会自动成为首选。最多可以绑定 {:n} 个。', { n: maxEmails }) }}
      </p>
      <EmailSetSection :csrf="csrf" :emails="emails" :max-emails="maxEmails" recovery-mode />
    </div>

    <div class="settings-section">
      <h2>{{ t('验证器 APP（TOTP）') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('使用 Google Authenticator、Authy 等 TOTP 应用生成6位动态验证码，作为双因素验证的第二要素') }}</p>
      <TotpSection
        :csrf="csrf"
        recovery-mode
        :label-enable="t('绑定验证器 APP')"
        :label-disable="t('解绑验证器 APP')"
        :label-enabled="t('验证器 APP 已绑定')"
        :label-not-enabled="t('您尚未绑定验证器 APP')"
        :label-verify="t('验证并绑定')"
      />
    </div>

    <div class="settings-section" id="sec-recovery">
      <h2>{{ t('恢复码') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('恢复码是最后手段的验证方式，每个码只能用一次。生成后请立即抄写或打印保存。') }}</p>
      <RecoveryCodesSection :csrf="csrf" recovery-mode />
    </div>

    <div class="settings-section">
      <h2>{{ t('生物识别') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('绑定生物识别（如 Windows Hello、指纹、面容 ID）后可快速登录，属于{:label}、不计入登录方式数量。最多同时绑定5个设备', { label: t(assistLabel) }) }}</p>
      <BiometricsSection :csrf="csrf" />
    </div>

    <div class="settings-section">
      <h2>{{ t('需要帮助？') }}</h2>
      <p class="form-hint">
        <template v-if="emailMissing">
          {{ t('您的账户邮箱尚未完成验证，它暂时不能作为登录方式。建议绑定验证器 APP 或生成恢复码来凑满 {:n} 种。', { n: requiredCount }) }}
        </template>
        {{ t('如果修复过程中遇到问题，请联系') }} <a :href="'mailto:' + supportEmail">{{ supportEmail }}</a>。
      </p>
      <p style="margin-top:16px;"><a href="/logout">{{ t('退出登录') }}</a></p>
    </div>
  </div>
</template>
