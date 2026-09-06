<?php
// api/pagamentos.php - CRUD de Pagamentos (Recebimento para todos / Deleção restrita ao Admin)
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $formandoId = (int)($_GET['formando_id'] ?? 0);

    if ($formandoId <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'ID do formando é obrigatório.'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM pagamentos WHERE formando_id = ? ORDER BY numero_parcela ASC, data_pagamento ASC");
    $stmt->execute([$formandoId]);
    $pagamentos = $stmt->fetchAll();

    jsonResponse(['status' => 'success', 'pagamentos' => $pagamentos]);
} elseif ($method === 'POST') {
    requireAuth(); // Usuários comuns E Administradores podem receber pagamentos
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $formandoId = (int)($input['formando_id'] ?? 0);
    $numeroParcela = (int)($input['numero_parcela'] ?? 1);
    $dataPagamento = trim($input['data_pagamento'] ?? date('Y-m-d'));
    $valor = (float)($input['valor'] ?? 0);
    $formaPagamento = trim($input['forma_pagamento'] ?? 'Pix');
    $chavePix = trim($input['chave_pix'] ?? '');
    $observacao = trim($input['observacao'] ?? '');

    if ($formandoId <= 0 || $valor <= 0 || empty($dataPagamento)) {
        jsonResponse(['status' => 'error', 'message' => 'Formando, data e valor são obrigatórios.'], 400);
    }

    $stmtCheck = $pdo->prepare("SELECT * FROM formandos WHERE id = ?");
    $stmtCheck->execute([$formandoId]);
    $formando = $stmtCheck->fetch();
    if (!$formando) {
        jsonResponse(['status' => 'error', 'message' => 'Formando não encontrado.'], 404);
    }

    // Validação para não aceitar valor maior que o saldo devedor (Quanto Falta Pagar)
    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch() ?: [
        'valor_pessoa_extra' => 80.00
    ];
    $valorExtraGlobal = (float)($config['valor_pessoa_extra'] ?? 80.00);
    if ($valorExtraGlobal <= 0 && (float)($config['valor_total_base'] ?? 0) > 0) {
        $valorExtraGlobal = (float)$config['valor_total_base'];
    }
    $convidadosExtra = (int)($formando['convidados_extra'] ?? 0);
    if ($convidadosExtra <= 0) $convidadosExtra = 1;
    $valorTotalAPagar = $convidadosExtra * $valorExtraGlobal;

    $stmtPag = $pdo->prepare("SELECT SUM(valor) as total_pago FROM pagamentos WHERE formando_id = ?");
    $stmtPag->execute([$formandoId]);
    $totalPago = (float)($stmtPag->fetchColumn() ?: 0);

    $saldoDevedor = round(max(0, $valorTotalAPagar - $totalPago), 2);
    if (round($valor, 2) > $saldoDevedor + 0.001) {
        jsonResponse([
            'status' => 'error',
            'message' => 'O valor do pagamento (R$ ' . number_format($valor, 2, ',', '.') . ') não pode ser maior que o saldo devedor restante (R$ ' . number_format($saldoDevedor, 2, ',', '.') . ').'
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO pagamentos (formando_id, numero_parcela, data_pagamento, valor, forma_pagamento, chave_pix, observacao, criado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$formandoId, $numeroParcela, $dataPagamento, $valor, $formaPagamento, $chavePix, $observacao]);
    $newId = $pdo->lastInsertId();

    jsonResponse([
        'status' => 'success',
        'message' => 'Pagamento registrado com sucesso!',
        'id' => $newId
    ], 201);
} elseif ($method === 'DELETE') {
    requireAdmin(); // Apenas Admin pode excluir parcelas de pagamento
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($_GET['id'] ?? $input['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'ID do pagamento é inválido.'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM pagamentos WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);

    jsonResponse(['status' => 'success', 'message' => 'Pagamento removido com sucesso!']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Método HTTP não suportado.'], 405);
}
