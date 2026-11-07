// 邮箱回收验证页：重发按钮的冷却倒计时
document.addEventListener('DOMContentLoaded', function () {
    var claimForm = document.getElementById('claim-form');
    var btn = document.getElementById('resend-btn');
    var text = document.getElementById('resend-text');
    if (!claimForm || !btn || !text) {
        return;
    }

    var left = parseInt(claimForm.dataset.resendAfter, 10) || 0;
    var timer = null;

    function render() {
        if (left > 0) {
            btn.disabled = true;
            text.textContent = t('Resend (in {:n}s)', { n: left });
            return;
        }
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        btn.disabled = false;
        text.textContent = t('Resend code');
    }

    render();
    if (left > 0) {
        timer = setInterval(function () {
            left--;
            render();
        }, 1000);
    }
});
