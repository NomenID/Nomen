/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 注册页面脚本
// 状态标记走独立 SVG 图标：只换 class 与 data-state，供 CSS 上色
function setMark(el, state) {
    if (state === 'ok') el.innerHTML = '<span class="icon icon-check"></span>';
    else if (state === 'bad') el.innerHTML = '<span class="icon icon-close"></span>';
    else if (state === 'loading') el.innerHTML = '<span class="icon icon-refresh"></span>';
    else el.innerHTML = '';
    el.dataset.state = state || '';
}

let currentStep = 1;
const totalSteps = 5;
const progressFromStep = 3;   // 前两步不显示进度条
let usernameCheckTimer = null;

// 切换密码可见性
function togglePassword(inputId) {
    const input = document.getElementById(inputId);
    const icon = input.parentElement.querySelector('.eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'eye-icon icon icon-eye-off';
    } else {
        input.type = 'password';
        icon.className = 'eye-icon icon icon-eye';
    }
}

// 刷新验证码
function refreshCaptcha() {
    const img = document.getElementById('captcha-img');
    img.src = '/captcha?t=' + Date.now();
}

// 检查用户名可用性
document.getElementById('username').addEventListener('input', function(e) {
    const username = e.target.value.trim();
    const status = document.getElementById('username-status');
    const hint = document.getElementById('username-hint');

    clearTimeout(usernameCheckTimer);

    if (!username) {
        setMark(status, '');
        hint.innerHTML = '<span class="hint-icon icon icon-bulb"></span>' + t('Lowercase letters, digits and underscore, 3-32 characters');
        hint.className = 'form-hint';
        return;
    }

    if (!/^[a-z0-9_]{3,32}$/.test(username)) {
        setMark(status, 'bad');
        hint.innerHTML = t('Username: lowercase letters, digits, underscore; 3-32 characters');
        hint.className = 'form-hint error';
        return;
    }

    setMark(status, 'loading');
    hint.innerHTML = t('Checking...');
    hint.className = 'form-hint';

    usernameCheckTimer = setTimeout(() => {
        fetch('/api/check-username?username=' + encodeURIComponent(username))
            .then(res => res.json())
            .then(data => {
                if (data.available) {
                    setMark(status, 'ok');
                    hint.innerHTML = data.message;
                    hint.className = 'form-hint success';
                } else {
                    setMark(status, 'bad');
                    hint.innerHTML = data.message;
                    hint.className = 'form-hint error';
                }
            })
            .catch(() => {
                setMark(status, '');
                hint.innerHTML = t('Check failed, try again');
                hint.className = 'form-hint error';
            });
    }, 500);
});

// 密码强度与「生成强密码」按钮在 app.js 的 initPasswordTools() 里统一处理

// 检查密码匹配
document.getElementById('password_confirm').addEventListener('input', function(e) {
    const password = document.getElementById('password').value;
    const confirm = e.target.value;
    const status = document.getElementById('password-match-status');

    if (!confirm) {
        setMark(status, '');
        return;
    }

    if (password === confirm) {
        setMark(status, 'ok');
    } else {
        setMark(status, 'bad');
    }
});

// ========== 国家选择器（可搜索：中文 / English / 本地名 / 国家码）==========

var countryPicker = null;

// 去变音符：Côte d'Ivoire 打 cote 也能命中
function foldText(text) {
    var s = (text || '').toLowerCase();
    if (s.normalize) {
        s = s.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
    }
    return s;
}

// 手动改过国家之后，「按网络位置填好」那句提示就不成立了
function clearCountryHint() {
    var hint = document.getElementById('country-hint');
    if (hint) hint.remove();
}

function initCountryPicker() {
    var box = document.getElementById('country-picker');
    if (!box) return;

    var input = document.getElementById('country-search');
    var hidden = document.getElementById('country');
    var list = document.getElementById('country-list');
    var options = [].slice.call(list.querySelectorAll('.country-picker__option')).map(function (li) {
        return { el: li, code: li.dataset.code, name: li.dataset.name, hay: foldText(li.dataset.hay) };
    });

    var matches = options;
    var active = -1;

    function applyFilter() {
        var q = foldText(input.value.trim());
        matches = q === '' ? options : options.filter(function (o) { return o.hay.indexOf(q) !== -1; });
        render();
    }

    function render() {
        list.innerHTML = '';
        if (!matches.length) {
            var empty = document.createElement('li');
            empty.className = 'country-picker__empty';
            empty.textContent = t('No such country/region — try the English name or code');
            list.appendChild(empty);
            active = -1;
            return;
        }
        // 最多渲染 60 条，够用又不至于一次塞几百个节点
        matches.slice(0, 60).forEach(function (o, i) {
            o.el.removeAttribute('aria-selected');
            if (i === active) o.el.setAttribute('aria-selected', 'true');
            list.appendChild(o.el);
        });
    }

    function open() {
        if (!list.hidden) return;
        list.hidden = false;
        input.setAttribute('aria-expanded', 'true');
    }

    function close() {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        active = -1;
    }

    function pick(o) {
        hidden.value = o.code;
        input.value = o.name;
        clearCountryHint();
        close();
        refreshConsentRule();
    }

    function move(step) {
        if (list.hidden) { open(); return; }
        if (!matches.length) return;
        active = (active + step + Math.min(matches.length, 60)) % Math.min(matches.length, 60);
        render();
        var el = matches[active] && matches[active].el;
        if (el && el.scrollIntoView) el.scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('input', function () {
        // 手打就等于还没选定国家，清掉隐藏值，免得提交一个旧国家
        hidden.value = '';
        clearCountryHint();
        active = -1;
        open();
        applyFilter();
    });

    input.addEventListener('focus', function () { open(); applyFilter(); });
    input.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowDown') { e.preventDefault(); move(1); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); move(-1); }
        else if (e.key === 'Enter') {
            if (!list.hidden && matches[active >= 0 ? active : 0]) { e.preventDefault(); pick(matches[active >= 0 ? active : 0]); }
        } else if (e.key === 'Escape') { close(); }
    });

    list.addEventListener('mousedown', function (e) {
        var li = e.target.closest ? e.target.closest('.country-picker__option') : null;
        if (!li) return;
        e.preventDefault();   // 别让输入框先失焦，否则点击被吞
        var found = options.filter(function (o) { return o.el === li; })[0];
        if (found) pick(found);
    });

    document.addEventListener('click', function (e) {
        if (!box.contains(e.target)) close();
    });

    // 错误回显：带回来一个已经选过的国家
    if (hidden.value) {
        var preset = options.filter(function (o) { return o.code === hidden.value; })[0];
        if (preset) input.value = preset.name;
    }

    countryPicker = { close: close };
}

// ========== 儿童同意年龄（不预存，选完国家问 /api/consent-age）==========

var consentAgeCache = {};
var consentAgePending = {};

function calcAge(birthDate) {
    var parts = birthDate.split('-');
    if (parts.length !== 3) return null;
    var birth = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
    var today = new Date();
    var age = today.getFullYear() - birth.getFullYear();
    if (today.getMonth() < birth.getMonth() || (today.getMonth() === birth.getMonth() && today.getDate() < birth.getDate())) {
        age--;
    }
    return age;
}

function fetchConsentAge(code) {
    if (consentAgeCache[code]) return Promise.resolve(consentAgeCache[code]);
    if (consentAgePending[code]) return consentAgePending[code];

    var form = document.getElementById('register-form');
    var url = (form && form.dataset.consentAgeUrl) || '/api/consent-age';

    consentAgePending[code] = fetch(url + '?country=' + encodeURIComponent(code))
        .then(function (res) { return res.json(); })
        .then(function (data) {
            delete consentAgePending[code];
            if (!data || typeof data.age !== 'number') throw new Error('bad payload');
            consentAgeCache[code] = data;
            return data;
        })
        .catch(function (err) {
            delete consentAgePending[code];
            throw err;
        });

    return consentAgePending[code];
}

// 家长邮箱那一段的依据说明：有法条写「依据 xx」，没有就引平台隐私权政策
function renderConsentSource(info) {
    var el = document.getElementById('consent-source');
    if (!el) return;
    if (!info) { el.hidden = true; el.textContent = ''; return; }

    var hasStatute = info.kind === 'statute' || info.kind === 'advisory';
    var text;
    if (hasStatute) {
        text = t('In your region, children under {:age} need parental consent (basis: {:label}{:kind})', { age: info.age, label: info.label, kind: info.kind === 'advisory' ? t(', local regulator guidance') : '' });
    } else {
        text = t('Under the site privacy policy, this region is handled as {:age}.', { age: info.age });
    }

    el.textContent = text;
    if (info.url) {
        el.appendChild(document.createTextNode(' '));
        var a = document.createElement('a');
        a.href = info.url;
        a.target = '_blank';
        a.rel = 'noopener noreferrer';
        a.textContent = t('Official source ↗');
        el.appendChild(a);
    } else if (info.policy_url) {
        el.appendChild(document.createTextNode(' '));
        var p = document.createElement('a');
        p.href = info.policy_url;
        p.target = '_blank';
        p.rel = 'noopener noreferrer';
        p.textContent = t('Privacy Policy ↗');
        el.appendChild(p);
    }
    el.hidden = false;
}

function applyConsentRule(isChild, info) {
    var emailLabel = document.getElementById('email-label');
    var emailHint = document.getElementById('email-hint');
    var emailInput = document.getElementById('email');
    var parentGroup = document.getElementById('parent-email-group');

    if (isChild) {
        emailLabel.textContent = t('Your own email (optional)');
        emailHint.textContent = t('Use your own if you have one; otherwise leave empty and fill in the parent email below');
        emailInput.removeAttribute('required');
        parentGroup.style.display = 'block';
    } else {
        emailLabel.textContent = t('Email addresses');
        emailHint.textContent = t('For important notifications and account recovery');
        emailInput.setAttribute('required', '');
        parentGroup.style.display = 'none';
    }

    renderConsentSource(isChild ? info : null);
    syncParentEmailRequired();
}

// 出生日期或国家一变就重判；缺一个就先按成人那段显示
function refreshConsentRule() {
    var birthDate = document.getElementById('birth_date').value;
    var code = document.getElementById('country').value;
    if (!birthDate || !code) {
        applyConsentRule(false, null);
        return;
    }

    var age = calcAge(birthDate);
    if (age === null) {
        applyConsentRule(false, null);
        return;
    }

    if (consentAgeCache[code]) {
        applyConsentRule(age < consentAgeCache[code].age, consentAgeCache[code]);
        return;
    }

    // 还没拿到当地年龄：先按儿童处理（宁可多显示一个可留空的家长邮箱，
    // 也不能让未成年漏填后被服务端拦在最后一步），拿到结果再纠正
    applyConsentRule(true, null);
    fetchConsentAge(code)
        .then(function (info) { applyConsentRule(age < info.age, info); })
        .catch(function () { /* 拿不到就保持"按儿童显示"这个保守状态 */ });
}

// 只有「没填自己的邮箱」时家长邮箱才必填
function syncParentEmailRequired() {
    var emailInput = document.getElementById('email');
    var parentInput = document.getElementById('parent_email');
    var parentGroup = document.getElementById('parent-email-group');
    if (!emailInput || !parentInput || !parentGroup) return;

    if (parentGroup.style.display !== 'none' && !emailInput.value.trim()) {
        parentInput.setAttribute('required', '');
    } else {
        parentInput.removeAttribute('required');
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var emailInput = document.getElementById('email');
    if (emailInput) {
        emailInput.addEventListener('input', syncParentEmailRequired);
    }

    initCountryPicker();

    var birthDate = document.getElementById('birth_date');
    if (birthDate) {
        birthDate.addEventListener('change', refreshConsentRule);
        birthDate.addEventListener('input', refreshConsentRule);
    }
});

// 下一步
function nextStep() {
    if (!validateCurrentStep()) return;

    // 离开第三步（国家）/ 第四步（生日）时重判一次：国家是刚选的，这时候才拿得到当地年龄
    if (currentStep === 3 || currentStep === 4) {
        refreshConsentRule();
    }

    currentStep++;
    document.getElementById('current-step').value = currentStep;
    updateStepUI();
}

// 上一步
function prevStep() {
    currentStep--;
    document.getElementById('current-step').value = currentStep;
    updateStepUI();
}

// 更新步骤UI
function updateStepUI() {
    document.querySelectorAll('.form-step').forEach(function(el) {
        el.classList.remove('active');
    });

    document.getElementById('step-' + currentStep).classList.add('active');

    var progress = document.getElementById('register-progress');
    if (progress) {
        progress.hidden = currentStep < progressFromStep;
    }

    var fill = document.getElementById('register-progress-fill');
    if (fill) {
        fill.style.width = (currentStep / totalSteps * 100) + '%';
        fill.parentElement.setAttribute('aria-valuenow', currentStep);
    }

    var count = document.getElementById('register-progress-count');
    if (count) {
        count.textContent = currentStep + ' / ' + totalSteps;
    }

    document.getElementById('btn-prev').style.display = currentStep > 1 ? 'block' : 'none';
    document.getElementById('btn-next').style.display = currentStep < totalSteps ? 'block' : 'none';
    document.getElementById('btn-submit').style.display = currentStep === totalSteps ? 'block' : 'none';
}

// 验证当前步骤
function validateCurrentStep() {
    switch (currentStep) {
        case 1:
            var username = document.getElementById('username').value.trim();
            if (!username) {
                showToast(t('Enter a username'), 'warning');
                return false;
            }
            if (!/^[a-zA-Z0-9_]{3,32}$/.test(username)) {
                showToast(t('Invalid username format'), 'error');
                return false;
            }
            var usernameStatus = document.getElementById('username-status');
            if (usernameStatus.dataset.state === 'bad') {
                showToast(t('Username unavailable'), 'error');
                return false;
            }
            return true;

        case 2:
            var password = document.getElementById('password').value;
            var passwordConfirm = document.getElementById('password_confirm').value;

            if (!password || password.length < 8) {
                showToast(t('Password needs at least 8 characters'), 'warning');
                return false;
            }
            if (!/[a-zA-Z]/.test(password) || !/[0-9]/.test(password)) {
                showToast(t('Password needs both letters and digits'), 'warning');
                return false;
            }
            if (password !== passwordConfirm) {
                showToast(t('The passwords you entered don\'t match'), 'error');
                return false;
            }
            return true;

        case 3:
            var country = document.getElementById('country').value;
            if (!country) {
                showToast(t('Pick a country/region'), 'warning');
                return false;
            }
            return true;

        case 4:
            var birthDate = document.getElementById('birth_date').value;
            if (!birthDate) {
                showToast(t('Pick a date of birth'), 'warning');
                return false;
            }
            var gender = document.querySelector('input[name="gender"]:checked');
            if (!gender) {
                showToast(t('Pick a gender'), 'warning');
                return false;
            }
            return true;

        case 5:
            var emailRe = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            var ownEmail = document.getElementById('email').value.trim();
            var parentVisible = document.getElementById('parent-email-group').style.display !== 'none';
            var captcha = document.querySelector('input[name="captcha"]').value;
            var agreeTerms = document.querySelector('input[name="agree_terms"]').checked;
            var agreeService = document.querySelector('input[name="agree_service"]').checked;

            if (ownEmail) {
                if (!emailRe.test(ownEmail)) {
                    showToast(t('Enter a valid email address'), 'warning');
                    return false;
                }
            } else {
                if (!parentVisible) {
                    showToast(t('Enter a valid email address'), 'warning');
                    return false;
                }
                var parentEmail = document.getElementById('parent_email').value.trim();
                if (!emailRe.test(parentEmail)) {
                    showToast(t('Enter a parent\'s email so we can send the verification'), 'warning');
                    return false;
                }
            }
            if (!captcha) {
                showToast(t('Enter the code'), 'warning');
                return false;
            }
            if (!agreeTerms || !agreeService) {
                showToast(t('Please agree to the Privacy Policy and Terms of Service'), 'warning');
                return false;
            }
            return true;

        default:
            return true;
    }
}

// 初始化
document.addEventListener('DOMContentLoaded', function() {
    // 恢复步骤（错误回显时）
    var savedStep = parseInt(document.getElementById('current-step').value) || 1;
    if (savedStep > 1 && savedStep <= totalSteps) {
        currentStep = savedStep;
        // 恢复到第四步之后：按出生日期 + 国家重判一次
        if (currentStep >= 4) {
            refreshConsentRule();
        }
    }
    updateStepUI();
});
