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

const stepLabels = [t('本人确认'), t('申诉描述'), t('联系方式')]
const formEl = ref(null)
const fileInput = ref(null)
const tokens = ref([])
const submitLocked = ref(false)
const submitText = ref(t('提交申诉'))

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
  if (n >= props.descriptionMin) return n + t(' / 2000 字')
  return n + t(' / 2000 字（至少 ') + props.descriptionMin + t(' 字，还差 ') + (props.descriptionMin - n) + t(' 字）')
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
      alertUser(t('还差一点'), t('请选择出生日期'))
      return false
    }
    if (!gender.value) {
      alertUser(t('还差一点'), t('请选择性别'))
      return false
    }
    return true
  }

  if (step === 2) {
    const text = description.value.trim()
    if (!text) {
      alertUser(t('还差一点'), t('请填写申诉描述与账号归属证明'))
      return false
    }
    if (text.length > 2000) {
      alertUser(t('内容过长'), t('申诉描述不能超过 2000 字'))
      return false
    }
    if (text.length < props.descriptionMin) {
      alertUser(
        t('请写得更具体一些'),
        t('请至少填写 ') + props.descriptionMin + t(' 个字。尽量写出注册邮箱、常用登录地点、授权过的应用这类只有账号本人知道的信息，这些是审核判断账号归属的主要依据。'),
      )
      return false
    }
    return true
  }

  if (step === 3) {
    if (!contact.value) {
      alertUser(t('还差一点'), t('请选择收信邮箱'))
      return false
    }
    if (contact.value === 'temp' && !isEmail(tempEmail.value.trim())) {
      alertUser(t('邮箱有误'), t('请输入有效的临时邮箱地址'))
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
  submitText.value = text || t('提交中…')
}

function releaseSubmit() {
  submitLocked.value = false
  submitText.value = t('提交申诉')
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
    alertUser(t('有图片没准备好'), t('请先移除标记为「上传失败」的图片，或重新添加后再提交。'))
    return
  }

  const form = formEl.value
  if (!form) return

  if (!items.value.length) {
    lockSubmit()
    form.submit()
    return
  }

  lockSubmit(t('正在上传图片…'))
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
      setStatus(err.message + t(' —— 图片已保留，可以重试提交，或删掉问题图片再提交。'), 'error')
      alertUser(t('图片上传失败'), err.message + t('。\n\n图片还留在这里：您可以再点一次「提交申诉」重试，或者把出问题的图删掉再提交。'))
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
        <img src="/assets/brand/nomen-icon.svg" alt="" width="48" height="48">
      </div>
      <h1>{{ t('账号恢复申诉') }}</h1>
      <p>{{ t('向支持团队申请恢复 {:name} 的访问权限', { name: ctx.username }) }}</p>
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
          :title="t('确认是您本人')"
          :desc="t('我们不会重复收集您的信息，这只是希望确认是您本人在使用 Nomen 账户')"
        />

        <div class="form-group">
          <label for="birth_date">{{ t('出生日期') }}</label>
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
          <label>{{ t('您的性别是什么？') }}</label>
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
          :title="t('证明这个账号确实是您本人的')"
          :desc="t('请尽量具体地写出只有账号本人知道的信息 —— 这是审核判断账号归属的主要依据')"
        />

        <div class="form-group">
          <label for="description">{{ t('申诉描述与账号归属证明') }}</label>
          <ul style="margin:0 0 12px;padding-left:20px;font-size:13px;line-height:2;color:var(--color-text-muted);">
            <li>{{ t('注册时使用的邮箱（后来换过、或用的是家长邮箱，请一并说明）') }}</li>
            <li>{{ t('常用登录地点与设备：常在哪个城市或地区、经哪家网络运营商接入、常在哪台设备上登录（写得出设备型号更好）') }}</li>
            <li>{{ t('授权过哪些接入应用') }}</li>
            <li>{{ t('最近一次能正常登录的时间，以及当时在做什么') }}</li>
            <li>{{ t('现在具体卡在哪一步、页面上显示什么提示（例如只剩 2 种登录方式、无法完成两步验证）') }}</li>
          </ul>
          <textarea
            id="description"
            v-model="description"
            name="description"
            rows="9"
            maxlength="2000"
            :placeholder="t('例如：我常在这台 Windows 电脑、经某个网络运营商接入登录，注册邮箱是 xxxxx@example.com。上周手机丢失后我忘记了密码，账号只剩 2 个登录选项，没法完成两步验证。')"
            style="width:100%;resize:vertical;padding:12px;border-radius:8px;border:1px solid var(--color-border);background:var(--color-bg-secondary);color:var(--color-text);font-family:inherit;font-size:14px;line-height:1.7;"
          ></textarea>
          <p class="form-hint" id="description-counter" :style="{ color: counterColor }">{{ counterText }}</p>
        </div>

        <div class="rc-uploads-group">
          <label>{{ t('证明材料（选填，最多 {:n} 张）', { n: attachMax }) }}</label>
          <p class="rc-hint">
            {{ t('可以点「+」选择图片，也可以直接把截图粘贴进来（Ctrl+V）。支持 PNG / JPG / WebP，') }}
            {{ t('单张不超过 {:size}，超过会自动压缩；', { size: attachMaxLabel }) }}
            {{ t('图片要等点「提交申诉」那一刻才会上传。') }}
          </p>
          <div class="rc-uploads" id="recovery-uploads">
            <div
              v-for="item in items"
              :key="item.id"
              :class="['rc-upload', item.state === 'error' ? 'rc-upload--error' : '', item.state === 'uploading' ? 'rc-upload--busy' : '']"
            >
              <img class="rc-upload__img" :alt="t('证明材料预览')" :src="item.url">
              <div class="rc-upload__meta">
                {{ item.state === 'uploading' ? t('上传中…') : item.state === 'error' ? t('上传失败') : formatSize(item.size) + (item.compressed ? t(' ·已压缩') : '') }}
              </div>
              <button type="button" class="rc-upload__remove" :aria-label="t('移除这张图片')" @click="removeItem(item.id)">×</button>
            </div>
            <button
              v-show="items.length < MAX_FILES"
              type="button"
              class="rc-uploads__add"
              id="recovery-upload-add"
              @click="openFilePicker"
            >
              <span class="rc-uploads__plus" aria-hidden="true">+</span>
              <span class="rc-uploads__addtext">{{ t('添加图片') }}</span>
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
          {{ t('上一步填写的出生日期与性别也会作为比对参考。请勿在本页填写密码、验证码或恢复码 —— 我们任何时候都不会向您索要这些。') }}
        </div>
      </div>

      <div class="form-step" :class="{ active: currentStep === 3 }" id="step-3">
        <StepHero
          icon="send"
          :title="t('团队该如何联系您')"
          :desc="t('您可以填写当前账号的绑定邮箱，但当账号的邮箱不可用时，可以使用临时邮箱')"
        />

        <div class="form-group">
          <label>{{ t('收信邮箱') }}</label>
          <div class="gender-radio-group">
            <div v-if="ctx.maskedEmail" class="gender-radio-row">
              <input id="contact-account" v-model="contact" type="radio" name="contact" value="account">
              <label for="contact-account">{{ t('当前账号邮箱：') + ctx.maskedEmail }}</label>
            </div>
            <div class="gender-radio-row">
              <input id="contact-temp" v-model="contact" type="radio" name="contact" value="temp">
              <label for="contact-temp">{{ t('填写临时邮箱') }}</label>
            </div>
          </div>
        </div>

        <div v-show="contact === 'temp'" class="form-group" id="temp-email-group">
          <label for="temp_email">{{ t('临时邮箱地址') }}</label>
          <input
            id="temp_email"
            v-model="tempEmail"
            type="email"
            name="temp_email"
            :placeholder="t('请输入可用于接收回复的邮箱')"
          >
          <p class="form-hint">{{ t('审核结果与恢复链接都会发到这个邮箱，请确保可以正常收信') }}</p>
        </div>

        <div class="nomen-alert nomen-alert--info">
          {{ t('提交后我们会在 2 个自然日内审核（部分请求可能需要更久）。为保护账号安全，无论填写的信息是否正确，我们都不会在页面上给出判断结果。') }}
        </div>
      </div>

      <div class="form-navigation">
        <button v-show="currentStep > 1" type="button" class="btn btn-secondary" id="btn-prev" @click="prevStep">{{ t('上一步') }}</button>
        <button v-show="currentStep < 3" type="button" class="btn btn-primary" id="btn-next" @click="nextStep">{{ t('下一步') }}</button>
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
      <p><a href="/login">{{ t('返回登录') }}</a></p>
    </div>
  </div>
</template>
