<?php

declare(strict_types=1);

session_start();

# Domínio atual da requisição — usado no payload JWT (iss/aud) e no cookie auth_token
# Definido aqui centralmente para evitar repetição e proteger contra Host Header Injection
define('HOST', $_SERVER['HTTP_HOST']);

define('ROOT', dirname(__FILE__, 3));
#DIRETÓRIO DAS VIEWS
define('DIR_VIEWS', ROOT . '/app/view');
#EXTENSÃO PADRÃO DAS VIEWS
define('EXT_VIEWS', '.html');
#Chave secreta para geração de tokens JWT — nunca exponha em repositórios públicos
define('SECRET_KEY', '58ae142d-afae-4443-994a-43f2bef0e366');