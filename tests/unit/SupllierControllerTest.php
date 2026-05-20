<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('supplier create com dados validos retorna 201 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/supplier')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody([
            'nome' => 'Fornecedor ABC LTDA',
            'cnpj' => '12.345.678/0001-99',
            'email' => 'contato@fornecedorabc.com',
            'telefone' => '68999999999',
            'responsavel' => 'Maria Oliveira',
            'endereco' => 'Rua Exemplo',
            'numero' => '100',
            'bairro' => 'Centro',
            'cidade' => 'Rio Branco',
            'estado' => 'AC',
            'cep' => '69900-000',
            'senhaCadastro' => '123456'
        ]);

    $response = (new ResponseFactory())->createResponse();

    $result = (new app\controller\Supplier())->create($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    expect($result->getStatusCode())->toBe(201);

    expect($json['status'])->toBeTrue();

    expect($json['msg'])->toContain('Fornecedor criado com sucesso');
});