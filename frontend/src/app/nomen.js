/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

// 应用区 SPA 的提示层：从 public/js/app.js 原样抽出的 Nomen IIFE（toast/alert/confirm/prompt）。
// app 布局不加载旧 app.js，组件里的 toast()/Nomen.confirm 全靠这一份；样式仍在 style.css。
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
            '<button type="button" class="nomen-toast__close" aria-label="' + t('Close') + '">' + iconTag('close') + '</button>' +
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
            title: title || t('Notice'),
            message: message,
            content: opts.content,
            variant: boxVariant(opts),
            cancelValue: true,
            buttons: [
                makeButton(opts.confirmText || t('Got it'), 'nomen-modal-btn--primary', function() { settle(true); })
            ]
        });
    }

    function showConfirm(title, message, opts) {
        opts = opts || {};

        var confirmText = opts.confirmText || t('OK');
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
            title: title || t('Confirm'),
            message: message,
            content: opts.content,
            variant: boxVariant(opts),
            cancelValue: false,
            buttons: [
                makeButton(opts.cancelText || t('Cancel'), 'nomen-modal-btn--ghost', function() { settle(false); }),
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

        var confirmBtn = makeButton(opts.confirmText || t('OK'), confirmButtonClass(opts), function() {
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
            title: title || t('Enter'),
            message: message,
            content: field,
            variant: boxVariant(opts),
            cancelValue: null,
            buttons: [
                makeButton(opts.cancelText || t('Cancel'), 'nomen-modal-btn--ghost', function() { settle(null); }),
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
            close.setAttribute('aria-label', t('Close'));
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
