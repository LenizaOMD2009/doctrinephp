<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('product create com dados validos retorna 201 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/product')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody([
            'nome' => 'Produto Teste',
            'descricao' => 'Descrição do produto teste',
            'preco' => 99.90,
            'estoque' => 10,
            'codigo' => 'PROD-001',
            'categoria' => 'Geral',
            'fornecedor_id' => 1
        ]);

    $response = (new ResponseFactory())->createResponse();

    $result = (new app\controller\Product())->create($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    expect($result->getStatusCode())->toBe(201);

    expect($json['status'])->toBeTrue();

    expect($json['msg'])->toContain('Produto criado com sucesso');
});