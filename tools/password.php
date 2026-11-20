<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$password = bin2hex(random_bytes(20));
echo 'Access password (save this in a password manager): ', $password, PHP_EOL;
echo 'Hash for config.local.php: ', password_hash($password, PASSWORD_DEFAULT), PHP_EOL;
