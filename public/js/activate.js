// 激活页面脚本
function confirmDelete() {
    console.log('[Activate] confirmDelete called');
    
    Nomen.confirm(t('注销账号'), t('确定要注销账号吗？此操作不可撤销，你的数据将被永久删除。'), {
        confirmText: t('确认注销'),
        cancelText: t('再想想'),
        danger: true,
        icon: 'trash'
    }).then(function(confirmed) {
        console.log('[Activate] First confirm:', confirmed);
        if (confirmed) {
            Nomen.confirm(t('最后确认'), t('真的要删除吗？删除后无法恢复！'), {
                confirmText: t('确定删除'),
                cancelText: t('取消'),
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
