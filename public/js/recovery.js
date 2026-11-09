/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

(function () {
    'use strict';

    var form = document.getElementById('recovery-form');
    if (!form) return;

    var TOTAL_STEPS = 3;
    var MIN_DESCRIPTION = 20;
    var stepInput = document.getElementById('current-step');
    var formSteps = form.querySelectorAll('.form-step');
    var indicators = document.querySelectorAll('.register-steps .step');
    var lines = document.querySelectorAll('.register-steps .step-line');
    var prevBtn = document.getElementById('btn-prev');
    var nextBtn = document.getElementById('btn-next');
    var submitBtn = document.getElementById('btn-submit');

    var birthDate = document.getElementById('birth_date');
    var description = document.getElementById('description');
    var counter = document.getElementById('description-counter');
    var tempEmailGroup = document.getElementById('temp-email-group');
    var tempEmail = document.getElementById('temp_email');
    var accountOption = document.getElementById('contact-account');
    var tempOption = document.getElementById('contact-temp');

    var current = parseInt(stepInput.value, 10) || 1;
    if (current < 1 || current > TOTAL_STEPS) current = 1;

    // ========== 步骤切换 ==========

    function selectedGender() {
        var checked = form.querySelector('input[name="gender"]:checked');
        return checked ? checked.value : '';
    }

    function selectedContact() {
        var checked = form.querySelector('input[name="contact"]:checked');
        return checked ? checked.value : '';
    }

    function isEmail(value) {
        return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
    }

    function syncTempEmail() {
        if (!tempEmailGroup) return;
        tempEmailGroup.style.display = selectedContact() === 'temp' ? 'block' : 'none';
    }

    function updateCounter() {
        if (!counter || !description) return;
        var n = description.value.length;
        counter.textContent = n >= MIN_DESCRIPTION
            ? t('{:n} / 2000 characters', { n: n })
            : t('{:n} / 2000 characters (at least {:min}; {:lack} to go)', { n: n, min: MIN_DESCRIPTION, lack: MIN_DESCRIPTION - n });
        counter.style.color = n > 0 && n < MIN_DESCRIPTION ? 'var(--color-warning)' : '';
    }

    function renderSteps() {
        Array.prototype.forEach.call(formSteps, function (el, index) {
            el.classList.toggle('active', index + 1 === current);
        });

        Array.prototype.forEach.call(indicators, function (el, index) {
            var step = index + 1;
            el.classList.toggle('active', step === current);
            el.classList.toggle('completed', step < current);
        });

        Array.prototype.forEach.call(lines, function (el, index) {
            el.classList.toggle('active', index + 1 < current);
        });

        prevBtn.style.display = current > 1 ? 'inline-block' : 'none';
        nextBtn.style.display = current < TOTAL_STEPS ? 'inline-block' : 'none';
        submitBtn.style.display = current === TOTAL_STEPS ? 'block' : 'none';

        stepInput.value = current;
        if (current === TOTAL_STEPS) syncTempEmail();
    }

    function validateStep(step) {
        if (step === 1) {
            if (!birthDate.value) {
                Nomen.alert(t('Almost there'), t('Pick a date of birth'));
                return false;
            }
            if (!selectedGender()) {
                Nomen.alert(t('Almost there'), t('Pick a gender'));
                return false;
            }
            return true;
        }

        if (step === 2) {
            var text = description.value.trim();
            if (!text) {
                Nomen.alert(t('Almost there'), t('Please fill in the description and proof of account ownership'));
                return false;
            }
            if (text.length > 2000) {
                Nomen.alert(t('Too long'), t('The description can\'t exceed 2000 characters'));
                return false;
            }
            if (text.length < MIN_DESCRIPTION) {
                Nomen.alert(
                    t('Please be more specific'),
                    t('At least {:n} characters. Include things only the owner would know — the sign-up email, usual sign-in locations, authorized apps. This is the main basis for ownership review.', { n: MIN_DESCRIPTION })
                );
                return false;
            }
            return true;
        }

        if (step === 3) {
            var contact = selectedContact();
            if (!contact) {
                Nomen.alert(t('Almost there'), t('Pick a mailbox'));
                return false;
            }
            if (contact === 'temp' && !isEmail(tempEmail.value.trim())) {
                Nomen.alert(t('Email looks wrong'), t('Enter a valid temporary email address'));
                return false;
            }
            return true;
        }

        return true;
    }

    window.recoveryNextStep = function () {
        if (!validateStep(current)) return;
        if (current < TOTAL_STEPS) {
            current++;
            renderSteps();
        }
    };

    window.recoveryPrevStep = function () {
        if (current > 1) {
            current--;
            renderSteps();
        }
    };

    // ========== 证明材料：选图 / 粘贴 / 压缩 / 暂存 ==========

    var MAX_FILES = 5;
    var MAX_BYTES = 1024 * 1024;
    // 压到留点余量，别正好卡在 1MB 边界上被后端拒收
    var TARGET_BYTES = Math.floor(MAX_BYTES * 0.94);
    var ALLOWED = ['image/png', 'image/jpeg', 'image/webp'];
    var MAX_DIMENSION = 2400;

    var uploadsEl = document.getElementById('recovery-uploads');
    var addBtn = document.getElementById('recovery-upload-add');
    var fileInput = document.getElementById('recovery-file-input');
    var statusEl = document.getElementById('recovery-upload-status');
    var tokensEl = document.getElementById('recovery-upload-tokens');

    var items = [];
    var seq = 0;
    var uploading = false;

    function formatSize(bytes) {
        if (bytes >= 1024 * 1024) return (bytes / 1024 / 1024).toFixed(1) + ' MB';
        if (bytes >= 1024) return Math.round(bytes / 1024) + ' KB';
        return bytes + ' B';
    }

    function setStatus(text, kind) {
        if (!statusEl) return;
        statusEl.textContent = text || '';
        statusEl.className = 'rc-uploads__status' + (kind ? ' rc-uploads__status--' + kind : '');
    }

    function updateSummary() {
        if (!items.length) {
            setStatus('');
            return;
        }
        var total = items.reduce(function (sum, item) { return sum + (item.size || 0); }, 0);
        var compressed = items.filter(function (item) { return item.compressed; }).length;
        setStatus(
            t('Selected {:n} / {:max}, {:size}{:comp} total', { n: items.length, max: MAX_FILES, size: formatSize(total), comp: compressed ? t('({:n} auto-compressed)', { n: compressed }) : '' })
        );
    }

    function renderTiles() {
        var olds = uploadsEl.querySelectorAll('.rc-upload');
        Array.prototype.forEach.call(olds, function (el) { el.parentNode.removeChild(el); });

        items.forEach(function (item) {
            var tile = document.createElement('div');
            tile.className = 'rc-upload'
                + (item.state === 'error' ? ' rc-upload--error' : '')
                + (item.state === 'uploading' ? ' rc-upload--busy' : '');

            var img = document.createElement('img');
            img.className = 'rc-upload__img';
            img.alt = t('Evidence preview');
            if (item.url) img.src = item.url;
            tile.appendChild(img);

            var meta = document.createElement('div');
            meta.className = 'rc-upload__meta';
            if (item.state === 'uploading') {
                meta.textContent = t('Uploading…');
            } else if (item.state === 'error') {
                meta.textContent = t('Upload failed');
            } else {
                meta.textContent = formatSize(item.size) + (item.compressed ? t(' · compressed') : '');
            }
            tile.appendChild(meta);

            var remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'rc-upload__remove';
            remove.setAttribute('aria-label', t('Remove this image'));
            remove.textContent = '×';
            remove.addEventListener('click', function () { removeItem(item.id); });
            tile.appendChild(remove);

            uploadsEl.insertBefore(tile, addBtn);
        });

        addBtn.style.display = items.length >= MAX_FILES ? 'none' : 'flex';
    }

    function removeItem(id) {
        for (var i = 0; i < items.length; i++) {
            if (items[i].id === id) {
                if (items[i].url) URL.revokeObjectURL(items[i].url);
                items.splice(i, 1);
                break;
            }
        }
        renderUploads();
    }

    function renderUploads() {
        renderTiles();
        updateSummary();
    }

    function loadImage(blob) {
        return new Promise(function (resolve, reject) {
            var url = URL.createObjectURL(blob);
            var img = new Image();
            img.onload = function () { resolve({ img: img, url: url }); };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error(t('Can\'t decode this image — try another format')));
            };
            img.src = url;
        });
    }

    function pickEncodingType() {
        try {
            var probe = document.createElement('canvas');
            probe.width = 1;
            probe.height = 1;
            return probe.toDataURL('image/webp').indexOf('data:image/webp') === 0
                ? 'image/webp'
                : 'image/jpeg';
        } catch (e) {
            return 'image/jpeg';
        }
    }

    function encodeAt(img, scale, quality, type) {
        return new Promise(function (resolve, reject) {
            var w = Math.max(1, Math.round(img.naturalWidth * scale));
            var h = Math.max(1, Math.round(img.naturalHeight * scale));
            var canvas = document.createElement('canvas');
            canvas.width = w;
            canvas.height = h;

            var ctx = canvas.getContext('2d');
            // webp/jpeg 都不带透明通道，先铺白底，否则透明区会变黑块
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, w, h);
            ctx.drawImage(img, 0, 0, w, h);

            canvas.toBlob(function (blob) {
                if (blob) {
                    resolve(blob);
                } else {
                    reject(new Error(t('In-browser compression failed')));
                }
            }, type, quality);
        });
    }

    /**
     * 超过 1MB 就压：先降质量，仍不够再逐步缩尺寸，返回体积最小的一次
     */
    async function compressImage(file) {
        var type = pickEncodingType();
        var handle = await loadImage(file);
        var img = handle.img;

        var scale = Math.min(1, MAX_DIMENSION / Math.max(img.naturalWidth, img.naturalHeight));
        var qualities = [0.9, 0.82, 0.74, 0.66, 0.58];
        var best = null;

        try {
            for (var round = 0; round < 5 && scale > 0.12; round++) {
                for (var qi = 0; qi < qualities.length; qi++) {
                    var blob = await encodeAt(img, scale, qualities[qi], type);
                    if (!best || blob.size < best.size) best = blob;
                    if (blob.size <= TARGET_BYTES) return blob;
                }
                scale *= 0.72;
            }
        } finally {
            URL.revokeObjectURL(handle.url);
        }

        return best || file;
    }

    async function addFiles(fileList) {
        var incoming = Array.prototype.slice.call(fileList || []);
        var usable = incoming.filter(function (f) {
            return f && ALLOWED.indexOf(f.type) >= 0;
        });

        var skippedType = incoming.length - usable.length;
        if (skippedType > 0) {
            Nomen.alert(t('Format not supported'), t('Only PNG / JPG / WebP accepted — skipped {:n} file(s).', { n: skippedType }));
        }
        if (!usable.length) return;

        var room = MAX_FILES - items.length;
        if (room <= 0) {
            Nomen.alert(t('Image limit reached'), t('Up to {:n} evidence images.', { n: MAX_FILES }));
            return;
        }

        var overflow = usable.length - room;
        if (overflow > 0) {
            usable = usable.slice(0, room);
            Nomen.alert(t('Too many images'), t('At most {:max} images — skipped the extra {:n}.', { max: MAX_FILES, n: overflow }));
        }

        for (var i = 0; i < usable.length; i++) {
            var file = usable[i];
            var item = {
                id: ++seq,
                name: file.name || ('paste-' + seq + '.png'),
                blob: file,
                size: file.size,
                state: 'ready',
                compressed: false,
                url: '',
                token: ''
            };
            items.push(item);
            renderUploads();

            if (file.size > MAX_BYTES) {
                setStatus(t('Image {:n} is over 1 MB, compressing…', { n: items.length }));
                try {
                    var compressed = await compressImage(file);
                    item.blob = compressed;
                    item.size = compressed.size;
                    item.compressed = true;
                    if (compressed.size > MAX_BYTES) {
                        item.state = 'error';
                        setStatus(t('One image is still over 1 MB after compression — remove it and pick another'), 'error');
                    }
                } catch (err) {
                    item.state = 'error';
                    setStatus(err.message, 'error');
                }
            }

            item.url = URL.createObjectURL(item.blob);
            renderUploads();
        }

        if (!items.some(function (it) { return it.state === 'error'; })) {
            updateSummary();
        }
    }

    function uploadOne(item, csrf) {
        return new Promise(function (resolve, reject) {
            var data = new FormData();
            data.append('_csrf', csrf);
            data.append('file', item.blob, item.name);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/recovery/upload', true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                var body = null;
                try { body = JSON.parse(xhr.responseText); } catch (e) { body = null; }
                if (body && body.success && body.token) {
                    resolve(body.token);
                    return;
                }
                if (body && body.message) {
                    reject(new Error(body.message));
                    return;
                }
                // 拿不到 JSON（例如 nginx 413 / PHP 致命错误返回的错误页）：
                // 把状态码带出来，别只说「请重试」让人无从下手
                reject(new Error(t('Server returned {:code} — the image didn\'t upload', { code: xhr.status })));
            };
            xhr.onerror = function () { reject(new Error(t('Network error — the image didn\'t upload'))); };
            xhr.send(data);
        });
    }

    async function uploadAll() {
        var csrfInput = form.querySelector('input[name="_csrf"]');
        var csrf = csrfInput ? csrfInput.value : '';
        tokensEl.innerHTML = '';

        var remaining = items.filter(function (item) { return item.state !== 'done'; });

        for (var i = 0; i < remaining.length; i++) {
            var item = remaining[i];
            item.state = 'uploading';
            renderUploads();
            setStatus(t('Uploading evidence image {:a} / {:b}…', { a: i + 1, b: remaining.length }));

            item.token = await uploadOne(item, csrf);
            item.state = 'done';
            renderUploads();
        }

        items.forEach(function (item) {
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = 'upload_tokens[]';
            hidden.value = item.token;
            tokensEl.appendChild(hidden);
        });
    }

    function lockSubmit(text) {
        submitBtn.disabled = true;
        submitBtn.textContent = text || t('Submitting…');
    }

    function releaseSubmit() {
        submitBtn.disabled = false;
        submitBtn.textContent = t('Submit request');
    }

    // ========== 绑定 ==========

    form.addEventListener('submit', function (event) {
        for (var step = 1; step <= TOTAL_STEPS; step++) {
            if (!validateStep(step)) {
                event.preventDefault();
                current = step;
                renderSteps();
                return;
            }
        }

        if (uploading) {
            event.preventDefault();
            return;
        }

        var broken = items.filter(function (item) { return item.state === 'error'; });
        if (broken.length) {
            event.preventDefault();
            Nomen.alert(t('Some images aren\'t ready'), t('Remove the images marked as failed first, or add them again, then submit.'));
            return;
        }

        if (!items.length) {
            lockSubmit();
            return;
        }

        // 有图：拦下这次提交，先把图传完再真正提交
        event.preventDefault();
        uploading = true;
        lockSubmit(t('Uploading images…'));

        uploadAll().then(function () {
            uploading = false;
            releaseSubmit();
            Nomen.beginSubmit(form);
            // 用原生 submit 绕过本监听器，否则会再进一次这里
            HTMLFormElement.prototype.submit.call(form);
        }).catch(function (err) {
            uploading = false;
            releaseSubmit();
            renderUploads();
            setStatus(err.message + t(' — your images are kept. You can submit again, or remove the problem images first.'), 'error');
            Nomen.alert(t('Image upload failed'), err.message + t('\n\nYour images are kept: you can press Submit again to retry, or remove the problem images first.'));
        });
    });

    if (addBtn && fileInput) {
        addBtn.addEventListener('click', function () { fileInput.click(); });
        fileInput.addEventListener('change', function () {
            addFiles(fileInput.files);
            fileInput.value = '';
        });
    }

    document.addEventListener('paste', function (event) {
        if (current !== 2) return;
        var clipboard = event.clipboardData;
        if (!clipboard) return;

        var list = clipboard.items || [];
        var files = [];
        for (var i = 0; i < list.length; i++) {
            if (list[i].kind === 'file' && list[i].type && list[i].type.indexOf('image/') === 0) {
                var file = list[i].getAsFile();
                if (file) files.push(file);
            }
        }

        // 纯文本粘贴照常走默认行为，只拦带图的
        if (!files.length) return;
        event.preventDefault();
        addFiles(files);
    });

    if (description) {
        description.addEventListener('input', updateCounter);
        updateCounter();
    }

    if (accountOption) accountOption.addEventListener('change', syncTempEmail);
    if (tempOption) tempOption.addEventListener('change', syncTempEmail);

    renderSteps();
    renderUploads();
})();
