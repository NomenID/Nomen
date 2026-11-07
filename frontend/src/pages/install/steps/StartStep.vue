<script setup>
import StepHead from '../StepHead.vue'
import { go, resetStep, wizard } from '../wizard'

function start() {
  go(wizard.resume || 'env')
}
</script>

<template>
  <StepHead icon="brand" :logo="wizard.defaultLogo" title="安装 Nomen"
    :lead="wizard.started
      ? '上次填到一半，接着来。每一步只问一件事，随时可以回上一步。'
      : '把 Nomen 装到这台服务器上。每一步只问一件事，随时可以回上一步。'" />

  <ul class="prep">
    <li><i>1</i><span><b>连接数据库</b><small>MySQL、MariaDB 或 SQLite。先在面板里建好库和用户最省事。</small></span></li>
    <li><i>2</i><span><b>填写站点信息</b><small>站点名、访问地址、图标和界面语言。</small></span></li>
    <li><i>3</i><span><b>建管理员账号</b><small>装完就能用它登录，不需要再收验证邮件。</small></span></li>
  </ul>

  <div class="actions">
    <button class="btn btn-primary" type="button" @click="start">
      {{ wizard.started ? '继续安装' : '开始安装' }}
    </button>
  </div>

  <div v-if="wizard.started" class="nav-links">
    <span></span>
    <button class="linkish" type="button" :disabled="wizard.busy" @click="resetStep">重新开始</button>
  </div>
</template>
