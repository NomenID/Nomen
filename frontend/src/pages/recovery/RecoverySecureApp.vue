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
  assistLabel: { type: String, default: 'Quick assist verification methods' },
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
    toast(result.message || (result.success ? t('Saved') : t('Save failed')), result.success ? 'success' : 'error')
    if (result.success) {
      passwordForm.next = ''
      passwordForm.confirm = ''
    }
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
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
    <h1>{{ t('Regain access to your account') }}</h1>

    <div class="nomen-alert nomen-alert--warning">
      {{ t('Hello {:name}. You\'re signing in through the recovery flow. Other pages stay unavailable until you finish the security repair below.', { name: username }) }}
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />
    <BaseAlert v-if="success" type="success" :message="success" />

    <div class="settings-section" id="sec-recovery-progress">
      <h2>{{ t('Repair progress') }}</h2>

      <div class="recovery-check" :class="{ 'recovery-check--done': resetDone }">
        <span class="recovery-check__mark icon" :class="resetDone ? 'icon-check-square icon--success' : 'icon-square'"></span>
        <div>
          <strong>{{ t('Change your password, or add / replace and verify an email') }}</strong>
          <p class="form-hint">{{ t('This is likely why you couldn\'t sign in. Password changes apply immediately; a new email takes effect after you click the link in that mailbox.') }}</p>
        </div>
      </div>

      <div class="recovery-check" :class="{ 'recovery-check--done': methodCount >= requiredCount }">
        <span class="recovery-check__mark icon" :class="methodCount >= requiredCount ? 'icon-check-square icon--success' : 'icon-square'"></span>
        <div>
          <strong>{{ t('At least {:required} sign-in methods (currently {:current})', { required: requiredCount, current: methodCount }) }}</strong>
          <p class="form-hint">{{ t('With only 2 sign-in methods left you must add a third — otherwise the next lock-out means another recovery request.') }}</p>
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
          <span class="recovery-method__state">{{ method.present ? (method.on || t('Set up')) : (method.off || t('Not set')) }}</span>
        </span>
      </div>

      <p class="form-hint" style="margin-top:16px;">{{ t('{:label} (not counted above)', { label: t(assistLabel) }) }}</p>
      <div class="recovery-methods" style="margin-top:8px;">
        <span
          v-for="method in assistMethods"
          :key="method.label"
          class="recovery-method"
          :class="{ 'recovery-method--on': method.present }"
        >
          <span class="recovery-method__name">{{ method.label }}</span>
          <span class="recovery-method__state">{{ method.present ? (method.on || t('Set up')) : (method.off || t('Not set')) }}</span>
        </span>
      </div>

      <form v-if="canFinish" method="POST" action="/recovery/secure/finish" style="margin-top:20px;">
        <input type="hidden" name="_csrf" :value="csrf">
        <button type="submit" class="btn btn-primary">{{ t('Finish repair and enter account') }}</button>
      </form>
      <p v-else class="form-hint" style="margin-top:16px;">{{ t('The Finish button appears once both are done.') }}</p>
    </div>

    <div class="settings-section" id="sec-password">
      <h2>{{ t('Change password') }} <span class="form-hint" style="font-weight:400;">{{ t('(recommended, instant)') }}</span></h2>
      <form class="settings-form" @submit.prevent="savePassword">
        <div class="form-group">
          <label for="new_password">{{ t('New password') }}</label>
          <div class="pw-wrap">
            <input id="new_password" v-model="passwordForm.next" type="password" required minlength="8" :placeholder="t('8+ characters, letters and digits')">
          </div>
        </div>
        <div class="form-group">
          <label for="confirm_password">{{ t('Confirm new password') }}</label>
          <input id="confirm_password" v-model="passwordForm.confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="passwordSaving">{{ passwordSaving ? t('Saving...') : t('Change password') }}</button>
      </form>
    </div>

    <div class="settings-section" id="sec-emails">
      <h2>{{ t('Email addresses') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">
        {{ t('Add an email of your own and confirm it through the link in the email — that counts as one security repair. If the account has no primary email yet, the verified address becomes primary automatically. You can bind up to {:n}.', { n: maxEmails }) }}
      </p>
      <EmailSetSection :csrf="csrf" :emails="emails" :max-emails="maxEmails" recovery-mode />
    </div>

    <div class="settings-section">
      <h2>{{ t('Authenticator app (TOTP)') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Generate 6-digit codes with a TOTP app like Google Authenticator or Authy as the second factor') }}</p>
      <TotpSection
        :csrf="csrf"
        recovery-mode
        :label-enable="t('Bind authenticator app')"
        :label-disable="t('Unbind authenticator app')"
        :label-enabled="t('Authenticator app bound')"
        :label-not-enabled="t('No authenticator app bound yet')"
        :label-verify="t('Verify and bind')"
      />
    </div>

    <div class="settings-section" id="sec-recovery">
      <h2>{{ t('Recovery codes') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Recovery codes are the last-resort method; each works once. Write them down or print them right away.') }}</p>
      <RecoveryCodesSection :csrf="csrf" recovery-mode />
    </div>

    <div class="settings-section">
      <h2>{{ t('Biometric') }}</h2>
      <p class="form-hint" style="margin-bottom:16px;">{{ t('Binding biometrics (like Windows Hello, fingerprint or Face ID) enables quick sign-in. It\'s one of your {:label} and doesn\'t count toward sign-in methods. Up to 5 devices at a time', { label: t(assistLabel) }) }}</p>
      <BiometricsSection :csrf="csrf" />
    </div>

    <div class="settings-section">
      <h2>{{ t('Need help?') }}</h2>
      <p class="form-hint">
        <template v-if="emailMissing">
          {{ t('Your account email isn\'t verified yet, so it can\'t be used as a sign-in method. Bind an authenticator app or generate recovery codes to reach {:n} methods.', { n: requiredCount }) }}
        </template>
        {{ t('If anything goes wrong during repair, contact') }} <a :href="'mailto:' + supportEmail">{{ supportEmail }}</a>。
      </p>
      <p style="margin-top:16px;"><a href="/logout">{{ t('Sign out') }}</a></p>
    </div>
  </div>
</template>
