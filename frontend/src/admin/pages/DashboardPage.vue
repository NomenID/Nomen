<script setup>
import { onMounted, ref } from 'vue'
import { apiGet } from '../../app/api'
import AdminPage from '../components/AdminPage.vue'
import AdminCard from '../components/AdminCard.vue'
import AdminAlert from '../components/AdminAlert.vue'
import AdminBadge from '../components/AdminBadge.vue'
import AdminEmpty from '../components/AdminEmpty.vue'

const loading = ref(true)
const error = ref('')
const data = ref(null)

async function load() {
  loading.value = true
  error.value = ''
  try {
    data.value = await apiGet('/api/admin/dashboard')
  } catch (err) {
    error.value = '概览加载失败，请刷新重试'
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <AdminPage title="概览" description="站点状态、待处理事项和最近活动。">
    <AdminAlert v-if="error" type="danger" :message="error" />
    <div v-else-if="loading" class="a-loading">加载中…</div>

    <template v-else-if="data">
      <AdminAlert v-if="data.upgradePending" type="warning">
        程序是 <code>{{ data.upgradePending.code }}</code>，库里记录的是
        <code>{{ data.upgradePending.db || '未记录' }}</code>。在项目根目录跑
        <code>php bin/nomen upgrade</code>，跑完这条提示会自己消失。
      </AdminAlert>

      <AdminAlert v-if="data.installerPresent" type="warning">
        <code>public/nomen-install/</code> 还在服务器上。装完应删除这个目录，否则任何人都能重新打开安装页。
      </AdminAlert>

      <div class="a-statgrid">
        <div v-for="card in data.cards" :key="card.label" class="a-stat" :class="{ 'a-stat--alert': card.alert }">
          <div class="a-stat__value">{{ card.value }}</div>
          <div class="a-stat__label">{{ card.label }}</div>
          <div class="a-stat__hint">{{ card.hint }}</div>
        </div>
      </div>

      <div class="a-grid a-grid--2">
        <AdminCard title="Redis">
          <template #actions>
            <AdminBadge v-if="!data.redis.ok" tone="danger">不可用</AdminBadge>
            <AdminBadge v-else-if="data.redis.warning" tone="warning">内存偏紧</AdminBadge>
            <AdminBadge v-else tone="success">正常</AdminBadge>
          </template>

          <dl v-if="!data.redis.ok" class="a-kv">
            <dt>错误</dt><dd>{{ data.redis.error }}</dd>
            <dt>影响</dt><dd>限流会静默放行、登录状态可能丢失。先看 Redis 进程和整机内存。</dd>
          </dl>
          <dl v-else class="a-kv">
            <dt>版本</dt><dd>{{ data.redis.version }}</dd>
            <dt>内存</dt><dd>{{ data.redis.used }} / {{ data.redis.max }}<template v-if="data.redis.percent !== null">（{{ data.redis.percent }}%）</template></dd>
            <dt>淘汰策略</dt><dd>{{ data.redis.policy }}<template v-if="data.redis.policy === 'noeviction'">（不做淘汰，写满即报错）</template></dd>
            <dt>当前库键数</dt><dd>{{ data.redis.keys }}</dd>
            <dt>会话索引</dt><dd>{{ data.redis.index ? '已回填' : '未回填，踢其他设备时会扫全库' }}</dd>
          </dl>
        </AdminCard>

        <AdminCard title="插件">
          <template #actions>
            <a class="a-link" href="/special/plugins">详情</a>
          </template>
          <p class="a-text">
            已启用 {{ data.plugins.loaded }} 个；随包带了 {{ data.plugins.bundled.length }} 个：
            {{ data.plugins.bundled.join('、') }}
          </p>
          <AdminAlert v-if="data.plugins.missingExt.length" type="warning">
            缺 PHP 扩展 {{ data.plugins.missingExt.join('、') }}，相关功能会降级。
          </AdminAlert>
          <p class="a-text a-text--muted">当前版本 {{ data.site?.version || '未知' }}</p>
        </AdminCard>
      </div>

      <AdminCard title="最近活动">
        <template #actions>
          <router-link class="a-link" to="/admin/logs">查看全部</router-link>
        </template>

        <AdminEmpty v-if="!data.recentLogs.length" text="还没有任何记录。" />
        <div v-else class="a-table-wrap">
          <table class="a-table">
            <thead>
              <tr><th>时间</th><th>操作者</th><th>分类</th><th>动作</th><th>说明</th></tr>
            </thead>
            <tbody>
              <tr v-for="log in data.recentLogs" :key="log.id">
                <td class="a-nowrap"><router-link class="a-link" :to="`/admin/logs/${log.id}`">{{ log.created_at }}</router-link></td>
                <td>
                  <router-link v-if="log.actor_url" class="a-link" :to="log.actor_url">{{ log.actor_username || '系统' }}</router-link>
                  <span v-else>{{ log.actor_username || '系统' }}</span>
                  <AdminBadge tone="muted">{{ log.actor_label }}</AdminBadge>
                </td>
                <td>{{ log.category_label }}</td>
                <td>{{ log.action_label }}</td>
                <td class="a-cell-summary">{{ log.summary }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </AdminCard>
    </template>
  </AdminPage>
</template>
