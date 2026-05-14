<?php

namespace app\controller;

final class Login extends Base
{
    public function login($request, $response)
    {
        try {
            return $this->getTwig()
                ->render($response, $this->setView('login'), [
                    'titulo' => 'Início',
                ])
                ->withHeader('Content-Type', 'text/html')
                ->withStatus(200);
        } catch (\Exception $e) {
            var_dump($e->getMessage());
        }
    }
    public function authenticate($request, $response)
    {
        # Recupera as credenciais enviadas no corpo da requisição
        $form  = $request->getParsedBody();
        $login = $form['login'] ?? null;
        $senha = $form['senha'] ?? null;

        # Bloqueia se algum campo veio vazio
        if (is_null($login) || is_null($senha)) {
            return $this->json($response, ['status' => false, 'msg' => 'Por favor informe seu usuário e senha!', 'id' => 0]);
        }

        # Verifica se a sessão está em "lockout" por excesso de tentativas falhas
        if (isset($_SESSION['login_locked_until']) && $_SESSION['login_locked_until'] > time()) {
            return $this->json($response, ['status' => false, 'msg' => 'Muitas tentativas. Tente novamente em alguns minutos.', 'id' => 0], 429);
        }

        try {
            # Começa a montar a query: SELECT * FROM vw_user
            $qb = \app\database\DB::select('*')
                ->from('vw_user');

            # Define o valor que será procurado nos campos.
            # O Doctrine cria um "placeholder seguro" no lugar do valor real,
            # protegendo a aplicação contra SQL injection.
            $placeholder = $qb->createNamedParameter($login);

            # Monta a cláusula WHERE com três condições ligadas por OR:
            # WHERE cpf = :login OR email = :login OR whatsapp = :login
            $qb->where('cpf = '      . $placeholder)
               ->orWhere('email = '  . $placeholder)
               ->orWhere('whatsapp = '. $placeholder);

            # Executa a query e busca um único registro (a primeira linha encontrada)
            $user = $qb->fetchAssociative();

            # Bloqueia login de usuários inativos antes de verificar a senha.
            # Evita que contas desativadas pelo admin consigam acessar o sistema.
            if ($user && !$user['ativo']) {
                return $this->json($response, ['status' => false, 'msg' => 'Usuário inativo. Entre em contato com o administrador.', 'id' => 0], 403);
            }

            # Hash bcrypt pré-computado e inválido, usado quando o usuário não existe (proteção contra timing attack)
            $dummyHash = '$2y$10$CwTycUXWue0Thq9StjUM0uJ8.k3.kK1m3Sv7lJ1uG9N9Yvb.MqYsa';

            # Sempre executa password_verify, mesmo sem usuário, para manter tempo de resposta constante
            $senhaValida = password_verify($senha, $user['senha'] ?? $dummyHash);

            # Falha de autenticação: mensagem genérica + contador de tentativas
            if (!$user || !$senhaValida) {
                # Incrementa o contador de tentativas falhas da sessão atual
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                # Após 5 falhas, bloqueia novas tentativas por 15 minutos (rate limiting básico)
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + 900;
                    $_SESSION['login_attempts'] = 0;
                }
                return $this->json($response, ['status' => false, 'msg' => 'Verifique seu e-mail e senha e tente novamente!', 'id' => 0], 403);
            }

            # Login válido: zera contadores de tentativa e lockout
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

            # Regenera o ID da sessão para mitigar session fixation
            session_regenerate_id(true);

            # Renova o hash da senha se o algoritmo/custo padrão tiver mudado
            if (password_needs_rehash($user['senha'], PASSWORD_DEFAULT)) {
                \app\database\DB::connection()->update(
                    'users',
                    [
                        'senha'         => password_hash($senha, PASSWORD_DEFAULT),
                        'atualizado_em' => date('Y-m-d H:i:s'),
                    ],
                    ['id' => $user['id']],
                );
            }

            # Marca o usuário como ativo no banco ao realizar login
            \app\database\DB::connection()->update(
                'users',
                [
                    'ativo'         => true,
                    'atualizado_em' => date('Y-m-d H:i:s'),
                ],
                ['id' => $user['id']],
                ['ativo' => \Doctrine\DBAL\ParameterType::BOOLEAN]
            );

            # Remove o hash da senha antes de gravar o usuário na sessão (evita expor credencial)
            unset($user['senha']);

            # Persiste o usuário autenticado na sessão (fonte de verdade do estado)
            $_SESSION['user'] = $user;
            $_SESSION['user']['logado'] = true;

            # Calcula o tempo de vida da sessão a partir do php.ini, com fallback de 3600s
            $lifetime = (int) (ini_get('session.gc_maxlifetime') ?: 3600);

            # Cacheia o timestamp atual para manter coerência entre iat, nbf e exp
            $now = time();

            # Identificador único deste token, em hex de 32 caracteres (16 bytes random_bytes)
            # Permite revogar tokens individualmente via denylist no Redis
            $jti = bin2hex(random_bytes(16));

            # Monta o payload do JWT seguindo a RFC 7519 (Registered Claim Names)
            $payload = [
                'iat' => $now,                  # Issued At: momento de emissão
                'nbf' => $now,                  # Not Before: token só é válido a partir daqui
                'exp' => $now + $lifetime,      # Expiration: expiração alinhada à sessão PHP
                'sub' => (string) $user['id'],  # Subject: ID do usuário autenticado
                'iss' => HOST,                  # Issuer: domínio emissor (mesma constante do cookie)
                'aud' => HOST,                  # Audience: aplicação que vai consumir o token
                'jti' => $jti,                  # JWT ID: identificador único para revogação
            ];

            # Assina o token JWT com a chave secreta da aplicação
            $jwt = \Firebase\JWT\JWT::encode($payload, SECRET_KEY, 'HS256');

            # Determina se a conexão está em HTTPS (define o atributo Secure do cookie)
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;

            # Define o cookie auth_token usando domain (constante de configuração, imune a Host Header Injection)
            setcookie('auth_token', $jwt, [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'domain'   => HOST,
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            # Cria um único DateTimeImmutable aproveitando $now já cacheado (coerência com iat/exp do JWT)
            $agora = (new \DateTimeImmutable())->setTimestamp($now);
            # Registra na sessão o horário de criação e o horário previsto de expiração (formato H:i:s correto)
            $_SESSION['user']['sessao_criada_em'] = $agora->format('Y-m-d H:i:s');
            $_SESSION['user']['sessao_expira_em'] = $agora->modify("+{$lifetime} seconds")->format('Y-m-d H:i:s');

            # Retorna a resposta de sucesso ao cliente
            return $this->json($response, [
                'status'           => true,
                'msg'              => 'Seja bem vindo de volta!',
                'id'               => $user['id'],
                'sessao_expira_em' => $_SESSION['user']['sessao_expira_em']
            ], 200);

        } catch (\PDOException $e) {
            error_log('[auth][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\UnexpectedValueException | \DomainException $e) {
            error_log('[auth][JWT] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\Throwable $e) {
            error_log('[auth][GERAL] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro inesperado. Tente novamente ', 'id' => 0], 500);
        }
    }
    # Destrói a sessão e apaga o cookie JWT, encerrando a autenticação
    public function logout($request, $response)
    {
        # Marca o usuário como inativo (ativo = false) no banco antes de encerrar a sessão
        $userId = $_SESSION['user']['id'] ?? null;
        if ($userId) {
            try {
                \app\database\DB::connection()->update(
                    'users',
                    [
                        'ativo'         => false,
                        'atualizado_em' => date('Y-m-d H:i:s'),
                    ],
                    ['id' => $userId],
                    ['ativo' => \Doctrine\DBAL\ParameterType::BOOLEAN]
                );
            } catch (\Throwable $e) {
                error_log('[logout][DB] ' . $e->getMessage());
            }
        }

        # Limpa todos os dados da sessão
        $_SESSION = [];

        # Apaga o cookie de sessão do PHP, se configurado para usar cookies
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Lax',
            ]);
        }

        # Destrói a sessão no servidor
        session_destroy();

        # Remove o cookie JWT sobrescrevendo com expiração no passado
        setcookie('auth_token', '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => HOST,
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        # Redireciona para a tela de login
        return (new \Slim\Psr7\Response())
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }
    public function preRegister($request, $response)
    {
        $form      = $request->getParsedBody();
        $nome      = trim($form['nome']      ?? '');
        $sobrenome = trim($form['sobrenome'] ?? '');
        $cpf       = trim($form['cpf']       ?? '');
        $rg        = trim($form['rg']        ?? '');
        $senha     = $form['senha']          ?? '';
        $contacts  = $form['contacts']       ?? [];
        $email     = trim($form['email']     ?? '');
        $telefone  = trim($form['telefone']  ?? '');

        if ($email) {
            $contacts[] = ['tipo' => 'EMAIL', 'contato' => $email];
        }
        if ($telefone) {
            $contacts[] = ['tipo' => 'TELEFONE', 'contato' => $telefone];
        }

        if (!$nome || !$sobrenome || !$cpf || !$senha) {
            return $this->json($response, [
                'status' => false,
                'msg'    => 'Nome, sobrenome, CPF e senha são obrigatórios.',
            ], 400);
        }

        $DataUser = [
            'nome'      => $nome,
            'sobrenome' => $sobrenome,
            'cpf'       => $cpf,
            'rg'        => $rg,
            'senha'     => password_hash($senha, PASSWORD_DEFAULT),
        ];

        $id_usuario = \app\database\DB::connection()->insert('users', $DataUser);

        if (is_array($contacts)) {
            foreach ($contacts as $contact) {
                $tipo    = strtoupper(trim($contact['tipo'] ?? ''));
                $contato = trim($contact['contato'] ?? '');
                if (!$tipo || !$contato) {
                    continue;
                }
                if (!in_array($tipo, ['EMAIL', 'CELULAR', 'TELEFONE', 'WHATSAPP'], true)) {
                    continue;
                }

                \app\database\DB::connection()->insert('contact', [
                    'id_usuario' => $id_usuario,
                    'tipo'       => $tipo,
                    'contato'    => $contato,
                ]);
            }
        }

        return $this->json($response, [
            'status' => true,
            'msg'    => 'Usuário cadastrado com sucesso!',
        ], 200);
    }
    public function google($request, $response)
    {
        $form              = $request->getParsedBody();
        $credential        = $form['credential']   ?? null;
        $form_g_csrf_token = $form['g_csrf_token'] ?? null;
        $cookie_g_csrf_token = $_COOKIE['g_csrf_token'] ?? null;
        $google_client_id  = $_ENV['GOOGLE_CLIENT_ID'] ?? null;

        if (is_null($credential) || is_null($form_g_csrf_token) || is_null($cookie_g_csrf_token)) {
            return $this->json($response, ['status' => false, 'msg' => 'Credenciais Google ausentes.', 'id' => 0], 400);
        }

        $client = new \Google\Client(['client_id' => $google_client_id]);

        try {
            $payload = $client->verifyIdToken($credential);

            if (!$payload) {
                return $this->json($response, ['status' => false, 'msg' => 'Token do Google inválido.', 'id' => 0], 401);
            }

            # Dados do usuário extraídos do payload validado
            $email       = $payload['email']       ?? null;
            $given_name  = $payload['given_name']  ?? '';
            $family_name = $payload['family_name'] ?? '';

            if (!$email) {
                return $this->json($response, ['status' => false, 'msg' => 'E-mail não encontrado no token Google.', 'id' => 0], 400);
            }

            # Busca o usuário pelo e-mail na view vw_user
            $qb = \app\database\DB::select('*')->from('vw_user');
            $qb->where('email = ' . $qb->createNamedParameter($email));
            $user = $qb->fetchAssociative();

            # Usuário não encontrado no sistema
            if (!$user) {
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Nenhuma conta vinculada a este e-mail. Por favor, cadastre-se.',
                    'id'     => 0,
                ], 404);
            }

            # Conta inativa: aguarda aprovação do administrador
            if (!$user['ativo']) {
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Por enquanto você ainda não está autorizado, por favor aguarde...',
                    'id'     => 0,
                ], 403);
            }

            # Conta ativa: cria sessão igual ao fluxo normal de login
            unset($user['senha']);

            $_SESSION['user']          = $user;
            $_SESSION['user']['logado'] = true;

            $lifetime = (int) (ini_get('session.gc_maxlifetime') ?: 3600);
            $now      = time();
            $jti      = bin2hex(random_bytes(16));

            $payload_jwt = [
                'iat' => $now,
                'nbf' => $now,
                'exp' => $now + $lifetime,
                'sub' => (string) $user['id'],
                'iss' => HOST,
                'aud' => HOST,
                'jti' => $jti,
            ];

            $jwt = \Firebase\JWT\JWT::encode($payload_jwt, SECRET_KEY, 'HS256');

            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443;

            setcookie('auth_token', $jwt, [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'domain'   => HOST,
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            $agora = (new \DateTimeImmutable())->setTimestamp($now);
            $_SESSION['user']['sessao_criada_em'] = $agora->format('Y-m-d H:i:s');
            $_SESSION['user']['sessao_expira_em'] = $agora->modify("+{$lifetime} seconds")->format('Y-m-d H:i:s');

            return $this->json($response, [
                'status'           => true,
                'msg'              => 'Seja bem vindo de volta!',
                'id'               => $user['id'],
                'sessao_expira_em' => $_SESSION['user']['sessao_expira_em'],
            ], 200);

        } catch (\Throwable $e) {
            error_log('[google][auth] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Falha na autenticação com Google. Tente novamente.', 'id' => 0], 500);
        }
    }
}