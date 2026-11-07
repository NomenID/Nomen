<script setup>
import { reactive } from 'vue'
import StepHead from '../StepHead.vue'
import { go, submit, wizard } from '../wizard'

const saved = wizard.state.db || {}

const form = reactive({
  driver: saved.driver || 'mysql',
  host: saved.host || '127.0.0.1',
  port: saved.port || 3306,
  name: saved.name || 'nomen',
  user: saved.user || 'nomen',
  pass: saved.pass || '',
  path: saved.path || 'database/nomen.sqlite',
})

async function save() {
  await submit('db', { ...form })
}
</script>

<template>
  <StepHead icon="db" title="连接数据库" lead="账号、日志和应用都放在这一个数据库里。库是空的就行，表由安装器来建。" />

  <form @submit.prevent="save">
    <div class="seg">
      <label>
        <input v-model="form.driver" type="radio" name="db_driver" value="mysql">
        <span>MySQL / MariaDB</span>
      </label>
      <label>
        <input v-model="form.driver" type="radio" name="db_driver" value="sqlite">
        <span>SQLite</span>
      </label>
    </div>

    <div v-if="form.driver === 'mysql'" class="fields">
      <div class="field-row">
        <div class="field">
          <label for="db_host">主机</label>
          <input id="db_host" v-model="form.host" class="input" autocomplete="off">
        </div>
        <div class="field">
          <label for="db_port">端口</label>
          <input id="db_port" v-model="form.port" class="input" inputmode="numeric">
        </div>
      </div>
      <div class="field">
        <label for="db_name">库名</label>
        <input id="db_name" v-model="form.name" class="input" autocomplete="off">
      </div>
      <div class="field">
        <label for="db_user">用户名</label>
        <input id="db_user" v-model="form.user" class="input" autocomplete="off">
      </div>
      <div class="field">
        <label for="db_pass">密码</label>
        <input id="db_pass" v-model="form.pass" class="input" type="password" autocomplete="off">
      </div>
    </div>

    <div v-else class="fields">
      <div class="field">
        <label for="db_path">数据库文件</label>
        <input id="db_path" v-model="form.path" class="input">
        <div class="hint">相对路径按项目根算。文件不存在会自动创建，目录要让 PHP 能写。</div>
      </div>
    </div>

    <div class="actions">
      <button class="btn btn-primary" type="submit" :class="{ 'is-busy': wizard.busy }" :disabled="wizard.busy">
        连接并继续
      </button>
    </div>

    <div class="nav-links">
      <button class="linkish" type="button" @click="go('env')">← 上一步</button>
      <span></span>
    </div>
  </form>

  <details class="more">
    <summary>连不上？</summary>
    <div class="fields">
      <div class="hint">面板建的数据库账号一般只允许本机连接，主机可以试试 <code>localhost</code>。
        库和用户要先在面板里建好；端口不是 3306 就改成实际端口。</div>
      <pre class="code">CREATE DATABASE `nomen` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;</pre>
    </div>
  </details>
</template>
