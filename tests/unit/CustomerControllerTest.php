<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('customer criado com dados válidos retorna 201 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/customer')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withParsedBody([
            'nome' => 'Felipe',
            'sobrenome' => 'Gabriel Santos De Jesus',
            'cpf' => '999.999.999-99',
            'rg' => '98765',
            'email' => 'felipegabrielsantosro@gmail.com',
            'telefone' => '6999999988',
            'endereco' => 'Rua Exemplo',
            'numero' => '123',
            'bairro' => 'Centro',
            'cidade' => 'Rio Branco',
            'estado' => 'AC',
            'cep' => '69900-000'
        ]);

    $response = (new ResponseFactory())->createResponse();

    $result = (new app\controller\Customer())->create($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    // Verifica status HTTP
    expect($result->getStatusCode())->toBe(201);

    // Verifica retorno JSON
    expect($json['status'])->toBeTrue();

    expect($json['msg'])->toContain('Customer criado com sucesso');
});