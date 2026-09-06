<?php
// api/auth.php - Autenticação, Sessão e Gestão/Edição de Usuários com Perfis (Admin/Comum)
require_once __DIR__ . '/config.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

switch ($action) {
    case 'login':
        $usuario = trim($input['usuario'] ?? '');
        $senha = trim($input['senha'] ?? '');

        if (empty($usuario) || empty($senha)) {
            jsonResponse(['status' => 'error', 'message' => 'Informe o usuário e a senha.'], 400);
        }

        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE usuario = ?");
        $stmt->execute([$usuario]);
        $user = $stmt->fetch();

        if ($user && password_verify($senha, $user['senha_hash'])) {
            $perfil = $user['perfil'] ?? 'admin';
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_nome'] = $user['nome'];
            $_SESSION['user_usuario'] = $user['usuario'];
            $_SESSION['user_perfil'] = $perfil;

            jsonResponse([
                'status' => 'success',
                'message' => 'Login realizado com sucesso!',
                'user' => [
                    'id' => $user['id'],
                    'nome' => $user['nome'],
                    'usuario' => $user['usuario'],
                    'perfil' => $perfil
                ]
            ]);
        } else {
            jsonResponse(['status' => 'error', 'message' => 'Usuário ou senha incorretos.'], 401);
        }
        break;

    case 'logout':
        session_destroy();
        jsonResponse(['status' => 'success', 'message' => 'Sessão encerrada com sucesso.']);
        break;

    case 'check':
        if (!empty($_SESSION['user_id'])) {
            jsonResponse([
                'status' => 'authenticated',
                'user' => [
                    'id' => $_SESSION['user_id'],
                    'nome' => $_SESSION['user_nome'],
                    'usuario' => $_SESSION['user_usuario'],
                    'perfil' => $_SESSION['user_perfil'] ?? 'admin'
                ]
            ]);
        } else {
            jsonResponse(['status' => 'unauthenticated'], 200);
        }
        break;

    case 'listar_usuarios':
        requireAdmin();
        $pdo = getPDO();
        $stmt = $pdo->query("SELECT id, usuario, nome, perfil, criado_em FROM usuarios ORDER BY nome ASC");
        jsonResponse(['status' => 'success', 'usuarios' => $stmt->fetchAll()]);
        break;

    case 'cadastrar_usuario':
        requireAdmin();
        $nome = trim($input['nome'] ?? '');
        $usuario = trim($input['usuario'] ?? '');
        $senha = trim($input['senha'] ?? '');
        $perfil = trim($input['perfil'] ?? 'comum');

        if (!in_array($perfil, ['admin', 'comum'])) {
            $perfil = 'comum';
        }

        if (empty($nome) || empty($usuario) || empty($senha)) {
            jsonResponse(['status' => 'error', 'message' => 'Nome, usuário e senha são obrigatórios.'], 400);
        }

        $pdo = getPDO();
        $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmtCheck->execute([$usuario]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['status' => 'error', 'message' => 'Este nome de usuário já está cadastrado.'], 400);
        }

        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO usuarios (nome, usuario, senha_hash, perfil, criado_em) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)");
        $stmt->execute([$nome, $usuario, $hash, $perfil]);

        jsonResponse(['status' => 'success', 'message' => 'Usuário cadastrado com sucesso!', 'id' => $pdo->lastInsertId()]);
        break;

    case 'editar_usuario':
        requireAdmin();
        $id = (int)($input['id'] ?? 0);
        $nome = trim($input['nome'] ?? '');
        $usuario = trim($input['usuario'] ?? '');
        $senha = trim($input['senha'] ?? '');
        $perfil = trim($input['perfil'] ?? 'comum');

        if ($id <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'ID do usuário inválido.'], 400);
        }

        if (!in_array($perfil, ['admin', 'comum'])) {
            $perfil = 'comum';
        }

        if (empty($nome) || empty($usuario)) {
            jsonResponse(['status' => 'error', 'message' => 'Nome e usuário são obrigatórios.'], 400);
        }

        $pdo = getPDO();
        $stmtCheck = $pdo->prepare("SELECT id FROM usuarios WHERE usuario = ? AND id != ?");
        $stmtCheck->execute([$usuario, $id]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['status' => 'error', 'message' => 'Este nome de usuário já pertence a outro cadastro.'], 400);
        }

        if (!empty($senha)) {
            $hash = password_hash($senha, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, usuario = ?, senha_hash = ?, perfil = ? WHERE id = ?");
            $stmt->execute([$nome, $usuario, $hash, $perfil, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE usuarios SET nome = ?, usuario = ?, perfil = ? WHERE id = ?");
            $stmt->execute([$nome, $usuario, $perfil, $id]);
        }

        if ($id === (int)$_SESSION['user_id']) {
            $_SESSION['user_nome'] = $nome;
            $_SESSION['user_usuario'] = $usuario;
            $_SESSION['user_perfil'] = $perfil;
        }

        jsonResponse(['status' => 'success', 'message' => 'Usuário atualizado com sucesso!']);
        break;

    case 'excluir_usuario':
        requireAdmin();
        $currentUserId = $_SESSION['user_id'];
        $idExcluir = (int)($input['id'] ?? $_GET['id'] ?? 0);

        if ($idExcluir <= 0) {
            jsonResponse(['status' => 'error', 'message' => 'ID de usuário inválido.'], 400);
        }

        if ($idExcluir === (int)$currentUserId) {
            jsonResponse(['status' => 'error', 'message' => 'Você não pode excluir o seu próprio usuário conectado.'], 400);
        }

        $pdo = getPDO();
        $stmt = $pdo->prepare("DELETE FROM usuarios WHERE id = ?");
        $stmt->execute([$idExcluir]);

        jsonResponse(['status' => 'success', 'message' => 'Usuário excluído com sucesso!']);
        break;

    case 'alterar_senha':
        requireAuth();
        $senhaAtual = trim($input['senha_atual'] ?? '');
        $novaSenha = trim($input['nova_senha'] ?? '');

        if (empty($senhaAtual) || empty($novaSenha)) {
            jsonResponse(['status' => 'error', 'message' => 'Preencha a senha atual e a nova senha.'], 400);
        }

        $pdo = getPDO();
        $stmt = $pdo->prepare("SELECT senha_hash FROM usuarios WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        $hashAtual = $stmt->fetchColumn();

        if (!password_verify($senhaAtual, $hashAtual)) {
            jsonResponse(['status' => 'error', 'message' => 'Senha atual incorreta.'], 400);
        }

        $novoHash = password_hash($novaSenha, PASSWORD_DEFAULT);
        $stmtUp = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE id = ?");
        $stmtUp->execute([$novoHash, $_SESSION['user_id']]);

        jsonResponse(['status' => 'success', 'message' => 'Senha alterada com sucesso!']);
        break;

    default:
        jsonResponse(['status' => 'error', 'message' => 'Ação inválida.'], 400);
        break;
}
