<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

/** @var string|null $error */
/** @var array $old */
/** @var string $detectedCountry */
$title = t('Sign up');
$extraCss = '/css/register.css?v=83568981';

$featureCountry = \Nomen\Src\Features::country();
$featureGuardians = \Nomen\Src\Features::guardians();

$old = $old ?? [];
$countryValue = $featureCountry ? ($old['country'] ?? '') : '';
$autoCountry = false;
if ($featureCountry && $countryValue === '' && ($detectedCountry ?? '') !== '') {
    $countryValue = $detectedCountry;
    $autoCountry = true;
}

$countries = [];
if ($featureCountry) {
    foreach (\Nomen\Src\FamilyHelper::countries() as $code => $country) {
        $countries[] = [
            'code' => $code,
            'zh' => $country['zh'] ?? $code,
            'en' => $country['en'] ?? '',
            'local' => $country['local'] ?? '',
        ];
    }
}

// 儿童同意年龄不预存进页面：选完国家由 /api/consent-age 现查（带来源），
// 真正的判定在服务端
$props = [
    'csrf' => \Nomen\Src\Csrf::getToken(),
    'error' => $error ?: '',
    'old' => $old ?: new \stdClass(),
    'country' => $countryValue,
    'autoCountry' => $autoCountry,
    'today' => date('Y-m-d'),
    'countries' => $countries,
    'features' => [
        'country' => $featureCountry,
        'guardians' => $featureGuardians,
    ],
    'providers' => \Nomen\Src\IdentityProviders::publicList(),
    'passwordRegistration' => (bool)($passwordRegistration ?? true),
    'privacyUrl' => (string)\Nomen\Src\Brand::get('privacy_url'),
    'termsUrl' => (string)\Nomen\Src\Brand::get('terms_url'),
    'supportEmail' => (string)\Nomen\Src\Brand::get('support_email'),
    'brandLogo' => (string)\Nomen\Src\Brand::get('logo'),
    'urls' => [
        'consentAge' => '/api/consent-age',
        'checkUsername' => '/api/check-username',
        'captcha' => '/captcha',
    ],
];

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div id="register-app" class="auth-container">
    <noscript><p class="form-hint"><?= t('Sign-up requires JavaScript.') ?></p></noscript>
    <script type="application/json" id="register-props"><?= json_encode($props, $jsonFlags) ?></script>
</div>
<?= \Nomen\Src\ViteAssets::entry('src/entries/register.js') ?>
