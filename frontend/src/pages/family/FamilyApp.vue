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
  pending: t('Invitation pending'),
  active: t('Joined'),
  independent: t('Supervision ended'),
  removed: t('Left'),
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

const relationshipLabel = (key) => t(props.relationships[key] ?? 'Parent')

const checks = computed(() => {
  if (!isChild.value) return []
  const age = props.user.age
  return [
    {
      label: t('Bind an email you own'),
      ok: !!props.user.hasOwnEmail,
      note: t('Notifications currently go to a parent\'s address — you need your own before ending supervision'),
      link: '/settings',
    },
    {
      label: t('A parent has already joined your family'),
      ok: isActive.value,
      note: t('The invitation hasn\'t been accepted yet'),
    },
    {
      label: t('Past your region\'s independent-use age ({:age})', { age: props.consentAge }),
      ok: age !== null && age >= props.consentAge,
      note: age === null ? t('No date of birth on record for this account') : t('Currently recorded as age {:age}', { age }),
    },
    {
      label: t('Account is older than {:days} days', { days: props.minDays }),
      ok: props.user.accountAgeOk,
      note: t('Prevents leaving a parent right after sign-up'),
    },
  ]
})

const childStatusText = (child) => {
  if (child.status === 'independent') return t('Supervision ended')
  if (child.status === 'pending') return t('Invitation pending')
  if (Number(child.user_status) !== 1) return t('Locked')
  return t('Normal')
}

const childAgeText = (child) => {
  if (child.age === null || child.age === undefined) return t('Date of birth unknown')
  return t('age {:age}', { age: child.age })
}

const releaseReason = computed(() =>
  t(props.releaseReasons[props.releaseCheck?.reason ?? ''] ?? '条件还没齐'))

// ---- 提交：服务端对 AJAX 回 JSON（{success, message, data: 最新页面数据}），原地更新 ----
const busy = ref(false)

async function submit(action, fields, { confirm } = {}) {
  if (confirm) {
    const ok = await window.Nomen.confirm(confirm.title, confirm.message, {
      confirmText: confirm.ok || t('OK'),
      cancelText: t('Cancel'),
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
    toast(res.message || (res.success ? t('Done') : t('Operation failed')), res.success ? 'success' : 'error')
    window.scrollTo({ top: 0, behavior: 'smooth' })
  } catch (err) {
    toast(err.message || t('Network error'), 'error')
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
    <h1>{{ t('Family') }}</h1>
    <p class="form-hint" style="margin-bottom:20px;">
      {{ t('A parent can look after the account: once they join the family, they can reset your password or lock it temporarily,') }}{{ t('but they can\'t see your password, read your messages, or sign in as you.') }}
    </p>

    <BaseAlert v-if="state.error" type="error" :message="state.error" />
    <BaseAlert v-if="state.success" type="success" :message="state.success" />

    <!-- 发给你的邀请 -->
    <div v-if="state.invitations.length" class="settings-section">
      <h2>{{ t('Invitations for you') }}</h2>
      <div v-for="invite in state.invitations" :key="invite.email" class="family-member">
        <div class="family-member__main">
          <span class="family-list__name">{{ invite.child_names || t('One child account') }}</span>
          <span class="family-list__meta">{{ t('Invite') }} <strong>{{ invite.email }}</strong> {{ t('Become a parent') }}</span>
        </div>
        <form method="POST" action="/family/accept" @submit.prevent="post('/family/accept')">
          <button type="submit" class="btn btn-primary btn-sm">{{ t('Join') }}</button>
        </form>
      </div>
      <small class="form-hint" style="display:block;margin-top:10px;">{{ t('After joining you can reset their sign-in password or lock the account temporarily.') }}</small>
    </div>

    <!-- 你作为家长的每个家庭 -->
    <div v-for="group in state.groups" :key="group.id" class="settings-section">
      <h2>{{ t('Your family') }}</h2>
      <p class="form-hint" style="margin-bottom:14px;">
        {{ t('This family uses the email') }} <strong>{{ group.guardian_email }}</strong>{{ t('.') }}
      </p>

      <h3 class="family-sub">{{ t('Parent') }}</h3>
      <ul class="family-list">
        <li v-for="guardian in group.guardians" :key="guardian.email + guardian.user_id">
          <span class="family-list__name">
            {{ guardian.username || guardian.email }}
            <span v-if="Number(guardian.user_id) === Number(user.id)" class="family-tag">{{ t('you') }}</span>
          </span>
          <span class="family-list__meta">
            {{ relationshipLabel(guardian.relationship) }}
            · {{ guardian.user_id ? t('Joined') : t('Pending (no Nomen yet)') }}
          </span>
        </li>
      </ul>

      <h3 class="family-sub">{{ t('Child') }}</h3>
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
                  title: t('Lock account'),
                  message: t('They\'ll be signed out immediately and unable to sign in. Are you sure?'),
                  ok: t('Lock'),
                },
              })"
            >
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('Lock') }}</button>
            </form>
            <form v-else method="POST" action="/family/child/status" @submit.prevent="post('/family/child/status', { child_id: child.user_id, op: 'unlock' })">
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('Unlock') }}</button>
            </form>
            <form method="POST" action="/family/child/reset/send" @submit.prevent="post('/family/child/reset/send', { child_id: child.user_id })">
              <button type="submit" class="btn btn-secondary btn-sm">{{ t('Reset password') }}</button>
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
              <label>{{ t('Code (sent to your email)') }}</label>
              <input v-model="resetForm.code" type="text" required maxlength="6" :placeholder="t('6-digit code')">
            </div>
            <div class="form-group">
              <label>{{ t('New password for the child') }}</label>
              <div class="pw-wrap">
                <input v-model="resetForm.new_password" type="password" required minlength="8" :placeholder="t('8+ characters, letters and digits')">
              </div>
            </div>
            <div class="form-group">
              <label>{{ t('Confirm new password') }}</label>
              <input v-model="resetForm.confirm_password" type="password" required>
            </div>
            <button type="submit" class="btn btn-primary btn-sm" :disabled="busy">{{ t('Reset') }}</button>
          </form>
        </li>
        <li v-if="!(state.childrenByGroup[group.id] || []).length">
          <span class="family-list__meta">{{ t('No child accounts in this family yet') }}</span>
        </li>
      </ul>

      <h3 class="family-sub">{{ t('Invite another parent') }}</h3>
      <form
        method="POST"
        action="/family/guardian/add"
        class="family-inline-form"
        @submit.prevent="post('/family/guardian/add', { group_id: group.id, guardian_email: guardianEmail, relationship: guardianRelation })"
      >
        <input v-model="guardianEmail" type="email" required :placeholder="t('Mom\'s or dad\'s email')">
        <select v-model="guardianRelation">
          <option v-for="(label, key) in relationships" :key="key" :value="key">{{ t(label) }}</option>
        </select>
        <button type="submit" class="btn btn-secondary">{{ t('Invite') }}</button>
      </form>
      <small class="form-hint">{{ t('Once they register or sign in with that address, they can join from the Family page.') }}</small>

      <h3 class="family-sub">{{ t('Create child account') }}</h3>
      <form
        method="POST"
        action="/family/child/create"
        class="family-create-form"
        @submit.prevent="post('/family/child/create', { group_id: group.id, username: newChild.username, birth_date: newChild.birth_date, gender: newChild.gender, password: newChild.password, password_confirm: newChild.password_confirm })"
      >
        <div class="form-group">
          <label>{{ t('Username') }}</label>
          <input v-model="newChild.username" type="text" required pattern="[a-z0-9_]{3,32}" :placeholder="t('lowercase letters, digits, underscore')">
        </div>
        <div class="form-group">
          <label>{{ t('Date of birth') }}</label>
          <input v-model="newChild.birth_date" type="date" required>
        </div>
        <div class="form-group">
          <label>{{ t('Gender') }}</label>
          <select v-model="newChild.gender" required>
            <option value="female">{{ t('Female') }}</option>
            <option value="male">{{ t('Male') }}</option>
            <option value="non-binary">{{ t('Non-binary') }}</option>
            <option value="prefer-not-to-say">{{ t('Prefer not to say') }}</option>
          </select>
        </div>
        <div class="form-group">
          <label>{{ t('Initial password') }}</label>
          <div class="pw-wrap">
            <input v-model="newChild.password" type="password" required minlength="8" :placeholder="t('8+ characters, letters and digits')">
          </div>
        </div>
        <div class="form-group">
          <label>{{ t('Confirm password') }}</label>
          <input v-model="newChild.password_confirm" type="password" required>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="busy">{{ t('Create account') }}</button>
        <small class="form-hint">{{ t('Hand the username and initial password to the child — they can change it after signing in.') }}</small>
      </form>
    </div>

    <!-- 你是孩子 -->
    <div v-if="isChild" class="settings-section">
      <h2>{{ t('Your parents') }}</h2>
      <p class="family-status">
        {{ t('Current status: ') }}<strong>{{ statusLabels[state.membership.status] || state.membership.status }}</strong>
        <template v-if="isPending"> · {{ t('Invitation sent — waiting for the parent to accept') }}</template>
        <template v-else-if="isActive"> · {{ t('Parents can reset your password or lock your account') }}</template>
      </p>

      <ul class="family-list">
        <li v-for="guardian in guardians" :key="guardian.email + guardian.user_id">
          <span class="family-list__name">{{ guardian.username || guardian.email }}</span>
          <span class="family-list__meta">
            {{ relationshipLabel(guardian.relationship) }}
            · {{ guardian.user_id ? t('Joined') : t('Pending (no Nomen yet)') }}
          </span>
        </li>
        <li v-if="!guardians.length"><span class="family-list__meta">{{ t('No parent invited yet') }}</span></li>
      </ul>

      <template v-if="isActive">
        <h3 class="family-sub">{{ t('What parents can do') }}</h3>
        <ul class="family-perms">
          <li class="is-can">{{ t('Reset your sign-in password for you') }}</li>
          <li class="is-can">{{ t('Temporarily lock or unlock your account') }}</li>
          <li class="is-cannot">{{ t('See your password') }}</li>
          <li class="is-cannot">{{ t('Read your in-site messages or mail') }}</li>
          <li class="is-cannot">{{ t('Sign in as you') }}</li>
        </ul>
      </template>

      <template v-if="isPending || isActive">
        <h3 class="family-sub">{{ t('Invite parent') }}</h3>
        <form
          method="POST"
          action="/family/invite-parent"
          class="family-inline-form"
          @submit.prevent="post('/family/invite-parent', { guardian_email: inviteEmail, relationship: inviteRelation })"
        >
          <input v-model="inviteEmail" type="email" required :placeholder="t('Parent or guardian\'s email')">
          <select v-model="inviteRelation">
            <option v-for="(label, key) in relationships" :key="key" :value="key">{{ t(label) }}</option>
          </select>
          <button type="submit" class="btn btn-secondary">{{ t('Send invite') }}</button>
        </form>
        <small class="form-hint">{{ t('Once the parent registers or signs in with that address, they can join from the Family page.') }}</small>

        <h3 class="family-sub">{{ t('End supervision') }}</h3>
        <ul class="family-checks">
          <li v-for="check in checks" :key="check.label" :class="check.ok ? 'is-ok' : 'is-todo'">
            <span class="family-checks__mark">{{ check.ok ? '✓' : '·' }}</span>
            <span>
              {{ check.label }}
              <small v-if="!check.ok && check.note" class="form-hint">{{ check.note }}</small>
              <a v-if="!check.ok && check.link" :href="check.link">{{ t('Go to settings') }}</a>
            </span>
          </li>
        </ul>

        <form method="POST" action="/family/release" @submit.prevent="post('/family/release', {}, {
          confirm: {
            title: t('End supervision'),
            message: t('After this, parents can no longer reset your password or lock your account. Are you sure?'),
            ok: t('End supervision'),
          },
        })">
          <button type="submit" class="btn btn-primary" :disabled="busy || !releaseCheck.ok">{{ t('End supervision') }}</button>
          <small v-if="!releaseCheck.ok" class="form-hint">{{ releaseReason }}</small>
        </form>
      </template>
      <template v-else>
        <form method="POST" action="/family/leave" @submit.prevent="post('/family/leave', {}, {
          confirm: {
            title: t('Leave family'),
            message: t('After leaving, your parents can no longer see you. Are you sure?'),
            ok: t('Sign out'),
            danger: true,
          },
        })">
          <button type="submit" class="btn btn-secondary">{{ t('Leave family') }}</button>
        </form>
      </template>
    </div>

    <!-- 家长入口（还没有家庭） -->
    <div v-if="!state.groups.length" class="settings-section">
      <h2>{{ t('For parents') }}</h2>
      <p v-if="state.invitations.length" class="form-hint">
        {{ t('Press Join on an invitation above. If it was sent to another address of yours, request the code on that mailbox and join here.') }}
      </p>
      <p v-else class="form-hint" style="margin-bottom:16px;">
        {{ t('If your child used your email at sign-up or invited you, the invitation shows up here.') }}{{ t('If that address already has a Nomen account, sign in with it and accept directly.') }}
      </p>

      <form
        v-if="!state.claimPending"
        method="POST"
        action="/family/claim/send"
        class="family-inline-form"
        @submit.prevent="post('/family/claim/send', { guardian_email: claimEmail })"
      >
        <input v-model="claimEmail" type="email" required :placeholder="t('The invited address')">
        <button type="submit" class="btn btn-secondary">{{ t('Send code') }}</button>
      </form>
      <template v-else>
        <form method="POST" action="/family/claim" class="family-inline-form" @submit.prevent="post('/family/claim', { code: claimCode })">
          <input v-model="claimCode" type="text" required maxlength="6" :placeholder="t('6-digit code')">
          <button type="submit" class="btn btn-primary">{{ t('Join') }}</button>
        </form>
        <small class="form-hint">
          {{ t('The code went to') }} {{ user.claimPendingMasked }}{{ t(', valid for 10 minutes.') }}
        </small>
      </template>
    </div>
  </div>
</template>
