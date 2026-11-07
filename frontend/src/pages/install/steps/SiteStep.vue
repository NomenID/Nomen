<script setup>
import { computed, reactive, ref } from 'vue'
import StepHead from '../StepHead.vue'
import { go, submit, uploadLogo, wizard } from '../wizard'

const saved = wizard.state.site || {}

const form = reactive({
  name: saved.name || 'Nomen',
  base_url: saved.base_url || wizard.baseUrl || '',
  copyright: saved.copyright || '',
  support_email: saved.support_email || '',
  admin_notify_email: saved.admin_notify_email || '',
  logo: saved.logo || '',
  language_default: saved.language_default || 'zh-hans',
  language_allow_switch: saved.language_allow_switch !== false,
})

const optionalOpen = computed(() =>
  form.copyright !== '' || form.support_email !== '' || form.admin_notify_email !== ''
)

const fileInput = ref(null)
const uploading = ref(false)
const uploadError = ref('')
const preview = computed(() => form.logo || wizard.defaultLogo)

const uploadReady = computed(() => wizard.upload?.enabled === true)
const uploadHint = computed(() => uploadReady.value
  ? 'JPG / PNG / GIF / WebP，最大 2MB，会自动裁成正方形。'
  : (wizard.upload?.reason || '这台服务器暂时传不了图片，只能填图片链接。'))

function pickFile() {
  if (uploading.value || !uploadReady.value) return
  fileInput.value?.click()
}

async function onFile(event) {
  const file = event.target.files?.[0]
  event.target.value = ''
  if (!file) return

  uploading.value = true
  uploadError.value = ''
  try {
    form.logo = await uploadLogo(file)
  } catch (err) {
    uploadError.value = err.message
  } finally {
    uploading.value = false
  }
}

function useDefault() {
  form.logo = ''
  uploadError.value = ''
}

async function save() {
  await submit('site', { ...form })
}
</script>

<template>
  <StepHead icon="site" title="站点信息" lead="这些内容会出现在页面上，装完之后也能改。" />

  <form @submit.prevent="save">
    <div class="fields">
      <div class="field">
        <label for="site_name">站点名</label>
        <input id="site_name" v-model="form.name" class="input" maxlength="60" required>
        <div class="hint">出现在登录页、页面标题和邮件里。</div>
      </div>

      <div class="field">
        <label for="base_url">访问地址</label>
        <input id="base_url" v-model="form.base_url" class="input" type="url" required>
        <div class="hint">带协议，例如 https://id.example.com。装完发信和跳转都用它。</div>
      </div>

      <div class="field">
        <span class="field-label">站点图标</span>
        <div class="avatar">
          <div class="avatar__preview" :class="{ 'is-busy': uploading }">
            <img :src="preview" alt="" width="72" height="72">
            <span v-if="uploading" class="avatar__spinner" aria-hidden="true"></span>
          </div>
          <div class="avatar__body">
            <div class="avatar__actions">
              <input ref="fileInput" class="upload-input" type="file"
                accept="image/jpeg,image/png,image/gif,image/webp" @change="onFile">
              <button class="btn btn-ghost btn-sm" type="button" :disabled="uploading || !uploadReady"
                @click="pickFile">上传图片</button>
              <button class="btn btn-ghost btn-sm" type="button" :disabled="uploading || form.logo === ''"
                @click="useDefault">用默认图标</button>
            </div>
            <p class="avatar__hint">{{ uploadHint }}</p>
            <p v-if="uploadError" class="avatar__error" role="alert">{{ uploadError }}</p>
          </div>
        </div>
      </div>

      <div class="field">
        <label for="language_default">默认语言</label>
        <select id="language_default" v-model="form.language_default" class="input">
          <option v-for="language in wizard.languages" :key="language.slug" :value="language.slug">
            {{ language.name }} · {{ language.english }}
          </option>
        </select>
        <div class="hint">浏览器语言匹配不上时用它。</div>
      </div>

      <div class="field">
        <label class="switch">
          <input v-model="form.language_allow_switch" type="checkbox">
          <span class="switch__track"></span>
          <span>允许访客切换界面语言</span>
        </label>
      </div>
      <div class="field">
        <label for="site_logo">图标链接（可选）</label>
        <input id="site_logo" v-model="form.logo" class="input" maxlength="500"
          placeholder="https://example.com/icon.png">
        <div class="hint">不想上传的话可以填完整 URL 或 / 开头的站内路径；留空就用默认的 Nomen 图标。</div>
      </div>
    </div>

    <details class="more" :open="optionalOpen">
      <summary>可选：页脚版权和联系邮箱</summary>
      <div class="fields">
        <div class="field">
          <label for="site_copyright">页脚版权</label>
          <input id="site_copyright" v-model="form.copyright" class="input" maxlength="200"
            placeholder="© 2026 Example">
          <div class="hint">留空就不显示版权行。</div>
        </div>
        <div class="field">
          <label for="support_email">对外支持邮箱</label>
          <input id="support_email" v-model="form.support_email" class="input" type="email">
          <div class="hint">登录页、恢复页给访客看的联系地址。留空就不显示。</div>
        </div>
        <div class="field">
          <label for="admin_notify_email">管理员通知邮箱</label>
          <input id="admin_notify_email" v-model="form.admin_notify_email" class="input" type="email">
          <div class="hint">新恢复工单的提醒发到这里。留空就不发提醒。</div>
        </div>
      </div>
    </details>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        继续
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('db')">← 上一步</button>
      <span></span>
    </div>
  </form>
</template>
