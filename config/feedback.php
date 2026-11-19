<?php

return [
    'timezone' => 'Asia/Vientiane',
    'staff_auth' => env('FEEDBACK_STAFF_AUTH', 'disabled'),
    'customer_jwt_secret' => env('FEEDBACK_CUSTOMER_JWT_SECRET'),
    'local_admin' => [
        'email' => env('FEEDBACK_LOCAL_ADMIN_EMAIL'),
        'password' => env('FEEDBACK_LOCAL_ADMIN_PASSWORD'),
    ],
    'locale' => env('FEEDBACK_UI_LOCALE', 'en'), // Local development choice; business default is unconfirmed.
    'services' => [
        'app-backend' => ['token_hash' => env('FEEDBACK_APP_TOKEN_SHA256'), 'scopes' => ['config.read', 'response.write', 'response.read', 'invitation.write']],
        'user-adapter' => ['token_hash' => env('FEEDBACK_COHORT_TOKEN_SHA256'), 'scopes' => ['cohort.write']],
    ],
    'permissions' => ['analytics.read', 'feedback.read', 'customers.read', 'customer_identity.read', 'report.export', 'survey.read', 'survey.edit', 'rules.read', 'rules.edit', 'changes.read', 'changes.approve', 'changes.publish', 'audit.read'],
];
