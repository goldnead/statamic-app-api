<?php

return [
    'groups' => [
        'requests' => [
            'title' => 'Requests',
            'description' => 'How many requests a client may send, where the reset link points and which user fields the API shows. Prefix, middleware and areas are set in config/app-api.php.',
        ],
        'checkout' => [
            'title' => 'Checkout and portal',
            'description' => 'How long a started checkout is handed out again, and who gets a link into the customer portal.',
        ],
        'account' => [
            'title' => 'Export and tokens',
            'description' => 'How long an export can be downloaded and how long a new access token lasts.',
        ],
    ],
    'fields' => [
        'routes_rate_limit' => ['label' => 'Requests per minute', 'description' => 'Per user, or per address for guests. 0 switches the limit off.'],
        'auth_password_reset_url' => ['label' => 'Page for a new password', 'description' => 'A path of the app, e.g. /reset-password. The mail links there with ?token=. Empty: Statamic\'s page.'],
        'user_fields' => ['label' => 'Extra user fields', 'description' => 'Fields of the user blueprint the API sends along. Never a secret.'],
        'checkout_idempotency_seconds' => ['label' => 'Same checkout again (seconds)', 'description' => 'Within this time the same request answers with the first checkout instead of a second payment.'],
        'checkout_return_url' => ['label' => 'Return page after paying', 'description' => 'A path on this site. Empty: the page set in statamic-payments.'],
        'billing_return_url' => ['label' => 'Return page after changing the payment method', 'description' => 'A path on this site, such as the purchases tab of the app. Empty: the start page.'],
        'billing_cancellation_copy_to_team' => ['label' => 'Cancellation confirmation to the team as well', 'description' => 'When somebody cancels a team\'s contract, the confirmation also goes to the team\'s billing address, if it has one and it is a different one.'],
        'portal_require_verified_email' => ['label' => 'Portal only with confirmed address', 'description' => 'Recommended. Without it, whoever registers with a stranger\'s address sees that stranger\'s orders.'],
        'export_link_minutes' => ['label' => 'Export link valid (minutes)', 'description' => 'After that the file is deleted.'],
        'tokens_expires_after_days' => ['label' => 'Tokens valid (days)', 'description' => 'Empty: a token lasts until it is revoked.'],
    ],
];
