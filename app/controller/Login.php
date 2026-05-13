<?php

namespace app\controller;

use Doctrine\DBAL\ParameterType;

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

            # Monta a cláusula WHERE aceitando CPF, e-mail, whatsapp, celular ou telefone como login
            $qb->where('cpf = '       . $placeholder)
               ->orWhere('email = '   . $placeholder)
               ->orWhere('whatsapp = '. $placeholder)
               ->orWhere('celular = ' . $placeholder)
               ->orWhere('telefone = '. $placeholder);

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

            # Remove o hash da senha antes de gravar o usuário na sessão (evita expor credencial)
            unset($user['senha']);

            # Persiste o usuário autenticado na sessão (fonte de verdade do estado)
            $_SESSION['user'] = $user;
            $_SESSION['user']['logado'] = true;

            # Calcula o tempo de vida da sessão a partir do php.ini, com fallback de 3600s
            $lifetime = (int) (ini_get('session.gc_maxlifetime') ?: 3600);

            # Cacheia o timestamp atual para manter coerência entre iat, nbf e exp
            $now = time();

            # Identificador único deste token em hex de 32 caracteres (16 bytes random_bytes).
            # Permite revogar tokens individualmente via denylist no Redis (futuro).
            $jti = bin2hex(random_bytes(16));

            # Monta o payload do JWT seguindo a RFC 7519 (Registered Claim Names)
            $payload = [
                'iat' => $now,                  # Issued At: momento de emissão
                'nbf' => $now,                  # Not Before: token só é válido a partir daqui
                'exp' => $now + $lifetime,      # Expiration: expiração alinhada à sessão PHP
                'sub' => (string) $user['id'],  # Subject: ID do usuário autenticado
                'iss' => \HOST,                 # Issuer: domínio emissor — \HOST força namespace global
                'aud' => \HOST,                 # Audience: aplicação que vai consumir o token
                'jti' => $jti,                  # JWT ID: identificador único para revogação futura
            ];

            # Assina o token JWT com a chave secreta da aplicação
            $jwt = \Firebase\JWT\JWT::encode($payload, \SECRET_KEY, 'HS256');

            # Determina se a conexão está em HTTPS (define o atributo Secure do cookie)
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                     || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

            # Grava o JWT em cookie httponly — domain='' restringe ao domínio exato sem propagar subdomínios
            setcookie('auth_token', $jwt, [
                'expires'  => time() + $lifetime,
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            # Cria um único DateTimeImmutable aproveitando $now já cacheado (coerência com iat/exp do JWT)
            $agora = (new \DateTimeImmutable())->setTimestamp($now);
            # Registra na sessão o horário de criação e o horário previsto de expiração
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
            # Erro de banco: loga internamente e responde de forma genérica
            error_log('[auth][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\UnexpectedValueException | \DomainException $e) {
            # Erro específico do Firebase JWT (chave inválida, payload malformado, etc.)
            error_log('[auth][JWT] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Não foi possível concluir o login. Tente novamente.', 'id' => 0], 500);
        } catch (\Throwable $e) {
            # Qualquer outra falha inesperada: loga e responde de forma genérica
            error_log('[auth][GERAL] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro inesperado. Tente novamente.', 'id' => 0], 500);
        }
    }

    # Destrói a sessão e apaga o cookie JWT, encerrando a autenticação
    public function logout($request, $response)
    {
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
            'domain'   => '',
            'secure'   => false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        # Redireciona para a tela de login
        return (new \Slim\Psr7\Response())
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    # Cadastro de novo usuário — lê JSON, valida, insere users + contacts
    public function preRegister($request, $response)
    {
        # Lê o JSON enviado pelo fetch do frontend
        $body = (string) $request->getBody();
        $data = json_decode($body, true);

        # Captura os dados informados pelo usuário
        $nome      = trim($data['nome']      ?? '');
        $sobrenome = trim($data['sobrenome'] ?? '');
        $cpf       = preg_replace('/\D/', '', $data['cpf'] ?? '');
        $rg        = preg_replace('/\D/', '', $data['rg']  ?? '');
        $senha     = $data['senha']    ?? '';
        $contacts  = $data['contacts'] ?? [];

        # Validação básica server-side antes de tocar no banco
        if (!$nome || !$sobrenome || strlen($cpf) !== 11 || strlen($senha) < 8) {
            return $this->json($response, [
                'status' => false,
                'msg'    => 'Dados inválidos. Verifique os campos obrigatórios.',
            ], 422);
        }

        try {
            $conn = \app\database\DB::connection();

            # Verifica se o CPF já está cadastrado antes de tentar inserir
            $existe = $conn->fetchOne('SELECT id FROM users WHERE cpf = ?', [$cpf]);
            if ($existe) {
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'Este CPF já está cadastrado.',
                ], 409);
            }

            # Insere o usuário — ativo=false aguarda aprovação do administrador
            $conn->insert('users', [
                'nome'          => $nome,
                'sobrenome'     => $sobrenome,
                'cpf'           => $cpf,
                'rg'            => $rg,
                'senha'         => password_hash($senha, PASSWORD_DEFAULT),
                'ativo'         => false,
                'administrador' => false,
                'criado_em'     => date('Y-m-d H:i:s'),
                'atualizado_em' => date('Y-m-d H:i:s'),
            ], [
                'ativo'         => ParameterType::BOOLEAN,
                'administrador' => ParameterType::BOOLEAN,
            ]);

            # Busca o ID gerado de forma segura via currval (PostgreSQL)
            $id_usuario = (int) $conn->fetchOne("SELECT currval(pg_get_serial_sequence('users', 'id'))");

            # Insere os contatos (email, celular, telefone, whatsapp) com validação de tipo
            $tiposValidos = ['EMAIL', 'CELULAR', 'TELEFONE', 'WHATSAPP'];
            foreach ($contacts as $contact) {
                $tipo    = strtoupper(trim($contact['tipo']    ?? ''));
                $contato = trim($contact['contato'] ?? '');

                # Ignora contatos com tipo inválido ou valor vazio
                if (!in_array($tipo, $tiposValidos, true) || !$contato) {
                    continue;
                }

                $conn->insert('contact', [
                    'id_usuario'    => $id_usuario,
                    'tipo'          => $tipo,
                    'contato'       => $contato,
                    'criado_em'     => date('Y-m-d H:i:s'),
                    'atualizado_em' => date('Y-m-d H:i:s'),
                ]);
            }

            # Retorna 201 (Created) — código semântico correto para criação de recurso
            return $this->json($response, [
                'status' => true,
                'msg'    => 'Cadastro realizado! Aguarde a ativação da sua conta pelo administrador.',
                'id'     => $id_usuario,
            ], 201);

        } catch (\PDOException $e) {
            # Trata violação de unique constraint (CPF ou contato duplicado por race condition)
            if (str_contains($e->getMessage(), 'unique') || str_contains($e->getMessage(), 'duplicate')) {
                return $this->json($response, [
                    'status' => false,
                    'msg'    => 'CPF ou contato já cadastrado no sistema.',
                ], 409);
            }
            error_log('[register][DB] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro ao salvar. Tente novamente.'], 500);
        } catch (\Throwable $e) {
            error_log('[register][GERAL] ' . $e->getMessage());
            return $this->json($response, ['status' => false, 'msg' => 'Erro inesperado. Tente novamente.'], 500);
        }
    }

    # TODO: implementar autenticação via Google OAuth
    public function google($request, $response)
    {
        $form              = $request->getParsedBody();
        $credential        = $form['credential']   ?? null;
        $form_g_csrf_token = $form['g_csrf_token'] ?? null;

        if (is_null($credential) || is_null($form_g_csrf_token)) {
            return $this->json($response, ['status' => false, 'msg' => 'Credenciais Google ausentes.', 'id' => 0], 400);
        }
    }
}