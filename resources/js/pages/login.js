import Validate from '../components/validate.js';
import Requests from '../components/requests.js';

const BtnLogin   = document.getElementById('btnLogin');
const InputLogin = document.getElementById('login');
const InputSenha = document.getElementById('senha');

function setLoading(loading) {
    BtnLogin.disabled = loading;
    BtnLogin.textContent = loading ? 'Entrando…' : 'Login';
}

function markInvalid(input) {
    input.classList.add('is-invalid');
    input.addEventListener('input', () => input.classList.remove('is-invalid'), { once: true });
}

function clearErrors() {
    [InputLogin, InputSenha].forEach(el => el.classList.remove('is-invalid'));
}

function validate() {
    Validate.SetForm('form');
    Validate.form.validate({
        rules: {
            login: { required: true },
            senha: { required: true }
        }
    });

    const ok = Validate.Validate();
    if (!ok) {
        Swal.fire({ icon: 'warning', title: 'Campos obrigatórios', text: 'Preencha o usuário e a senha antes de continuar.', confirmButtonColor: '#198754' });
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
            Swal.fire({ icon: 'error', title: 'Falha no login', text: response?.msg || 'Usuário ou senha incorretos.', confirmButtonColor: '#198754' });
            markInvalid(InputSenha);
            InputSenha.value = '';
            InputSenha.focus();
            return;
        }
        await Swal.fire({ icon: 'success', title: 'Bem-vindo!', text: response.msg, timer: 1500, timerProgressBar: true, showConfirmButton: false });
        window.location.href = '/home';
    } catch (error) {
        let titulo = 'Erro';
        let texto  = error.message || 'Não foi possível conectar ao servidor.';
        if (texto.includes('429'))      { titulo = 'Muitas tentativas'; texto = 'Sua conta foi temporariamente bloqueada. Tente novamente em alguns minutos.'; }
        else if (texto.includes('403')) { titulo = 'Acesso negado';     texto = 'Usuário ou senha incorretos.'; }
        else if (texto.includes('500')) { titulo = 'Erro no servidor';  texto = 'Ocorreu um problema interno. Tente novamente em instantes.'; }
        Swal.fire({ icon: 'error', title: titulo, text: texto, confirmButtonColor: '#198754' });
        markInvalid(InputSenha);
        InputSenha.value = '';
        InputSenha.focus();
    } finally {
        setLoading(false);
    }
}

// ===== MODAL CADASTRO =====
window.openModal = function () {
    document.getElementById('overlay-cadastro').classList.add('active');
    document.body.style.overflow = 'hidden';
};

window.closeModal = function () {
    document.getElementById('overlay-cadastro').classList.remove('active');
    document.body.style.overflow = '';
};

window.toggleChip = function (btn, type) {
    btn.classList.toggle('active');
    document.getElementById('contact-' + type).classList.toggle('visible', btn.classList.contains('active'));
};

window.togglePw = function (id, btn) {
    const input = document.getElementById(id);
    input.type = input.type === 'password' ? 'text' : 'password';
    btn.textContent = input.type === 'password' ? '👁' : '🙈';
};

document.getElementById('overlay-cadastro').addEventListener('click', (e) => {
    if (e.target === e.currentTarget) window.closeModal();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') window.closeModal();
});

// Máscaras
document.getElementById('cad-cpf').addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 11);
    v = v.replace(/(\d{3})(\d)/, '$1.$2').replace(/(\d{3})\.(\d{3})(\d)/, '$1.$2.$3').replace(/(\d{3})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
    this.value = v;
});

document.getElementById('cad-rg').addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 9);
    v = v.replace(/(\d{2})(\d)/, '$1.$2').replace(/(\d{2})\.(\d{3})(\d)/, '$1.$2.$3').replace(/(\d{2})\.(\d{3})\.(\d{3})(\d)/, '$1.$2.$3-$4');
    this.value = v;
});

['cad-celular', 'cad-whatsapp'].forEach(id => {
    document.getElementById(id).addEventListener('input', function () {
        let v = this.value.replace(/\D/g, '').slice(0, 11);
        v = v.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{1}) (\d{4})(\d)/, '$1 $2-$3');
        this.value = v;
    });
});

document.getElementById('cad-telefone').addEventListener('input', function () {
    let v = this.value.replace(/\D/g, '').slice(0, 10);
    v = v.replace(/(\d{2})(\d)/, '($1) $2').replace(/(\d{4})(\d)/, '$1-$2');
    this.value = v;
});

document.getElementById('cad-senha').addEventListener('input', function () {
    const pw = this.value;
    let score = 0;
    if (pw.length >= 8)           score++;
    if (/[A-Z]/.test(pw))        score++;
    if (/[0-9]/.test(pw))        score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const colors = ['#e05252', '#e07c52', '#d4b800', '#1e6b45'];
    const labels = ['Muito fraca', 'Fraca', 'Média', 'Forte'];
    for (let i = 1; i <= 4; i++) {
        document.getElementById('s' + i).style.background = i <= score ? colors[score - 1] : '#eee';
    }
    const lbl = document.getElementById('strength-label');
    lbl.textContent = pw.length ? labels[score - 1] : '';
    lbl.style.color  = pw.length ? colors[score - 1] : '#aaa';
});

// Submit do cadastro
document.getElementById('btnCadastrar').addEventListener('click', async () => {
    let valid = true;
    document.querySelectorAll('.err-msg').forEach(e => e.textContent = '');
    document.querySelectorAll('.field input').forEach(e => e.classList.remove('error'));

    const fields = {
        nome:      { el: document.getElementById('cad-nome'),      msg: 'Nome é obrigatório' },
        sobrenome: { el: document.getElementById('cad-sobrenome'), msg: 'Sobrenome é obrigatório' },
    };
    for (const [key, { el, msg }] of Object.entries(fields)) {
        if (!el.value.trim()) { document.getElementById('err-' + key).textContent = msg; el.classList.add('error'); valid = false; }
    }

    const cpf = document.getElementById('cad-cpf').value.replace(/\D/g, '');
    if (cpf.length !== 11) { document.getElementById('err-cpf').textContent = 'CPF inválido'; document.getElementById('cad-cpf').classList.add('error'); valid = false; }

    const senha = document.getElementById('cad-senha').value;
    const conf  = document.getElementById('cad-confirmar-senha').value;
    if (senha.length < 8) { document.getElementById('err-senha').textContent = 'Mínimo 8 caracteres'; document.getElementById('cad-senha').classList.add('error'); valid = false; }
    if (senha !== conf)   { document.getElementById('err-confirmar-senha').textContent = 'As senhas não coincidem'; document.getElementById('cad-confirmar-senha').classList.add('error'); valid = false; }

    const emailAtivo = document.getElementById('contact-email').classList.contains('visible');
    const email = document.getElementById('cad-email').value.trim();
    if (emailAtivo && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) { document.getElementById('err-email').textContent = 'E-mail inválido'; document.getElementById('cad-email').classList.add('error'); valid = false; }

    if (!valid) return;

    const payload = {
        nome:      document.getElementById('cad-nome').value.trim(),
        sobrenome: document.getElementById('cad-sobrenome').value.trim(),
        cpf,
        rg:        document.getElementById('cad-rg').value.replace(/\D/g, ''),
        senha,
        contacts:  [],
    };
    if (emailAtivo && email) payload.contacts.push({ tipo: 'EMAIL',    contato: email });
    const cel = document.getElementById('cad-celular').value.replace(/\D/g, '');
    if (cel) payload.contacts.push({ tipo: 'CELULAR',   contato: cel });
    const tel = document.getElementById('cad-telefone').value.replace(/\D/g, '');
    if (tel) payload.contacts.push({ tipo: 'TELEFONE',  contato: tel });
    const wpp = document.getElementById('cad-whatsapp').value.replace(/\D/g, '');
    if (wpp) payload.contacts.push({ tipo: 'WHATSAPP',  contato: wpp });

    try {
        const response = await fetch('/cadastro', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
        });
        const data = await response.json();

        if (!data?.status) {
            Swal.fire({ icon: 'error', title: 'Erro no cadastro', text: data?.msg, confirmButtonColor: '#198754' });
            return;
        }

        await Swal.fire({ icon: 'success', title: 'Conta criada!', text: data.msg, timer: 1800, timerProgressBar: true, showConfirmButton: false });
        window.location.reload();

    } catch (err) {
        Swal.fire({ icon: 'error', title: 'Erro', text: 'Não foi possível conectar ao servidor.', confirmButtonColor: '#198754' });
    }
});
// ===== /MODAL CADASTRO =====

// Clique no botão de login
BtnLogin.addEventListener('click', handleLogin);

// Enter em qualquer campo do formulário de login
document.getElementById('form').addEventListener('keydown', (e) => {
    if (e.key === 'Enter') { e.preventDefault(); handleLogin(); }
});