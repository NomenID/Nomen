/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 站内地址统一走服务端注入的 window.Nomen.url：
// 有伪静态时原样返回，没有时换成 index.php?page=…
export function url(path) {
  return window.Nomen && typeof window.Nomen.url === 'function' ? window.Nomen.url(path) : path
}

/** 查询串模式下的当前页面路径（?page=settings → /settings）；有伪静态时返回 null */
export function currentPagePath() {
  if (window.NOMEN_URL_MODE !== 'query') return null
  const page = (new URLSearchParams(window.location.search).get('page') || '').replace(/^\/+/, '')
  return page === '' ? '/' : '/' + page
}

/**
 * 查询串模式下 SPA 不能自己改浏览器地址（改了就成了 /settings 这种死链），
 * 把 push / replace 换成整页跳转，地址始终由服务端拼。
 */
export function installQueryNavigation(router) {
  if (window.NOMEN_URL_MODE !== 'query') return router

  const go = (to, replace) => {
    const target = url(router.resolve(to).fullPath)
    if (replace) window.location.replace(target)
    else window.location.assign(target)
    return Promise.resolve()
  }

  router.push = (to) => go(to, false)
  router.replace = (to) => go(to, true)

  return router
}
