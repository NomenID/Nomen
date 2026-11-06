'use strict';

// 图标：独立 SVG 文件 + CSS mask，页面上只留一个带图标名的 span
function iconTag(name, extraClass) {
    return '<span class="icon icon-' + name + (extraClass ? ' ' + extraClass : '') + '"></span>';
}

// 多语言：词典由布局挂的 /i18n/{语言}.js 注入（window.NOMEN_I18N），键是简体中文原文。
// 拿不到词典（管理后台）或词条缺失时原样返回键，页面永远不空白。
function t(key, params) {
    var v = window.NOMEN_I18N ? window.NOMEN_I18N[key] : null;
    var text = (typeof v === 'string' && v !== '') ? v : key;
    if (params) {
        for (var name in params) {
            if (Object.prototype.hasOwnProperty.call(params, name)) {
                text = text.split('{:' + name + '}').join(String(params[name]));
            }
        }
    }
    return text;
}
window.t = t;

// 选择器切语言走 ?lang= 链接（服务端落 cookie / 账号偏好），落地后把参数从地址栏抹掉
(function() {
    if (/[?&]lang=/.test(window.location.search)) {
        var params = new URLSearchParams(window.location.search);
        if (params.has('lang')) {
            params.delete('lang');
            var qs = params.toString();
            window.history.replaceState(null, '', window.location.pathname + (qs ? '?' + qs : '') + window.location.hash);
        }
    }
})();

function initLangPicker() {
    var picker = document.querySelector('[data-lang-picker]');
    if (!picker) return;
    var trigger = picker.querySelector('.lang-trigger');
    var panel = picker.querySelector('.lang-panel');
    var search = picker.querySelector('[data-lang-search]');
    var empty = picker.querySelector('.lang-empty');
    var options = picker.querySelectorAll('.lang-option');
    if (!trigger || !panel) return;

    function close() {
        panel.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
    }
    function open() {
        panel.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        if (search) search.focus();
    }
    function filter(q) {
        q = (q || '').trim().toLowerCase();
        var visible = 0;
        options.forEach(function(opt) {
            var show = q === '' || (opt.getAttribute('data-search') || '').indexOf(q) !== -1;
            if (opt.parentElement) opt.parentElement.hidden = !show;
            if (show) visible++;
        });
        if (empty) empty.hidden = visible > 0;
    }

    trigger.addEventListener('click', function(e) {
        e.stopPropagation();
        if (panel.hidden) open(); else close();
    });
    if (search) {
        search.addEventListener('input', function() { filter(search.value); });
        search.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                for (var i = 0; i < options.length; i++) {
                    if (options[i].parentElement && !options[i].parentElement.hidden) {
                        window.location.href = options[i].href;
                        return;
                    }
                }
            }
        });
    }
    document.addEventListener('click', function(e) {
        if (!picker.contains(e.target)) close();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !panel.hidden) close();
    });
}

(function() {
    var style = document.createElement('style');
    style.textContent = `
.token-list, .device-list {
    display: flex;
    flex-direction: column;
    gap: 10px;
}
.token-item, .device-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 14px 16px;
    background: var(--glass-bg);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    border: 1px solid var(--glass-border);
    border-radius: 10px;
    gap: 12px;
    transition: all 0.2s ease;
}
.token-item:hover, .device-item:hover {
    border-color: var(--color-border-hover);
    box-shadow: var(--shadow);
}
.token-item__info, .device-item__info {
    flex: 1;
    min-width: 0;
}
.token-item__name, .device-item__name {
    font-weight: 600;
    font-size: 14px;
    color: var(--color-text);
    margin-bottom: 4px;
    display: flex;
    align-items: center;
}
.token-item__meta, .device-item__meta {
    font-size: 12px;
    color: var(--color-text-subtle);
    line-height: 1.5;
}
.btn-danger-outline {
    background: transparent;
    border: 1px solid var(--color-danger, #e74c3c);
    color: var(--color-danger, #e74c3c);
    padding: 6px 14px;
    border-radius: 6px;
    font-size: 13px;
    cursor: pointer;
    white-space: nowrap;
    transition: background 0.15s ease, color 0.15s ease;
}
.btn-danger-outline:hover {
    background: var(--color-danger, #e74c3c);
    color: #fff;
}
`;
    document.head.appendChild(style);

    // ================================================================
    //  Nomen 提示层：全站唯一的 toast / alert / confirm / prompt 入口
    //  约定：页面里不要再出现原生 alert() / confirm() / prompt()
    //  ----------------------------------------------------------------
    //  Nomen.toast(message, type, duration)          toast，type: success|error|warning|info
    //  Nomen.alert(title, message, opts)             -> Promise（关闭后 resolve(true)）
    //  Nomen.confirm(title, message, opts)           -> Promise<boolean>
    //  Nomen.prompt(title, message, opts)            -> Promise<string|null>，带输入框
    //  opts: icon / confirmText / cancelText / danger / variant / content /
    //        countdown / inputType / placeholder / value
    //  confirm 的 variant: 'confirm'(授权确认) | 'risk'(风险确认，按钮转黄红)
    // ================================================================
    var TOAST_ICONS = { success: 'check', error: 'close', warning: 'warning', info: 'info' };

    function escapeHtml(str) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str == null ? '' : String(str)));
        return div.innerHTML;
    }
    window.escapeHtml = escapeHtml;

    // ---------------- Toast ----------------
    var toastContainer = null;

    function ensureToastContainer() {
        if (!toastContainer || !document.body.contains(toastContainer)) {
            toastContainer = document.querySelector('.nomen-toast-container');
        }
        if (!toastContainer) {
            toastContainer = document.createElement('div');
            toastContainer.className = 'nomen-toast-container';
            document.body.appendChild(toastContainer);
        }
        return toastContainer;
    }

    function dismissToast(toast) {
        if (!toast || toast.dataset.closing === '1') return;
        toast.dataset.closing = '1';
        toast.classList.remove('show');
        toast.classList.add('hide');
        setTimeout(function() { toast.remove(); }, 500);
    }

    function showToast(message, type, duration) {
        if (!TOAST_ICONS[type]) type = 'info';
        if (!duration) duration = 3500;

        var toast = document.createElement('div');
        toast.className = 'nomen-toast nomen-toast--' + type;
        toast.setAttribute('role', 'status');
        toast.setAttribute('aria-live', 'polite');
        toast.innerHTML =
            '<span class="nomen-toast__icon" aria-hidden="true">' + iconTag(TOAST_ICONS[type]) + '</span>' +
            '<span class="nomen-toast__message"></span>' +
            '<button type="button" class="nomen-toast__close" aria-label="' + t('关闭') + '">' + iconTag('close') + '</button>' +
            '<div class="nomen-toast__progress"></div>';
        toast.querySelector('.nomen-toast__message').textContent = message == null ? '' : String(message);
        ensureToastContainer().appendChild(toast);

        var progress = toast.querySelector('.nomen-toast__progress');
        var span = duration;   // 当前这段过渡的总时长，悬停暂停后会被扣减
        var timer = null;

        function dismiss() {
            clearTimeout(timer);
            dismissToast(toast);
        }

        function schedule(ms) {
            clearTimeout(timer);
            timer = setTimeout(dismiss, ms);
            if (progress) {
                progress.style.transitionDuration = ms + 'ms';
                void progress.offsetWidth;   // 强制回流，让过渡从当前位置重新计时
                progress.style.width = '0%';
            }
        }

        if (progress) progress.style.width = '100%';
        requestAnimationFrame(function() {
            requestAnimationFrame(function() {
                toast.classList.add('show');
                schedule(span);
            });
        });

        toast.querySelector('.nomen-toast__close').addEventListener('click', dismiss);

        // 悬停暂停：冻结进度条，移开后按剩余比例继续
        toast.addEventListener('mouseenter', function() {
            clearTimeout(timer);
            if (!progress) return;
            span = parseFloat(getComputedStyle(progress).width) / toast.offsetWidth * span;
            if (!isFinite(span) || span < 0) span = 0;
            progress.style.transitionDuration = '0s';
            progress.style.width = getComputedStyle(progress).width;
        });

        toast.addEventListener('mouseleave', function() {
            schedule(Math.max(span, 400));
        });

        return toast;
    }

    // ---------------- Modal（alert / confirm / prompt 共用同一个遮罩） ----------------
    var overlay = document.createElement('div');
    overlay.className = 'nomen-modal-overlay';
    overlay.style.display = 'none';
    overlay.innerHTML =
        '<div class="nomen-modal-box" role="dialog" aria-modal="true">' +
            '<div class="nomen-modal-box__icon" aria-hidden="true"></div>' +
            '<h3 class="nomen-modal-box__title"></h3>' +
            '<p class="nomen-modal-box__message"></p>' +
            '<div class="nomen-modal-box__content"></div>' +
            '<div class="nomen-modal-box__actions"></div>' +
        '</div>';
    document.body.appendChild(overlay);

    var boxEl     = overlay.querySelector('.nomen-modal-box');
    var iconEl    = overlay.querySelector('.nomen-modal-box__icon');
    var titleEl   = overlay.querySelector('.nomen-modal-box__title');
    var msgEl     = overlay.querySelector('.nomen-modal-box__message');
    var contentEl = overlay.querySelector('.nomen-modal-box__content');
    var actionsEl = overlay.querySelector('.nomen-modal-box__actions');

    var pendingResolve = null;   // 当前弹窗的 resolve；null 表示没有弹窗
    var cancelValue = false;     // 遮罩 / Esc 关闭时回给调用方的值
    var onCloseHook = null;
    var lastFocused = null;

    function settle(value) {
        if (!pendingResolve) return;
        var resolve = pendingResolve;
        pendingResolve = null;
        overlay.style.display = 'none';
        if (onCloseHook) { onCloseHook(); onCloseHook = null; }
        if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
        lastFocused = null;
        resolve(value);
    }

    function fill(el, text) {
        var has = text !== undefined && text !== null && text !== '';
        el.textContent = has ? text : '';
        el.style.display = has ? '' : 'none';
    }

    function makeButton(text, className, onClick, disabled) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'nomen-modal-btn ' + className;
        btn.textContent = text;
        btn.disabled = !!disabled;
        btn.addEventListener('click', function() {
            if (!btn.disabled) onClick();
        });
        return btn;
    }

    function boxVariant(opts) {
        return opts.variant || (opts.danger ? 'risk' : '');
    }

    function confirmButtonClass(opts) {
        if (opts.danger) return 'nomen-modal-btn--danger';
        if (opts.variant === 'risk') return 'nomen-modal-btn--warning';
        if (opts.variant === 'confirm') return 'nomen-modal-btn--confirm';
        return 'nomen-modal-btn--primary';
    }

    function present(cfg) {
        // 同一时刻只留一个弹窗：旧的按它自己的「取消」语义收尾，别让调用方悬着
        if (pendingResolve) settle(cancelValue);

        boxEl.className = cfg.variant
            ? 'nomen-modal-box nomen-modal-box--' + cfg.variant
            : 'nomen-modal-box';

        if (cfg.icon) {
            iconEl.innerHTML = iconTag(cfg.icon);
            iconEl.style.display = '';
        } else {
            iconEl.innerHTML = '';
            iconEl.style.display = 'none';
        }
        fill(titleEl, cfg.title);
        fill(msgEl, cfg.message);

        contentEl.innerHTML = '';
        if (cfg.content instanceof Node) contentEl.appendChild(cfg.content);
        else if (cfg.content) contentEl.innerHTML = cfg.content;
        contentEl.style.display = cfg.content ? '' : 'none';

        actionsEl.innerHTML = '';
        cfg.buttons.forEach(function(b) { actionsEl.appendChild(b); });

        cancelValue = cfg.cancelValue === undefined ? false : cfg.cancelValue;
        onCloseHook = cfg.onClose || null;
        lastFocused = document.activeElement;
        overlay.style.display = 'flex';

        // 优先聚焦主按钮；主按钮禁用时（如倒计时中）退回取消按钮
        var target = actionsEl.querySelector(
            '.nomen-modal-btn--primary:not(:disabled), .nomen-modal-btn--danger:not(:disabled),' +
            '.nomen-modal-btn--confirm:not(:disabled), .nomen-modal-btn--warning:not(:disabled)'
        ) || actionsEl.querySelector('.nomen-modal-btn--ghost');
        if (target) target.focus();

        if (cfg.onOpen) cfg.onOpen();

        return new Promise(function(resolve) { pendingResolve = resolve; });
    }

    function showAlert(title, message, opts) {
        opts = opts || {};
        return present({
            icon: opts.icon || 'info',
            title: title || t('提示'),
            message: message,
            content: opts.content,
            variant: boxVariant(opts),
            cancelValue: true,
            buttons: [
                makeButton(opts.confirmText || t('知道了'), 'nomen-modal-btn--primary', function() { settle(true); })
            ]
        });
    }

    function showConfirm(title, message, opts) {
        opts = opts || {};

        var confirmText = opts.confirmText || t('确定');
        var countdown = opts.countdown || 0;
        var timer = null;

        var confirmBtn = makeButton(
            countdown > 0 ? confirmText + ' (' + countdown + 's)' : confirmText,
            confirmButtonClass(opts),
            function() { settle(true); },
            countdown > 0
        );

        return present({
            icon: opts.icon || (opts.danger ? 'warning' : 'info'),
            title: title || t('确认操作'),
            message: message,
            content: opts.content,
            variant: boxVariant(opts),
            cancelValue: false,
            buttons: [
                makeButton(opts.cancelText || t('取消'), 'nomen-modal-btn--ghost', function() { settle(false); }),
                confirmBtn
            ],
            onOpen: countdown > 0 ? function() {
                var left = countdown;
                timer = setInterval(function() {
                    left--;
                    if (left > 0) {
                        confirmBtn.textContent = confirmText + ' (' + left + 's)';
                    } else {
                        clearInterval(timer);
                        timer = null;
                        confirmBtn.disabled = false;
                        confirmBtn.textContent = confirmText;
                    }
                }, 1000);
            } : null,
            onClose: function() {
                if (timer) { clearInterval(timer); timer = null; }
            }
        });
    }

    function showPrompt(title, message, opts) {
        opts = opts || {};

        var input = document.createElement('input');
        input.type = opts.inputType || 'password';
        input.className = 'nomen-modal-input';
        input.placeholder = opts.placeholder || '';
        input.value = opts.value || '';
        input.autocomplete = 'off';
        input.spellcheck = false;

        var field = document.createElement('div');
        field.className = 'nomen-modal-field';
        field.appendChild(input);

        var confirmBtn = makeButton(opts.confirmText || t('确定'), confirmButtonClass(opts), function() {
            var value = input.value.trim();
            if (opts.required !== false && !value) {
                input.classList.add('nomen-modal-input--invalid');
                input.focus();
                return;
            }
            settle(value);
        });

        input.addEventListener('input', function() {
            input.classList.remove('nomen-modal-input--invalid');
        });
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                confirmBtn.click();
            }
        });

        return present({
            icon: opts.icon || 'lock',
            title: title || t('请输入'),
            message: message,
            content: field,
            variant: boxVariant(opts),
            cancelValue: null,
            buttons: [
                makeButton(opts.cancelText || t('取消'), 'nomen-modal-btn--ghost', function() { settle(null); }),
                confirmBtn
            ],
            onOpen: function() { input.focus(); input.select(); }
        });
    }

    overlay.addEventListener('click', function(e) {
        if (e.target === overlay) settle(cancelValue);
    });

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && overlay.style.display === 'flex') settle(cancelValue);
    });

    // ---------------- 页面内提示条 ----------------
    function fadeOutAlert(el) {
        if (!el.parentNode) return;
        el.style.transition = 'opacity 0.3s, transform 0.3s';
        el.style.opacity = '0';
        el.style.transform = 'translateY(-8px) scale(0.97)';
        setTimeout(function() { el.remove(); }, 300);
    }

    function alertType(el) {
        var m = /nomen-alert--(success|error|warning|info)/.exec(el.className);
        return m ? m[1] : 'info';
    }

    function autoDismissAlert(el) {
        // 服务端渲染的提示条也补齐类型图标和关闭按钮，跟 toast 用同一套视觉
        if (!el.querySelector('.nomen-alert__icon')) {
            var icon = document.createElement('span');
            icon.className = 'nomen-alert__icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.innerHTML = iconTag(TOAST_ICONS[alertType(el)]);
            el.insertBefore(icon, el.firstChild);
        }

        if (!el.querySelector('.nomen-alert__close')) {
            var close = document.createElement('button');
            close.type = 'button';
            close.className = 'nomen-alert__close';
            close.setAttribute('aria-label', t('关闭'));
            close.innerHTML = iconTag('close');
            close.addEventListener('click', function() { fadeOutAlert(el); });
            el.appendChild(close);
        }

        // 只有成功提示会自动消失；错误 / 警告留在页面上，等用户自己看完
        if (!el.classList.contains('nomen-alert--success')) return;
        setTimeout(function() { fadeOutAlert(el); }, 5000);
    }

    function inlineAlert(container, type, message) {
        if (!container) return null;
        var el = document.createElement('div');
        el.className = 'nomen-alert nomen-alert--' + (TOAST_ICONS[type] ? type : 'info');
        el.textContent = message == null ? '' : String(message);
        container.innerHTML = '';
        container.appendChild(el);
        autoDismissAlert(el);
        return el;
    }

    // 普通表单提交后到页面跳转之间有几百毫秒到几秒的空档，
    // 这里先给按钮和顶部进度条一点反馈，免得用户以为没点上。
    var submitProgress = null;

    function ensureSubmitProgress() {
        if (submitProgress && document.body.contains(submitProgress)) return submitProgress;
        submitProgress = document.createElement('div');
        submitProgress.className = 'nomen-top-progress';
        submitProgress.innerHTML = '<div class="nomen-top-progress__bar"></div>';
        document.body.appendChild(submitProgress);
        return submitProgress;
    }

    function beginSubmit(form) {
        if (!form || form.dataset.submitting === '1') return false;
        form.dataset.submitting = '1';
        form.setAttribute('aria-busy', 'true');
        ensureSubmitProgress().classList.add('nomen-top-progress--active');
        clearTimeout(beginSubmit.timer);
        beginSubmit.timer = setTimeout(resetSubmitState, 30000);
        return true;
    }

    function resetSubmitState() {
        document.querySelectorAll('form[data-submitting="1"]').forEach(function(form) {
            delete form.dataset.submitting;
            form.removeAttribute('aria-busy');
        });
        if (submitProgress) submitProgress.classList.remove('nomen-top-progress--active');
    }

    window.showToast = showToast;
    window.Nomen = {
        toast: showToast,
        alert: showAlert,
        confirm: showConfirm,
        prompt: showPrompt,
        inlineAlert: inlineAlert,
        beginSubmit: beginSubmit,
        resetSubmitState: resetSubmitState,
        autoDismissAlerts: function(root) {
            (root || document).querySelectorAll('.nomen-alert').forEach(autoDismissAlert);
        }
    };
})();

// 普通表单（没被 JS 接管的那种）提交时给反馈；被 preventDefault 的交给各自的 AJAX 流程
document.addEventListener('submit', function(e) {
    var form = e.target;
    if (!form || form.tagName !== 'FORM') return;
    if (form.dataset.submitting === '1') {
        e.preventDefault();
        return;
    }
    setTimeout(function() {
        if (!e.defaultPrevented) Nomen.beginSubmit(form);
    }, 0);
}, true);

// 从 bfcache 返回时把提交态清掉，否则按钮会一直停在禁用样式
window.addEventListener('pageshow', function() {
    if (window.Nomen) Nomen.resetSubmitState();
});

// ========== 页面脚本初始化 ==========
document.addEventListener('DOMContentLoaded', function() {
    var scripts = [
        'initEmailSet',
        'initSettingsResetPassword',
        'initSettingsAvatar',
        'initSettingsDevices',
        'initSettingsTempKeys',
        'initDashboardPage',
        'initActivatePage',
        'initTwoFactorVerify',
        'initSettingsTotp',
        'initSettingsRecoveryCodes',
        'initNotificationsPage',
        'initNotifPopup',
        'initSettingsAnchor',
        'initSettingsForms',
        'initFamilyPage',
        'initConfirmForms',
    ];
    scripts.forEach(function(fn) {
        if (typeof window[fn] === 'function') {
            try { window[fn](); } catch(e) {}
        }
    });

    // 页面内提示条：成功的 5 秒后自行淡出，错误/警告留给用户自己关
    Nomen.autoDismissAlerts(document);
});

// 需要二次确认的表单：<form data-confirm="提示语" data-confirm-title="标题" data-confirm-danger data-confirm-ok="确定">
function initConfirmForms() {
    document.querySelectorAll('form[data-confirm]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            Nomen.confirm(form.dataset.confirmTitle || t('确认操作'), form.dataset.confirm, {
                danger: 'confirmDanger' in form.dataset,
                confirmText: form.dataset.confirmOk || t('确定')
            }).then(function(ok) {
                if (!ok) return;
                if (form.dataset.soft === '1' && typeof window.__softSubmit === 'function') {
                    window.__softSubmit(form);
                    return;
                }
                Nomen.beginSubmit(form);
                form.submit();
            });
        });
    });
}

function handleLogout() {
    Nomen.confirm(t('退出登录'), t('确定要退出登录吗？'), {
        confirmText: t('退出'),
        cancelText: t('取消')
    }).then(function(confirmed) {
        if (confirmed) {
            window.location.href = '/logout';
        }
    });
}

// ========== 2FA 验证页交互 ==========
function initTwoFactorVerify() {
    // 2FA 页已迁到 Vue，别让旧逻辑重复接管
    if (document.getElementById('twofa-app')) return;

    var form = document.getElementById('twofa-form');
    if (!form) return;

    var radios = form.querySelectorAll('input[name="factor_type"]');
    var verifyBtn = document.getElementById('verify-2fa-btn');
    var sendBtn = document.getElementById('send-2fa-code-btn');
    var csrfInput = form.querySelector('input[name="_csrf"]');
    var csrfToken = csrfInput ? csrfInput.value : '';
    var nonceInput = form.querySelector('input[name="nonce"]');
    var nonce = nonceInput ? nonceInput.value : '';

    function getCsrfToken() {
        var inp = document.querySelector('input[name="_csrf"]');
        return inp ? inp.value : csrfToken;
    }

    function hideAllInputs() {
        form.querySelectorAll('.factor-input').forEach(function(el) {
            el.style.display = 'none';
        });
    }

    function getSelectedFactor() {
        var checked = form.querySelector('input[name="factor_type"]:checked');
        return checked ? checked.value : null;
    }

    // 选项点击切换
    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            var factor = this.value;

            // 更新选中样式
            form.querySelectorAll('.factor-option').forEach(function(opt) {
                opt.classList.toggle('selected', opt.querySelector('input').checked);
            });

            hideAllInputs();

            var inputEl = document.getElementById('factor-input-' + factor);
            if (inputEl) {
                inputEl.style.display = 'block';
            }

            // 更新按钮文字
            var labels = { password: t('验证密码'), email_code: t('验证邮箱'), totp: t('验证动态码'), recovery_code: t('验证恢复码') };
            verifyBtn.textContent = labels[factor] || t('验证');
            verifyBtn.disabled = false;
        });
    });

    // 发送验证码
    if (sendBtn) {
        sendBtn.addEventListener('click', function() {
            var self = this;
            self.disabled = true;
            self.textContent = t('发送中...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/2fa/verify/send-code', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                self.disabled = false;
                self.textContent = t('发送验证码');
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        showToast(data.message, 'success');
                        // 60秒倒计时
                        var countdown = 60;
                        self.disabled = true;
                        var timer = setInterval(function() {
                            self.textContent = t('重新发送({:n}s)', { n: countdown });
                            countdown--;
                            if (countdown < 0) {
                                clearInterval(timer);
                                self.disabled = false;
                                self.textContent = t('发送验证码');
                            }
                        }, 1000);
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast(t('发送失败'), 'error');
                }
            };
            xhr.onerror = function() {
                self.disabled = false;
                self.textContent = t('发送验证码');
                showToast(t('网络错误'), 'error');
            };
            // 账号有多个邮箱时，页面上有一个收码邮箱下拉（默认首选）
            var target = document.getElementById('twofa-email-target');
            xhr.send('_csrf=' + encodeURIComponent(getCsrfToken()) + '&nonce=' + encodeURIComponent(nonce)
                + (target ? '&send_to=' + encodeURIComponent(target.value) : ''));
        });
    }

    // 提交验证
    verifyBtn.addEventListener('click', function() {
        var factor = getSelectedFactor();
        if (!factor) {
            showToast(t('请选择验证方式'), 'warning');
            return;
        }

        var valueInput = document.getElementById('factor-value-' + factor);
        var value = valueInput ? valueInput.value.trim() : '';

        if (!value) {
            showToast(t('请输入验证信息'), 'warning');
            return;
        }

        verifyBtn.disabled = true;
        verifyBtn.textContent = t('验证中...');

        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/2fa/verify', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            verifyBtn.disabled = false;
            verifyBtn.textContent = t('验证');
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    showToast(t('验证通过'), 'success');
                    window.location.href = data.redirect || '/dashboard';
                } else {
                    showToast(data.message, 'error');
                }
            } catch(e) {
                showToast(t('请求失败'), 'error');
            }
        };
        xhr.onerror = function() {
            verifyBtn.disabled = false;
            verifyBtn.textContent = t('验证');
            showToast(t('网络错误'), 'error');
        };
        xhr.send('_csrf=' + encodeURIComponent(getCsrfToken()) + '&nonce=' + encodeURIComponent(nonce) + '&factor_type=' + encodeURIComponent(factor) + '&factor_value=' + encodeURIComponent(value));
    });
}

// ========== TOTP 设置 ==========
function initSettingsTotp() {
    if (document.getElementById('settings-app') || document.getElementById('recovery-secure-app')) return;

    var enableBtn = document.getElementById('totp-enable-btn');
    var disableBtn = document.getElementById('totp-disable-btn');
    var setupForm = document.getElementById('totp-setup-form');
    var verifyBtn = document.getElementById('totp-verify-btn');
    var verifyCodeInput = document.getElementById('totp-verify-code');
    var secretDisplay = document.getElementById('totp-secret-display');
    var qrPlaceholder = document.getElementById('totp-qr-placeholder');
    var cancelBtn = document.getElementById('totp-setup-cancel-btn');
    var enabledDiv = document.getElementById('totp-enabled');
    var disabledDiv = document.getElementById('totp-disabled');

    if (!enableBtn && !disableBtn) return;

    function getCsrf() {
        var inp = document.querySelector('input[name="_csrf"]');
        return inp ? inp.value : '';
    }

    function loadStatus() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/2fa/totp/status', true);
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    if (enabledDiv) enabledDiv.style.display = data.enabled ? 'block' : 'none';
                    if (disabledDiv) disabledDiv.style.display = data.enabled ? 'none' : 'block';
                }
            } catch(e) {}
        };
        xhr.send();
    }

    loadStatus();

    // 启用 TOTP
    if (enableBtn) {
        enableBtn.addEventListener('click', function() {
            var xhr = new XMLHttpRequest();
            xhr.open('GET', '/2fa/totp/setup', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        if (data.has_totp) {
                            showToast(t('TOTP 已启用'), 'info');
                            loadStatus();
                            return;
                        }

                        setupForm.style.display = 'block';
                        enableBtn.style.display = 'none';

                        // 显示密钥
                        if (secretDisplay) {
                            secretDisplay.textContent = data.secret || '';
                        }

                        // 生成二维码（用 goQR API，不依赖任何 JS 库）
                        if (qrPlaceholder && data.uri) {
                            qrPlaceholder.innerHTML = '<img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=' + encodeURIComponent(data.uri) + '" alt="' + t('TOTP 二维码') + '" style="width:180px;height:180px;border-radius:8px;display:block;" onerror="this.style.display=\'none\';this.parentNode.innerHTML=\'<p style=font-size:13px;color:var(--color-text-subtle);>' + t('无法加载二维码，请手动输入下方密钥') + '</p>\'">';
                        }
                    }
                } catch(e) {
                    showToast(t('加载失败'), 'error');
                }
            };
            xhr.onerror = function() { showToast(t('网络错误'), 'error'); };
            xhr.send();
        });
    }

    // 验证并启用
    if (verifyBtn) {
        verifyBtn.addEventListener('click', function() {
            var code = verifyCodeInput ? verifyCodeInput.value.trim() : '';
            if (!code || code.length !== 6) {
                showToast(t('请输入6位验证码'), 'warning');
                return;
            }

            verifyBtn.disabled = true;
            verifyBtn.textContent = t('验证中...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/2fa/totp/verify', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                verifyBtn.disabled = false;
                verifyBtn.textContent = t('验证并启用');
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        showToast(t('TOTP 已启用'), 'success');
                        setupForm.style.display = 'none';
                        if (enableBtn) enableBtn.style.display = 'none';
                        if (disabledDiv) disabledDiv.style.display = 'none';
                        if (enabledDiv) enabledDiv.style.display = 'block';
                        // TOTP 启用时自动生成的恢复码在这里补展示，用户必须当场保存
                        if (data.recovery_codes && data.recovery_codes.length) {
                            displayRecoveryCodes(data.recovery_codes);
                        }
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast(t('请求失败'), 'error');
                }
            };
            xhr.onerror = function() {
                verifyBtn.disabled = false;
                verifyBtn.textContent = t('验证并启用');
                showToast(t('网络错误'), 'error');
            };
            xhr.send('_csrf=' + encodeURIComponent(getCsrf()) + '&code=' + encodeURIComponent(code));
        });
    }

    // 取消设置
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            setupForm.style.display = 'none';
            if (enableBtn) enableBtn.style.display = 'inline-block';
        });
    }

    // 禁用 TOTP
    if (disableBtn) {
        // 恢复流程下后端已跳过密码校验，这里不能再用 required 把空输入挡死
        var disableRecoveryMode = disableBtn.dataset.recoveryMode === '1';
        disableBtn.addEventListener('click', function() {
            Nomen.prompt(t('禁用 TOTP'), disableRecoveryMode
                ? t('恢复流程中无需填写密码，直接确认即可：')
                : t('请输入当前密码以确认禁用 TOTP 验证器：'), {
                confirmText: t('确认禁用'),
                cancelText: t('取消'),
                danger: true,
                inputType: 'password',
                placeholder: disableRecoveryMode ? t('恢复流程中可留空') : t('当前密码'),
                required: !disableRecoveryMode
            }).then(function(password) {
                if (password === null) return;

                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/2fa/totp/disable', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    try {
                        var data = JSON.parse(xhr.responseText);
                        if (data.success) {
                            showToast(t('TOTP 已禁用'), 'success');
                            loadStatus();
                            if (setupForm) setupForm.style.display = 'none';
                            if (enableBtn) enableBtn.style.display = 'inline-block';
                        } else {
                            showToast(data.message, 'error');
                        }
                    } catch(e) {
                        showToast(t('请求失败'), 'error');
                    }
                };
                xhr.onerror = function() { showToast(t('网络错误'), 'error'); };
                xhr.send('_csrf=' + encodeURIComponent(getCsrf()) + '&password=' + encodeURIComponent(password));
            });
        });
    }
}

// ========== 恢复码设置 ==========
function initSettingsRecoveryCodes() {
    if (document.getElementById('settings-app') || document.getElementById('recovery-secure-app')) return;

    var listEl = document.getElementById('recovery-codes-list');
    var genBtn = document.getElementById('recovery-codes-gen-btn');
    var outputDiv = document.getElementById('recovery-codes-output');
    var newCodesDiv = document.getElementById('recovery-codes-new');
    var closeBtn = document.getElementById('recovery-codes-close-btn');
    var passwordForm = document.getElementById('recovery-codes-password-form');
    var passwordInput = document.getElementById('recovery-codes-password');
    var confirmGenBtn = document.getElementById('recovery-codes-confirm-gen-btn');
    var cancelGenBtn = document.getElementById('recovery-codes-cancel-btn');

    if (!listEl) return;

    // 恢复流程下后端已跳过密码校验，空密码也允许提交
    var recoveryMode = !!(passwordForm && passwordForm.dataset.recoveryMode === '1');

    function getCsrf() {
        var inp = document.querySelector('input[name="_csrf"]');
        return inp ? inp.value : '';
    }

    function deleteAllCodes(btn) {
        Nomen.prompt(t('删除全部恢复码'), recoveryMode
            ? t('恢复流程中无需填写密码，直接确认即可。删除后所有恢复码立即失效：')
            : t('请输入当前密码以删除全部恢复码，删除后这些码立即失效：'), {
            confirmText: t('删除'),
            cancelText: t('取消'),
            danger: true,
            inputType: 'password',
            placeholder: recoveryMode ? t('恢复流程中可留空') : t('当前密码'),
            required: !recoveryMode
        }).then(function(password) {
            if (password === null) return;
            btn.disabled = true;
            btn.textContent = t('删除中...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/2fa/recovery-codes/delete', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                var d;
                try { d = JSON.parse(xhr.responseText); } catch (e) { d = {}; }
                if (d.success) {
                    showToast(d.message || t('恢复码已删除'), 'success');
                    loadCodes();
                } else {
                    showToast(d.message || t('删除失败'), 'error');
                    btn.disabled = false;
                    btn.textContent = t('删除全部恢复码');
                }
            };
            xhr.onerror = function() {
                showToast(t('网络错误'), 'error');
                btn.disabled = false;
                btn.textContent = t('删除全部恢复码');
            };
            xhr.send('_csrf=' + encodeURIComponent(getCsrf()) + '&password=' + encodeURIComponent(password));
        });
    }

    function loadCodes() {
        listEl.innerHTML = '<p class="text-muted">' + t('加载中...') + '</p>';
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/2fa/recovery-codes', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    if (!data.batches || data.batches.length === 0) {
                        listEl.innerHTML = '<p class="text-muted">' + t('还没有生成过恢复码。生成后即使不启用验证器 APP，登录二验时也能选择「恢复码」验证。') + '</p>';
                        return;
                    }
                    var totalUnused = 0;
                    data.batches.forEach(function(batch) {
                        totalUnused += parseInt(batch.unused_count, 10);
                    });
                    var status = totalUnused > 0
                        ? '<p style="margin-bottom:12px;">' + iconTag('check', 'icon--success') + ' <span style="color:var(--color-success);font-weight:600;">' + t('恢复码已启用') + '</span>' + t('，剩余 {:n} 个未使用', { n: totalUnused }) + '</p>'
                        : '<p style="margin-bottom:12px;">' + iconTag('warning', 'icon--warning') + ' <span style="color:var(--color-warning);font-weight:600;">' + t('恢复码已全部用完') + '</span>' + t('，登录验证将无法使用恢复码，建议重新生成') + '</p>';
                    var html = status;
                    data.batches.forEach(function(batch) {
                        var unused = parseInt(batch.unused_count, 10);
                        var total = parseInt(batch.total_count, 10);
                        var used = total - unused;
                        html += '<div class="recovery-batch">' +
                            '<div class="recovery-batch__header">' +
                                '<span>' + t('批次 {:id}...', { id: batch.batch_id.slice(0, 8) }) + '</span>' +
                                '<span class="recovery-batch__count">' +
                                    '<span style="color:var(--color-success);">' + t('{:n} 未使用', { n: unused }) + '</span>' +
                                    ' / ' + t('{:n} 已使用', { n: used }) + '</span>' +
                            '</div>' +
                            '<div style="font-size:12px;color:var(--color-text-subtle);">' +
                                t('创建时间: ') + batch.created_at +
                            '</div>' +
                        '</div>';
                    });
                    html += '<div style="margin-top:12px;">' +
                        '<button type="button" class="btn btn-sm btn-danger-outline" id="recovery-codes-delete-btn">' + t('删除全部恢复码') + '</button>' +
                    '</div>';
                    listEl.innerHTML = html;
                    var delBtn = document.getElementById('recovery-codes-delete-btn');
                    if (delBtn) delBtn.addEventListener('click', function() { deleteAllCodes(delBtn); });
                } else {
                    listEl.innerHTML = '<p class="text-muted">' + t('加载失败') + '</p>';
                }
            } catch(e) {
                listEl.innerHTML = '<p class="text-muted">' + t('加载失败') + '</p>';
            }
        };
        xhr.onerror = function() {
            listEl.innerHTML = '<p class="text-muted">' + t('网络错误') + '</p>';
        };
        xhr.send();
    }

    loadCodes();

    // 生成新恢复码
    if (genBtn) {
        genBtn.addEventListener('click', function() {
            if (passwordForm) passwordForm.style.display = 'block';
        });
    }

    if (cancelGenBtn) {
        cancelGenBtn.addEventListener('click', function() {
            passwordForm.style.display = 'none';
            if (passwordInput) passwordInput.value = '';
        });
    }

    if (confirmGenBtn) {
        confirmGenBtn.addEventListener('click', function() {
            var password = passwordInput ? passwordInput.value : '';
            if (!password && !recoveryMode) {
                showToast(t('请输入当前密码'), 'warning');
                return;
            }

            confirmGenBtn.disabled = true;
            confirmGenBtn.textContent = t('生成中...');

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/2fa/recovery-codes/generate', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                confirmGenBtn.disabled = false;
                confirmGenBtn.textContent = t('确认生成');
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        displayRecoveryCodes(data.codes);
                        if (passwordForm) passwordForm.style.display = 'none';
                        if (passwordInput) passwordInput.value = '';
                        showToast(data.message || t('恢复码已生成'), 'success');
                    } else {
                        showToast(data.message, 'error');
                    }
                } catch(e) {
                    showToast(t('请求失败'), 'error');
                }
            };
            xhr.onerror = function() {
                confirmGenBtn.disabled = false;
                confirmGenBtn.textContent = t('确认生成');
                showToast(t('网络错误'), 'error');
            };
            xhr.send('_csrf=' + encodeURIComponent(getCsrf()) + '&password=' + encodeURIComponent(password));
        });
    }

    // 打印恢复码：打印期间只显示码区
    var printBtn = document.getElementById('recovery-codes-print-btn');
    if (printBtn) {
        printBtn.addEventListener('click', function() {
            document.body.classList.add('printing-recovery-codes');
            var cleanup = function() {
                document.body.classList.remove('printing-recovery-codes');
                window.removeEventListener('afterprint', cleanup);
            };
            window.addEventListener('afterprint', cleanup);
            window.print();
            setTimeout(cleanup, 2000);
        });
    }

    // 关闭恢复码显示
    if (closeBtn) {
        closeBtn.addEventListener('click', function() {
            outputDiv.style.display = 'none';
            if (newCodesDiv) newCodesDiv.innerHTML = '';
            loadCodes(); // 刷新列表
        });
    }
}

// 展示一批明文恢复码（生成 / TOTP 启用成功两条路径共用），只显示到关闭前
function displayRecoveryCodes(codes) {
    var outputDiv = document.getElementById('recovery-codes-output');
    var newCodesDiv = document.getElementById('recovery-codes-new');
    if (!outputDiv || !newCodesDiv || !codes || !codes.length) return;

    var html = '';
    codes.forEach(function(code) {
        html += '<div class="recovery-code-item">' + escapeHtml(code) + '</div>';
    });
    newCodesDiv.innerHTML = html;
    outputDiv.style.display = 'block';
    outputDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

// ========== 消息中心 ==========
function initNotificationsPage() {
    var readAllBtn = document.getElementById('notif-read-all-btn');
    if (!readAllBtn) return; // 不在消息中心页

    // 进过消息中心就视为看过最新一条，飘出提醒不再重复弹（已读仍以点开/全部已读为准）
    var bell = document.querySelector('.nav-notif');
    if (bell && bell.dataset.notifLatestId) {
        try { localStorage.setItem('nomen_notif_last_seen', bell.dataset.notifLatestId); } catch(e) {}
    }

    if (readAllBtn.disabled) return;

    function getCsrf() {
        var inp = document.querySelector('input[name="_csrf"]');
        return inp ? inp.value : '';
    }

    readAllBtn.addEventListener('click', function() {
        readAllBtn.disabled = true;
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/notifications/read-all', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = function() {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    document.querySelectorAll('.notif-item--unread').forEach(function(item) {
                        item.classList.remove('notif-item--unread');
                    });
                    document.querySelectorAll('.notif-item__dot').forEach(function(dot) {
                        dot.remove();
                    });
                    var countText = document.querySelector('.dashboard-header .text-muted');
                    if (countText) countText.remove();
                    readAllBtn.disabled = true;

                    var bell = document.querySelector('.nav-notif');
                    if (bell) {
                        bell.dataset.notifUnread = '0';
                        var badge = bell.querySelector('.nav-notif__count');
                        if (badge) badge.remove();
                    }

                    showToast(data.message || t('已全部标记为已读'), 'success');
                    return;
                }
                showToast(data.message || t('操作失败'), 'error');
            } catch(e) {
                showToast(t('请求失败'), 'error');
            }
            readAllBtn.disabled = false;
        };
        xhr.onerror = function() {
            readAllBtn.disabled = false;
            showToast(t('网络错误'), 'error');
        };
        xhr.send('_csrf=' + encodeURIComponent(getCsrf()));
    });
}

// 新消息飘出提醒：铃铛上的最新未读比本地记的更新时弹一次 toast。
// 点正文 = 标已读 + 跳到消息目标；点叉叉 / 自动消失 = 只记住不重复飘，消息保持未读
function initNotifPopup() {
    var bell = document.querySelector('.nav-notif');
    if (!bell || !bell.dataset.notifLatestId) return;

    var KEY = 'nomen_notif_last_seen';
    var latestId = parseInt(bell.dataset.notifLatestId, 10) || 0;
    var lastSeen = parseInt(localStorage.getItem(KEY) || '0', 10);
    if (!latestId || latestId <= lastSeen) return;

    var unread = parseInt(bell.dataset.notifUnread || '1', 10);
    var title = bell.dataset.notifLatestTitle || t('新消息');
    var link = bell.dataset.notifLatestLink || ('/notifications/' + latestId);

    var text = unread > 1 ? t('你有 {:n} 条新消息\n最新：{:title}', { n: unread, title: title }) : title;
    var toast = showToast(text, 'info', 10000);
    if (!toast) return;

    toast.classList.add('nomen-toast--popup');
    toast.setAttribute('role', 'button');

    function remember() {
        try { localStorage.setItem(KEY, String(latestId)); } catch(e) {}
    }

    function markReadAndGo() {
        remember();
        var inp = document.querySelector('input[name="_csrf"]');
        var xhr = new XMLHttpRequest();
        var done = false;
        function go() { if (!done) { done = true; window.location.href = link; } }
        xhr.open('POST', '/notifications/read', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.onload = go;
        xhr.onerror = go;
        setTimeout(go, 1500);
        xhr.send('_csrf=' + encodeURIComponent(inp ? inp.value : '') + '&id=' + latestId);
    }

    toast.addEventListener('click', function(e) {
        if (e.target.closest('.nomen-toast__close')) return;
        markReadAndGo();
    });

    // 叉叉 / 自动消失：只记住，不清未读
    var container = toast.parentNode;
    if (container && window.MutationObserver) {
        var observer = new MutationObserver(function() {
            if (!document.contains(toast)) {
                remember();
                observer.disconnect();
            }
        });
        observer.observe(container, { childList: true });
    }
}

// 设置页锚点：带 hash 进入时平滑滚到目标区块并高亮提示（原生跳转会先发生，这里接管视觉）
function initSettingsAnchor() {
    if (document.getElementById('settings-app')) return;

    if (!document.querySelector('.settings-section')) return;

    var hash = window.location.hash;
    if (!hash || hash.length < 2) return;

    var target = document.getElementById(decodeURIComponent(hash.slice(1)));
    if (!target || !target.classList.contains('settings-section')) return;

    target.classList.add('settings-section--flash');
    target.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // 原生锚点跳转可能先于字体/图片加载发生，兜底再滚一次
    setTimeout(function() {
        target.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 300);
}

// ========== 主题切换 ==========
(function initTheme() {
    var saved = localStorage.getItem('theme');
    if (saved) {
        document.documentElement.setAttribute('data-theme', saved);
    } else {
        var prefersDark = window.matchMedia('(prefers-color-scheme: dark)');
        document.documentElement.setAttribute('data-theme', prefersDark.matches ? 'dark' : 'light');
        prefersDark.addEventListener('change', function(e) {
            if (!localStorage.getItem('theme')) {
                document.documentElement.setAttribute('data-theme', e.matches ? 'dark' : 'light');
            }
        });
    }
})();

function toggleTheme() {
    setTheme(document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
}

function setTheme(theme) {
    if (theme !== 'light' && theme !== 'dark') return;
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);
    syncThemeButtons();
}

function syncThemeButtons() {
    var current = document.documentElement.getAttribute('data-theme');
    document.querySelectorAll('.mobile-theme-btn').forEach(function(btn) {
        btn.classList.toggle('active', btn.getAttribute('data-theme-value') === current);
    });
}

// ========== 移动端抽屉菜单 ==========
function initMobileMenu() {
    var btn = document.getElementById('mobile-menu-btn');
    var overlay = document.getElementById('mobile-menu-overlay');
    var drawer = document.getElementById('mobile-menu-drawer');
    if (!btn || !overlay || !drawer) return;

    function openMenu() {
        btn.classList.add('active');
        btn.setAttribute('aria-expanded', 'true');
        overlay.classList.add('show');
        drawer.classList.add('show');
        document.body.classList.add('mobile-menu-open');
    }

    function closeMenu() {
        btn.classList.remove('active');
        btn.setAttribute('aria-expanded', 'false');
        overlay.classList.remove('show');
        drawer.classList.remove('show');
        document.body.classList.remove('mobile-menu-open');
    }

    btn.addEventListener('click', function() {
        drawer.classList.contains('show') ? closeMenu() : openMenu();
    });
    overlay.addEventListener('click', closeMenu);
    drawer.querySelectorAll('a').forEach(function(link) {
        link.addEventListener('click', closeMenu);
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && drawer.classList.contains('show')) closeMenu();
    });

    drawer.querySelectorAll('.mobile-theme-btn').forEach(function(themeBtn) {
        themeBtn.addEventListener('click', function() {
            setTheme(themeBtn.getAttribute('data-theme-value'));
        });
    });
    syncThemeButtons();
}

// ========== 统计数字动画 ==========
function initStatCounters() {
    var counters = document.querySelectorAll('.cloud-stat-counter');
    if (!counters.length) return;

    var observer = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
            if (!entry.isIntersecting) return;

            var el = entry.target;
            var target = parseInt(el.getAttribute('data-target'), 10);
            if (isNaN(target)) return;

            var duration = 2000;
            var startTime = performance.now();

            function animate(now) {
                var elapsed = now - startTime;
                var progress = Math.min(elapsed / duration, 1);
                var ease = progress === 1 ? 1 : 1 - Math.pow(2, -10 * progress);
                el.textContent = Math.floor(ease * target) + '+';

                if (progress < 1) {
                    requestAnimationFrame(animate);
                }
            }

            requestAnimationFrame(animate);
            observer.unobserve(el);
        });
    }, { threshold: 0.5 });

    counters.forEach(function(counter) {
        observer.observe(counter);
    });
}

// 卡片式单选（.factor-select）：跟着勾选状态维护 .selected 高亮
function initFactorSelects() {
    if (document.getElementById('twofa-app')) return;

    document.querySelectorAll('.factor-select').forEach(function(group) {
        function sync() {
            group.querySelectorAll('.factor-option').forEach(function(opt) {
                var input = opt.querySelector('input');
                if (input) opt.classList.toggle('selected', input.checked);
            });
        }
        sync();
        group.addEventListener('change', sync);
    });
}

// 应用设置：改成「公共客户端」是放弃密钥认证的降级操作，先让开发者确认一次
function initAppClientTypeGuard() {
    var radios = document.querySelectorAll('input[name="client_type"]');
    var checked = document.querySelector('input[name="client_type"]:checked');
    if (!radios.length || !checked) return;

    var original = checked.value;

    radios.forEach(function(radio) {
        radio.addEventListener('change', function() {
            if (this.value !== 'public' || original === 'public') return;

            Nomen.confirm(t('改为公共客户端？'), t('公共客户端不校验 Client Secret，任何知道 Client ID 的人都能发起授权，只有确实拿不住密钥的应用才该选它。确认接入代码已实现 PKCE 再继续。'), {
                danger: true,
                confirmText: t('仍要改为公共客户端')
            }).then(function(ok) {
                if (ok) {
                    original = 'public';
                    return;
                }
                var back = document.querySelector('input[name="client_type"][value="' + original + '"]');
                if (back) {
                    back.checked = true;
                    back.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });
    });
}

// ========== 强密码生成 / 密码框增强 ==========

// 去掉了易混字符（0 O 1 l I）
var PW_UPPER = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
var PW_LOWER = 'abcdefghijkmnopqrstuvwxyz';
var PW_DIGIT = '23456789';
var PW_SYMBOL = '!@#$%^&*-_=+';

// 拒绝采样取 [0, max) 的随机下标：直接 % 在这几个字符集上都会引入取模偏差
function randomIndex(max) {
    var limit = 256 - (256 % max);
    var buf = new Uint8Array(1);
    for (;;) {
        crypto.getRandomValues(buf);
        if (buf[0] < limit) return buf[0] % max;
    }
}

function randomChars(alphabet, count) {
    var out = '';
    for (var i = 0; i < count; i++) out += alphabet[randomIndex(alphabet.length)];
    return out;
}

function generateStrongPassword(length) {
    length = length || 16;
    var all = PW_UPPER + PW_LOWER + PW_DIGIT + PW_SYMBOL;
    var chars = (randomChars(PW_UPPER, 1) + randomChars(PW_LOWER, 1) + randomChars(PW_DIGIT, 1) +
                 randomChars(PW_SYMBOL, 1) + randomChars(all, length - 4)).split('');

    for (var i = chars.length - 1; i > 0; i--) {
        var j = randomIndex(i + 1);
        var t = chars[i];
        chars[i] = chars[j];
        chars[j] = t;
    }
    return chars.join('');
}

function passwordStrength(password) {
    var score = 0;
    if (password.length >= 8) score++;
    if (password.length >= 12) score++;
    if (/[a-z]/.test(password) && /[A-Z]/.test(password)) score++;
    if (/[0-9]/.test(password)) score++;
    if (/[^a-zA-Z0-9]/.test(password)) score++;

    if (score <= 1) return { label: t('弱'), color: 'var(--color-danger)', width: '20%' };
    if (score <= 2) return { label: t('中'), color: 'var(--color-warning)', width: '40%' };
    if (score <= 3) return { label: t('强'), color: 'var(--color-info)', width: '70%' };
    return { label: t('非常强'), color: 'var(--color-success)', width: '100%' };
}

function copyText(text) {
    if (navigator.clipboard && window.isSecureContext) {
        return navigator.clipboard.writeText(text).then(function() {
            return true;
        }).catch(function() {
            return copyTextFallback(text);
        });
    }
    return Promise.resolve(copyTextFallback(text));
}

function copyTextFallback(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.setAttribute('readonly', '');
    ta.style.position = 'fixed';
    ta.style.top = '-1000px';
    document.body.appendChild(ta);
    ta.select();
    var ok = false;
    try {
        ok = document.execCommand('copy');
    } catch (e) {
        ok = false;
    }
    document.body.removeChild(ta);
    return ok;
}

// 给密码框加 data-pw-gen="确认框的 id"，即可获得：聚焦时才出现的「生成」按钮 + 强度条
function initPasswordTools() {
    document.querySelectorAll('input[type="password"][data-pw-gen]').forEach(function(input) {
        var wrap = input.parentElement;
        if (!wrap || !wrap.classList.contains('pw-wrap')) return;

        var confirmInput = document.getElementById(input.dataset.pwGen);

        var meter = document.createElement('div');
        meter.className = 'pw-strength';
        meter.innerHTML = '<div class="pw-strength__bar"><div class="pw-strength__fill"></div></div>' +
                          '<span class="pw-strength__text">' + t('密码强度') + '</span>';
        wrap.parentElement.appendChild(meter);
        var fill = meter.querySelector('.pw-strength__fill');
        var text = meter.querySelector('.pw-strength__text');

        function refreshStrength() {
            var s = passwordStrength(input.value);
            fill.style.width = s.width;
            fill.style.background = s.color;
            text.textContent = t('密码强度：{:label}', { label: s.label });
            text.style.color = s.color;
        }

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'pw-gen';
        btn.textContent = t('生成');
        btn.title = t('生成一个随机强密码');
        // 不阻止的话按下按钮会先让密码框失焦，按钮随 :focus-within 消失、click 就永远不触发
        btn.addEventListener('mousedown', function(e) {
            e.preventDefault();
        });
        btn.addEventListener('click', function() {
            var password = generateStrongPassword(16);
            input.value = password;
            if (confirmInput) confirmInput.value = password;
            // 生成后必须明文显示，否则用户看不见拿到的是什么
            input.type = 'text';
            if (confirmInput) confirmInput.type = 'text';
            var eye = wrap.querySelector('.eye-icon');
            if (eye) eye.className = 'eye-icon icon icon-eye-off';
            input.dispatchEvent(new Event('input', { bubbles: true }));
            if (confirmInput) confirmInput.dispatchEvent(new Event('input', { bubbles: true }));
            input.focus();

            copyText(password).then(function(ok) {
                Nomen.toast(ok
                    ? t('已生成强密码并复制。粘贴到密码管理器后，请清空剪贴板。')
                    : t('已生成强密码。请在密码框中全选复制，并注意清空剪贴板。'),
                    ok ? 'success' : 'warning', 6000);
            });
        });
        meter.appendChild(btn);
        input.addEventListener('input', refreshStrength);
    });
}

// ========== 页面初始化 ==========
document.addEventListener('DOMContentLoaded', function() {
    initMobileMenu();
    initLangPicker();
    initStatCounters();
    NOMEN_LOGIN.consumeHandoff();
    initLoginPage();
    initFactorSelects();
    initAppClientTypeGuard();
    initPasswordTools();

    // 密码确认验证
    var registerForm = document.querySelector('form[action="/register"]');
    if (registerForm) {
        registerForm.addEventListener('submit', function(e) {
            var password = registerForm.querySelector('input[name="password"]');
            var confirm = registerForm.querySelector('input[name="password_confirm"]');
            if (password && confirm && password.value !== confirm.value) {
                e.preventDefault();
                confirm.setCustomValidity(t('两次输入的密码不一致'));
                confirm.reportValidity();
            }
        });
    }

    // 授权页面
    initAuthorizePage();
});

function initAuthorizePage() {
    // 授权页已迁到 Vue；旧逻辑留着只会和组件抢同一个表单
    if (document.getElementById('authorize-app')) return;

    var form = document.getElementById('authorizeForm');
    if (!form) return;

    // 第一方应用：没有勾选项，打勾动画播完就自动提交，
    // 顶部进度条一直晃到浏览器跳回 callback 为止
    var autoBox = document.getElementById('authorizeAuto');
    if (autoBox) {
        var progress = document.createElement('div');
        progress.className = 'nomen-top-progress';
        progress.innerHTML = '<div class="nomen-top-progress__bar"></div>';
        document.body.appendChild(progress);

        var autoText = document.getElementById('authorizeAutoText');
        setTimeout(function() {
            progress.classList.add('nomen-top-progress--active');
            if (autoText) {
                autoText.textContent = t('授权完成，正在返回 {:name}…', { name: form.dataset.appName || t('应用') });
            }
            form.submit();
        }, 1200);
        return;
    }

    var scopeInput = form.querySelector('input[name="scope"]');
    var checkboxes = form.querySelectorAll('input[name="scopes[]"]');

    function updateScope() {
        var scopes = ['openid'];
        checkboxes.forEach(function(cb) {
            if (cb.checked) scopes.push(cb.value);
        });
        if (scopeInput) scopeInput.value = scopes.join(' ');
    }

    checkboxes.forEach(function(cb) {
        cb.addEventListener('change', function() {
            var item = cb.closest('.scope-item');
            if (item) item.classList.toggle('denied', !cb.checked);
            updateScope();
        });
    });

    updateScope();

    form.addEventListener('submit', function(e) {
        e.preventDefault();

        // 收集中危和高危 scope
        var mediumRiskScopes = [];
        var highRiskScopes = [];

        checkboxes.forEach(function(cb) {
            if (!cb.checked) return;
            var mediumItem = cb.closest('.scope-item--medium-risk');
            var highItem = cb.closest('.scope-item--high-risk');
            var name = cb.closest('.scope-item').querySelector('.scope-name');
            var label = name ? name.textContent : cb.value;
            if (mediumItem) mediumRiskScopes.push(label);
            else if (highItem) highRiskScopes.push(label);
        });

        var appName = form.dataset.appName || t('该应用');

        // 先弹中危确认，再弹高危确认
        if (mediumRiskScopes.length > 0) {
            showConfirmModal(appName, mediumRiskScopes, function(confirmed) {
                if (!confirmed) return;
                if (highRiskScopes.length > 0) {
                    showRiskModal(highRiskScopes, function(confirmed2) {
                        if (confirmed2) { updateScope(); Nomen.beginSubmit(form); form.submit(); }
                    });
                } else {
                    updateScope(); Nomen.beginSubmit(form); form.submit();
                }
            });
        } else if (highRiskScopes.length > 0) {
            showRiskModal(highRiskScopes, function(confirmed) {
                if (confirmed) { updateScope(); Nomen.beginSubmit(form); form.submit(); }
            });
        } else {
            updateScope();
            Nomen.beginSubmit(form);
            form.submit();
        }
    });
}

// ========== 登录页 ==========
// 浏览器侧记两样东西：上次登录成功的账号（预填用）和登录方式历史（决定推荐哪种方式）。
// 两者都由服务端在登录成功时通过一次性 cookie（nomen_login_ok）交接过来，
// 记的是账号真实的用户名 / 邮箱，不是用户在框里打的那串。
var NOMEN_LOGIN = {
    ID_KEY: 'nomen_login_id_v1',
    HIST_KEY: 'nomen_login_hist_v1',
    HANDOFF_COOKIE: 'nomen_login_ok',
    HIST_MAX: 12,
    // 推荐次序；某个方式失败后往下顺延也是这个次序
    ORDER: ['password', 'email_code', 'biometric'],
    FORMS: { password: 'password-form', email_code: 'code-form', biometric: 'biometric-form' },

    formFor: function(method) {
        var id = NOMEN_LOGIN.FORMS[method];
        return id ? document.getElementById(id) : null;
    },

    supported: function() {
        return !!(window.PublicKeyCredential && navigator.credentials && navigator.credentials.get);
    },

    available: function(method) {
        return method === 'biometric' ? NOMEN_LOGIN.supported() : !!NOMEN_LOGIN.formFor(method);
    },

    readJson: function(key, fallback) {
        try {
            var raw = localStorage.getItem(key);
            return raw ? JSON.parse(raw) : fallback;
        } catch (e) {
            return fallback;
        }
    },

    writeJson: function(key, value) {
        try {
            localStorage.setItem(key, JSON.stringify(value));
        } catch (e) {
            // 无痕模式或存储被禁用：记不住就退回默认行为，不影响登录
        }
    },

    consumeHandoff: function() {
        var raw = readCookie(NOMEN_LOGIN.HANDOFF_COOKIE);
        if (!raw) return;

        document.cookie = NOMEN_LOGIN.HANDOFF_COOKIE + '=; Max-Age=0; path=/';

        var data = null;
        try {
            data = JSON.parse(base64urlToText(raw));
        } catch (e) {
            return;
        }
        if (!data || !data.u) return;

        NOMEN_LOGIN.writeJson(NOMEN_LOGIN.ID_KEY, { u: data.u, e: data.e || '' });

        var hist = NOMEN_LOGIN.readJson(NOMEN_LOGIN.HIST_KEY, []);
        if (!Array.isArray(hist)) hist = [];
        hist.push({ u: data.u, e: data.e || '', m: data.m || 'password', t: Math.floor(Date.now() / 1000) });
        NOMEN_LOGIN.writeJson(NOMEN_LOGIN.HIST_KEY, hist.slice(-NOMEN_LOGIN.HIST_MAX));
    },

    // 推荐：最近连续两次都是同一个方式才切过去，否则用密码
    // 密码·密码·邮箱·密码 → 密码；密码·邮箱·邮箱 → 邮箱
    recommend: function() {
        var id = NOMEN_LOGIN.readJson(NOMEN_LOGIN.ID_KEY, null);
        if (!id || !id.u) return 'password';

        var hist = NOMEN_LOGIN.readJson(NOMEN_LOGIN.HIST_KEY, []);
        if (!Array.isArray(hist)) return 'password';

        var mine = [];
        for (var i = 0; i < hist.length; i++) {
            var h = hist[i];
            if (h && (h.u === id.u || (id.e && h.e === id.e))) mine.push(h.m);
        }
        if (mine.length < 2) return 'password';

        var last = mine[mine.length - 1];
        if (mine[mine.length - 2] !== last) return 'password';

        return NOMEN_LOGIN.available(last) ? last : 'password';
    },

    prefill: function() {
        var id = NOMEN_LOGIN.readJson(NOMEN_LOGIN.ID_KEY, null);
        if (!id || !id.u) return;

        // 只填空的框，不覆盖 ?username= / ?email= 带过来的值
        fillIfEmpty(document.getElementById('username'), id.u);
        fillIfEmpty(document.getElementById('biometric-identifier'), id.u);
        if (id.e) fillIfEmpty(document.getElementById('email'), id.e);
    }
};

var NOMEN_LOGIN_LABELS = {
    password: t('密码登录'),
    email_code: t('邮箱验证码登录'),
    biometric: t('生物识别登录'),
    temp_key: t('临时密钥登录')
};

function fillIfEmpty(input, value) {
    if (input && !input.value && value) input.value = value;
}

function readCookie(name) {
    var parts = ('; ' + document.cookie).split('; ' + name + '=');
    return parts.length === 2 ? parts.pop().split(';').shift() : '';
}

function base64urlToText(value) {
    value = value.replace(/-/g, '+').replace(/_/g, '/');
    while (value.length % 4) value += '=';
    var binary = atob(value);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    return new TextDecoder().decode(bytes);
}

// 后端出错时响应体不是 JSON，带上状态码，不然界面上只剩一句「请重试」
function parseJsonResponse(response) {
    return response.text().then(function(text) {
        try {
            return JSON.parse(text);
        } catch (e) {
            throw new Error(t('服务器返回了 {:code}，请稍后重试', { code: response.status }));
        }
    });
}

// 本次还没试过、界面上也支持的第一个方式；preferred 是历史推荐的那个
function pickLoginMethod(tried, preferred) {
    if (preferred && !tried[preferred] && NOMEN_LOGIN.available(preferred)) return preferred;

    for (var i = 0; i < NOMEN_LOGIN.ORDER.length; i++) {
        var method = NOMEN_LOGIN.ORDER[i];
        if (!tried[method] && NOMEN_LOGIN.available(method)) return method;
    }
    return 'password';
}

function initLoginPage() {
    // 登录页已迁到 Vue，别让旧的 DOM 逻辑和新组件抢同一批表单
    if (document.getElementById('login-app')) return;

    var passwordForm = document.getElementById('password-form');
    var codeForm = document.getElementById('code-form');
    var biometricForm = document.getElementById('biometric-form');
    if (!passwordForm || !codeForm) return;

    var sendCodeBtn = document.getElementById('send-code-btn');
    var emailInput = document.getElementById('email');

    var moreToggle = document.getElementById('login-more-toggle');
    var moreList = document.getElementById('login-more-list');
    var triedWrap = document.getElementById('login-tried');
    var triedItems = document.getElementById('login-tried-items');
    var switchHint = document.getElementById('login-switch-hint');

    // 本次已尝试的方式：服务端渲染出来的 + 在页面上当场失败的
    var tried = {};
    document.querySelectorAll('#login-tried-items .login-tried__item').forEach(function(item) {
        tried[item.dataset.tab] = true;
    });

    function closeMore() {
        if (!moreList) return;
        moreList.style.display = 'none';
        if (moreToggle) moreToggle.setAttribute('aria-expanded', 'false');
    }

    function renderTried(current) {
        if (!triedWrap || !triedItems) return;

        var methods = Object.keys(tried).filter(function(method) { return method !== current; });
        triedItems.innerHTML = '';
        methods.forEach(function(method) {
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'login-tried__item';
            btn.dataset.tab = method;
            btn.textContent = NOMEN_LOGIN_LABELS[method] || method;

            var retry = document.createElement('span');
            retry.className = 'login-tried__retry';
            retry.textContent = t('重试');
            btn.appendChild(retry);

            triedItems.appendChild(btn);
        });
        triedWrap.style.display = methods.length ? '' : 'none';
    }

    // 把主区切到某个方式，同时把它从「更多登录方式」里挪走
    function showMethod(method) {
        if (!NOMEN_LOGIN.available(method)) method = 'password';

        var forms = { password: passwordForm, email_code: codeForm, biometric: biometricForm };
        Object.keys(forms).forEach(function(key) {
            if (forms[key]) forms[key].style.display = (key === method) ? '' : 'none';
        });

        if (moreList) {
            moreList.querySelectorAll('[data-tab]').forEach(function(item) {
                item.style.display = (item.dataset.tab === method) ? 'none' : '';
            });
        }

        renderTried(method);
        return method;
    }

    function announceSwitch(method) {
        if (!switchHint) return;

        var allTried = NOMEN_LOGIN.ORDER.every(function(m) {
            return tried[m] || !NOMEN_LOGIN.available(m);
        });

        switchHint.textContent = allTried
            ? t('这几种登录方式本次都没成功。如果都进不去，可以按页面下方的说明发起账号恢复。')
            : t('已为你切换到「{:method}」，需要的话可以在下面「更多登录方式」里换回去。', { method: NOMEN_LOGIN_LABELS[method] || method });
        switchHint.style.display = '';
    }

    var biometricItem = document.querySelector('#login-more-list [data-requires-webauthn]');
    if (biometricItem) biometricItem.style.display = NOMEN_LOGIN.supported() ? '' : 'none';

    NOMEN_LOGIN.prefill();

    // 本次还没试过任何方式 → 用历史推荐；已经试过 → 让给下一个没试过的
    var current = showMethod(pickLoginMethod(tried, Object.keys(tried).length ? null : NOMEN_LOGIN.recommend()));
    if (Object.keys(tried).length) announceSwitch(current);

    if (moreToggle && moreList) {
        moreToggle.addEventListener('click', function() {
            var willOpen = moreList.style.display === 'none';
            moreList.style.display = willOpen ? '' : 'none';
            moreToggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    }

    // 切换登录方式：「更多登录方式」与「本次已尝试」里的项都带 data-tab（含动态加进来的）
    var card = document.querySelector('.auth-card');
    if (card) {
        card.addEventListener('click', function(e) {
            var item = e.target.closest ? e.target.closest('[data-tab]') : null;
            if (!item) return;
            showMethod(item.dataset.tab);
            closeMore();
        });
    }

    initBiometricLogin(function() {
        tried.biometric = true;
        announceSwitch(showMethod(pickLoginMethod(tried, null)));
    });


    // ===== 两种「先选一个再发码」的情况共用同一个面板 =====
    //   account：这个邮箱落在两个账号上（主邮箱 + 备选）→ 选账号
    //   address：填的是用户名、账号绑了多个邮箱 → 选发到哪个（打码地址）
    var pickWrap    = document.getElementById('login-account-pick');
    var pickTip     = document.getElementById('login-account-pick-tip');
    var pickPrimary = document.getElementById('login-account-pick-primary');
    var pickList    = document.getElementById('login-account-pick-list');
    var pickSwitch  = document.getElementById('login-account-pick-switch');
    var accountField = document.getElementById('login-code-account');
    var pickMode  = 'account';
    var pickItems = [];

    function resetPick() {
        pickItems = [];
        if (pickWrap) pickWrap.hidden = true;
        if (pickPrimary) pickPrimary.innerHTML = '';
        if (pickList) { pickList.hidden = true; pickList.innerHTML = ''; }
        if (pickSwitch) pickSwitch.hidden = true;
        if (accountField) accountField.value = '';
    }

    function pickRow(mode, item) {
        var row = document.createElement('button');
        row.type = 'button';
        row.className = 'login-account-pick__item';
        row.dataset.pickId = item.id;

        if (item.avatar) {
            var img = document.createElement('img');
            img.className = 'login-account-pick__avatar';
            img.src = item.avatar;
            img.alt = '';
            row.appendChild(img);
        }

        var text = document.createElement('span');
        text.className = 'login-account-pick__name';
        // 收信地址只给打码值：这一页是未登录状态，不该把账号绑了哪些地址写进响应里
        text.textContent = mode === 'address'
            ? item.masked
            : (item.display_name || item.username) + '（' + item.username + '）';
        row.appendChild(text);

        var tagText = mode === 'address'
            ? (item.is_primary ? t('首选') : '')
            : (item.as_primary ? t('主邮箱') : t('备选邮箱'));
        if (tagText) {
            var tag = document.createElement('span');
            tag.className = 'login-account-pick__tag';
            tag.textContent = tagText;
            row.appendChild(tag);
        }

        return row;
    }

    function renderPick(mode, items, preferred) {
        if (!pickWrap || !items || !items.length) return;

        pickMode  = mode;
        pickItems = items;

        if (pickTip) {
            pickTip.textContent = mode === 'address'
                ? t('这个账号绑了多个邮箱，请选择发到哪一个')
                : t('这个邮箱对应多个账号，请选择要登录哪一个');
        }
        if (pickSwitch) {
            pickSwitch.textContent = mode === 'address' ? t('换一个邮箱收码') : t('我需要另一个账号');
        }

        pickPrimary.innerHTML = '';
        pickList.innerHTML = '';
        pickWrap.hidden = false;

        // 收码地址：**全列出来**让他直接点（用户原话「让他选两个」= 绑了几个就摆几个），
        // 只藏一个再让他点「换一个」太绕。
        // 账号选择器按他自己定的那套：默认「上次登录那个」+「我需要另一个账号」。
        if (mode === 'address' || !preferred) {
            items.forEach(function(item) { pickList.appendChild(pickRow(mode, item)); });
            pickList.hidden = false;
            pickSwitch.hidden = true;
        } else {
            pickPrimary.appendChild(pickRow(mode, preferred));
            pickSwitch.hidden = false;
        }
    }

    /** 账号选择器：有上次登录记录就默认它（localStorage 里记的账号名），没有就直接全列出来 */
    function preferredAccount(accounts) {
        var remembered = NOMEN_LOGIN.readJson(NOMEN_LOGIN.ID_KEY, null);
        var name = remembered && remembered.u ? String(remembered.u) : '';
        for (var i = 0; i < accounts.length; i++) {
            if (name && accounts[i].username === name) return accounts[i];
        }
        return null;
    }

    if (pickSwitch) {
        pickSwitch.addEventListener('click', function() {
            if (!pickList) return;
            // 第一次点才填，之后只是切换显隐
            if (!pickList.childElementCount) {
                pickItems.forEach(function(item) { pickList.appendChild(pickRow(pickMode, item)); });
            }
            pickList.hidden = !pickList.hidden;
            // 只改尾巴，别把「我需要另一个账号 / 换一个邮箱收码」那句写死回来
            pickSwitch.textContent = pickList.hidden
                ? (pickMode === 'address' ? t('换一个邮箱收码') : t('我需要另一个账号'))
                : t('收起');
        });
    }

    if (pickWrap) {
        pickWrap.addEventListener('click', function(e) {
            var item = e.target.closest ? e.target.closest('[data-pick-id]') : null;
            if (!item || sendCodeBtn.disabled) return;   // 正在发就别重复点
            var id = item.dataset.pickId;
            sendCode(pickMode === 'address' ? { send_to_id: id } : { account_id: id });
        });
    }

    // 账号换了、邮箱改了，之前选中的那个就不作数了
    emailInput.addEventListener('input', function() { resetPick(); });

    function sendCode(extra) {
        var email = emailInput.value.trim();
        if (!email) { showToast(t('请输入邮箱或用户名'), 'warning'); return; }
        // 邮箱，或者用户名（未成年账号没有自己的邮箱，用用户名收码）
        var identifierOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email) || /^[a-z0-9_]{3,32}$/.test(email);
        if (!identifierOk) { showToast(t('请输入有效的邮箱地址或用户名'), 'warning'); return; }

        sendCodeBtn.disabled = true;
        sendCodeBtn.textContent = t('发送中...');

        var formData = new FormData();
        formData.append('email', email);
        formData.append('_csrf', codeForm.dataset.csrfToken);
        if (extra && extra.account_id) formData.append('account_id', extra.account_id);
        if (extra && extra.send_to_id) formData.append('send_to_id', extra.send_to_id);
        var captchaInput = document.getElementById('code-captcha');
        if (captchaInput) formData.append('captcha', captchaInput.value.trim());

        fetch('/login/send-code', {
            method: 'POST',
            body: formData,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) { return response.json(); })
        .then(function(data) {
            if (data.success) {
                // 选中的账号要跟着 /login/code 一起提交（验证码是按账号发的）
                if (accountField) accountField.value = (extra && extra.account_id) || '';
                pickWrap.hidden = true;   // 码都发出去了，选择框让开，别挡着输码
                showToast(data.message, 'success');

                var countdown = 60;
                var timer = setInterval(function() {
                    countdown--;
                    sendCodeBtn.textContent = countdown + t('秒后重试');
                    if (countdown <= 0) {
                        clearInterval(timer);
                        sendCodeBtn.disabled = false;
                        sendCodeBtn.textContent = t('发送验证码');
                    }
                }, 1000);
                return;
            }
            if (data.need_account_choice) {
                // 这个邮箱落在两个账号上，让用户点一个 —— 别替他挑
                renderPick('account', data.accounts || [], preferredAccount(data.accounts || []));
                showToast(data.message, 'info');
            } else if (data.need_address_choice) {
                // 填的是用户名、账号绑了多个邮箱：绑了几个就摆几个，让他直接点（首选标出来）
                renderPick('address', data.addresses || [], null);
                showToast(data.message, 'info');
            } else {
                showToast(data.message, 'error');
            }
            sendCodeBtn.disabled = false;
            sendCodeBtn.textContent = t('发送验证码');
        })
        .catch(function() {
            showToast(t('发送失败，请稍后重试'), 'error');
            sendCodeBtn.disabled = false;
            sendCodeBtn.textContent = t('发送验证码');
        });
    }

    sendCodeBtn.addEventListener('click', function() {
        sendCode(accountField && accountField.value ? { account_id: accountField.value } : {});
    });
}

// ========== 生物识别登录 ==========
// 一键：不填账号也能登（服务端不下发 allowCredentials，由系统选择器列出本机通行密钥）；
// 有记住的账号、或手填了账号时带上它，就按该账号已绑定的凭证验证
// （老凭证可能不是可发现凭证，那种只能走这条）
function initBiometricLogin(onFail) {
    var btn = document.getElementById('biometric-login-btn');
    var btnText = document.getElementById('biometric-btn-text');
    var statusDiv = document.getElementById('biometric-status');
    var manualWrap = document.getElementById('biometric-manual');
    var manualToggle = document.getElementById('biometric-manual-toggle');
    var identifierInput = document.getElementById('biometric-identifier');
    if (!btn) return;

    function resetBtn() {
        btn.disabled = false;
        if (btnText) btnText.textContent = t('使用本机生物识别登录');
    }

    function setStatus(text) {
        if (!statusDiv) return;
        statusDiv.textContent = text || '';
        statusDiv.style.display = text ? '' : 'none';
    }

    function revealManual() {
        if (manualWrap) manualWrap.style.display = '';
        if (manualToggle) manualToggle.style.display = 'none';
    }

    if (manualToggle) {
        manualToggle.addEventListener('click', function() {
            revealManual();
            if (identifierInput) identifierInput.focus();
        });
    }

    btn.addEventListener('click', function() {
        if (!NOMEN_LOGIN.supported()) {
            showToast(t('当前浏览器不支持生物识别登录'), 'warning');
            return;
        }

        var identifier = identifierInput ? identifierInput.value.trim() : '';
        var optionsUrl = '/biometric/auth/options' + (identifier ? '?identifier=' + encodeURIComponent(identifier) : '');

        btn.disabled = true;
        if (btnText) btnText.textContent = t('准备中...');
        setStatus('');

        fetch(optionsUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(parseJsonResponse)
            .then(function(data) {
                if (!data.success) {
                    var err = new Error(data.message || t('无法开始生物识别验证'));
                    err.cldMessage = data.message || '';
                    throw err;
                }

                var options = data.options;
                options.publicKey.challenge = base64urlDecode(options.publicKey.challenge);
                if (options.publicKey.allowCredentials) {
                    options.publicKey.allowCredentials.forEach(function(c) {
                        c.id = base64urlDecode(c.id);
                    });
                }

                if (btnText) btnText.textContent = t('请完成生物识别验证...');
                setStatus(t('正在调用系统生物识别（指纹 / 面容 / PIN）...'));

                return navigator.credentials.get({ publicKey: options.publicKey });
            })
            .then(function(credential) {
                if (!credential) {
                    throw new Error('cancelled');
                }

                var response = credential.response;
                return fetch('/biometric/auth/verify', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        credential: {
                            id: credential.id,
                            type: credential.type,
                            rawId: base64urlEncode(credential.rawId),
                            authenticatorData: base64urlEncode(response.authenticatorData),
                            clientDataJSON: base64urlEncode(response.clientDataJSON),
                            signature: base64urlEncode(response.signature),
                            userHandle: response.userHandle ? base64urlEncode(response.userHandle) : null
                        }
                    })
                }).then(parseJsonResponse);
            })
            .then(function(result) {
                if (result.success) {
                    showToast(result.message, 'success');
                    setTimeout(function() { window.location.href = result.redirect || '/dashboard'; }, 800);
                    return;
                }

                showToast(result.message, 'error');
                if (result.redirect) {
                    setTimeout(function() { window.location.href = result.redirect; }, 1000);
                    return;
                }

                resetBtn();
                setStatus('');
                if (onFail) onFail();
            })
            .catch(function(err) {
                resetBtn();
                setStatus('');

                // 用户自己在系统弹窗里点了取消，不算这个方式失败
                if (err && err.message === 'cancelled') return;

                // 本机没有可用凭证时浏览器报 NotAllowedError：让他填上账号再试，先不当成失败
                if (err && err.name === 'NotAllowedError') {
                    setStatus(t('没有完成验证。如果本机没有绑定过该账号的通行密钥，可以在下面填上用户名或邮箱再试。'));
                    revealManual();
                    return;
                }

                showToast(err && err.message ? err.message : t('生物识别登录失败，请重试'), 'error');

                if (err && err.cldMessage) {
                    setStatus(err.cldMessage);
                    revealManual();
                    if (onFail) onFail();
                }
            });
    });
}

// ========== 激活页面 ==========
function initActivatePage() {
    // 激活页已迁到 Vue
    if (document.getElementById('activate-app')) return;

    var form = document.getElementById('activate-form');
    if (!form) return;
    var btn = document.getElementById('send-btn');
    var btnText = document.getElementById('btn-text');
    var btnCountdown = document.getElementById('btn-countdown');
    var alertContainer = document.getElementById('alert-container');
    if (!btn || !alertContainer) return;
    var cooldown = 0, timer = null;
    var csrfToken = document.querySelector('input[name="_csrf"]').value;

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        if (cooldown > 0) return;
        btn.disabled = true; btnText.textContent = t('发送中...');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/activate/send', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { d = {}; }
            actAlert(d.success ? 'success' : 'error', d.message || t('请求失败'));
            if (d.success) actCool(60); else if (d.retry_after) actCool(d.retry_after);
            else { btn.disabled = false; btnText.textContent = t('发送验证邮件'); }
        };
        xhr.onerror = function() { actAlert('error',t('网络错误')); btn.disabled = false; btnText.textContent = t('发送验证邮件'); };
        xhr.send('_csrf=' + encodeURIComponent(csrfToken));
    });

    function actCool(s) { cooldown = s; btn.disabled = true; btnText.style.display = 'none'; btnCountdown.style.display = 'inline'; actTick(); timer = setInterval(function(){ cooldown--; if (cooldown <= 0) { clearInterval(timer); btn.disabled = false; btnText.style.display = 'inline'; btnCountdown.style.display = 'none'; btnText.textContent = t('发送验证邮件'); } else actTick(); }, 1000); }
    function actTick() { var m = Math.floor(cooldown / 60), s = cooldown % 60; btnCountdown.textContent = (m > 0 ? m + t('分') : '') + s + t('秒后重试'); }
    function actAlert(t, m) { Nomen.inlineAlert(alertContainer, t, m); }
}

// ========== 仪表盘 - 开发者横幅 ==========
function initDashboardPage() {
    // 仪表盘已迁到 Vue，横幅的显示/关闭由组件自己管
    if (document.getElementById('dashboard-app')) return;

    var KEY = 'hide_dev_promo';
    if (document.cookie.indexOf(KEY + '=1') !== -1) return;
    var banner = document.getElementById('devPromoBanner');
    var closeBtn = document.getElementById('devPromoClose');
    if (!banner || !closeBtn) return;
    banner.style.display = 'flex';
    closeBtn.addEventListener('click', function(){
        banner.style.transition = 'opacity 0.3s, transform 0.3s';
        banner.style.opacity = '0'; banner.style.transform = 'translateY(-8px)';
        setTimeout(function(){ banner.remove(); }, 300);
        var d = new Date(); d.setTime(d.getTime() + 30*24*60*60*1000);
        document.cookie = KEY + '=1;expires=' + d.toUTCString() + ';path=/;SameSite=Lax';
    });
}

// ========== 设置页：需要保存的几个表单，原地提交不刷新 ==========
function initSettingsForms() {
    if (document.getElementById('settings-app') || document.getElementById('recovery-secure-app')) return;

    [
        'settings-profile-form',
        'settings-password-form',
        'settings-2fa-form',
        'settings-quick-login-form',
        'settings-notifications-form'
    ].forEach(function(id) {
        var form = document.getElementById(id);
        if (!form || !form.getAttribute('action')) return;

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (form.dataset.busy === '1') return;
            form.dataset.busy = '1';

            var btn = form.querySelector('button[type="submit"]');
            var label = btn ? btn.textContent : '';
            if (btn) {
                btn.disabled = true;
                btn.textContent = t('保存中...');
            }

            fetch(form.getAttribute('action'), {
                method: 'POST',
                body: new FormData(form),
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(response) {
                return response.json().catch(function() {
                    throw new Error(t('服务器返回了 ') + response.status);
                });
            })
            .then(function(data) {
                showToast(data.message || (data.success ? t('已保存') : t('保存失败')), data.success ? 'success' : 'error');
                if (data.success && id === 'settings-password-form') {
                    form.reset();
                    form.querySelectorAll('input[data-pw-gen]').forEach(function(input) {
                        input.dispatchEvent(new Event('input'));
                    });
                }
            })
            .catch(function(err) {
                showToast(err.message || t('网络错误'), 'error');
            })
            .finally(function() {
                form.dataset.busy = '0';
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = label;
                }
            });
        });
    });
}

// ========== 家庭页：提交后原地换内容，保留滚动位置 ==========
function initFamilyPage() {
    // 家庭页已迁到 Vue
    if (document.getElementById('family-app')) return;

    var page = document.querySelector('.settings');
    if (!page || !document.querySelector('form[action^="/family/"]')) return;

    document.querySelectorAll('form[action^="/family/"]').forEach(function(form) {
        form.dataset.soft = '1';
    });

    function softSubmit(form) {
        if (form.dataset.busy === '1') return;
        form.dataset.busy = '1';
        Nomen.beginSubmit(form);

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            var target = new URL(response.url, window.location.href);
            if (target.pathname !== '/family') {
                window.location.href = target.href;
                return null;
            }
            if (!response.ok) throw new Error(t('服务器返回了 ') + response.status);
            return response.text();
        })
        .then(function(html) {
            if (html === null) return;
            var doc = new DOMParser().parseFromString(html, 'text/html');
            var fresh = doc.querySelector('.settings');
            var current = document.querySelector('.settings');
            if (!fresh || !current) {
                window.location.reload();
                return;
            }
            var scrollY = window.scrollY;
            current.replaceWith(fresh);
            window.scrollTo(0, scrollY);
            initFamilyPage();
            initConfirmForms();
            Nomen.autoDismissAlerts(document);
        })
        .catch(function(err) {
            showToast(err.message || t('网络错误'), 'error');
        })
        .finally(function() {
            form.dataset.busy = '0';
            Nomen.resetSubmitState();
        });
    }

    window.__softSubmit = softSubmit;

    if (window.__familySoftBound) return;
    window.__familySoftBound = true;

    document.addEventListener('submit', function(e) {
        var form = e.target;
        if (!form || form.tagName !== 'FORM' || form.dataset.soft !== '1') return;
        if (e.defaultPrevented) return; // 交给确认弹窗，用户点了确定再走
        e.preventDefault();
        softSubmit(form);
    });
}

// ========== 设置 / 恢复页 - 邮箱地址列表 ==========
function initEmailSet() {
    // 设置页 / 恢复修复页已迁到 Vue
    if (document.getElementById('settings-app') || document.getElementById('recovery-secure-app')) return;

    var list = document.querySelector('.email-list');
    if (!list) return;

    var recoveryMode = list.dataset.recoveryMode === '1';
    var addForm = document.getElementById('email-add-form');
    var csrfField = document.querySelector('input[name="_csrf"]');
    if (!csrfField) return;
    var csrfToken = csrfField.value;

    function post(url, params, done) {
        var body = new URLSearchParams();
        body.append('_csrf', csrfToken);
        Object.keys(params).forEach(function(key) { body.append(key, params[key]); });

        fetch(url, {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(function(response) {
            return response.json().then(function(data) { return { ok: response.ok, data: data }; })
                .catch(function() { throw new Error(t('服务器返回了 ') + response.status); });
        })
        .then(function(res) { done(res.data); })
        .catch(function(err) { showToast(err.message || t('网络错误'), 'error'); });
    }

    // 服务端连最新列表一起返回，直接换掉这一块，不再整页刷新
    function refreshList(data) {
        var root = document.getElementById('email-set-root');
        if (!root || !data || !data.html) return false;
        root.innerHTML = data.html;
        initEmailSet();
        Nomen.autoDismissAlerts(root);
        return true;
    }

    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();

            var email = document.getElementById('new_email').value.trim();
            var password = document.getElementById('email_add_password').value;
            if (!email) { showToast(t('请输入邮箱地址'), 'warning'); return; }
            if (!recoveryMode && !password) { showToast(t('请输入当前密码'), 'warning'); return; }

            var btn = document.getElementById('email-add-btn');
            btn.disabled = true;
            btn.textContent = t('发送中...');

            post('/settings/emails/add', { new_email: email, password: password }, function(data) {
                btn.disabled = false;
                btn.textContent = t('发送验证邮件');
                showToast(data.message, data.success ? 'success' : 'error');
                if (data.success) {
                    addForm.reset();
                    refreshList(data);
                }
            });
        });
    }

    list.addEventListener('click', function(e) {
        var btn = e.target.closest ? e.target.closest('[data-email-action]') : null;
        if (!btn) return;

        var item = btn.closest('[data-email-id]');
        if (!item) return;

        var emailId = item.dataset.emailId;
        var action = btn.dataset.emailAction;
        var addrEl = item.querySelector('.email-item__addr');
        var addr = addrEl ? addrEl.textContent : '';

        if (action === 'resend') {
            btn.disabled = true;
            post('/settings/emails/resend', { email_id: emailId }, function(data) {
                btn.disabled = false;
                showToast(data.message, data.success ? 'success' : 'error');
            });
            return;
        }

        var isRemove = action === 'remove';
        Nomen.prompt(
            isRemove ? t('移除邮箱') : t('设为首选'),
            isRemove
                ? t('移除 ') + addr + t(' 之后，这个地址就不能再用来登录了。')
                : t('把 ') + addr + t(' 设为收信地址和登录首选邮箱。'),
            {
                inputType: 'password',
                placeholder: recoveryMode ? t('恢复流程中可留空') : t('当前密码'),
                required: !recoveryMode,
                confirmText: isRemove ? t('移除') : t('设为首选'),
                danger: isRemove
            }
        ).then(function(password) {
            if (password === null) return;
            post(
                isRemove ? '/settings/emails/remove' : '/settings/emails/primary',
                { email_id: emailId, password: password },
                function(data) {
                    showToast(data.message, data.success ? 'success' : 'error');
                    if (data.success) refreshList(data);
                }
            );
        });
    });
}

// ========== 设置 - 验证码重置密码 ==========
function initSettingsResetPassword() {
    if (document.getElementById('settings-app')) return;

    var form = document.getElementById('reset-code-form');
    if (!form) return;
    var sendBtn = document.getElementById('send-reset-code-btn'), resetBtn = document.getElementById('reset-btn');
    var cooldown = 0, timer = null;
    var csrfToken = document.querySelector('input[name="_csrf"]').value;

    sendBtn.addEventListener('click', function() {
        if (cooldown > 0) return;
        sendBtn.disabled = true; sendBtn.textContent = t('发送中...');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/settings/password/send-code', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { d = {}; }
            if (d.success) { sendBtn.textContent = d.message; sendBtn.disabled = false; showToast(d.message, 'success'); rpCool(60); }
            else if (d.retry_after) { rpCool(d.retry_after); showToast(d.message, 'error'); }
            else { sendBtn.textContent = t('发送验证码'); sendBtn.disabled = false; showToast(d.message, 'error'); }
        };
        xhr.onerror = function() { sendBtn.textContent = t('发送验证码'); sendBtn.disabled = false; showToast(t('网络错误'), 'error'); };
        // 账号有多个邮箱时，页面上有一个收码邮箱下拉（默认首选）
        var target = document.getElementById('reset_code_target');
        xhr.send('_csrf=' + encodeURIComponent(csrfToken) + (target ? '&send_to=' + encodeURIComponent(target.value) : ''));
    });

    form.addEventListener('submit', function(e) {
        e.preventDefault();
        var code = document.getElementById('reset_code').value.trim();
        var np = document.getElementById('reset_new_password').value, cp = document.getElementById('reset_confirm_password').value;
        if (!code || !np || !cp) { showToast(t('请填写所有字段'), 'warning'); return; }
        if (np.length < 8 || !/[a-zA-Z]/.test(np) || !/[0-9]/.test(np)) { showToast(t('密码至少8位，含字母和数字'), 'warning'); return; }
        if (np !== cp) { showToast(t('两次密码不一致'), 'warning'); return; }
        resetBtn.disabled = true; resetBtn.textContent = t('重置中...');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/settings/password/reset-code', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { d = {}; }
            if (d.success) {
                showToast(d.message, 'success');
                form.reset();
            } else {
                showToast(d.message || t('重置失败'), 'error');
            }
            resetBtn.disabled = false;
            resetBtn.textContent = t('重置密码');
        };
        xhr.onerror = function() { showToast(t('网络错误'), 'error'); resetBtn.disabled = false; resetBtn.textContent = t('重置密码'); };
        xhr.send('_csrf=' + encodeURIComponent(csrfToken) + '&reset_code=' + encodeURIComponent(code) + '&new_password=' + encodeURIComponent(np) + '&confirm_password=' + encodeURIComponent(cp));
    });

    function rpCool(s) { cooldown = s; sendBtn.disabled = true; rpTick(); timer = setInterval(function(){ cooldown--; if (cooldown <= 0) { clearInterval(timer); sendBtn.disabled = false; sendBtn.textContent = t('发送验证码'); } else rpTick(); }, 1000); }
    function rpTick() { sendBtn.textContent = cooldown + t('秒后重试'); }
}

// ========== 图标 / 头像上传：SVG 先在浏览器里栅格化 ==========
// 服务端用 GD 处理图标，读不了矢量图，所以 SVG 在这里转成 135×135 的 PNG 再提交。
var SVG_PNG_SIZE = 135;

function isSvgFile(file) {
    return file.type === 'image/svg+xml' || /\.svg$/i.test(file.name || '');
}

function svgFileToPng(file, size) {
    return new Promise(function(resolve, reject) {
        var url = URL.createObjectURL(file);
        var img = new Image();
        img.onload = function() {
            var w = img.naturalWidth || img.width || size;
            var h = img.naturalHeight || img.height || size;
            var scale = Math.min(size / w, size / h);
            var dw = Math.max(1, Math.round(w * scale));
            var dh = Math.max(1, Math.round(h * scale));
            var canvas = document.createElement('canvas');
            canvas.width = size;
            canvas.height = size;
            // 保住宽高比居中画，空出来的部分留透明即可（PNG 有 alpha 通道）
            canvas.getContext('2d').drawImage(img, Math.round((size - dw) / 2), Math.round((size - dh) / 2), dw, dh);
            URL.revokeObjectURL(url);
            try {
                canvas.toBlob(function(blob) {
                    if (blob) { resolve(blob); } else { reject(new Error(t('SVG 转换失败'))); }
                }, 'image/png');
            } catch (e) {
                reject(new Error(t('SVG 转换失败')));
            }
        };
        img.onerror = function() {
            URL.revokeObjectURL(url);
            reject(new Error(t('这个 SVG 读不出来')));
        };
        img.src = url;
    });
}

function svgToPngFile(file, size) {
    return svgFileToPng(file, size).then(function(blob) {
        return new File([blob], (file.name || 'image.svg').replace(/\.svg$/i, '') + '.png', { type: 'image/png' });
    });
}

function initIconSvgConvert() {
    var inputs = document.querySelectorAll('input[type="file"][name="icon"]');
    Array.prototype.forEach.call(inputs, function(input) {
        input.addEventListener('change', function() {
            var file = input.files && input.files[0];
            if (!file || !isSvgFile(file)) return;

            svgToPngFile(file, SVG_PNG_SIZE).then(function(png) {
                var dt = new DataTransfer();
                dt.items.add(png);
                input.files = dt.files;
                showToast(t('SVG 已转成 ') + SVG_PNG_SIZE + '×' + SVG_PNG_SIZE + t(' 的 PNG，保存后生效'), 'success');
            }).catch(function() {
                input.value = '';
                showToast(t('这个 SVG 转不出来，请换成 PNG 或 JPG'), 'error');
            });
        });
    });
}

// ========== 设置 - 头像 ==========
function initSettingsAvatar() {
    if (document.getElementById('settings-app')) return;

    var input = document.getElementById('avatar-input'), selectBtn = document.getElementById('avatar-select-btn'), uploadBtn = document.getElementById('avatar-upload-btn');
    var filenameSpan = document.getElementById('avatar-filename'), preview = document.getElementById('avatar-preview');
    if (!input || !selectBtn || !uploadBtn) return;
    var csrfToken = document.querySelector('input[name="_csrf"]').value, selectedFile = null;

    selectBtn.addEventListener('click', function() { input.click(); });
    function useAvatarFile(file) {
        selectedFile = file; filenameSpan.textContent = selectedFile.name; uploadBtn.style.display = 'inline-block';
        var r = new FileReader();
        r.onload = function(e) { preview.innerHTML = '<img src="' + e.target.result + '" alt="' + t('预览') + '" style="width:100%;height:100%;object-fit:cover;">'; };
        r.readAsDataURL(selectedFile);
    }
    input.addEventListener('change', function() {
        if (!input.files || !input.files[0]) return;
        var file = input.files[0];
        if (!isSvgFile(file)) { useAvatarFile(file); return; }

        svgToPngFile(file, SVG_PNG_SIZE).then(function(png) {
            useAvatarFile(png);
            showToast(t('SVG 已转成 ') + SVG_PNG_SIZE + '×' + SVG_PNG_SIZE + t(' 的 PNG'), 'success');
        }).catch(function() {
            input.value = '';
            showToast(t('这个 SVG 转不出来，请换成 PNG 或 JPG'), 'error');
        });
    });
    uploadBtn.addEventListener('click', function() {
        if (!selectedFile) return;
        uploadBtn.disabled = true; uploadBtn.textContent = t('上传中...');
        var fd = new FormData(); fd.append('avatar', selectedFile); fd.append('_csrf', csrfToken);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/settings/avatar', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(ex) { d = {}; }
            if (d.success) {
                preview.innerHTML = '<img src="' + d.avatar_url + '" alt="' + t('头像') + '" style="width:100%;height:100%;object-fit:cover;">';
                showToast(d.message, 'success'); uploadBtn.style.display = 'none'; filenameSpan.textContent = ''; selectedFile = null; input.value = '';
            } else { showToast(d.message, 'error'); }
            uploadBtn.disabled = false; uploadBtn.textContent = t('上传头像');
        };
        xhr.onerror = function() { showToast(t('网络错误'), 'error'); uploadBtn.disabled = false; uploadBtn.textContent = t('上传头像'); };
        xhr.send(fd);
    });
}

// ========== 设置 - 撤销设备 ==========
function initSettingsDevices() {
    if (document.getElementById('settings-app')) return;

    var btns = document.querySelectorAll('.revoke-device-btn');
    if (!btns.length) return;
    var csrfToken = document.querySelector('input[name="_csrf"]').value;

    btns.forEach(function(btn) {
        btn.addEventListener('click', function() {
            var did = this.dataset.deviceId, dnm = this.dataset.deviceName;
            Nomen.confirm(t('撤销设备信任'), t('确定要撤销设备 "{:name}" 的信任吗？该设备下次登录需要重新验证。', { name: dnm }), {
                danger: true,
                confirmText: t('撤销'),
                cancelText: t('取消')
            }).then(function(confirmed) {
                if (!confirmed) return;
                btn.disabled = true; btn.textContent = t('撤销中...');
                var xhr = new XMLHttpRequest();
                xhr.open('POST', '/settings/devices/revoke', true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                xhr.onload = function() {
                    var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
                    if (d.success) { showToast(d.message, 'success'); var i = btn.closest('.device-item'); if (i) i.remove(); }
                    else { showToast(d.message, 'error'); btn.disabled = false; btn.textContent = t('撤销'); }
                };
                xhr.onerror = function() { showToast(t('网络错误'), 'error'); btn.disabled = false; btn.textContent = t('撤销'); };
                xhr.send('_csrf=' + encodeURIComponent(csrfToken) + '&device_id=' + encodeURIComponent(did));
            });
        });
    });
}

function initSettingsTempKeys() {
    if (document.getElementById('settings-app')) return;

    var container = document.getElementById('temp-keys-container');
    if (!container) return;

    var list = document.getElementById('temp-keys-list');
    var createBtn = document.getElementById('create-temp-key-btn');
    var form = document.getElementById('temp-key-form');
    var labelInput = document.getElementById('temp-key-label');
    var valueInput = document.getElementById('temp-key-value');
    var expiresInput = document.getElementById('temp-key-expires');
    var generateBtn = document.getElementById('generate-temp-key-btn');
    var saveBtn = document.getElementById('save-temp-key-btn');
    var cancelBtn = document.getElementById('cancel-temp-key-btn');
    var csrfToken = document.querySelector('input[name="_csrf"]').value;

    function formatTime(ts) {
        if (!ts) return t('从未使用');
        var d = new Date(ts.replace(' ', 'T') + 'Z');
        return d.toLocaleString(window.NOMEN_LANG || 'zh-Hans');
    }

    function loadKeys() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/settings/temp-keys', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (!d.success) return;
            list.innerHTML = '';
            if (!d.keys.length) {
                list.innerHTML = '<p class="text-muted" style="padding:12px 0;">' + t('暂无临时密钥') + '</p>';
                return;
            }
            d.keys.forEach(function(k) {
                var isExpired = new Date(k.expires_at) < new Date();
                var el = document.createElement('div');
                el.className = 'device-item';
                el.innerHTML = '<div class="device-item__info">' +
                    '<div class="device-item__name">' +
                    (isExpired ? '<span style="text-decoration:line-through;">' : '') +
                    escapeHtml(k.label || t('未命名密钥')) +
                    (isExpired ? '</span>' : '') +
                    (isExpired ? '<span class="badge" style="margin-left:8px;font-size:11px;background:#eee;color:#999;">' + t('已过期') + '</span>' : '') +
                    '</div>' +
                    '<div class="device-item__meta">' + t('到期: ') + formatTime(k.expires_at) + t(' · 创建: ') + formatTime(k.created_at) + (k.last_used_at ? t(' · 上次使用: ') + formatTime(k.last_used_at) : '') + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-danger-outline" onclick="deleteTempKey(' + k.id + ', this)">' + t('删除') + '</button>';
                list.appendChild(el);
            });
        };
        xhr.send();
    }

    window.deleteTempKey = function(id, btn) {
        Nomen.confirm(t('删除临时密钥'), t('确定要删除此临时密钥吗？'), {
            danger: true,
            confirmText: t('删除'),
            cancelText: t('取消')
        }).then(function(confirmed) {
            if (!confirmed) return;
            btn.disabled = true; btn.textContent = t('删除中...');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/settings/temp-keys/delete', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
                if (d.success) { showToast(d.message, 'success'); loadKeys(); }
                else { showToast(d.message, 'error'); btn.disabled = false; btn.textContent = t('删除'); }
            };
            xhr.send('_csrf=' + encodeURIComponent(csrfToken) + '&key_id=' + id);
        });
    };

    createBtn.addEventListener('click', function() {
        container.style.display = 'none';
        form.style.display = 'block';
        labelInput.value = '';
        valueInput.readOnly = false;
        expiresInput.value = '';

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/settings/temp-keys/generate', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (d.success) { valueInput.value = d.key; }
        };
        xhr.send();

        var now = new Date();
        now.setHours(now.getHours() + 12);
        expiresInput.value = now.toISOString().slice(0, 16);
    });

    cancelBtn.addEventListener('click', function() {
        form.style.display = 'none';
        container.style.display = 'block';
    });

    generateBtn.addEventListener('click', function() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/settings/temp-keys/generate', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (d.success) { valueInput.value = d.key; }
        };
        xhr.send();
    });

    saveBtn.addEventListener('click', function() {
        var label = labelInput.value.trim();
        var value = valueInput.value.trim();
        var expires = expiresInput.value;
        if (!label || !value || !expires) { showToast(t('请填写所有字段'), 'warning'); return; }
        saveBtn.disabled = true; saveBtn.textContent = t('保存中...');
        var xhr = new XMLHttpRequest();
        xhr.open('POST', '/settings/temp-keys', true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (d.success) { showToast(d.message, 'success'); form.style.display = 'none'; container.style.display = 'block'; loadKeys(); }
            else { showToast(d.message, 'error'); }
            saveBtn.disabled = false; saveBtn.textContent = t('保存');
        };
        xhr.send('_csrf=' + encodeURIComponent(csrfToken) + '&label=' + encodeURIComponent(label) + '&key_value=' + encodeURIComponent(value) + '&expires_at=' + encodeURIComponent(expires.replace('T', ' ')));
    });

    loadKeys();
}

function showConfirmModal(appName, scopes, callback) {
    Nomen.confirm(t('确认授权'), '', {
        icon: 'lock',
        variant: 'confirm',
        confirmText: t('确认授权'),
        content:
            '<div class="nomen-confirm-app">' + escapeHtml(appName) + '</div>' +
            '<div class="nomen-confirm-scopes">' + scopes.map(function(s) {
                return '<span class="nomen-confirm-scope-tag">' + iconTag('user') + ' ' + escapeHtml(s) + '</span>';
            }).join('') + '</div>' +
            '<p class="nomen-modal-box__desc">' + t('授权后，该应用将可以访问以上信息。<br>您可以随时在仪表盘中撤销授权。') + '</p>'
    }).then(callback);
}

function showRiskModal(items, callback) {
    Nomen.confirm(t('确认授权以下敏感信息？'), '', {
        icon: 'warning',
        variant: 'risk',
        countdown: 5,
        confirmText: t('确认授权'),
        content:
            '<ul class="nomen-risk-list">' + items.map(function(i) {
                return '<li>' + escapeHtml(i) + '</li>';
            }).join('') + '</ul>' +
            '<p class="nomen-modal-box__desc">' + t('这些信息可能被用于识别或追踪您的身份，请谨慎授权。') + '</p>'
    }).then(callback);
}

// ========== Base64URL 工具函数 ==========
function base64urlEncode(buffer) {
    var bytes = new Uint8Array(buffer);
    var len = bytes.length;
    var base64 = '';
    var table = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';

    for (var i = 0; i < len; i += 3) {
        var a = bytes[i];
        var b = i + 1 < len ? bytes[i + 1] : 0;
        var c = i + 2 < len ? bytes[i + 2] : 0;

        base64 += table[a >> 2];
        base64 += table[((a & 3) << 4) | (b >> 4)];
        base64 += i + 1 < len ? table[((b & 15) << 2) | (c >> 6)] : '=';
        base64 += i + 2 < len ? table[c & 63] : '=';
    }

    return base64.replace(/=+$/, '');
}

function base64urlDecode(str) {
    str = str.replace(/-/g, '+').replace(/_/g, '/');
    while (str.length % 4) str += '=';
    var binary = atob(str);
    var bytes = new Uint8Array(binary.length);
    for (var i = 0; i < binary.length; i++) {
        bytes[i] = binary.charCodeAt(i);
    }
    return bytes.buffer;
}

// ========== 设置页面 - 生物识别管理 ==========
function initSettingsBiometrics() {
    if (document.getElementById('settings-app') || document.getElementById('recovery-secure-app')) return;

    var container = document.getElementById('biometric-container');
    var list = document.getElementById('biometric-list');
    var addBtn = document.getElementById('add-biometric-btn');
    var form = document.getElementById('biometric-add-form');
    var regBtn = document.getElementById('biometric-register-btn');
    var cancelBtn = document.getElementById('biometric-cancel-btn');
    var statusDiv = document.getElementById('biometric-reg-status');
    var csrfToken = document.querySelector('input[name="_csrf"]')?.value;

    if (!container || !list || !addBtn) return;

    if ('credentials' in navigator) {
        addBtn.style.display = '';
    }

    function loadBiometrics() {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/settings/biometrics', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (!d.success) return;
            list.innerHTML = '';
            if (!d.credentials.length) {
                list.innerHTML = '<p class="text-muted" style="padding:12px 0;">' + t('暂无生物识别凭证') + '</p>';
                return;
            }
            d.credentials.forEach(function(c) {
                var el = document.createElement('div');
                el.className = 'device-item';
                var typeText = c.device_type === 'platform' ? t('内置传感器（指纹/面容）') :
                               c.device_type === 'usb_key' ? t('USB 安全密钥') :
                               c.device_type === 'nfc_card' ? t('NFC 卡片') :
                               c.device_type === 'ble_device' ? t('BLE 设备') : t('未知设备');
                el.innerHTML = '<div class="device-item__info">' +
                    '<div class="device-item__name">' + typeText + '</div>' +
                    '<div class="device-item__meta">' + t('创建时间: ') + formatTime(c.created_at) + '</div>' +
                    '</div>' +
                    '<button type="button" class="btn btn-sm btn-danger-outline" onclick="deleteBiometric(' + c.id + ', this)">' + t('删除') + '</button>';
                list.appendChild(el);
            });
        };
        xhr.send();
    }

    window.deleteBiometric = function(id, btn) {
        Nomen.confirm(t('删除生物识别'), t('确定要删除此生物识别凭证吗？删除后需要重新绑定才能使用生物识别登录。'), {
            danger: true,
            confirmText: t('删除'),
            cancelText: t('取消')
        }).then(function(confirmed) {
            if (!confirmed) return;
            btn.disabled = true; btn.textContent = t('删除中...');
            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/settings/biometrics/delete', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function() {
                var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
                if (d.success) { showToast(d.message, 'success'); loadBiometrics(); }
                else { showToast(d.message, 'error'); btn.disabled = false; btn.textContent = t('删除'); }
            };
            xhr.send('_csrf=' + encodeURIComponent(csrfToken) + '&id=' + id);
        });
    };

    addBtn.addEventListener('click', function() {
        container.style.display = 'none';
        form.style.display = 'block';
        if (statusDiv) statusDiv.textContent = '';
    });

    cancelBtn.addEventListener('click', function() {
        form.style.display = 'none';
        container.style.display = 'block';
        if (statusDiv) statusDiv.textContent = '';
    });

    regBtn.addEventListener('click', function() {
        regBtn.disabled = true;
        regBtn.textContent = t('准备中...');
        if (statusDiv) {
            statusDiv.textContent = t('正在准备绑定...');
            statusDiv.style.color = 'var(--color-text-subtle)';
        }

        var xhr = new XMLHttpRequest();
        xhr.open('GET', '/biometric/registration/options', true);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var d; try { d = JSON.parse(xhr.responseText); } catch(e) { d = {}; }
            if (!d.success) {
                showToast(d.message || t('获取选项失败'), 'error');
                regBtn.disabled = false; regBtn.textContent = t('开始绑定');
                return;
            }

            var options = d.options;
            options.publicKey.challenge = base64urlDecode(options.publicKey.challenge);
            options.publicKey.user.id = base64urlDecode(options.publicKey.user.id);

            regBtn.textContent = t('请完成生物识别验证...');
            if (statusDiv) {
                statusDiv.textContent = t('正在调用系统生物识别（指纹/面容/PIN）...');
                statusDiv.style.color = 'var(--color-text-subtle)';
            }

            navigator.credentials.create({ publicKey: options.publicKey })
                .then(function(credential) {
                    if (!credential) {
                        showToast(t('绑定取消'), 'warning');
                        regBtn.disabled = false; regBtn.textContent = t('开始绑定');
                        if (statusDiv) statusDiv.textContent = '';
                        return;
                    }

                    var response = credential.response;
                    var dataToSend = {
                        credential: {
                            id: credential.id,
                            type: credential.type,
                            rawId: base64urlEncode(credential.rawId),
                            attestationObject: base64urlEncode(response.attestationObject),
                            clientDataJSON: base64urlEncode(response.clientDataJSON),
                            transports: response.getTransports ? response.getTransports() : [],
                        }
                    };

                    var xhr2 = new XMLHttpRequest();
                    xhr2.open('POST', '/biometric/registration/verify', true);
                    xhr2.setRequestHeader('Content-Type', 'application/json');
                    xhr2.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr2.onload = function() {
                        var d2; try { d2 = JSON.parse(xhr2.responseText); } catch(e) { d2 = {}; }
                        if (d2.success) {
                            showToast(d2.message, 'success');
                            form.style.display = 'none';
                            container.style.display = 'block';
                            loadBiometrics();
                        } else {
                            showToast(d2.message || t('绑定失败'), 'error');
                        }
                        regBtn.disabled = false; regBtn.textContent = t('开始绑定');
                        if (statusDiv) statusDiv.textContent = '';
                    };
                    xhr2.send(JSON.stringify(dataToSend));
                })
                .catch(function(err) {
                    console.error('Biometric registration error:', err);
                    showToast(t('绑定失败: ') + (err.message || t('未知错误')), 'error');
                    regBtn.disabled = false; regBtn.textContent = t('开始绑定');
                    if (statusDiv) statusDiv.textContent = '';
                });
        };
        xhr.send();
    });

    loadBiometrics();
}

function formatTime(ts) {
    if (!ts) return t('未知');
    var d = new Date(ts.replace(' ', 'T') + 'Z');
    return d.toLocaleString(window.NOMEN_LANG || 'zh-Hans');
}

// 页脚署名：静态 HTML 链到版本页（无 JS 也满足 NOTICE 2.1(b)）；
// 有 JS 时把地址换成项目主页，点左键仍回站内版本页（中键 / Ctrl 点按 href 打开项目站）
function initFooterPowered() {
    var link = document.querySelector('a.footer-powered[data-project-url]');
    if (!link) return;
    var projectUrl = link.getAttribute('data-project-url');
    var versionUrl = link.getAttribute('href');
    if (!projectUrl || !versionUrl) return;
    link.setAttribute('href', projectUrl);
    link.addEventListener('click', function(e) {
        if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
        e.preventDefault();
        window.location.href = versionUrl;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    initSettingsBiometrics();
    initIconSvgConvert();
    initFooterPowered();
});
