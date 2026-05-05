<?php

declare(strict_types=1);

arch('todos os arquivos usam strict types')
    ->expect('App')
    ->toUseStrictTypes();

arch('sem debug no código de produção')
    ->expect('App')
    ->not->toUse(['var_dump', 'dd', 'dump', 'die']);

arch('controllers não acessam banco direto')
    ->expect('app\controller')
    ->not->toUse('PDO');

#Nenhuma classe deve usar funções perigosas
arch('sem funções perigosas no código')
    ->expect('App')
    ->not->toUse([
        'eval',
        'exec',
        'shell_exec',
        'system',
        'passthru',
        'proc_open',
    ]);

#Garantir que classes são finais ou abstratas
arch('controllers devem ser classes finais')
    ->expect('app\controller')
    ->toBeFinal()
    ->ignoring('app\controller\Base');
