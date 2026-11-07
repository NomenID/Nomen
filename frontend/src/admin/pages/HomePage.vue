<script setup>
import { onMounted, reactive, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminField from '../components/AdminField.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminBadge from '../components/AdminBadge.vue'

const loading = ref(true)
const saving = ref(false)
const error = ref('')
const data = ref(null)

const form = reactive({
  mode: 'default',
  page_slug: '',
  template: '',
  title: '',
  subtitle: '',
  primary_label: '',
  primary_url: '',
  secondary_label: '',
  secondary_url: '',
  hidden_links: [],
  links: [],
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/homepage')
    Object.assign(form, data.value.settings)
    form.hidden_links = data.value.default_links.filter((link) => link.hidden).map((link) => link.id)
    form.links = (data.value.custom_links || []).map((link) => ({ label: link.label, href: link.href }))
  } catch (err) {
    error.value = '首页设置加载失败'
  } finally {
    loading.value = false
  }
}

async function save() {
  saving.value = true
  try {
    const result = await apiPost('/admin/homepage', {
      ...form,
      hidden_links: JSON.stringify(form.hidden_links),
      links_json: JSON.stringify(form.links),
    })
    window.Nomen.toast(result.message, result.success ? 'success' : 'error')
    if (result.success) await load()
  } finally {
    saving.value = false
  }
}

function toggleDefaultLink(id, event) {
  const hidden = new Set(form.hidden_links)
  if (event.target.checked) {
    hidden.delete(id)
  } else {
    hidden.add(id)
  }
  form.hidden_links = [...hidden]
}

onMounted(load)
</script>

<template>
  <AdminPage title="首页" description="换标题、按钮或整页模板，不需要改皮肤。">
    <template #actions>
      <a class="a-btn a-btn--ghost" href="/" target="_blank" rel="noopener">打开首页</a>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <form v-else-if="data" @submit.prevent="save">
      <AdminCard title="展示方式">
        <div class="a-radios">
          <label v-for="mode in data.modes" :key="mode.value" class="a-radio" :class="{ 'is-active': form.mode === mode.value }">
            <input v-model="form.mode" type="radio" :value="mode.value">
            <span>
              <strong>{{ mode.label }}</strong>
              <span class="a-sub a-block">{{ mode.hint }}</span>
            </span>
          </label>
        </div>

        <div v-if="form.mode === 'page'" class="a-form" style="margin-top:14px">
          <AdminField v-if="data.pages.length" label="页面" hint="只列出 Pages 里已发布的页面">
            <select v-model="form.page_slug" class="a-input a-input--narrow">
              <option value="">（不指定，回退到默认首页）</option>
              <option v-for="page in data.pages" :key="page.slug" :value="page.slug" :disabled="page.status !== 1">
                {{ page.title }}（/{{ page.slug }}{{ page.status === 1 ? '' : ' · 草稿' }}）
              </option>
            </select>
          </AdminField>
          <AdminAlert v-else type="info">
            还没有可用的页面。到「内容」里先写一篇，再回来选。
          </AdminAlert>
        </div>

        <div v-if="form.mode === 'template'" class="a-form" style="margin-top:14px">
          <AdminField v-if="data.templates.length" label="模板文件" hint="文件放在 config/ 下，文件名以 home 开头">
            <select v-model="form.template" class="a-input a-input--narrow">
              <option value="">（不指定，回退到默认首页）</option>
              <option v-for="name in data.templates" :key="name" :value="name">{{ name }}</option>
            </select>
          </AdminField>
          <AdminAlert v-else type="info">
            还没有自定义模板。把 <code>config/home.example.php</code> 复制成 <code>config/home-自己的名字.php</code>，刷新这一页就能选。
          </AdminAlert>
        </div>
      </AdminCard>

      <AdminCard title="文案与按钮" description="留空就用默认值：登录后是「进入控制台 / 账户设置」，未登录是「创建账号 / 登录」。">
        <div class="a-form">
          <div class="a-form__row">
            <AdminField label="标题" hint="默认用站点名称">
              <input v-model="form.title" class="a-input" type="text" maxlength="120">
            </AdminField>
            <AdminField label="副标题">
              <input v-model="form.subtitle" class="a-input" type="text" maxlength="200">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="主按钮文字">
              <input v-model="form.primary_label" class="a-input" type="text" maxlength="40">
            </AdminField>
            <AdminField label="主按钮地址" hint="站内路径以 / 开头，也可以填完整 https:// 地址">
              <input v-model="form.primary_url" class="a-input" type="text" placeholder="/register">
            </AdminField>
          </div>

          <div class="a-form__row">
            <AdminField label="次按钮文字">
              <input v-model="form.secondary_label" class="a-input" type="text" maxlength="40">
            </AdminField>
            <AdminField label="次按钮地址">
              <input v-model="form.secondary_url" class="a-input" type="text" placeholder="/login">
            </AdminField>
          </div>
        </div>
      </AdminCard>

      <AdminCard title="下方链接" description="按钮下面那一行小链接。默认三条可以单独隐藏，也可以加自己的；插件加的在这里只读。">
        <div class="a-form">
          <AdminField label="默认链接" hint="取消勾选就不显示；它们来自插件挂载和核心，改名请用下面的自定义链接代替">
            <div class="home-links-defaults">
              <label v-for="link in data.default_links" :key="link.id" class="a-check home-links-defaults__item">
                <input type="checkbox" :checked="!form.hidden_links.includes(link.id)" @change="toggleDefaultLink(link.id, $event)">
                <span>
                  <strong>{{ link.label }}</strong>
                  <span class="a-sub a-block">{{ link.href }}（{{ link.source }}）</span>
                </span>
              </label>
            </div>
          </AdminField>

          <AdminField label="自定义链接" hint="最多 20 条，按添加顺序显示">
            <div v-for="(link, index) in form.links" :key="index" class="a-form__row home-links-custom">
              <input v-model="link.label" class="a-input" type="text" maxlength="40" placeholder="名称">
              <input v-model="link.href" class="a-input" type="text" placeholder="/w 或 https://…">
              <button class="a-btn a-btn--ghost" type="button" @click="form.links.splice(index, 1)">删除</button>
            </div>
            <button class="a-btn a-btn--ghost a-btn--sm" type="button" @click="form.links.push({ label: '', href: '' })">添加链接</button>
          </AdminField>

          <AdminField label="插件链接" hint="插件运行时加进来的，这里改不了">
            <p v-if="!data.plugin_links.length" class="a-text a-text--muted">目前没有插件往这里加链接。</p>
            <div v-else class="home-links-defaults">
              <div v-for="(link, index) in data.plugin_links" :key="index" class="home-links-defaults__item">
                <strong>{{ link.label }}</strong>
                <span class="a-sub a-block a-mono">{{ link.href }}</span>
                <AdminBadge tone="info">{{ link.by ? `由 ${link.by} 托管` : '由插件托管' }}</AdminBadge>
              </div>
            </div>
          </AdminField>
        </div>
      </AdminCard>

      <div class="a-form__actions">
        <button class="a-btn a-btn--primary" type="submit" :disabled="saving">{{ saving ? '保存中…' : '保存首页设置' }}</button>
      </div>
    </form>
  </AdminPage>
</template>
