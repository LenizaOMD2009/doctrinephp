<?php

declare(strict_types=1);

// Configura e inicia a sessão antes de qualquer output ou lógica.
// Os parâmetros aqui sobrescrevem o php.ini e tornam o cookie de sessão seguro.
session_set_cookie_params([
    'lifetime' => 0,      // Cookie dura até fechar o browser
    'path'     => '/',
    'domain'   => '',     // Domínio exato, sem propagar subdomínios
    'secure'   => false,  // Mude para true em produção (HTTPS)
    'httponly' => true,   // Inacessível via JavaScript
    'samesite' => 'Lax',
]);
session_start();

$app = require __DIR__ . '/../app/bootstrap.php';

$app->run();