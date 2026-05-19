<?php

declare(strict_types=1);

use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

test('preRegister com dados válidos retorna 200 com status true', function () {

    $request = (new RequestFactory())
        ->createRequest('POST', '/authentication/preregister')
        ->withHeader("Content-Type", "application/x-www-form-urlencoded")
        ->withParsedBody([
            'nome' => 'Wilton',
            'sobrenome' => 'Will de Paulo',
            'cpf' => '11144477735',
            'rg' => '123456789',
            'senha' => '1234',
            'contacts' => '1234',
            'email' => 'wiltonwilldepaulo@gmail.com',
            'telefone' => '(69) 9 9906-0839'
        ]);

    $response = (new ResponseFactory() )->createResponse();

    $result = ( new app\controller\Login() )->preRegister($request, $response);

    $result->getBody()->rewind();

    $json = json_decode($result->getBody()->getContents(), true);

    expect($result->getStatusCode())->toBe(201);
    
    expect($json['msg'])->toContain('Usuário cadastrado com sucesso');
    
    expect($json['status'])->toBeTrue(); 
});
