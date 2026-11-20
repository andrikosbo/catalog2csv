<?php

declare(strict_types=1);

return [
    'database' => [
        'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=catalog2csv;charset=utf8mb4',
        'user' => 'catalog2csv',
        'password' => '',
    ],
    'access' => [
        'username' => 'operator',
        'password_hash' => '', // Generate a password and hash with php tools/password.php.
    ],
];
