<!--
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
-->

<script setup>
import { computed, onMounted, ref } from 'vue'
import { apiGet, apiPost } from '../../app/api'
import { useT } from '../../composables/useT.js'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'
import AdminAlert from '../components/AdminAlert.vue'

const { t } = useT()
const loading = ref(true)
const error = ref('')
const data = ref(null)
const saving = ref('')
const toast = ref('')
let toastTimer = 0

// 显式可编辑的组（隐式组只展示，不可写规则）
const editableGroups = computed(() => (data.value?.groups || []).filter(g => !g.implicit))
const implicitGroups = computed(() => (data.value?.groups || []).filter(g => g.implicit))

function cellFor(group, ability) {
  return group.abilities?.[ability] || { allowed: null, override: false }
}

// 三态循环：默认 → allow → deny → 回默认（删规则）
function nextState(cell) {
  if (!cell.override) return 'allow'
  if (cell.allowed === true) return 'deny'
  return 'default'
}

async function toggle(group, ability) {
  const cell = cellFor(group, ability)
  const state = nextState(cell)
  saving.value = `${group.name}:${ability}`
  try {
    const result = await apiPost('/admin/permissions/rule', {
      group: group.name,
      ability,
      state,
    })
    if (result.success) {
      group.abilities[ability] = { allowed: result.allowed, override: result.override }
      toast.value = `${ability}: ${
        result.allowed === null ? t('default') : result.allowed ? t('allowed') : t('denied')
      }`
      window.clearTimeout(toastTimer)
      toastTimer = window.setTimeout(() => { toast.value = '' }, 2500)
    }
  } finally {
    saving.value = ''
  }
}

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/permissions')
  } catch (err) {
    error.value = t('Could not load the permission grid')
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminPage :title="t('Permissions')" :description="t('Which group can do what. Click a cell to cycle: default → allow → deny → default. Deny always wins over allow.')">
    <template #actions>
      <router-link class="a-btn a-btn--ghost" to="/admin/users">{{ t('Users') }}</router-link>
    </template>

    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">{{ t('Loading…') }}</div>

    <template v-else-if="data">
      <transition name="a-fade">
        <div v-if="toast" class="perms-toast a-mono">{{ toast }}</div>
      </transition>

      <AdminCard :title="t('Group × ability grid')">
        <div v-if="implicitGroups.length" class="a-badges perms-implicit">
          <span class="a-text a-text--muted">{{ t('Implicit groups:') }}</span>
          <AdminBadge v-for="group in implicitGroups" :key="group.name" tone="muted">
            {{ group.label }} ({{ group.name }})
          </AdminBadge>
          <span class="a-sub">{{ t('Everyone is in them; they cannot be assigned or overridden.') }}</span>
        </div>

        <AdminEmpty v-if="!editableGroups.length" :text="t('No assignable groups. Add one in config/permissions.php.')" />
        <div v-else class="a-table-wrap perms-wrap">
          <table class="a-table perms-table">
            <thead>
              <tr>
                <th class="perms-group-col">{{ t('Group') }}</th>
                <th v-for="ability in data.abilities" :key="ability.name" class="perms-ab-col">
                  <span class="perms-ab-name a-mono" :title="ability.description">{{ ability.label }}</span>
                  <AdminBadge v-if="!ability.core" tone="muted">{{ t('plugin') }}</AdminBadge>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="group in editableGroups" :key="group.name">
                <td class="perms-group-col">
                  <strong>{{ group.label }}</strong>
                  <span class="a-mono a-sub">{{ group.name }}</span>
                  <span v-if="group.members !== null" class="a-sub">{{ t('{:n} member(s)', { n: group.members }) }}</span>
                </td>
                <td v-for="ability in data.abilities" :key="ability.name" class="perms-cell">
                  <button
                    type="button"
                    class="perms-toggle"
                    :class="{
                      'is-allow': cellFor(group, ability.name).allowed === true,
                      'is-deny': cellFor(group, ability.name).allowed === false,
                      'is-override': cellFor(group, ability.name).override,
                    }"
                    :disabled="saving === `${group.name}:${ability.name}`"
                    :title="ability.description"
                    @click="toggle(group, ability.name)"
                  >
                    <span v-if="cellFor(group, ability.name).allowed === true">✓</span>
                    <span v-else-if="cellFor(group, ability.name).allowed === false">✕</span>
                    <span v-else>—</span>
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p class="a-sub perms-hint">
          {{ t('A cell with a dot is overridden by the database; setting it back to default removes the override. Users are assigned to groups on their detail page or via the group:assign CLI command.') }}
        </p>
      </AdminCard>
    </template>
  </AdminPage>
</template>

<style scoped>
.perms-implicit {
  margin-bottom: 14px;
  align-items: center;
}
.perms-wrap {
  overflow-x: auto;
}
.perms-table th,
.perms-table td {
  white-space: nowrap;
}
.perms-group-col {
  position: sticky;
  left: 0;
  background: var(--color-background-secondary, #fff);
  z-index: 1;
  min-width: 150px;
}
.perms-ab-col {
  vertical-align: bottom;
  padding-bottom: 8px;
}
.perms-ab-name {
  display: block;
  max-width: 140px;
  overflow: hidden;
  text-overflow: ellipsis;
}
.perms-cell {
  text-align: center;
}
.perms-toggle {
  width: 34px;
  height: 30px;
  border: 1px solid var(--color-border-tertiary, rgba(0, 0, 0, 0.15));
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
  font-size: 13px;
  line-height: 1;
  color: var(--color-text-tertiary, #888);
}
.perms-toggle:hover {
  border-color: var(--color-border-secondary, rgba(0, 0, 0, 0.3));
}
.perms-toggle.is-allow {
  background: rgba(29, 158, 117, 0.12);
  border-color: rgba(15, 110, 86, 0.4);
  color: #0f6e56;
  font-weight: 500;
}
.perms-toggle.is-deny {
  background: rgba(226, 75, 74, 0.12);
  border-color: rgba(163, 45, 45, 0.4);
  color: #a32d2d;
  font-weight: 500;
}
.perms-toggle {
  position: relative;
}
.perms-toggle.is-override::before {
  content: '';
  position: absolute;
  top: 2px;
  right: 2px;
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--color-text-secondary, #888);
}
.perms-hint {
  margin-top: 10px;
}
.perms-toast {
  position: sticky;
  top: 4px;
  z-index: 5;
  margin-bottom: 10px;
  padding: 8px 12px;
  border-radius: 8px;
  background: var(--color-background-secondary, #f6f8fa);
  border: 1px solid var(--color-border-tertiary, rgba(0, 0, 0, 0.15));
  font-size: 12px;
}
</style>
