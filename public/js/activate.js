/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

// 激活页面脚本
function confirmDelete() {
    console.log('[Activate] confirmDelete called');
    
    Nomen.confirm(t('Delete account'), t('Really delete this account? This can\'t be undone and your data is gone for good.'), {
        confirmText: t('Confirm account deletion'),
        cancelText: t('Let me think'),
        danger: true,
        icon: 'trash'
    }).then(function(confirmed) {
        console.log('[Activate] First confirm:', confirmed);
        if (confirmed) {
            Nomen.confirm(t('Final confirmation'), t('Really delete? This can\'t be undone!'), {
                confirmText: t('Confirm delete'),
                cancelText: t('Cancel'),
                danger: true,
                icon: 'warning'
            }).then(function(confirmed2) {
                console.log('[Activate] Second confirm:', confirmed2);
                if (confirmed2) {
                    console.log('[Activate] Submitting delete form');
                    var form = document.getElementById('delete-form');
                    Nomen.beginSubmit(form);
                    form.submit();
                }
            });
        }
    });
}
