<?php
// api/turmas.php - CRUD de Turmas da Formatura
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT id, nome, observacao, criado_em FROM turmas ORDER BY nome ASC");
    $turmas = $stmt->fetchAll();

    // Se nenhuma turma cadastrada, insere padrões 3º A, 3º B, 3º C
    if (empty($turmas)) {
        $pdo->exec("INSERT IGNORE INTO `turmas` (`nome`) VALUES ('3º A'), ('3º B'), ('3º C')");
        $stmt = $pdo->query("SELECT id, nome, observacao, criado_em FROM turmas ORDER BY nome ASC");
        $turmas = $stmt->fetchAll();
    }

    jsonResponse(['status' => 'success', 'turmas' => $turmas]);
} elseif ($method === 'POST') {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $nome = trim($input['nome'] ?? '');
    $observacao = trim($input['observacao'] ?? '');

    if (empty($nome)) {
        jsonResponse(['status' => 'error', 'message' => 'O nome da turma é obrigatório.'], 400);
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM turmas WHERE nome = ?");
    $stmtCheck->execute([$nome]);
    if ($stmtCheck->fetch()) {
        jsonResponse(['status' => 'error', 'message' => 'Já existe uma turma cadastrada com este nome.'], 400);
    }

    $stmt = $pdo->prepare("INSERT INTO turmas (nome, observacao, criado_em) VALUES (?, ?, CURRENT_TIMESTAMP)");
    $stmt->execute([$nome, $observacao]);

    jsonResponse(['status' => 'success', 'message' => 'Turma cadastrada com sucesso!', 'id' => $pdo->lastInsertId()]);
} elseif ($method === 'PUT') {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);
    $nome = trim($input['nome'] ?? '');
    $observacao = trim($input['observacao'] ?? '');

    if ($id <= 0 || empty($nome)) {
        jsonResponse(['status' => 'error', 'message' => 'ID e nome da turma são obrigatórios.'], 400);
    }

    $stmtCheck = $pdo->prepare("SELECT id FROM turmas WHERE nome = ? AND id != ?");
    $stmtCheck->execute([$nome, $id]);
    if ($stmtCheck->fetch()) {
        jsonResponse(['status' => 'error', 'message' => 'Já existe outra turma com este nome.'], 400);
    }

    $stmtOld = $pdo->prepare("SELECT nome FROM turmas WHERE id = ?");
    $stmtOld->execute([$id]);
    $nomeAntigo = $stmtOld->fetchColumn();

    $stmt = $pdo->prepare("UPDATE turmas SET nome = ?, observacao = ? WHERE id = ?");
    $stmt->execute([$nome, $observacao, $id]);

    if ($nomeAntigo && $nomeAntigo !== $nome) {
        $stmtUp = $pdo->prepare("UPDATE formandos SET turma = ? WHERE turma = ?");
        $stmtUp->execute([$nome, $nomeAntigo]);
    }

    jsonResponse(['status' => 'success', 'message' => 'Turma atualizada com sucesso!']);
} elseif ($method === 'DELETE') {
    requireAdmin();
    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'ID de turma inválido.'], 400);
    }

    $stmtOld = $pdo->prepare("SELECT nome FROM turmas WHERE id = ?");
    $stmtOld->execute([$id]);
    $nomeTurma = $stmtOld->fetchColumn();

    if ($nomeTurma) {
        $stmtCheckForm = $pdo->prepare("SELECT COUNT(*) FROM formandos WHERE turma = ?");
        $stmtCheckForm->execute([$nomeTurma]);
        if ($stmtCheckForm->fetchColumn() > 0) {
            jsonResponse(['status' => 'error', 'message' => 'Não é possível excluir esta turma pois existem formandos cadastrados nela.'], 400);
        }
    }

    $stmt = $pdo->prepare("DELETE FROM turmas WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['status' => 'success', 'message' => 'Turma excluída com sucesso!']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Método não permitido.'], 405);
}
