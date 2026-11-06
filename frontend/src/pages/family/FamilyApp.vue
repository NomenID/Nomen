<script setup>
import { computed, reactive, ref } from 'vue'
import BaseAlert from '../../components/BaseAlert.vue'
import { useT } from '../../composables/useT.js'
import { apiPost } from '../../app/api.js'
import { toast } from '../../lib/toast.js'

const { t } = useT()

const props = defineProps({
  csrf: { type: String, default: '' },
  user: { type: Object, default: () => ({}) },
  membership: { type: [Object, null], default: null },
  guardians: { type: Array, default: () => [] },
  groups: { type: Array, default: () => [] },
  childrenByGroup: { type: Object, default: () => ({}) },
  invitations: { type: Array, default: () => [] },
  releaseCheck: { type: Object, default: () => ({}) },
  releaseReasons: { type: Object, default: () => ({}) },
  consentAge: { type: Number, default: 16 },
  minDays: { type: Number, default: 180 },
  relationships: { type: Object, default: () => ({}) },
  claimPending: { type: String, default: '' },
  resetChild: { type: Number, default: 0 },
  error: { type: String, default: '' },
  success: { type: String, default: '' },
  urls: { type: Object, default: () => ({}) },
})

const statusLabels = {
  pending: t('邀请中'),
  active: t('已加入'),
  independent: t('已停止监护'),
  removed: t('已退出'),
}

const state = reactive({
  membership: props.membership,
  groups: props.groups,
  childrenByGroup: props.childrenByGroup,
  invitations: props.invitations,
  resetChild: props.resetChild,
  claimPending: props.claimPending,
  error: props.error,
  success: props.success,
})

const isChild = computed(() => state.membership !== null && state.membership !== undefined)
const isActive = computed(() => isChild.value && state.membership.status === 'active')
const isPending = computed(() => isChild.value && state.membership.status === 'pending')

const relationshipLabel = (key) => t(props.relationships[key] ?? '家长')

const checks = computed(() => {
  if (!isChild.value) return []
  const age = props.user.age
  return [
    {
      label: t('绑定一个属于你自己的邮箱'),
      ok: !!props.user.hasOwnEmail,
      note: t('现在收信用的是家长邮箱，停止监护前要先有自己的'),
      link: '/settings',
    },
    {
      label: t('家长已经加入你的家庭'),
      ok: isActive.value,
      note: t('邀请还没有被家长接受'),
    },
    {
      label: t('已满本地区可以独立使用的年龄（{:age} 岁）', { age: props.consentAge }),
      ok: age !== null && age >= props.consentAge,
      note: age === null ? t('账号没有出生日期记录') : t('当前记录为 {:age} 岁', { age }),
    },
    {
      label: t('账号创建已满 {:days} 天', { days: props.minDays }),
      ok: props.user.accountAgeOk,
      note: t('防止刚注册就脱离家长'),
    },
  ]
})

const childStatusText = (child) => {
  if (child.status === 'independent') return t('已停止监护')
  if (child.status === 'pending') return t('邀请中')
  if (Number(child.user_status) !== 1) return t('已锁定')
  return t('正常')
}

const childAgeText = (child) => {
  if (child.age === null || child.age === undefined) return t('出生日期未知')
  return t('{:age} 岁', { age: child.age })
}

const releaseReason = computed(() =>
  t(props.releaseReasons[props.releaseCheck?.reason ?? ''] ?? '条件还没齐'))

// ---- 提交：服务端对 AJAX 回 JSON（{success, message, data: 最新页面数据}），原地更新 ----
const busy = ref(false)

async function submit(action, fields, { confirm } = {}) {
  if (confirm) {
    const ok = await window.Nomen.confirm(confirm.title, confirm.message, {
      confirmText: confirm.ok || t('确定'),
      cancelText: t('取消'),
      danger: !!confirm.danger,
    })
    if (!ok) return
  }
  if (busy.value) return
  busy.value = true

  try {
    const res = await apiPost(action, fields)
    if (res.data) {
      state.membership = res.data.membership ?? null
      state.groups = res.data.groups ?? []
      state.childrenByGroup = res.data.childrenByGroup ?? {}
      state.invitations = res.data.invitations ?? []
      state.resetChild = res.data.resetChild ?? 0
      state.claimPending = res.data.claimPending ?? ''
      state.error = res.data.error || ''
      state.success = res.data.success || ''
    }
    toast(res.message || (res.success ? t('已完成') : t('操作失败')), res.success ? 'success' : 'error')
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (err) {
    toast(err.message || t('网络错误'), 'error')
  } finally {
    busy.value = false
  }
}

function post(action, fields, options) {
  return submit(action, fields, options)
}

const guardianEmail = ref('')
const guardianRelation = ref('other')
const inviteEmail = ref('')
const inviteRelation = ref('other')
const newChild = reactive({ username: '', birth_date: '', gender: 'female', password: '', password_confirm: '' })
const claimEmail = ref('')
const claimCode = ref('')
const resetForm = reactive({ code: '', new_password: '', confirm_password: '' })
</script>

<template>
  <div class="settings">
    <h1>{{ t('家庭') }}</h1>
    <p class="form-hint" style="margin-bottom:20px;">
      {{ t('一个账号可以被一位家长带着用：家长加入家庭后，能帮你重置密码、临时锁定账号，') }}{{ t('但看不到你的密码、读不到你的消息，也不能以你的身份登录。') }}
    </p>

    <BaseAlert v-if="state.error" type="error" :message="state.error" />
    <BaseAlert v-if="state.success" type="success" :message="state.success" />

    <!-- 发给你的邀请 -->
    <div v-if="state.invitations.length" class="settings-section">
      <h2>{{ t('发给你的邀请') }}</h2>
      <div v-for="invite in state.invitations" :key="invite.email" class="family-member">
        <div class="family-member__main">
          <span class="family-list__name">{{ invite.child_names || t('一个孩子账号') }}</span>
          <span class="family-list__meta">{{ t('邀请') }} <strong>{{ invite.email }}</strong> {{ t('成为家长') }}</span>
        </div>
        <form method="POST" action="/family/accept" @submit.prevent="post('/family/accept')">
          <button type="submit" class="btn btn-primary btn-sm">{{ t('加入') }}</button>
        </form>
      </div>
      <small class="form-hint" style="display:block;margin-top:10px;">{{ t('加入后你能帮 TA 重置登录密码、临时锁定账号。') }}</small>
    </div>

    <!-- 你作为家长的每个家庭 -->
    <div v-for="group in state.groups" :key="group.id" class="settings-section">
      <h2>{{ t('你的家庭') }}</h2>
      <p class="form-hint" style="margin-bottom:14px;">
        {{ t('这个家庭用的是邮箱') }} <strong>{{ group.guardian_email }}</strong>{{ t('。') }}
      </p>

      <h3 class="family-sub">{{ t('家长') }}</h3>
      <ul class="family-list">
        <li v-for="guardian in group.guardians" :key="guardian.email + guardian.user_id">
          <span class="family-list__name">
            {{ guardian.username || guardian.email }}
            <span v-if="Number(guardian.user_id) === Number(user.id)" class="family-tag">{{ t('你') }}</span>
          </span>
          <span class="family-list__meta">
            {{ relationshipLabel(guardian.relationship) }}
            · {{ guardian.user_id ? t('已加入') : t('邀请中（还没注册 Nomen）') }}
          </span>
        </li>
      </ul>

      <h3 class="family-sub">{{ t('孩子') }}</h3>
      <ul class="family-list">
        <li v-for="child in (state.childrenByGroup[group.id] || [])" :key="child.user_id" class="family-child">
          <span class="family-list__name">{{ child.username }}</span>
          <span class="family-list__meta">{{ childAgeText(child) }} · {{ childStatusText(child) }}</span>

          <div v-if="child.status === 'active'" class="family-actions">
            <form
              v-if="Number(child.user_status) === 1"
              method="POST"
              action="/family/child/status"
              @submit.prevent="post('/family/child/status', { child_id: child.user_id, op: 'lock' }, {
                confirm: {
                  title: t('锁定账号'),
                  message: t('锁定后 TA 会被立刻踢下线并且无法登录。确定吗？'),
                  ok: t('锁定'),
                },
              })"
            >
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('锁定') }}</button>
            </form>
            <form v-else method="POST" action="/family/child/status" @submit.prevent="post('/family/child/status', { child_id: child.user_id, op: 'unlock' })">
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('解锁') }}</button>
            </form>
            <form method="POST" action="/family/child/reset/send" @submit.prevent="post('/family/child/reset/send', { child_id: child.user_id })">
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('重置密码') }}</button>
            </form>
          </div>

          <form
            v-if="state.resetChild === Number(child.user_id)"
            method="POST"
            action="/family/child/reset"
            class="family-reset-form"
            @submit.prevent="post('/family/child/reset', { child_id: child.user_id, code: resetForm.code, new_password: resetForm.new_password, confirm_password: resetForm.confirm_password })"
          >
            <div class="form-group">
              <label>{{ t('验证码（已发到你的邮箱）') }}</label>
              <input v-model="resetForm.code" type="text" required maxlength="6" :placeholder="t('6 位验证码')">
            </div>
            <div class="form-group">
              <label>{{ t('给 TA 设置的新密码') }}</label>
              <div class="pw-wrap">
                <input v-model="resetForm.new_password" type="password" required minlength="8" :placeholder="t('至少8位，包含字母和数字')">
              </div>
            </div>
            <div class="form-group">
              <label>{{ t('确认新密码') }}</label>
              <input v-model="resetForm.confirm_password" type="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" :disabled="busy">{{ t('确认重置') }}</button>
          </form>
        </li>
        <li v-if="!(state.childrenByGroup[group.id] || []).length">
          <span class="family-list__meta">{{ t('这个家庭里还没有孩子账号') }}</span>
        </li>
      </ul>

      <h3 class="family-sub">{{ t('邀请另一位家长') }}</h3>
      <form
        method="POST"
        action="/family/guardian/add"
        class="family-inline-form"
        @submit.prevent="post('/family/guardian/add', { group_id: group.id, guardian_email: guardianEmail, relationship: guardianRelation })"
      >
        <input v-model="guardianEmail" type="email" required :placeholder="t('爸爸或妈妈的邮箱')">
        <select v-model="guardianRelation">
          <option v-for="(label, key) in relationships" :key="key" :value="key">{{ t(label) }}</option>
        </select>
        <button type="submit" class="btn btn-secondary">{{ t('邀请') }}</button>
      </form>
      <small class="form-hint">{{ t('对方用这条邮箱注册或登录 Nomen 后，在「家庭」页面就能加入。') }}</small>

      <h3 class="family-sub">{{ t('创建孩子账号') }}</h3>
      <form
        method="POST"
        action="/family/child/create"
        class="family-create-form"
        @submit.prevent="post('/family/child/create', { group_id: group.id, username: newChild.username, birth_date: newChild.birth_date, gender: newChild.gender, password: newChild.password, password_confirm: newChild.password_confirm })"
      >
        <div class="form-group">
          <label>{{ t('用户名') }}</label>
          <input v-model="newChild.username" type="text" required pattern="[a-z0-9_]{3,32}" :placeholder="t('小写字母、数字、下划线')">
        </div>
        <div class="form-group">
          <label>{{ t('出生日期') }}</label>
          <input v-model="newChild.birth_date" type="date" required>
        </div>
        <div class="form-group">
          <label>{{ t('性别') }}</label>
          <select v-model="newChild.gender" required>
            <option value="female">{{ t('女') }}</option>
            <option value="male">{{ t('男') }}</option>
            <option value="non-binary">{{ t('非二元') }}</option>
            <option value="prefer-not-to-say">{{ t('不愿透露') }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>{{ t('初始密码') }}</label>
          <div class="pw-wrap">
            <input v-model="newChild.password" type="password" required minlength="8" :placeholder="t('至少8位，包含字母和数字')">
          </div>
        </div>
        <div class="form-group">
          <label>{{ t('确认密码') }}</label>
          <input v-model="newChild.password_confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="busy">{{ t('创建账号') }}</button>
        <small class="form-hint">{{ t('创建后把用户名和初始密码交给孩子，让 TA 登录后自己改掉。') }}</small>
      </form>
    </div>

    <!-- 你是孩子 -->
    <div v-if="isChild" class="settings-section">
      <h2>{{ t('你的家长') }}</h2>
      <p class="family-status">
        {{ t('当前状态：') }}<strong>{{ statusLabels[state.membership.status] || state.membership.status }}</strong>
        <template v-if="isPending"> · {{ t('邀请已经发出，等家长接受') }}</template>
        <template v-else-if="isActive"> · {{ t('家长可以重置你的密码、临时锁定你的账号') }}</template>
      </p>

      <ul class="family-list">
        <li v-for="guardian in guardians" :key="guardian.email + guardian.user_id">
          <span class="family-list__name">{{ guardian.username || guardian.email }}</span>
          <span class="family-list__meta">
            {{ relationshipLabel(guardian.relationship) }}
            · {{ guardian.user_id ? t('已加入') : t('邀请中（还没注册 Nomen）') }}
          </span>
        </li>
        <li v-if="!guardians.length"><span class="family-list__meta">{{ t('还没有邀请家长') }}</span></li>
      </ul>

      <template v-if="isActive">
        <h3 class="family-sub">{{ t('家长能做什么') }}</h3>
        <ul class="family-perms">
          <li class="is-can">{{ t('帮你重置登录密码') }}</li>
          <li class="is-can">{{ t('临时锁定 / 解锁你的账号') }}</li>
          <li class="is-cannot">{{ t('看到你的密码') }}</li>
          <li class="is-cannot">{{ t('读你的站内消息或邮件') }}</li>
          <li class="is-cannot">{{ t('以你的身份登录') }}</li>
        </ul>
      </template>

      <template v-if="isPending || isActive">
        <h3 class="family-sub">{{ t('邀请家长') }}</h3>
        <form
          method="POST"
          action="/family/invite-parent"
          class="family-inline-form"
          @submit.prevent="post('/family/invite-parent', { guardian_email: inviteEmail, relationship: inviteRelation })"
        >
          <input v-model="inviteEmail" type="email" required :placeholder="t('家长或监护人的邮箱')">
          <select v-model="inviteRelation">
            <option v-for="(label, key) in relationships" :key="key" :value="key">{{ t(label) }}</option>
          </select>
          <button type="submit" class="btn btn-secondary">{{ t('发邀请') }}</button>
        </form>
        <small class="form-hint">{{ t('家长用这条邮箱注册或登录后，在「家庭」页面就能加入。') }}</small>

        <h3 class="family-sub">{{ t('停止监护') }}</h3>
        <ul class="family-checks">
          <li v-for="check in checks" :key="check.label" :class="check.ok ? 'is-ok' : 'is-todo'">
            <span class="family-checks__mark">{{ check.ok ? '✓' : '·' }}</span>
            <span>
              {{ check.label }}
              <small v-if="!check.ok && check.note" class="form-hint">{{ check.note }}</small>
              <a v-if="!check.ok && check.link" :href="check.link">{{ t('去设置') }}</a>
            </span>
          </li>
        </ul>

        <form method="POST" action="/family/release" @submit.prevent="post('/family/release', {}, {
          confirm: {
            title: t('停止监护'),
            message: t('停止监护后，家长不能再重置你的密码或锁定你的账号。确定吗？'),
            ok: t('停止监护'),
          },
        })">
          <button type="submit" class="btn btn-primary" :disabled="busy || !releaseCheck.ok">{{ t('停止监护') }}</button>
          <small v-if="!releaseCheck.ok" class="form-hint">{{ releaseReason }}</small>
        </form>
      </template>
      <template v-else>
        <form method="POST" action="/family/leave" @submit.prevent="post('/family/leave', {}, {
          confirm: {
            title: t('退出家庭'),
            message: t('退出后家长就看不到你了。确定吗？'),
            ok: t('退出'),
            danger: true,
          },
        })">
          <button type="submit" class="btn btn-secondary">{{ t('退出家庭') }}</button>
        </form>
      </template>
    </div>

    <!-- 家长入口（还没有家庭） -->
    <div v-if="!state.groups.length" class="settings-section">
      <h2>{{ t('家长入口') }}</h2>
      <p v-if="state.invitations.length" class="form-hint">
        {{ t('上面的邀请点「加入」即可；如果邀请发给了你另一条邮箱，用那条邮箱收验证码在这里加入。') }}
      </p>
      <p v-else class="form-hint" style="margin-bottom:16px;">
        {{ t('孩子注册时填了你的邮箱、或者邀请了你，这里就会出现邀请。') }}{{ t('用那条邮箱注册过 Nomen 的话，登录后就能直接接受。') }}
      </p>

      <form
        v-if="!state.claimPending"
        method="POST"
        action="/family/claim/send"
        class="family-inline-form"
        @submit.prevent="post('/family/claim/send', { guardian_email: claimEmail })"
      >
        <input v-model="claimEmail" type="email" required :placeholder="t('收到邀请的那条邮箱')">
        <button type="submit" class="btn btn-secondary">{{ t('发送验证码') }}</button>
      </form>
      <template v-else>
        <form method="POST" action="/family/claim" class="family-inline-form" @submit.prevent="post('/family/claim', { code: claimCode })">
          <input v-model="claimCode" type="text" required maxlength="6" :placeholder="t('6 位验证码')">
          <button type="submit" class="btn btn-primary">{{ t('加入') }}</button>
        </form>
        <small class="form-hint">
          {{ t('验证码已发到') }} {{ user.claimPendingMasked }}{{ t('，10 分钟内有效。') }}
        </small>
      </template>
    </div>
  </div>
</template>
