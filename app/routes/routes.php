<?php

declare(strict_types=1);

use app\middleware\Middleware;

$app->get('/login', app\controller\Login::class . ':login')->add(Middleware::web());
$app->post('/login', app\controller\Login::class . ':authenticate');
$app->get('/logout', app\controller\Login::class . ':logout');

$app->post('/cadastro', app\controller\Login::class . ':preRegister');

$app->group('/authentication', function (\Slim\Routing\RouteCollectorProxy $group) {
    $group->post('/google',      app\controller\Login::class . ':google');
    $group->post('/auth',        app\controller\Login::class . ':authenticate');
    $group->post('/preregister', app\controller\Login::class . ':preRegister');
});

$app->get('/',     app\controller\Home::class . ':home')->add(Middleware::web());
$app->get('/home', app\controller\Home::class . ':home')->add(Middleware::web());

$app->group('/cliente', function (\Slim\Routing\RouteCollectorProxy $group) {
    # Páginas HTML protegidas
    $group->get('/lista',         app\controller\Customer::class . ':list')->add(Middleware::web());
    $group->get('/detalhes/{id}', app\controller\Customer::class . ':details')->add(Middleware::web());
    $group->get('/detalhes',      app\controller\Customer::class . ':details')->add(Middleware::web());
    # Endpoints JSON protegidos
    $group->post('/insert',      app\controller\Customer::class . ':insert')->add(Middleware::api());
    $group->post('/update',      app\controller\Customer::class . ':update')->add(Middleware::api());
    $group->post('/delete',      app\controller\Customer::class . ':delete')->add(Middleware::api());
    $group->post('/listingdata', app\controller\Customer::class . ':listingdata')->add(Middleware::api());
});