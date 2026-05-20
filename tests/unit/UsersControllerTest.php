<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('users create com dados validos retorna 201 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/users')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody([
            'nome' => 'Felipe',
            'sobrenome' => 'Santos',
            'cpf' => '999.999.999-99',
            'email' => 'felipe@gmail.com',
            'telefone' => '6999999988',
            'senha' => '123456'
        ]);

    $response = (new ResponseFactory())->createResponse();

    $result = (new app\controller\Users())->create($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    expect($result->getStatusCode())->toBe(201);

    expect($json['status'])->toBeTrue();

    expect($json['msg'])->toContain('Usuário criado com sucesso');
});