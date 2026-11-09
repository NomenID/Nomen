<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

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
  { title: t('Unified sign-in'), text: t('One account for every connected app.') },
  { title: t('Multi-factor authentication'), text: t('TOTP, email codes, recovery codes and passkeys.') },
  { title: t('Account recovery'), text: t('Submit a request; an admin reviews it and sends a one-time sign-in link.') },
  { title: t('Open Platform'), text: t('Developers can register and create apps; admins can also create them in the backend.') },
]

const docsBase = (props.docsBase || '').replace(/\/$/, '')

const links = docsBase
  ? [
      { label: t('Install and upgrade'), href: docsBase + '/install' },
      { label: t('Integrations'), href: docsBase + '/oauth' },
      { label: t('Plugins'), href: docsBase + '/plugins' },
      { label: t('Skins'), href: docsBase + '/skins' },
      { label: t('Source code'), href: docsBase + '/source' },
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
      <p class="np-hero__line">{{ t('A galaxy of free accounts') }}</p>

      <div class="np-hero__actions">
        <template v-if="props.loggedIn">
          <a class="btn btn-primary btn-lg" :href="urls.dashboard">{{ t('Open console') }}</a>
          <a class="btn btn-ghost btn-lg" :href="urls.settings">{{ t('Account settings') }}</a>
        </template>
        <template v-else>
          <a class="btn btn-primary btn-lg" :href="urls.register">{{ t('Create account') }}</a>
          <a class="btn btn-ghost btn-lg" :href="urls.login">{{ t('Sign in') }}</a>
        </template>
      </div>
    </div>
  </section>

  <section class="np-section">
    <h2>{{ t('What {:sitename} can do') }}</h2>
    <dl class="np-features">
      <div v-for="feature in features" :key="feature.title" class="np-feature">
        <dt>{{ feature.title }}</dt>
        <dd>{{ feature.text }}</dd>
      </div>
    </dl>
  </section>

  <section v-if="links.length" class="np-section">
    <h2>{{ t('Docs') }}</h2>
    <ul class="np-links">
      <li v-for="link in links" :key="link.href">
        <a :href="link.href">{{ link.label }}</a>
      </li>
    </ul>
  </section>
</template>
