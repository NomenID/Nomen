<script setup>
import { computed } from 'vue'
import StepHead from '../StepHead.vue'
import CheckRow from '../CheckRow.vue'
import { wizard } from '../wizard'

const done = computed(() => wizard.done || {})
const siteUrl = computed(() => (done.value.base_url || '').replace(/\/+$/, ''))
const loginUrl = computed(() => siteUrl.value + '/login?username=' + encodeURIComponent(done.value.username || ''))

function downloadConfig() {
  const blob = new Blob([done.value.content || ''], { type: 'text/plain' })
  const link = document.createElement('a')
  link.href = URL.createObjectURL(blob)
  link.download = 'installed.php'
  link.click()
  URL.revokeObjectURL(link.href)
}
</script>

<template>
  <StepHead icon="check" tone="done" title="装好了" :lead="'「' + (done.site_name || 'Nomen') + '」已经跑起来了。'" />

  <div v-if="done.written" class="alert alert--ok" role="status">
    数据库、管理员和配置文件都已就位。
  </div>
  <div v-else class="alert alert--warn" role="alert">
    数据库和账号都建好了，但 PHP 没权限写 <code>config/installed.php</code>。
    把下面的内容保存成这个文件放到 config/ 里，站点才会启动。
  </div>

  <div class="actions">
    <template v-if="done.written">
      <a class="btn btn-primary btn-lg" :href="siteUrl">进入网站</a>
      <a class="btn btn-ghost" :href="loginUrl">用管理员账号登录</a>
    </template>
    <template v-else>
      <button class="btn btn-primary" type="button" @click="downloadConfig">下载 installed.php</button>
      <textarea class="code" :value="done.content" readonly></textarea>
      <p class="fine">这份内容里有数据库密码和密钥，别贴到聊天或工单里。放好文件后重新打开站点首页即可。</p>
    </template>
  </div>

  <div style="margin-top:22px">
    <ul class="checks">
      <CheckRow :label="done.username || ''" :hint="done.email || ''" state="pass" state-text="已创建" />
      <CheckRow label="配置文件" :hint="done.written ? '已写入 config/installed.php' : 'PHP 没权限写 config/，要手动放进去'"
        :state="done.written ? 'pass' : 'fail'" :state-text="done.written ? '完成' : '待处理'" />
      <CheckRow label="安装目录" hint="public/nomen-install/ 还留着，确认站点没问题后删掉"
        state="off" state-text="要删" />
    </ul>
  </div>

  <div v-if="done.plugins_note" class="alert alert--warn" style="margin-top:18px">
    {{ done.plugins_note }}
  </div>

  <p class="fine">第一次登录后可以到后台建 OAuth 应用、改站点设置。
    没填 SMTP 的话，注册验证码和找回密码要等配置好邮件之后才能用。
    <template v-if="done.written">如果 PHP 进程和网站不是同一个用户，确认站点能读到 config/installed.php。</template>
  </p>
</template>
