<?php

return [
    'publishable_key' => env('CLERK_PUBLISHABLE_KEY'),
    // .env files must keep PEM values on one line; support escaped newlines.
    'jwt_key' => str_replace(
        ['\\n', '\\r'],
        [PHP_EOL, ''],
        (string) env('CLERK_JWT_KEY', '')
    ),
    'issuer' => env('CLERK_ISSUER'),
    'authorized_parties' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CLERK_AUTHORIZED_PARTIES', ''))
    ))),
    'secret_key' => env('CLERK_SECRET_KEY'),
    'api_url' => env('CLERK_API_URL', 'https://api.clerk.com/v1'),
];
