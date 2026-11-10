<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only.
 * Attribution requests are in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */

/**
 * nomenid.org 的首页。只在官方站启用（后台「首页」里选它），不进发布包。
 * 首屏的标题、标语、两个按钮仍读设置，所以后台编辑器照样能改；下面的能力分段写死在这里。
 */

use Nomen\Src\Brand;
use Nomen\Src\Homepage;
use Nomen\Src\Plugins;

$brand = Brand::all();
$home = Homepage::settings();
$app = require dirname(__DIR__) . '/config/app.php';

$title = $home['title'] !== '' ? $home['title'] : (string)$brand['name'];
$subtitle = $home['subtitle'] !== '' ? $home['subtitle'] : t('A galaxy of accounts');
$primaryLabel = $home['primary_label'] !== '' ? $home['primary_label'] : t('Download');
$primaryHref = $home['primary_url'] !== '' ? $home['primary_url'] : '/w/download';
$secondaryLabel = $home['secondary_label'] !== '' ? $home['secondary_label'] : t('Demo');
$secondaryHref = $home['secondary_url'] !== '' ? $home['secondary_url'] : '/login';
$links = Homepage::links();

$docs = Plugins::mountUrl('pages') ?? '/w';
$version = (string)($app['version'] ?? '');

// 页面文案走核心词典（英文母键），语言切换跟着站点语言走。
$copy = [
    'hero_desc'      => t('An account system that runs on your own server. Every site you connect shares one sign-in.'),
    's1_title'       => t('One sign-in for every site you run'),
    's1_body'        => t('Sites connect through the OAuth 2.0 authorization code flow; sign-in, consent and profile requests go through Nomen. A person keeps the same identity on every site, and a name or avatar change happens in one place.'),
    's1_link'        => t('Integration guide'),
    's2_title'       => t('Sign in with an account you already have'),
    's2_body'        => t('GitHub, Google and Microsoft are built in, and any other OAuth 2.0 provider can be added. An account with a verified email is linked to the person who already owns it instead of creating a duplicate.'),
    's3_title'       => t('A password is only one way in'),
    's3_body'        => t('Time-based codes for two-factor sign-in, passkeys over WebAuthn, recovery codes when the phone is gone, and a recovery request when everything else is. Temporary keys for borrowed devices expire on their own.'),
    's4_title'       => t('A site should look like its own'),
    's4_body'        => t('Site name, icon, footer and policy links are configuration, not code. Skins swap the whole look and users pick their own; when that is not enough, plugins get menus, pages and hooks.'),
    'install_title'  => t('Install'),
    'install_lead'   => t('One release package, one web installer. Once the rewrite rule is in place, the rest is filling in a form.'),
    'step1_title'    => t('Extract it into the site directory'),
    'step1_body'     => t('Point the web root at public/ and add the try_files rule from the docs.'),
    'step2_title'    => t('Open the site and follow the installer'),
    'step2_body'     => t('Environment check, database, site details, admin account, extensions and mail. Seven steps and it is done.'),
    'step3_title'    => t('Remove the installer'),
    'step3_body'     => t('Delete or rename public/nomen-install/ so the wizard is no longer reachable.'),
    'foot_version'   => t('Latest version'),
    'foot_changelog' => t('Changelog'),
    'foot_package'   => t('Release package'),
];
?>
<style>
    .hn {
        --hn-width: 1120px;
        color: var(--color-text);
    }

    .hn-wrap {
        width: 100%;
        max-width: var(--hn-width);
        margin: 0 auto;
        padding: 0 24px;
    }

    .hn-hero {
        padding: 92px 0 56px;
    }

    .hn-hero__inner {
        display: grid;
        grid-template-columns: minmax(0, 1.05fr) minmax(0, 0.95fr);
        gap: 48px;
        align-items: center;
    }

    .hn-hero__title {
        margin: 0;
        font-size: clamp(2rem, 4.6vw, 3rem);
        font-weight: 700;
        line-height: 1.15;
        letter-spacing: -0.03em;
        overflow-wrap: anywhere;
    }

    .hn-hero__line {
        margin: 16px 0 0;
        font-size: 1.125rem;
        line-height: 1.7;
        color: var(--color-text);
        opacity: 0.85;
    }

    .hn-hero__desc {
        margin: 6px 0 0;
        font-size: 15px;
        line-height: 1.8;
        color: var(--color-text-muted);
        max-width: 34em;
    }

    .hn-hero__actions {
        display: flex;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 30px;
    }

    .hn-hero__links {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
        margin-top: 30px;
        padding-top: 18px;
        border-top: 1px solid var(--color-border);
    }

    .hn-hero__links a {
        font-size: 13px;
        color: var(--color-text-muted);
        text-decoration: none;
    }

    .hn-hero__links a:hover {
        color: var(--color-primary);
    }

    .hn-hero__art {
        display: flex;
        align-items: center;
        justify-content: center;
        color: var(--color-text-subtle);
    }

    .hn-hero__art svg {
        width: 100%;
        max-width: 360px;
        height: auto;
    }

    .hn-mark__orbits circle {
        fill: none;
        stroke: var(--color-border);
        stroke-width: 1;
    }

    .hn-mark__orbits circle:last-child {
        stroke-dasharray: 3 7;
    }

    .hn-mark__links path {
        fill: none;
        stroke: var(--color-border);
        stroke-width: 1;
    }

    .hn-mark__nodes circle {
        fill: var(--color-text-subtle);
    }

    .hn-mark__core {
        fill: var(--color-primary);
    }

    .hn-rows {
        border-top: 1px solid var(--color-border);
    }

    .hn-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
        gap: 56px;
        align-items: center;
        padding: 64px 0;
        border-bottom: 1px solid var(--color-border);
    }

    .hn-row--flip .hn-row__text {
        order: 2;
    }

    .hn-row h2 {
        margin: 0;
        font-size: clamp(1.25rem, 2vw, 1.5rem);
        font-weight: 600;
        line-height: 1.35;
        letter-spacing: -0.01em;
    }

    .hn-row p {
        margin: 14px 0 0;
        font-size: 15px;
        line-height: 1.85;
        color: var(--color-text-muted);
    }

    .hn-row__more {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
        margin-top: 18px;
    }

    .hn-row__more a {
        font-size: 13px;
        color: var(--color-primary);
        text-decoration: none;
    }

    .hn-row__more a:hover {
        text-decoration: underline;
    }

    .hn-art svg {
        display: block;
        width: 100%;
        max-width: 480px;
        height: auto;
        margin: 0 auto;
        color: var(--color-text-subtle);
    }

    .hn-art .hn-line {
        fill: none;
        stroke: var(--color-text-subtle);
        stroke-opacity: 0.4;
        stroke-width: 1;
    }

    .hn-art .hn-panel {
        fill: var(--color-bg-secondary);
        stroke: var(--color-border);
        stroke-width: 1;
    }

    .hn-art .hn-bar {
        fill: var(--color-border);
    }

    .hn-art .hn-accent {
        fill: var(--color-primary);
    }

    .hn-art .hn-accent-stroke {
        fill: none;
        stroke: var(--color-primary);
        stroke-width: 1;
    }

    .hn-steps {
        padding: 64px 0;
        border-bottom: 1px solid var(--color-border);
    }

    .hn-steps h2 {
        margin: 0;
        font-size: clamp(1.25rem, 2vw, 1.5rem);
        font-weight: 600;
        letter-spacing: -0.01em;
    }

    .hn-steps__lead {
        margin: 12px 0 0;
        font-size: 15px;
        line-height: 1.8;
        color: var(--color-text-muted);
    }

    .hn-steps__grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 28px;
        margin-top: 32px;
    }

    .hn-step__num {
        font-family: var(--font-mono);
        font-size: 12px;
        letter-spacing: 0.08em;
        color: var(--color-primary);
    }

    .hn-step h3 {
        margin: 8px 0 0;
        font-size: 15px;
        font-weight: 600;
        line-height: 1.5;
    }

    .hn-step p {
        margin: 8px 0 0;
        font-size: 13px;
        line-height: 1.75;
        color: var(--color-text-muted);
    }

    .hn-step code {
        padding: 0.1em 0.3em;
        background: var(--color-bg-tertiary);
        border-radius: 4px;
        font-family: var(--font-mono);
        font-size: 0.92em;
        overflow-wrap: anywhere;
    }

    .hn-steps__foot {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        gap: 8px 20px;
        margin-top: 32px;
        padding-top: 20px;
        border-top: 1px solid var(--color-border);
        font-size: 13px;
        color: var(--color-text-muted);
    }

    .hn-steps__foot a {
        color: var(--color-primary);
        text-decoration: none;
    }

    .hn-steps__foot a:hover {
        text-decoration: underline;
    }

    .hn-foot {
        display: flex;
        flex-wrap: wrap;
        align-items: baseline;
        justify-content: space-between;
        gap: 12px 28px;
        padding: 26px 0 56px;
        font-size: 13px;
        color: var(--color-text-muted);
    }

    .hn-foot nav {
        display: flex;
        flex-wrap: wrap;
        gap: 18px;
    }

    .hn-foot a {
        color: var(--color-text-muted);
        text-decoration: none;
    }

    .hn-foot a:hover {
        color: var(--color-primary);
    }

    @media (max-width: 860px) {
        .hn-hero {
            padding-top: 56px;
        }

        .hn-hero__inner,
        .hn-row {
            grid-template-columns: 1fr;
            gap: 32px;
        }

        .hn-hero__art {
            order: -1;
        }

        .hn-hero__art svg {
            max-width: 220px;
        }

        .hn-row {
            padding: 44px 0;
        }

        .hn-row--flip .hn-row__text {
            order: 0;
        }

        .hn-art svg {
            max-width: 320px;
        }

        .hn-steps__grid {
            grid-template-columns: 1fr;
            gap: 22px;
        }
    }
</style>

<div class="hn">
    <section class="hn-hero">
        <div class="hn-wrap hn-hero__inner">
            <div>
                <h1 class="hn-hero__title"><?= htmlspecialchars($title) ?></h1>
                <?php if ($subtitle !== ''): ?>
                    <p class="hn-hero__line"><?= htmlspecialchars($subtitle) ?></p>
                <?php endif; ?>
                <p class="hn-hero__desc"><?= htmlspecialchars($copy['hero_desc']) ?></p>
                <div class="hn-hero__actions">
                    <a href="<?= htmlspecialchars($primaryHref) ?>" class="btn btn-primary btn-lg"><?= htmlspecialchars($primaryLabel) ?></a>
                    <a href="<?= htmlspecialchars($secondaryHref) ?>" class="btn btn-ghost btn-lg"><?= htmlspecialchars($secondaryLabel) ?></a>
                </div>
                <nav class="hn-hero__links" aria-label="<?= t('More') ?>">
                    <?php foreach ($links as $link): ?>
                        <a href="<?= htmlspecialchars((string)$link['href']) ?>"><?= htmlspecialchars((string)$link['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="hn-hero__art" aria-hidden="true">
                <svg viewBox="0 0 360 360" role="presentation" focusable="false">
                    <g class="hn-mark__orbits">
                        <circle cx="180" cy="180" r="88" />
                        <circle cx="180" cy="180" r="140" />
                    </g>
                    <g class="hn-mark__links">
                        <path d="M180 180 L96 96" />
                        <path d="M180 180 L268 104" />
                        <path d="M180 180 L286 246" />
                        <path d="M180 180 L118 268" />
                        <path d="M180 180 L62 202" />
                    </g>
                    <g class="hn-mark__nodes">
                        <circle cx="96" cy="96" r="4" />
                        <circle cx="268" cy="104" r="4" />
                        <circle cx="286" cy="246" r="4" />
                        <circle cx="118" cy="268" r="4" />
                        <circle cx="62" cy="202" r="4" />
                    </g>
                    <circle cx="180" cy="180" r="9" class="hn-mark__core" />
                </svg>
            </div>
        </div>
    </section>

    <div class="hn-wrap hn-rows">
        <article class="hn-row">
            <div class="hn-row__text">
                <h2><?= htmlspecialchars($copy['s1_title']) ?></h2>
                <p><?= htmlspecialchars($copy['s1_body']) ?></p>
                <div class="hn-row__more">
                    <a href="<?= htmlspecialchars($docs . '/oauth') ?>"><?= htmlspecialchars($copy['s1_link']) ?></a>
                </div>
            </div>
            <div class="hn-art" aria-hidden="true">
                <svg viewBox="0 0 360 240" role="presentation" focusable="false">
                    <path class="hn-line" d="M180 120 H84" />
                    <path class="hn-line" d="M180 120 H276" />
                    <path class="hn-line" d="M180 120 V60" />
                    <path class="hn-line" d="M180 120 V180" />
                    <rect class="hn-panel" x="20" y="42" width="66" height="36" rx="6" />
                    <rect class="hn-bar" x="30" y="54" width="30" height="3" rx="1.5" />
                    <rect class="hn-bar" x="30" y="63" width="44" height="3" rx="1.5" />
                    <rect class="hn-panel" x="20" y="162" width="66" height="36" rx="6" />
                    <rect class="hn-bar" x="30" y="174" width="40" height="3" rx="1.5" />
                    <rect class="hn-bar" x="30" y="183" width="24" height="3" rx="1.5" />
                    <rect class="hn-panel" x="274" y="42" width="66" height="36" rx="6" />
                    <rect class="hn-bar" x="284" y="54" width="36" height="3" rx="1.5" />
                    <rect class="hn-bar" x="284" y="63" width="26" height="3" rx="1.5" />
                    <rect class="hn-panel" x="274" y="162" width="66" height="36" rx="6" />
                    <rect class="hn-bar" x="284" y="174" width="28" height="3" rx="1.5" />
                    <rect class="hn-bar" x="284" y="183" width="42" height="3" rx="1.5" />
                    <circle class="hn-accent" cx="180" cy="120" r="18" fill-opacity="0.12" />
                    <circle class="hn-accent" cx="180" cy="120" r="7" />
                </svg>
            </div>
        </article>

        <article class="hn-row hn-row--flip">
            <div class="hn-row__text">
                <h2><?= htmlspecialchars($copy['s2_title']) ?></h2>
                <p><?= htmlspecialchars($copy['s2_body']) ?></p>
            </div>
            <div class="hn-art" aria-hidden="true">
                <svg viewBox="0 0 360 240" role="presentation" focusable="false">
                    <rect class="hn-panel" x="28" y="30" width="104" height="40" rx="7" />
                    <rect class="hn-bar" x="46" y="45" width="10" height="10" rx="2" />
                    <rect class="hn-bar" x="64" y="48" width="46" height="4" rx="2" />
                    <rect class="hn-panel" x="28" y="100" width="104" height="40" rx="7" />
                    <rect class="hn-bar" x="46" y="115" width="10" height="10" rx="2" />
                    <rect class="hn-bar" x="64" y="118" width="34" height="4" rx="2" />
                    <rect class="hn-panel" x="28" y="170" width="104" height="40" rx="7" />
                    <rect class="hn-bar" x="46" y="185" width="10" height="10" rx="2" />
                    <rect class="hn-bar" x="64" y="188" width="40" height="4" rx="2" />
                    <path class="hn-line" d="M132 50 H196 Q212 50 212 70 V96" />
                    <path class="hn-line" d="M132 120 H212" />
                    <path class="hn-line" d="M132 190 H196 Q212 190 212 170 V144" />
                    <rect class="hn-panel" x="242" y="66" width="96" height="108" rx="10" />
                    <circle class="hn-line" cx="290" cy="100" r="15" />
                    <rect class="hn-bar" x="268" y="128" width="44" height="4" rx="2" />
                    <rect class="hn-bar" x="276" y="140" width="28" height="4" rx="2" />
                    <rect class="hn-accent" x="268" y="154" width="44" height="6" rx="3" fill-opacity="0.35" />
                </svg>
            </div>
        </article>

        <article class="hn-row">
            <div class="hn-row__text">
                <h2><?= htmlspecialchars($copy['s3_title']) ?></h2>
                <p><?= htmlspecialchars($copy['s3_body']) ?></p>
            </div>
            <div class="hn-art" aria-hidden="true">
                <svg viewBox="0 0 360 240" role="presentation" focusable="false">
                    <rect class="hn-panel" x="34" y="46" width="84" height="148" rx="12" />
                    <rect class="hn-bar" x="68" y="56" width="16" height="3" rx="1.5" />
                    <circle class="hn-accent" cx="62" cy="96" r="5" />
                    <circle class="hn-accent" cx="76" cy="96" r="5" fill-opacity="0.35" />
                    <circle class="hn-accent" cx="90" cy="96" r="5" fill-opacity="0.35" />
                    <circle class="hn-accent" cx="62" cy="112" r="5" fill-opacity="0.35" />
                    <circle class="hn-accent" cx="76" cy="112" r="5" fill-opacity="0.35" />
                    <circle class="hn-accent" cx="90" cy="112" r="5" />
                    <rect class="hn-bar" x="54" y="146" width="44" height="4" rx="2" />
                    <rect class="hn-bar" x="62" y="158" width="28" height="3" rx="1.5" />
                    <circle class="hn-line" cx="176" cy="112" r="20" />
                    <circle class="hn-accent" cx="176" cy="112" r="6" />
                    <path class="hn-line" d="M192 126 L216 150 V172" />
                    <path class="hn-line" d="M216 158 H204" />
                    <path class="hn-line" d="M216 170 H204" />
                    <rect class="hn-panel" x="250" y="76" width="84" height="100" rx="10" />
                    <rect class="hn-accent-stroke" x="264" y="94" width="56" height="26" rx="4" />
                    <path class="hn-line" d="M270 106 H292" stroke-dasharray="4 4" />
                    <rect class="hn-bar" x="264" y="136" width="34" height="4" rx="2" />
                    <rect class="hn-bar" x="264" y="148" width="50" height="4" rx="2" />
                    <rect class="hn-bar" x="264" y="160" width="22" height="4" rx="2" />
                </svg>
            </div>
        </article>

        <article class="hn-row hn-row--flip">
            <div class="hn-row__text">
                <h2><?= htmlspecialchars($copy['s4_title']) ?></h2>
                <p><?= htmlspecialchars($copy['s4_body']) ?></p>
                <div class="hn-row__more">
                    <a href="<?= htmlspecialchars($docs . '/skins') ?>"><?= t('Skins') ?></a>
                    <a href="<?= htmlspecialchars($docs . '/plugins') ?>"><?= t('Plugins') ?></a>
                </div>
            </div>
            <div class="hn-art" aria-hidden="true">
                <svg viewBox="0 0 360 240" role="presentation" focusable="false">
                    <rect class="hn-panel" x="26" y="84" width="150" height="104" rx="8" />
                    <path class="hn-line" d="M26 108 H176" />
                    <circle class="hn-bar" cx="40" cy="96" r="2.5" />
                    <circle class="hn-bar" cx="50" cy="96" r="2.5" />
                    <circle class="hn-bar" cx="60" cy="96" r="2.5" />
                    <rect class="hn-bar" x="42" y="126" width="40" height="4" rx="2" />
                    <rect class="hn-bar" x="42" y="140" width="76" height="4" rx="2" />
                    <rect class="hn-bar" x="42" y="154" width="58" height="4" rx="2" />
                    <rect class="hn-panel" x="106" y="52" width="150" height="104" rx="8" />
                    <path class="hn-line" d="M106 76 H256" />
                    <circle class="hn-accent" cx="120" cy="64" r="2.5" />
                    <circle class="hn-bar" cx="130" cy="64" r="2.5" />
                    <circle class="hn-bar" cx="140" cy="64" r="2.5" />
                    <rect class="hn-accent" x="122" y="94" width="40" height="4" rx="2" fill-opacity="0.6" />
                    <rect class="hn-bar" x="122" y="108" width="76" height="4" rx="2" />
                    <rect class="hn-bar" x="122" y="122" width="58" height="4" rx="2" />
                    <rect class="hn-panel" x="186" y="96" width="150" height="104" rx="8" stroke-dasharray="5 5" />
                    <path class="hn-line" d="M186 120 H336" stroke-dasharray="5 5" />
                    <rect class="hn-bar" x="202" y="138" width="40" height="4" rx="2" />
                    <rect class="hn-bar" x="202" y="152" width="76" height="4" rx="2" />
                    <rect class="hn-bar" x="202" y="166" width="58" height="4" rx="2" />
                </svg>
            </div>
        </article>
    </div>

    <div class="hn-wrap">
        <section class="hn-steps">
            <h2><?= htmlspecialchars($copy['install_title']) ?></h2>
            <p class="hn-steps__lead"><?= htmlspecialchars($copy['install_lead']) ?></p>
            <div class="hn-steps__grid">
                <div class="hn-step">
                    <div class="hn-step__num">01</div>
                    <h3><?= htmlspecialchars($copy['step1_title']) ?></h3>
                    <p><?= htmlspecialchars($copy['step1_body']) ?></p>
                </div>
                <div class="hn-step">
                    <div class="hn-step__num">02</div>
                    <h3><?= htmlspecialchars($copy['step2_title']) ?></h3>
                    <p><?= htmlspecialchars($copy['step2_body']) ?></p>
                </div>
                <div class="hn-step">
                    <div class="hn-step__num">03</div>
                    <h3><?= htmlspecialchars($copy['step3_title']) ?></h3>
                    <p><?= htmlspecialchars($copy['step3_body']) ?></p>
                </div>
            </div>
            <div class="hn-steps__foot">
                <span>PHP 8.1+</span>
                <span>MySQL 8 / MariaDB 10.6+ / SQLite 3.35+</span>
                <span>nginx / Apache</span>
                <a href="<?= htmlspecialchars($docs . '/install') ?>"><?= t('Install') ?></a>
            </div>
        </section>

        <div class="hn-foot">
            <span><?= $version !== '' ? htmlspecialchars($copy['foot_version']) . ' ' . htmlspecialchars($version) : htmlspecialchars($copy['foot_version']) ?></span>
            <nav>
                <a href="<?= htmlspecialchars($docs . '/changelog') ?>"><?= htmlspecialchars($copy['foot_changelog']) ?></a>
                <a href="<?= htmlspecialchars($docs . '/download') ?>"><?= htmlspecialchars($copy['foot_package']) ?></a>
                <a href="<?= htmlspecialchars($docs . '/source') ?>"><?= t('Source code') ?></a>
            </nav>
        </div>
    </div>
</div>
