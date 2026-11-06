const realFetch = window.fetch.bind(window)

function json(data) {
  return Promise.resolve(new Response(JSON.stringify(data), {
    status: 200,
    headers: { 'Content-Type': 'application/json' },
  }))
}

window.fetch = (input, init) => {
  const url = typeof input === 'string' ? input : input.url

  // ===== 应用区 SPA（/dev/app.html）=====
  if (url === '/api/app/dashboard') return json(pageShell(dashboardPage))
  if (url === '/api/app/settings') return json(pageShell(settingsPage))
  if (url === '/api/app/family') return json(pageShell(familyPage))
  if (url.startsWith('/api/app/notifications/')) {
    const id = parseInt(url.split('/').pop(), 10) || 123
    return json(pageShell(notificationDetail(id)))
  }
  if (url.startsWith('/api/app/notifications')) {
    const page = parseInt(new URL(url, location.origin).searchParams.get('page'), 10) || 1
    return json(pageShell(notificationsPage(page)))
  }
  if (url.startsWith('/dashboard/revoke/')) {
    return json({ success: true, message: '已撤销该应用的授权（预览）', data: { authorizations: [] } })
  }
  if (url.startsWith('/family/')) {
    return json({ success: true, message: '操作成功（预览）', data: familyPage.data })
  }
  if (url === '/notifications/read-all') return json({ success: true, message: '已全部标记为已读' })
  if (url === '/notifications/read') return json({ success: true })

  if (url.startsWith('/api/check-username')) {
    const name = new URL(url, location.origin).searchParams.get('username') || ''
    const taken = ['admin', 'root', 'test', 'caellab', 'yunyun'].includes(name)
    return json({
      available: !taken,
      message: taken ? '这个用户名已经被占用' : '用户名可以使用',
    })
  }

  if (url.startsWith('/api/consent-age')) {
    const code = new URL(url, location.origin).searchParams.get('country') || ''
    const ages = { CN: 14, US: 13, GB: 13, FR: 15, TH: 10, BR: 12, IN: 18, JP: 16, KR: 14, AT: 14, ES: 14, IT: 14 }
    const age = ages[code] || 16
    return json({
      country: code,
      age,
      kind: 'none',
      label: '',
      url: null,
      policy_url: 'https://policy.caellab.com/id/privacy',
    })
  }

  if (url.startsWith('/login/send-code')) {
    const body = init && init.body instanceof FormData ? init.body : null
    const email = body ? String(body.get('email') || '') : ''
    if (body && (body.get('account_id') || body.get('send_to_id'))) {
      return json({ success: true, message: '验证码已发送（预览）' })
    }
    if (email.includes('account')) {
      return json({
        success: false,
        need_account_choice: true,
        message: '这个邮箱对应多个账号，请选择要登录哪一个',
        accounts: [
          { id: 1, username: 'yunyun', display_name: '云云', avatar: null, as_primary: true },
          { id: 2, username: 'yunyun_backup', display_name: '', avatar: null, as_primary: false },
        ],
      })
    }
    if (email.includes('address')) {
      return json({
        success: false,
        need_address_choice: true,
        message: '这个账号绑了多个邮箱，请选择发到哪一个',
        addresses: [
          { id: 11, masked: 'y*********@q*.com', is_primary: true },
          { id: 12, masked: 'i*********@c*.com', is_primary: false },
        ],
      })
    }
    return json({ success: true, message: '验证码已发送（预览）' })
  }

  if (url.startsWith('/2fa/verify/send-code')) {
    return json({ success: true, message: '验证码已发送（预览）' })
  }

  if (url === '/2fa/verify') {
    return json({ success: false, message: '验证码错误（预览）' })
  }

  if (url === '/2fa/totp/status') {
    return json({ success: true, enabled: false })
  }

  if (url === '/2fa/totp/setup') {
    return json({
      success: true,
      has_totp: false,
      secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP',
      uri: 'otpauth://totp/Nomen:yunyun?secret=JBSWY3DPEHPK3PXP&issuer=Nomen',
    })
  }

  if (url === '/2fa/totp/verify') {
    return json({ success: true, message: 'TOTP 已启用（预览）', recovery_codes: ['AAAA-BBBB-CCCC', 'DDDD-EEEE-FFFF'] })
  }

  if (url === '/2fa/recovery-codes') {
    return json({
      success: true,
      batches: [
        { batch_id: 'abcdef1234567890abcd', created_at: '2026-11-04 10:00:00', unused_count: 7, total_count: 10 },
      ],
    })
  }

  if (url === '/2fa/recovery-codes/generate') {
    return json({ success: true, message: '恢复码已生成（预览）', codes: ['1111-2222-3333', '4444-5555-6666', '7777-8888-9999'] })
  }

  if (url === '/settings/biometrics') {
    return json({
      success: true,
      credentials: [
        { id: 3, device_type: 'platform', created_at: '2026-10-09 21:30:00' },
      ],
    })
  }

  if (url === '/settings/temp-keys') {
    if (init && init.method === 'POST') {
      return json({ success: true, message: '临时密钥已保存（预览）' })
    }
    return json({
      success: true,
      keys: [
        { id: 1, label: '学校电脑', expires_at: '2026-11-20 08:00:00', created_at: '2026-11-04 20:00:00', last_used_at: null },
        { id: 2, label: '旧密钥', expires_at: '2026-10-01 08:00:00', created_at: '2026-09-20 20:00:00', last_used_at: '2026-09-25 12:00:00' },
      ],
    })
  }

  if (url === '/settings/temp-keys/generate') {
    return json({ success: true, key: 'tpk_a1b2c3d4e5f6g7h8' })
  }

  if (url === '/settings/profile' || url === '/settings/password' || url === '/settings/two-factor/policy'
    || url === '/settings/quick-login' || url === '/settings/notifications' || url === '/settings/devices/revoke'
    || url === '/settings/emails/add' || url === '/settings/emails/resend' || url === '/settings/emails/primary'
    || url === '/settings/emails/remove' || url === '/settings/password/send-code' || url === '/settings/password/reset-code'
    || url === '/settings/avatar' || url === '/settings/biometrics/delete' || url === '/settings/temp-keys/delete'
    || url === '/2fa/totp/disable' || url === '/2fa/recovery-codes/delete') {
    const success = !(init && init.body instanceof FormData && init.body.get && init.body.get('current_password') === 'wrong')
    return json({ success, message: success ? '已保存（预览）' : '当前密码不正确', emails: [
      { id: 1, email: 'yunyun@caellab.com', is_primary: true, verified: true },
      { id: 2, email: 'backup@caellab-test.com', is_primary: false, verified: true },
    ] })
  }

  if (url.startsWith('/family')) {
    return new Response('<html><body><div class="container"><script type="application/json" id="family-props">{"csrf":"dev-csrf","user":{"id":1,"age":16,"hasOwnEmail":true,"accountAgeOk":true,"claimPendingMasked":"p****@q*.com"},"membership":{"status":"active","label":"已加入"},"guardians":[{"username":"parent_user","email":"parent@qq.com","user_id":2,"relationship":"mother"}],"groups":[],"childrenByGroup":{},"invitations":[],"releaseCheck":{"ok":true},"releaseReasons":{},"consentAge":16,"minDays":180,"relationships":{"father":"爸爸","mother":"妈妈","other":"家长"},"claimPending":"","resetChild":0,"error":"","success":"操作成功（预览）"}</scr' + 'ipt></div></body></html>', {
      status: 200,
      headers: { 'Content-Type': 'text/html' },
    })
  }

  if (url.startsWith('/biometric/auth/options')) {
    return json({ success: false, message: '本机没有可用的生物识别凭证（预览）' })
  }

  return realFetch(input, init)
}

// ===== SPA 的页面数据（app.html harness 用） =====
const pageShell = (page) => ({
  ok: true,
  viewer: {
    id: 1, username: 'yunyun', displayName: '云云', avatarUrl: '',
    nav: [
      { key: 'notifications', label: '消息中心', href: '/notifications', icon: 'bell', spa: true, order: 5 },
      { key: 'dashboard', label: '仪表盘', href: '/dashboard', icon: 'chart', spa: true, order: 20 },
      { key: 'settings', label: '设置', href: '/settings', icon: 'sliders', spa: true, order: 30 },
    ],
    isAdmin: false, unreadCount: 2,
    latestUnread: null,
  },
  brand: { name: 'Nomen', logo: '/assets/brand/nomen-icon.svg', url: 'http://localhost:8080', copyright: '' },
  csrf: 'dev-csrf',
  locales: [
    { slug: 'zh-hans', name: '简体中文', code: 'zh-CN' },
    { slug: 'en', name: 'English', code: 'en' },
  ],
  page,
})

const dashboardPage = {
  page: 'dashboard',
  data: {
    user: { display_name: '云云', username: 'yunyun', avatar_url: '' },
    authorizations: [
      { client_id: 'dev-client-1', client_name: '轻之舟百科', icon: '', developer_icon: '', homepage_url: 'https://130.wiki', scopes: 'openid profile email', updated_at: '2026-11-04 21:40' },
    ],
    recentActivity: [
      { label: '登录成功', icon: 'log-in', device_name: 'Chrome · Windows', created_at: '2026-11-04 22:10' },
    ],
    success: '',
    error: '',
    redirectedFromHome: false,
  },
}

const settingsPage = {
  page: 'settings',
  data: {
    user: {
      username: 'yunyun', email: 'yunyun@caellab.com', displayName: '云云', bio: '虚舟实验室创始人',
      avatarUrl: '', birthDateDisplay: '2010年01月01日', genderDisplay: '男性', countryDisplay: 'CN',
      createdAtDisplay: '2025-07-05 12:00', twoFactorPolicy: 'new_device', quickLogin: true,
      contactVerified: true, assistLabel: '快速辅助验证方式', error: '', success: '',
    },
    emails: [
      { id: 1, email: 'yunyun@caellab.com', is_primary: true, verified: true },
    ],
    maxEmails: 5,
    verifiedEmails: [{ email: 'yunyun@caellab.com', is_primary: true }],
    notifSettings: { notify_system_important: true, notify_system_general: true, notify_system_interact: false },
    locales: [
      { slug: 'zh-hans', name: '简体中文', english: 'Simplified Chinese' },
      { slug: 'en', name: 'English', english: 'English' },
    ],
    currentLocale: 'zh-hans',
    activeTokens: [
      { deviceId: 'dev-1', deviceName: 'Chrome · Windows', isCurrent: true, meta: 'IP: 1.2.3.4 · 首次: 2026-11-01 09:00 · 最近: 2026-11-04 22:00' },
    ],
    trustedDevices: [
      { deviceId: 'dev-1', deviceName: 'Chrome · Windows', isCurrent: true, meta: 'IP: 1.2.3.4 · 信任至: 2027-01-30 09:00' },
    ],
    urls: {
      avatar: '/settings/avatar', profile: '/settings/profile', password: '/settings/password',
      sendResetCode: '/settings/password/send-code', resetCode: '/settings/password/reset-code',
      revokeDevice: '/settings/devices/revoke',
      tempKeys: { list: '/settings/temp-keys', create: '/settings/temp-keys', remove: '/settings/temp-keys/delete', generate: '/settings/temp-keys/generate' },
    },
  },
}

const familyPage = {
  page: 'family',
  data: {
    user: { id: 1, age: 16, hasOwnEmail: true, accountAgeOk: true, claimPendingMasked: '' },
    membership: { status: 'active', label: '已加入' },
    guardians: [{ username: 'parent_user', email: 'parent@qq.com', user_id: 2, relationship: 'mother' }],
    groups: [],
    childrenByGroup: {},
    invitations: [],
    releaseCheck: { ok: true },
    releaseReasons: { no_own_email: '要先绑定一个属于你自己的邮箱', not_claimed: '家长还没有加入' },
    consentAge: 16,
    minDays: 180,
    relationships: { father: '爸爸', mother: '妈妈', other: '家长' },
    claimPending: '',
    resetChild: 0,
    error: '',
    success: '',
  },
}

const notificationsPage = (page = 1) => ({
  page: 'notifications',
  data: {
    items: [
      { id: 123, category: 'system_important', categoryLabel: '系统重要通知', title: '安全提醒：你启用了 TOTP', excerpt: '你成功启用了验证器 APP…', read: false, createdAt: '2026-11-04 21:00' },
      { id: 122, category: 'system_general', categoryLabel: '系统一般通知', title: '欢迎来到 Nomen', excerpt: '这是你的第一条消息…', read: true, createdAt: '2026-11-01 09:00' },
    ],
    page,
    totalPages: 1,
    total: 2,
    unreadCount: 1,
    error: '',
    success: '',
  },
})

const notificationDetail = (id) => ({
  page: 'notification-detail',
  data: {
    notification: {
      id, category: 'system_important', categoryLabel: '系统重要通知',
      title: '安全提醒：你启用了 TOTP', body: '你成功启用了验证器 APP。如非本人操作请立即修改密码。',
      link: '', linkText: '', createdAt: '2026-11-04 21:00',
    },
  },
})
