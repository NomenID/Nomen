# 前端插槽

核心的登录 / 注册 / 二验页留了几个空插槽。插件把 HTML 挂进去就行，不用改核心 Vue 页面，也不用
维护一个前端分叉。

## 可用插槽

| 名称 | 位置 |
|---|---|
| `auth.login.before_submit` | 登录页主按钮上方（密码 / 邮箱验证码 / 生物识别三个面板共用） |
| `auth.register.before_submit` | 注册表单最后一步「完成注册」上方（只有最后一步渲染） |
| `auth.2fa.before_submit` | 两步验证主按钮上方 |

## 契约

核心在页面上渲染一个空容器：

```html
<div data-nomen-slot="auth.login.before_submit"></div>
```

挂载和卸载时，在 `document` 上派发两个事件：

| 事件 | `detail` | 什么时候 |
|---|---|---|
| `nomen:slot` | `{ name, element }` | 插槽进入 DOM 后 |
| `nomen:slot-unmount` | `{ name, element }` | 插槽移除前 |

插件脚本用 `Plugins::script('<slug>', 'js/your-slot.js')` 加载。脚本里：

```js
(function () {
  'use strict';

  var mounted = new WeakMap();

  function mount(element) {
    if (mounted.has(element)) {
      return;
    }
    var box = document.createElement('div');
    box.textContent = '...';
    element.appendChild(box);
    mounted.set(element, function () {
      box.remove();
    });
  }

  function unmount(element) {
    var cleanup = mounted.get(element);
    if (cleanup) {
      cleanup();
      mounted.delete(element);
    }
  }

  function scan() {
    document.querySelectorAll('[data-nomen-slot="auth.login.before_submit"]').forEach(mount);
  }

  document.addEventListener('nomen:slot', function (event) {
    if (event.detail.name === 'auth.login.before_submit') {
      mount(event.detail.element);
    }
  });
  document.addEventListener('nomen:slot-unmount', function (event) {
    if (event.detail.name === 'auth.login.before_submit') {
      unmount(event.detail.element);
    }
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
})();
```

## 注意

- **登录页同一个名字有三个元素**（密码 / 邮箱验证码 / 生物识别三个面板），但同时只会挂载当前面板。
  切换面板会先发 `nomen:slot-unmount`，再给新面板发 `nomen:slot`；一定要在 unmount 里清理。
- **脚本可能比核心晚加载**：后加载时先 `querySelectorAll('[data-nomen-slot="..."]')` 扫一遍已有插槽。
- **插槽默认是空的**：不要假设插件一定存在，核心不会因为插槽空着就报错。
- 插槽外层是 `display: contents`，插件内容直接参与父级布局；不要依赖它是个块级盒子。
- 只挂自己的 UI，不要替换 / 删除核心的表单和按钮。

新增插槽会写在这张表里；插件不要靠猜 `data-*` 名字。
