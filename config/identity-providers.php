<?php
/*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 or any later
 * version, with additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomen.caellab.com/
 */

// Presets for third-party login. Admins can override every field in the panel.
// Field names map the provider's userinfo JSON to the local account.

return [
    'github' => [
        'logo'           => '/assets/providers/github.svg',
        'name'           => 'GitHub',
        'type'           => 'oauth2',
        'authorize_url'  => 'https://github.com/login/oauth/authorize',
        'token_url'      => 'https://github.com/login/oauth/access_token',
        'userinfo_url'   => 'https://api.github.com/user',
        'emails_url'     => 'https://api.github.com/user/emails',
        'scopes'         => 'read:user user:email',
        'subject_field'  => 'id',
        'email_field'    => 'email',
        'name_field'     => 'name',
        'avatar_field'   => 'avatar_url',
        'email_verified_field' => '',
        'token_auth'     => 'post',
    ],
    'google' => [
        'logo'           => '/assets/providers/google.svg',
        'name'           => 'Google',
        'type'           => 'oidc',
        'authorize_url'  => 'https://accounts.google.com/o/oauth2/v2/auth',
        'token_url'      => 'https://oauth2.googleapis.com/token',
        'userinfo_url'   => 'https://openidconnect.googleapis.com/v1/userinfo',
        'emails_url'     => '',
        'scopes'         => 'openid profile email',
        'subject_field'  => 'sub',
        'email_field'    => 'email',
        'name_field'     => 'name',
        'avatar_field'   => 'picture',
        'email_verified_field' => 'email_verified',
        'token_auth'     => 'post',
    ],
    'microsoft' => [
        'logo'           => '/assets/providers/microsoft.svg',
        'name'           => 'Microsoft',
        'type'           => 'oidc',
        'authorize_url'  => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
        'token_url'      => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
        'userinfo_url'   => 'https://graph.microsoft.com/oidc/userinfo',
        'emails_url'     => '',
        'scopes'         => 'openid profile email',
        'subject_field'  => 'sub',
        'email_field'    => 'email',
        'name_field'     => 'name',
        'avatar_field'   => 'picture',
        'email_verified_field' => '',
        'token_auth'     => 'post',
    ],
    'caellabid' => [
        'logo'           => '/assets/providers/caellabid.svg',
        'name'           => 'CaelLabID',
        'type'           => 'oidc',
        'authorize_url'  => 'https://id.caellab.com/oauth/authorize',
        'token_url'      => 'https://id.caellab.com/oauth/token',
        'userinfo_url'   => 'https://id.caellab.com/api/userinfo',
        'emails_url'     => '',
        'scopes'         => 'openid profile',
        'subject_field'  => 'sub',
        'email_field'    => 'email',
        'name_field'     => 'display_name',
        'avatar_field'   => 'avatar',
        'email_verified_field' => '',
        'token_auth'     => 'post',
    ],
];
