<?php

declare(strict_types=1);

namespace app\middleware;

class Middleware
{
    #Metodo de autenticação via token de rota POST.
    public static function api()
    {
        $middleware = function ($request, $handler) {};
        return $middleware;
    }
    #Metodo de autenticação das rotas GET
    public static function web()
    {
        #Verifica se o usuário está autenticado.
        if (true) {
            #redireciona para a página de login.
        }
        $middleware = function ($request, $handler) {};
        return $middleware;
    }
}
