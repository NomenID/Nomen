<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
-->

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useT } from '../../composables/useT.js'
const { t } = useT()
import BaseAlert from '../../components/BaseAlert.vue'
import StepHero from '../register/StepHero.vue'
import { useRecoveryUploads } from '../../composables/useRecoveryUploads.js'

const props = defineProps({
  csrf: { type: String, default: '' },
  error: { type: String, default: '' },
  old: { type: [Object, Array], default: () => ({}) },
  ctx: { type: Object, default: () => ({}) },
  genders: { type: Object, default: () => ({}) },
  attachMax: { type: Number, default: 5 },
  attachMaxLabel: { type: String, default: '1 MB' },
  descriptionMin: { type: Number, default: 20 },
  today: { type: String, default: '' },
  uploadUrl: { type: String, default: '/recovery/upload' },
  brandLogo: { type: String, default: '/assets/brand/nomen-icon.svg' },
})

const old = Array.isArray(props.old) ? {} : (props.old || {})

function clamp(value, min, max) {
  return Math.min(Math.max(value, min), max)
}

const currentStep = ref(clamp(parseInt(old.current_step, 10) || 1, 1, 3))
const birthDate = ref(old.birth_date || '')
const gender = ref(old.gender || '')
const description = ref(old.description || '')
const contact = ref(old.contact || (props.ctx.maskedEmail ? 'account' : ''))
const tempEmail = ref(old.temp_email || '')

const stepLabels = [t('Verify identity'), t('Appeal description'), t('Contact')]
const formEl = ref(null)
const fileInput = ref(null)
const tokens = ref([])
const submitLocked = ref(false)
const submitText = ref(t('Submit request'))

const uploads = useRecoveryUploads(props.uploadUrl)
const {
  items,
  status,
  statusKind,
  uploading,
  hasErrors,
  addFiles,
  removeItem,
  uploadAll,
  setStatus,
  formatSize,
  MAX_FILES,
} = uploads

const descriptionLength = computed(() => description.value.length)
const counterText = computed(() => {
  const n = descriptionLength.value
  if (n >= props.descriptionMin) return n + t(' / 2000 characters')
  return n + t(' / 2000 characters (at least ') + props.descriptionMin + t(' characters — ') + (props.descriptionMin - n) + t(' to go)')
})
const counterColor = computed(() => (
  descriptionLength.value > 0 && descriptionLength.value < props.descriptionMin ? 'var(--color-warning)' : ''
))

function alertUser(title, message) {
  if (window.Nomen && typeof window.Nomen.alert === 'function') {
    window.Nomen.alert(title, message)
  }
}

function isEmail(value) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function validateStep(step) {
  if (step === 1) {
    if (!birthDate.value) {
      alertUser(t('Almost there'), t('Pick a date of birth'))
      return false
    }
    if (!gender.value) {
      alertUser(t('Almost there'), t('Pick a gender'))
      return false
    }
    return true
  }

  if (step === 2) {
    const text = description.value.trim()
    if (!text) {
      alertUser(t('Almost there'), t('Please fill in the description and proof of account ownership'))
      return false
    }
    if (text.length > 2000) {
      alertUser(t('Too long'), t('The description can\'t exceed 2000 characters'))
      return false
    }
    if (text.length < props.descriptionMin) {
      alertUser(
        t('Please be more specific'),
        t('Please enter at least ') + props.descriptionMin + t(' characters. Include things only the owner would know — the sign-up email, usual sign-in locations, authorized apps. This is the main basis for ownership review.'),
      )
      return false
    }
    return true
  }

  if (step === 3) {
    if (!contact.value) {
      alertUser(t('Almost there'), t('Pick a mailbox'))
      return false
    }
    if (contact.value === 'temp' && !isEmail(tempEmail.value.trim())) {
      alertUser(t('Email looks wrong'), t('Enter a valid temporary email address'))
      return false
    }
    return true
  }

  return true
}

function nextStep() {
  if (!validateStep(currentStep.value)) return
  if (currentStep.value < 3) currentStep.value += 1
}

function prevStep() {
  if (currentStep.value > 1) currentStep.value -= 1
}

function lockSubmit(text) {
  submitLocked.value = true
  submitText.value = text || t('Submitting…')
}

function releaseSubmit() {
  submitLocked.value = false
  submitText.value = t('Submit request')
}

function onSubmit(event) {
  event.preventDefault()

  for (let step = 1; step <= 3; step++) {
    if (!validateStep(step)) {
      currentStep.value = step
      return
    }
  }

  if (uploading.value) return

  if (hasErrors.value) {
    alertUser(t('Some images aren\'t ready'), t('Remove the images marked as failed first, or add them again, then submit.'))
    return
  }

  const form = formEl.value
  if (!form) return

  if (!items.value.length) {
    lockSubmit()
    form.submit()
    return
  }

  lockSubmit(t('Uploading images…'))
  uploadAll(props.csrf)
    .then((result) => {
      tokens.value = result
      releaseSubmit()
      if (window.Nomen && typeof window.Nomen.beginSubmit === 'function') {
        window.Nomen.beginSubmit(form)
      }
      form.submit()
    })
    .catch((err) => {
      releaseSubmit()
      setStatus(err.message + t(' — your images are kept. You can submit again, or remove the problem images first.'), 'error')
      alertUser(t('Image upload failed'), err.message + t('\n\nYour images are kept: you can press Submit again to retry, or remove the problem images first.'))
    })
}

function onFileChange(event) {
  addFiles(event.target.files)
  event.target.value = ''
}

function openFilePicker() {
  fileInput.value?.click()
}

function onPaste(event) {
  if (currentStep.value !== 2) return
  const clipboard = event.clipboardData
  if (!clipboard) return

  const files = []
  for (const item of clipboard.items || []) {
    if (item.kind === 'file' && item.type && item.type.indexOf('image/') === 0) {
      const file = item.getAsFile()
      if (file) files.push(file)
    }
  }

  if (!files.length) return
  event.preventDefault()
  addFiles(files)
}

onMounted(() => document.addEventListener('paste', onPaste))
onBeforeUnmount(() => document.removeEventListener('paste', onPaste))
</script>

<template>
  <div class="auth-card auth-card--register">
    <div class="auth-header">
      <div class="auth-logo">
        <img :src="brandLogo" alt="" width="48" height="48">
      </div>
      <h1>{{ t('Account recovery request') }}</h1>
      <p>{{ t('Ask support to restore access to {:name}.', { name: ctx.username }) }}</p>
    </div>

    <BaseAlert v-if="error" type="error" :message="error" />

    <div class="register-steps">
      <template v-for="(label, index) in stepLabels" :key="label">
        <div
          class="step"
          :class="{ active: currentStep === index + 1, completed: currentStep > index + 1 }"
          :data-step="index + 1"
        >
          <div class="step-circle">{{ index + 1 }}</div>
          <span class="step-label">{{ label }}</span>
        </div>
        <div v-if="index < stepLabels.length - 1" class="step-line" :class="{ active: currentStep > index + 1 }"></div>
      </template>
    </div>

    <form ref="formEl" method="POST" action="/recovery" class="auth-form" id="recovery-form" @submit.prevent="onSubmit">
      <input type="hidden" name="_csrf" :value="csrf">
      <input type="hidden" name="current_step" :value="currentStep">

      <div class="form-step" :class="{ active: currentStep === 1 }" id="step-1">
        <StepHero
          icon="file"
          :title="t('Confirm it\'s you')"
          :desc="t('We won\'t collect your information twice — this is only to confirm that you\'re the one using this {:sitename} account')"
        />

        <div class="form-group">
          <label for="birth_date">{{ t('Date of birth') }}</label>
          <input
            id="birth_date"
            v-model="birthDate"
            type="date"
            name="birth_date"
            min="1920-01-01"
            :max="today"
          >
        </div>

        <div class="form-group">
          <label>{{ t('What is your gender?') }}</label>
          <div class="gender-radio-group">
            <div v-for="(label, value, index) in genders" :key="value" class="gender-radio-row">
              <input
                :id="'r-gender-' + (index + 1)"
                v-model="gender"
                type="radio"
                name="gender"
                :value="value"
              >
              <label :for="'r-gender-' + (index + 1)">{{ label }}</label>
            </div>
          </div>
        </div>
      </div>

      <div class="form-step" :class="{ active: currentStep === 2 }" id="step-2">
        <StepHero
          icon="shield-check"
          :title="t('Prove this account is really yours')"
          :desc="t('Be as specific as you can about things only the account owner would know — this is the main basis for the ownership review')"
        />

        <div class="form-group">
          <label for="description">{{ t('Description and proof of ownership') }}</label>
          <ul style="margin:0 0 12px;padding-left:20px;font-size:13px;line-height:2;color:var(--color-text-muted);">
            <li>{{ t('The email you signed up with (mention any later change, or if it was a parent\'s address)') }}</li>
            <li>{{ t('Where you usually sign in: which city or region, which internet provider, and which devices you use (model names help)') }}</li>
            <li>{{ t('Which apps you have authorized') }}</li>
            <li>{{ t('When you last signed in successfully, and what you were doing then') }}</li>
            <li>{{ t('Where exactly you get stuck and what the page says (for example, only 2 sign-in methods left and two-step verification can\'t be completed)') }}</li>
          </ul>
          <textarea
            id="description"
            v-model="description"
            name="description"
            rows="9"
            maxlength="2000"
            :placeholder="t('For example: I usually sign in from this Windows PC through a network operator. My registered email is xxxxx@example.com. I lost my phone last week and forgot my password; the account has only two sign-in options left, so I cannot complete two-step verification.')"
            style="width:100%;resize:vertical;padding:12px;border-radius:8px;border:1px solid var(--color-border);background:var(--color-bg-secondary);color:var(--color-text);font-family:inherit;font-size:14px;line-height:1.7;"
          ></textarea>
          <p class="form-hint" id="description-counter" :style="{ color: counterColor }">{{ counterText }}</p>
        </div>

        <div class="rc-uploads-group">
          <label>{{ t('Evidence (optional, up to {:n} images)', { n: attachMax }) }}</label>
          <p class="rc-hint">
            {{ t('Click + to choose images, or paste a screenshot directly (Ctrl+V). Supports PNG / JPG / WebP, ') }}
            {{ t('up to {:size} each, and anything larger is compressed automatically. ', { size: attachMaxLabel }) }}
            {{ t('Images are only uploaded once you press “Submit request”.') }}
          </p>
          <div class="rc-uploads" id="recovery-uploads">
            <div
              v-for="item in items"
              :key="item.id"
              :class="['rc-upload', item.state === 'error' ? 'rc-upload--error' : '', item.state === 'uploading' ? 'rc-upload--busy' : '']"
            >
              <img class="rc-upload__img" :alt="t('Evidence preview')" :src="item.url">
              <div class="rc-upload__meta">
                {{ item.state === 'uploading' ? t('Uploading…') : item.state === 'error' ? t('Upload failed') : formatSize(item.size) + (item.compressed ? t(' · compressed') : '') }}
              </div>
              <button type="button" class="rc-upload__remove" :aria-label="t('Remove this image')" @click="removeItem(item.id)">×</button>
            </div>
            <button
              v-show="items.length < MAX_FILES"
              type="button"
              class="rc-uploads__add"
              id="recovery-upload-add"
              @click="openFilePicker"
            >
              <span class="rc-uploads__plus" aria-hidden="true">+</span>
              <span class="rc-uploads__addtext">{{ t('Add images') }}</span>
            </button>
          </div>
          <input
            ref="fileInput"
            type="file"
            id="recovery-file-input"
            accept="image/png,image/jpeg,image/webp"
            multiple
            hidden
            @change="onFileChange"
          >
          <div
            class="rc-uploads__status"
            :class="statusKind ? 'rc-uploads__status--' + statusKind : ''"
            id="recovery-upload-status"
            role="status"
            aria-live="polite"
          >{{ status }}</div>
          <div id="recovery-upload-tokens">
            <input v-for="(token, index) in tokens" :key="index" type="hidden" name="upload_tokens[]" :value="token">
          </div>
        </div>

        <div class="nomen-alert nomen-alert--info" style="margin-top:20px;">
          {{ t('The date of birth and gender from the previous step are also used for comparison. Never enter your password, verification code or recovery code here — we will never ask for them.') }}
        </div>
      </div>

      <div class="form-step" :class="{ active: currentStep === 3 }" id="step-3">
        <StepHero
          icon="send"
          :title="t('How should we reach you?')"
          :desc="t('You can use the email bound to this account, or a temp email if the account\'s address isn\'t available')"
        />

        <div class="form-group">
          <label>{{ t('Contact mailbox') }}</label>
          <div class="gender-radio-group">
            <div v-if="ctx.maskedEmail" class="gender-radio-row">
              <input id="contact-account" v-model="contact" type="radio" name="contact" value="account">
              <label for="contact-account">{{ t('Account email: ') + ctx.maskedEmail }}</label>
            </div>
            <div class="gender-radio-row">
              <input id="contact-temp" v-model="contact" type="radio" name="contact" value="temp">
              <label for="contact-temp">{{ t('Use a temp email') }}</label>
            </div>
          </div>
        </div>

        <div v-show="contact === 'temp'" class="form-group" id="temp-email-group">
          <label for="temp_email">{{ t('Temp email address') }}</label>
          <input
            id="temp_email"
            v-model="tempEmail"
            type="email"
            name="temp_email"
            :placeholder="t('Enter an email we can reply to')"
          >
          <p class="form-hint">{{ t('The review outcome and recovery link both go to this address — make sure you can receive mail there') }}</p>
        </div>

        <div class="nomen-alert nomen-alert--info">
          {{ t('We review requests within 2 business days (some take longer). To protect the account, we won\'t show any result on this page whether or not the details are correct.') }}
        </div>
      </div>

      <div class="form-navigation">
        <button v-show="currentStep > 1" type="button" class="btn btn-secondary" id="btn-prev" @click="prevStep">{{ t('Previous') }}</button>
        <button v-show="currentStep < 3" type="button" class="btn btn-primary" id="btn-next" @click="nextStep">{{ t('Next') }}</button>
        <button
          v-show="currentStep === 3"
          type="submit"
          class="btn btn-primary btn-block"
          id="btn-submit"
          :disabled="submitLocked"
        >{{ submitText }}</button>
      </div>
    </form>

    <div class="auth-footer">
      <p><a href="/login">{{ t('Back to sign-in') }}</a></p>
    </div>
  </div>
</template>
