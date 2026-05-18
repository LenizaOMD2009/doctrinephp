<?php

namespace app\controller;

final class Login extends Base
{
    // -------------------------------------------------------------------------
    // Renderiza a página de login
    // -------------------------------------------------------------------------
    public function login($request, $response)
    {
        try {
            $csrf = $_COOKIE['g_csrf_token'] ?? bin2hex(random_bytes(16));
            setcookie('g_csrf_token', $csrf, [
                'expires'  => time() + 3600,
                'path'     => '/',
                'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                'httponly' => false,
                'samesite' => 'Lax',
            ]);

            return $this->getTwig()
                ->render($response, $this->setView('login'), [
                    'titulo'           => 'Início',
                    'google_client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                ])
                ->withHeader('Content-Type', 'text/html')
                ->withStatus(200);
        } catch (\Exception $e) {
            error_log('[login][view] ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Autenticação via CPF / e-mail / celular / telefone + senha
    // -------------------------------------------------------------------------
    public function authenticate($request, $response)
    {
        $form  = $request->getParsedBody();
        $login = $form['login'] ?? null;
        $senha = $form['senha'] ?? null;

        if (is_null($login) || is_null($senha)) {
            return $this->json($response, [
                'status' => false,
                'msg'    => 'Por favor informe seu usuário e senha!',
                'id'     => 0,
            ]);
        }

        if (isset($_SESSION['login_locked_until']) && $_SESSION['login_locked_until'] > time()) {
            return $this->json($response, [
                'status' => false,
                'msg'    => 'Muitas tentativas. Tente novamente em alguns minutos.',
                'id'     => 0,
            ], 429);
        }

        try {
            $qb          = \app\database\DB::select('*')->from('vw_user');
            $placeholder = $qb->createNamedParameter($login);

            $qb->where('cpf = '         . $placeholder)
                ->orWhere('email = '    . $placeholder)
                ->orWhere('celular = '  . $placeholder)
                ->orWhere('telefone = ' . $placeholder)
                ->orWhere('whatsapp = ' . $placeholder);

            $user = $qb->fetchAssociative();

            # Hash inválido para proteção contra timing attack quando usuário não existe
            $dummyHash   = '$2y$10$CwTycUXWue0Thq9StjUM0uJ8.k3.kK1m3Sv7lJ1uG9N9Yvb.MqYsa';
            $senhaValida = password_verify($senha, $user['senha'] ?? $dummyHash);

            if (!$user || !$senhaValida) {
                $_SESSION['login_attempts'] = ($_SESSION['login_attempts'] ?? 0) + 1;
                if ($_SESSION['login_attempts'] >= 5) {
                    $_SESSION['login_locked_until'] = time() + 900;
                    $_SESSION['login_attempts']     = 0;
                }
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Verifique seu e-mail e senha e tente novamente!',
                    'id'     => 0,
                ], 403);
            }

            # Verificação robusta de ativo — PostgreSQL pode retornar 'f', '0' ou ''
            if (!self::_isAtivo($user['ativo'] ?? false)) {
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Sua conta ainda não foi ativada. Aguarde a aprovação de um administrador.',
                    'id'     => 0,
                ], 403);
            }

            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);

            if (password_needs_rehash($user['senha'], PASSWORD_DEFAULT)) {
                \app\database\DB::connection()->update(
                    'users',
                    ['senha' => password_hash($senha, PASSWORD_DEFAULT), 'atualizado_em' => date('Y-m-d H:i:s')],
                    ['id' => $user['id']],
                );
            }

            unset($user['senha']);

            return $this->_criarSessaoERetornar($response, $user);
        } catch (\PDOException $e) {
            error_log('[auth][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\UnexpectedValueException | \DomainException $e) {
            error_log('[auth][JWT] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\Throwable $e) {
            error_log('[auth][GERAL] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro inesperado. Tente novamente.', 'id' => 0], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Autenticação via Google One Tap / popup (valida id_token no Google)
    // -------------------------------------------------------------------------
    public function google($request, $response)
    {
        // 1. Lê o body — suporta JSON (fetch) e form (fallback)
        $body = $request->getParsedBody();
        if (empty($body)) {
            $raw  = (string) $request->getBody();
            $body = json_decode($raw, true) ?? [];
        }

        $credential          = $body['credential']   ?? null;
        $form_g_csrf_token   = $body['g_csrf_token'] ?? null;
        $cookie_g_csrf_token = $_COOKIE['g_csrf_token'] ?? null;
        $google_client_id    = $_ENV['GOOGLE_CLIENT_ID'] ?? null;

        // 2. Dados obrigatórios
        if (!$credential) {
            error_log('[google] credential ausente. body=' . json_encode($body));
            return $this->json($response, ['status' => false, 'msg' => 'Credenciais Google ausentes.', 'id' => 0], 400);
        }

        if (!$google_client_id) {
            error_log('[google] GOOGLE_CLIENT_ID não configurado no .env');
            return $this->json($response, ['status' => false, 'msg' => 'Configuração Google ausente no servidor.', 'id' => 0], 500);
        }

        // 3. Validação CSRF
        if ($form_g_csrf_token && $cookie_g_csrf_token && $form_g_csrf_token !== $cookie_g_csrf_token) {
            return $this->json($response, ['status' => false, 'msg' => 'Falha na verificação de segurança (CSRF).', 'id' => 0], 400);
        }

        try {
            // 4. Valida o id_token no endpoint oficial do Google
            $provider = new \League\OAuth2\Client\Provider\Google([
                'clientId'     => $google_client_id,
                'clientSecret' => '',
                'redirectUri'  => '',
            ]);

            $httpResponse = $provider->getHttpClient()->request(
                'GET',
                'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential),
                ['timeout' => 5, 'connect_timeout' => 3]
            );

            $statusCode = $httpResponse->getStatusCode();
            $rawBody    = (string) $httpResponse->getBody();

            if ($statusCode !== 200) {
                error_log('[google][tokeninfo] status=' . $statusCode . ' body=' . $rawBody);
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Token Google inválido ou expirado. Tente novamente.',
                    'id'     => 0,
                ], 401);
            }

            $claims = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);

            // 5. Verifica audience
            if (($claims['aud'] ?? '') !== $google_client_id) {
                error_log('[google][aud] esperado=' . $google_client_id . ' recebido=' . ($claims['aud'] ?? 'null'));
                return $this->json($response, ['status' => false, 'msg' => 'Token do Google inválido.', 'id' => 0], 401);
            }

            // 6. Verifica e-mail verificado
            if (!filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                return $this->json($response, ['status' => false, 'msg' => 'O e-mail Google não foi verificado.', 'id' => 0], 403);
            }

            // 7. Delega autenticação/cadastro ao helper
            return $this->_autenticarPorEmail($response, $claims, false);

        } catch (\JsonException $e) {
            error_log('[google][JSON] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Resposta inválida do Google. Tente novamente.', 'id' => 0], 502);
        } catch (\PDOException $e) {
            error_log('[google][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\Throwable $e) {
            error_log('[google][GERAL] ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
            return $this->json($response, ['status' => false, 'msg' => 'Falha na autenticação com Google. Tente novamente.', 'id' => 0], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Callback OAuth — Google redireciona aqui após o usuário escolher a conta
    // Fluxo: GET /auth/google/callback?code=xxx
    //
    // Para ativar, adicione em routes.php:
    //   $app->get('/auth/google/callback', app\controller\Login::class . ':googleCallback');
    // E no .env:
    //   GOOGLE_CLIENT_SECRET=seu_secret
    //   GOOGLE_REDIRECT_URI=https://seudominio.com/auth/google/callback
    // -------------------------------------------------------------------------
    public function googleCallback($request, $response)
    {
        $params       = $request->getQueryParams();
        $code         = $params['code']               ?? null;
        $clientId     = $_ENV['GOOGLE_CLIENT_ID']     ?? null;
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? null;
        $redirectUri  = $_ENV['GOOGLE_REDIRECT_URI']  ?? null;

        if (!$code) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        try {
            $provider = new \League\OAuth2\Client\Provider\Google([
                'clientId'     => $clientId,
                'clientSecret' => $clientSecret,
                'redirectUri'  => $redirectUri,
            ]);

            $token      = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $googleUser = $provider->getResourceOwner($token);
            $userArray  = $googleUser->toArray();

            # Monta claims no mesmo formato do id_token para reusar o helper
            $claims = [
                'email'          => $userArray['email']       ?? null,
                'given_name'     => $userArray['given_name']  ?? '',
                'family_name'    => $userArray['family_name'] ?? '',
                'sub'            => $userArray['sub']         ?? '',
                'email_verified' => true,
            ];

            return $this->_autenticarPorEmail($response, $claims, true);
        } catch (\Throwable $e) {
            error_log('[googleCallback] ' . $e->getMessage());
            return $response->withHeader('Location', '/login?google_error=1')->withStatus(302);
        }
    }

    // -------------------------------------------------------------------------
    // Pré-cadastro de usuário
    // -------------------------------------------------------------------------
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

        if ($email)    { $contacts[] = ['tipo' => 'EMAIL',    'contato' => $email]; }
        if ($telefone) { $contacts[] = ['tipo' => 'TELEFONE', 'contato' => preg_replace('/\D/', '', $telefone)]; }

        if (!$nome || !$sobrenome || !$cpf || !$senha) {
            return $this->json($response, ['status' => false, 'msg' => 'Nome, sobrenome, CPF e senha são obrigatórios.'], 400);
        }

        try {
            $qb = \app\database\DB::select('id')->from('users');
            $qb->where('cpf = ' . $qb->createNamedParameter($cpf));
            if ($qb->fetchAssociative()) {
                return $this->json($response, ['status' => false, 'msg' => 'CPF já cadastrado.'], 409);
            }

            $connection = \app\database\DB::connection();

            // Tipagem explícita garante boolean correto no PostgreSQL
            $connection->insert('users', [
                'nome'      => $nome,
                'sobrenome' => $sobrenome,
                'cpf'       => $cpf,
                'rg'        => $rg,
                'senha'     => password_hash($senha, PASSWORD_DEFAULT),
                'ativo'     => false,
            ], [
                'nome'      => \Doctrine\DBAL\Types\Types::STRING,
                'sobrenome' => \Doctrine\DBAL\Types\Types::STRING,
                'cpf'       => \Doctrine\DBAL\Types\Types::STRING,
                'rg'        => \Doctrine\DBAL\Types\Types::STRING,
                'senha'     => \Doctrine\DBAL\Types\Types::STRING,
                'ativo'     => \Doctrine\DBAL\Types\Types::BOOLEAN,
            ]);

            // PostgreSQL requer o nome da sequence
            $id_usuario = (int) $connection->lastInsertId('users_id_seq');
            if (!$id_usuario) {
                $id_usuario = (int) \app\database\DB::select('id')
                    ->from('users')
                    ->where('cpf = ' . \app\database\DB::select('id')->from('users')->createNamedParameter($cpf))
                    ->fetchOne();
            }
            if (!$id_usuario) {
                throw new \RuntimeException('Não foi possível obter ID do usuário');
            }

            foreach ($contacts as $contact) {
                $tipo    = strtoupper(trim($contact['tipo']    ?? ''));
                $contato = trim($contact['contato'] ?? '');
                if (!$tipo || !$contato) continue;
                if (!in_array($tipo, ['EMAIL', 'CELULAR', 'TELEFONE', 'WHATSAPP'], true)) continue;

                \app\database\DB::connection()->insert('contact', [
                    'id_usuario' => $id_usuario,
                    'tipo'       => $tipo,
                    'contato'    => $contato,
                ]);
            }

            return $this->json($response, ['status' => true, 'msg' => 'Usuário cadastrado com sucesso!'], 200);
        } catch (\PDOException $e) {
            error_log('[preRegister][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível realizar o cadastro. Tente novamente.'], 500);
        } catch (\Throwable $e) {
            error_log('[preRegister][GERAL] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro inesperado. Tente novamente.'], 500);
        }
    }

    // -------------------------------------------------------------------------
    // Logout
    // -------------------------------------------------------------------------
    public function logout($request, $response)
    {
        $userId = $_SESSION['user']['id'] ?? null;

        if ($userId) {
            try {
                \app\database\DB::connection()->update(
                    'users',
                    ['ativo' => false, 'atualizado_em' => date('Y-m-d H:i:s')],
                    ['id'    => (int) $userId],
                    ['ativo' => \Doctrine\DBAL\Types\Types::BOOLEAN]
                );
            } catch (\Throwable $e) {
                error_log('[logout][DB] ' . $e->getMessage());
            }
        }

        $_SESSION = [];

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

        $cookieDomain = parse_url(HOST, PHP_URL_HOST) ?: '';

        session_destroy();

        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443;

        setcookie('auth_token', '', [
            'expires'  => time() - 42000,
            'path'     => '/',
            'domain'   => $cookieDomain,
            'secure'   => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return (new \Slim\Psr7\Response())
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    // =========================================================================
    // HELPERS PRIVADOS
    // =========================================================================

    /**
     * Verifica se o campo "ativo" é verdadeiro de forma robusta.
     * O PostgreSQL pode retornar bool, 't', '1' ou 'true' para verdadeiro
     * e false, 'f', '0', '' para falso — dependendo do driver/versão.
     */
    private static function _isAtivo(mixed $value): bool
    {
        if (is_bool($value)) return $value;
        return in_array((string) $value, ['1', 't', 'true'], true);
    }

    /**
     * Busca ou cadastra automaticamente o usuário pelo e-mail Google,
     * depois inicia a sessão.
     *
     * Reutilizado por google() (One Tap/popup → resposta JSON)
     * e por googleCallback() (OAuth redirect → redirect HTTP).
     *
     * @param array $claims   Claims vindos do id_token ou array equivalente do OAuth callback
     * @param bool  $redirect true = redireciona HTTP | false = resposta JSON (AJAX)
     */
    private function _autenticarPorEmail($response, array $claims, bool $redirect = false)
    {
        $email = trim($claims['email'] ?? '');

        if (!$email) {
            if ($redirect) return $response->withHeader('Location', '/login?google_error=1')->withStatus(302);
            return $this->json($response, ['status' => false, 'msg' => 'E-mail não encontrado no token Google.', 'id' => 0], 400);
        }

        // Busca usuário existente
        $qb = \app\database\DB::select('*')->from('vw_user');
        $qb->where('email = ' . $qb->createNamedParameter($email));
        $user = $qb->fetchAssociative();

        // Usuário não existe → auto-cadastra
        if (!$user) {
            $givenName  = trim((string) ($claims['given_name']  ?? ''));
            $familyName = trim((string) ($claims['family_name'] ?? ''));
            $googleSub  = trim((string) ($claims['sub']         ?? ''));

            $nome      = $givenName  ?: explode('@', $email)[0];
            $sobrenome = $familyName ?: 'Google';
            $cpf       = 'google:' . ($googleSub ?: md5($email)); // CPF sintético, nunca conflita com CPF real
            $senhaHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

            $connection = \app\database\DB::connection();
            $connection->beginTransaction();

            try {
                // Evita duplicata em race condition (dois cliques simultâneos, etc.)
                $cpfExiste = \app\database\DB::select('id')
                    ->from('users')
                    ->where('cpf = ' . \app\database\DB::select('id')->from('users')->createNamedParameter($cpf))
                    ->fetchOne();

                if ($cpfExiste) {
                    $user = \app\database\DB::select('*')
                        ->from('vw_user')
                        ->where('cpf = ' . \app\database\DB::select('*')->from('vw_user')->createNamedParameter($cpf))
                        ->fetchAssociative();
                } else {
                    // Tipagem explícita garante boolean correto no PostgreSQL
                    $connection->insert('users', [
                        'nome'          => $nome,
                        'sobrenome'     => $sobrenome,
                        'cpf'           => $cpf,
                        'rg'            => '',
                        'senha'         => $senhaHash,
                        'ativo'         => true,
                        'administrador' => false,
                    ], [
                        'nome'          => \Doctrine\DBAL\Types\Types::STRING,
                        'sobrenome'     => \Doctrine\DBAL\Types\Types::STRING,
                        'cpf'           => \Doctrine\DBAL\Types\Types::STRING,
                        'rg'            => \Doctrine\DBAL\Types\Types::STRING,
                        'senha'         => \Doctrine\DBAL\Types\Types::STRING,
                        'ativo'         => \Doctrine\DBAL\Types\Types::BOOLEAN,
                        'administrador' => \Doctrine\DBAL\Types\Types::BOOLEAN,
                    ]);

                    // PostgreSQL requer o nome da sequence em lastInsertId()
                    $id_usuario = (int) $connection->lastInsertId('users_id_seq');

                    if (!$id_usuario) {
                        $id_usuario = (int) \app\database\DB::select('id')
                            ->from('users')
                            ->where('cpf = ' . \app\database\DB::select('id')->from('users')->createNamedParameter($cpf))
                            ->fetchOne();
                    }

                    if (!$id_usuario) {
                        throw new \RuntimeException('Não foi possível obter o ID do usuário inserido.');
                    }

                    // Verifica UNIQUE antes de inserir o e-mail na tabela contact
                    $emailExiste = \app\database\DB::select('id')
                        ->from('contact')
                        ->where('contato = ' . \app\database\DB::select('id')->from('contact')->createNamedParameter($email))
                        ->fetchOne();

                    if (!$emailExiste) {
                        $connection->insert('contact', [
                            'id_usuario' => $id_usuario,
                            'tipo'       => 'EMAIL',
                            'contato'    => $email,
                        ]);
                    }

                    $user = [
                        'id'            => $id_usuario,
                        'nome'          => $nome,
                        'sobrenome'     => $sobrenome,
                        'cpf'           => $cpf,
                        'rg'            => '',
                        'ativo'         => true,
                        'administrador' => false,
                        'email'         => $email,
                        'celular'       => null,
                        'telefone'      => null,
                        'whatsapp'      => null,
                    ];
                }

                $connection->commit();
            } catch (\Throwable $e) {
                $connection->rollBack();
                error_log('[google][auto-cadastro] ' . $e->getMessage());
                if ($redirect) return $response->withHeader('Location', '/login?google_error=1')->withStatus(302);
                return $this->json($response, ['status' => false, 'msg' => 'Erro ao criar conta. Tente novamente.', 'id' => 0], 500);
            }
        }

        // Conta inativa
        if (!self::_isAtivo($user['ativo'] ?? false)) {
            if ($redirect) return $response->withHeader('Location', '/login?google_error=inativo')->withStatus(302);
            return $this->json($response, [
                'status' => false,
                'msg'    => 'Sua conta não está ativa. Entre em contato com o administrador.',
                'id'     => 0,
            ], 403);
        }

        unset($user['senha']);

        if ($redirect) {
            $this->_criarSessaoERetornar($response, $user);
            return $response->withHeader('Location', '/home')->withStatus(302);
        }

        return $this->_criarSessaoERetornar($response, $user);
    }

    /**
     * Cria sessão + JWT + cookie e retorna JSON de sucesso.
     */
    private function _criarSessaoERetornar($response, array $user)
    {
        session_regenerate_id(true);

        $_SESSION['user']           = $user;
        $_SESSION['user']['logado'] = true;

        $lifetime = (int) (ini_get('session.gc_maxlifetime') ?: 3600);
        $now      = time();
        $jti      = bin2hex(random_bytes(16));

        $payload = [
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $lifetime,
            'sub' => (string) $user['id'],
            'iss' => HOST,
            'aud' => HOST,
            'jti' => $jti,
        ];

        $jwt      = \Firebase\JWT\JWT::encode($payload, SECRET_KEY, 'HS256');
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) == 443;

        setcookie('auth_token', $jwt, [
            'expires'  => $now + $lifetime,
            'path'     => '/',
            'domain'   => parse_url(HOST, PHP_URL_HOST),
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
    }
}