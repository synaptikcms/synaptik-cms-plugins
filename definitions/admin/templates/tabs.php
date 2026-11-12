<div class="tabs df-subnav">
    <?php foreach ([
        'dashboard' => df_t('df_nav_dashboard', 'Glossary', 'admin'),
        'settings'  => df_t('df_nav_settings', 'Settings', 'admin'),
    ] as $__df_v => $__df_label): ?>
    <a href="<?php echo hsc(df_admin_page_url(['view' => $__df_v])); ?>"
       class="tab <?php echo $view === $__df_v ? 'active' : ''; ?>">
        <?php echo hsc($__df_label); ?>
    </a>
    <?php endforeach; ?>
</div>
