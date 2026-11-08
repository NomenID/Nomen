/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

(function () {
    'use strict';

    function csrf() {
        var el = document.querySelector('input[name="_csrf"]');
        return el ? el.value : '';
    }

    function adminPost(url, data, onSuccess) {
        var body = new URLSearchParams();
        body.append('_csrf', csrf());
        Object.keys(data).forEach(function (key) {
            body.append(key, data[key]);
        });

        fetch(url, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/x-www-form-urlencoded'
            },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (response) {
            return response.json();
        }).then(function (data) {
            if (data && data.success) {
                if (onSuccess) {
                    onSuccess(data);
                } else {
                    window.location.reload();
                }
                return;
            }
            Nomen.alert('操作失败', (data && data.message) || '请求失败');
        }).catch(function () {
            Nomen.alert('操作失败', '网络错误，请稍后重试');
        });
    }

    function bindUserActions() {
        var buttons = document.querySelectorAll('[data-admin-action]');
        if (!buttons.length) return;

        Array.prototype.forEach.call(buttons, function (btn) {
            btn.addEventListener('click', function () {
                var action = btn.getAttribute('data-admin-action');
                var userId = btn.getAttribute('data-user-id');
                var username = btn.getAttribute('data-username') || ('#' + userId);

                if (action === 'toggle-admin') {
                    var grant = btn.getAttribute('data-role') !== 'admin';
                    Nomen.confirm(
                        grant ? '设为管理员' : '撤销管理员',
                        grant
                            ? username + ' 将获得管理后台的全部权限。'
                            : username + ' 将失去管理后台的访问权限。',
                        { danger: !grant, confirmText: grant ? '设为管理员' : '撤销' }
                    ).then(function (ok) {
                        if (!ok) return;
                        adminPost('/admin/users/toggle-admin', { user_id: userId }, function (data) {
                            btn.setAttribute('data-role', data.role);
                            btn.textContent = data.role === 'admin' ? '撤销管理员' : '设为管理员';
                            Nomen.toast(data.message, 'success');
                        });
                    });
                    return;
                }

                if (action === 'toggle-status') {
                    var enable = btn.getAttribute('data-status') !== '1';
                    Nomen.confirm(
                        enable ? '启用账户' : '禁用账户',
                        enable
                            ? '确认恢复 ' + username + ' 的登录权限？'
                            : username + ' 将无法登录，其现有会话不会被强制中断。',
                        { danger: !enable, confirmText: enable ? '启用' : '禁用' }
                    ).then(function (ok) {
                        if (!ok) return;
                        adminPost('/admin/users/toggle-status', { user_id: userId }, function (data) {
                            btn.setAttribute('data-status', data.status);
                            btn.textContent = Number(data.status) === 1 ? '禁用' : '启用';
                            btn.classList.toggle('admin-btn--danger', Number(data.status) === 1);
                            Nomen.toast(data.message, 'success');
                        });
                    });
                }
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindUserActions);
    } else {
        bindUserActions();
    }
})();
