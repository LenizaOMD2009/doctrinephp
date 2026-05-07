<?php

declare(strict_types=1);

$app->get('/', app\controller\Home::class . ':home');
$app->get('/home', app\controller\Home::class . ':home'); #->add(app\middleware\Middleware::web());
$app->get('/login', app\controller\Login::class . ':login'); #->add(app\middleware\Middleware::web());


$app->group('/cliente', function (Slim\Routing\RouteCollectorProxy $group) {
    $group->get('/lista', app\controller\Customer::class . ':list');
    $group->get('/detalhes/{id}', app\controller\Customer::class . ':details');
    $group->get('/detalhes', app\controller\Customer::class . ':details');
    $group->post('/insert', app\controller\Customer::class . ':insert');
    $group->post('/update', app\controller\Customer::class . ':update');
    $group->post('/delete', app\controller\Customer::class . ':delete');
    $group->post('/listingdata', app\controller\Customer::class . ':listingdata');
});
