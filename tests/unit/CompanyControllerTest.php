<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('company create com dados validos retorna 201 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/company')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody([
            'nome' => 'Empresa Teste LTDA',
            'cnpj' => '12.345.678/0001-99',
            'email' => 'contato@empresateste.com',
            'telefone' => '68999999999',
            'responsavel' => 'João Silva',
            'senhaCadastro' => '123456'
        ]);

    $response = (new ResponseFactory())->createResponse();

    $result = (new app\controller\Company())->create($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    expect($result->getStatusCode())->toBe(201);

    expect($json['status'])->toBeTrue();

    expect($json['msg'])->toContain('Empresa criada com sucesso');
});