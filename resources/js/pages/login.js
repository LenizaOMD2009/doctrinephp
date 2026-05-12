import Requests from '../components/requests.js';

const BtnLogin   = document.getElementById('btnLogin');
const InputLogin = document.getElementById('login');
const InputSenha = document.getElementById('senha');

// Bloqueia/desbloqueia botão durante a requisição
function setLoading(loading) {
    BtnLogin.disabled = loading;
    BtnLogin.textContent = loading ? 'Entrando…' : 'Login';
}

// Destaca campo inválido e remove ao digitar
function markInvalid(input) {
    input.classList.add('is-invalid');
    input.addEventListener('input', () => input.classList.remove('is-invalid'), { once: true });
}

function clearErrors() {
    [InputLogin, InputSenha].forEach(el => el.classList.remove('is-invalid'));
}

// Validação local antes de bater no servidor
function validate() {
    let ok = true;
    if (!InputLogin.value.trim()) { markInvalid(InputLogin); ok = false; }
    if (!InputSenha.value.trim()) { markInvalid(InputSenha); ok = false; }
    if (!ok) {
        Swal.fire({
            icon: 'warning',
            title: 'Campos obrigatórios',
            text: 'Preencha o usuário e a senha antes de continuar.',
            confirmButtonColor: '#198754',
        });
    }
    return ok;
}

async function handleLogin() {
    clearErrors();
    if (!validate()) return;

    setLoading(true);

    try {
        const requests = new Requests();
        const response = await requests.setForm('form').post('/login');

        if (!response?.status) {
            Swal.fire({
                icon: 'error',
                title: 'Falha no login',
                text: response?.msg || 'Usuário ou senha incorretos.',
                confirmButtonColor: '#198754',
            });
            markInvalid(InputSenha);
            InputSenha.value = '';
            InputSenha.focus();
            return;
        }

        await Swal.fire({
            icon: 'success',
            title: 'Bem-vindo!',
            text: response.msg,
            timer: 1500,
            timerProgressBar: true,
            showConfirmButton: false,
        });

        window.location.href = '/home';

    } catch (error) {
        let titulo = 'Erro';
        let texto  = error.message || 'Não foi possível conectar ao servidor.';

        if (texto.includes('429')) {
            titulo = 'Muitas tentativas';
            texto  = 'Sua conta foi temporariamente bloqueada. Tente novamente em alguns minutos.';
        } else if (texto.includes('403')) {
            titulo = 'Acesso negado';
            texto  = 'Usuário ou senha incorretos.';
        } else if (texto.includes('500')) {
            titulo = 'Erro no servidor';
            texto  = 'Ocorreu um problema interno. Tente novamente em instantes.';
        }

        Swal.fire({
            icon: 'error',
            title: titulo,
            text: texto,
            confirmButtonColor: '#198754',
        });

        markInvalid(InputSenha);
        InputSenha.value = '';
        InputSenha.focus();

    } finally {
        setLoading(false);
    }
}

// Clique no botão
BtnLogin.addEventListener('click', handleLogin);

// Enter em qualquer campo do formulário
document.getElementById('form').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); handleLogin(); }
});