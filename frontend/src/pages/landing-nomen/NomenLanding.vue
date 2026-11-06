<script setup>
import { useT } from '../../composables/useT.js'

const props = defineProps({
  brand: { type: String, default: 'Nomen' },
  loggedIn: { type: Boolean, default: false },
  docsBase: { type: String, default: '' },
  urls: { type: Object, default: () => ({}) },
})

const { t } = useT()

const urls = {
  register: props.urls?.register || '/register',
  login: props.urls?.login || '/login',
  dashboard: props.urls?.dashboard || '/dashboard',
  settings: props.urls?.settings || '/settings',
}

const features = [
  { title: t('统一登录'), text: t('一个账号登录接入的应用。') },
  { title: t('多因素验证'), text: t('TOTP、邮件验证码、恢复码和通行密钥。') },
  { title: t('账号恢复'), text: t('提交申诉，管理员审核后发一次性登录链接。') },
  { title: t('开放平台'), text: t('开发者注册并创建应用，管理员也能在后台直接建。') },
]

const docsBase = (props.docsBase || '').replace(/\/$/, '')

const links = docsBase
  ? [
      { label: t('安装与升级'), href: docsBase + '/install' },
      { label: t('应用接入'), href: docsBase + '/oauth' },
      { label: t('插件'), href: docsBase + '/plugins' },
      { label: t('皮肤'), href: docsBase + '/skins' },
      { label: t('源码'), href: docsBase + '/source' },
    ]
  : []
</script>

<template>
  <section class="np-hero">
    <svg
      class="np-hero__scene"
      viewBox="0 0 640 640"
      aria-hidden="true"
      focusable="false"
    >
      <g class="np-scene__orbits">
        <circle cx="360" cy="320" r="120" />
        <circle cx="360" cy="320" r="210" />
        <circle cx="360" cy="320" r="300" />
      </g>
      <g class="np-scene__links">
        <path d="M360 320 Q 300 290 247 279" />
        <path d="M360 320 Q 420 280 464 260" />
        <path d="M360 320 Q 340 420 324 527" />
        <path d="M360 320 Q 460 360 557 392" />
        <path d="M360 320 Q 220 360 78 423" />
        <path d="M360 320 Q 450 200 510 60" />
      </g>
      <g class="np-scene__nodes">
        <circle cx="247" cy="279" r="5" />
        <circle cx="464" cy="260" r="5" />
        <circle cx="324" cy="527" r="5" />
        <circle cx="557" cy="392" r="5" />
        <circle cx="78" cy="423" r="5" />
        <circle cx="510" cy="60" r="5" />
      </g>
      <g class="np-scene__core">
        <circle cx="360" cy="320" r="38" class="np-scene__core-ring" />
        <circle cx="360" cy="320" r="6" class="np-scene__core-dot" />
      </g>
    </svg>

    <div class="np-hero__inner">
      <h1 class="np-hero__title">{{ props.brand }}</h1>
      <p class="np-hero__line">{{ t('自托管的统一登录服务') }}</p>

      <div class="np-hero__actions">
        <template v-if="props.loggedIn">
          <a class="btn btn-primary btn-lg" :href="urls.dashboard">{{ t('进入控制台') }}</a>
          <a class="btn btn-ghost btn-lg" :href="urls.settings">{{ t('账户设置') }}</a>
        </template>
        <template v-else>
          <a class="btn btn-primary btn-lg" :href="urls.register">{{ t('创建账号') }}</a>
          <a class="btn btn-ghost btn-lg" :href="urls.login">{{ t('登录') }}</a>
        </template>
      </div>
    </div>
  </section>

  <section class="np-section">
    <h2>{{ t('能做什么') }}</h2>
    <dl class="np-features">
      <div v-for="feature in features" :key="feature.title" class="np-feature">
        <dt>{{ feature.title }}</dt>
        <dd>{{ feature.text }}</dd>
      </div>
    </dl>
  </section>

  <section v-if="links.length" class="np-section">
    <h2>{{ t('文档') }}</h2>
    <ul class="np-links">
      <li v-for="link in links" :key="link.href">
        <a :href="link.href">{{ link.label }}</a>
      </li>
    </ul>
  </section>
</template>
