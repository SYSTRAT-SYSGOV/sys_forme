<?php
// api/configuracoes.php - CRUD de Configurações Gerais (Somente Admin altera)
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmt->fetch();
    if (!$config) {
        $config = [
            'id' => 1,
            'titulo_formatura' => 'Formatura 2026',
            'valor_total_base' => 1500.00,
            'valor_pessoa_extra' => 80.00,
            'max_parcelas' => 12,
            'chave_pix' => 'formatura2026@escola.com'
        ];
    }
    $config['valor_total_base'] = (float)$config['valor_total_base'];
    $config['valor_pessoa_extra'] = (float)$config['valor_pessoa_extra'];
    $config['max_parcelas'] = (int)$config['max_parcelas'];
    $config['formas_pagamento'] = $config['formas_pagamento'] ?? 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto';
    $config['chaves_pix'] = $config['chaves_pix'] ?? '';

    jsonResponse(['status' => 'success', 'config' => $config]);
} elseif ($method === 'POST' || $method === 'PUT') {
    requireAdmin(); // Apenas Admin altera os preços e número de parcelas
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $titulo = trim($input['titulo_formatura'] ?? 'Formatura 2026');
    $valorBase = (float)($input['valor_total_base'] ?? 0);
    $valorExtra = (float)($input['valor_pessoa_extra'] ?? 0);
    if ($valorExtra <= 0 && $valorBase > 0) {
        $valorExtra = $valorBase;
    }
    $maxParcelas = (int)($input['max_parcelas'] ?? 12);
    $chavePix = trim($input['chave_pix'] ?? '');
    $formasPagamento = trim($input['formas_pagamento'] ?? 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto');
    $chavesPix = is_array($input['chaves_pix'] ?? null) ? json_encode($input['chaves_pix']) : trim($input['chaves_pix'] ?? '');

    if ($valorExtra <= 0 || $maxParcelas <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'O valor por pessoa e o número máximo de parcelas devem ser maiores que zero.'], 400);
    }

    $stmt = $pdo->prepare("
        UPDATE configuracoes SET
            titulo_formatura = ?,
            valor_total_base = ?,
            valor_pessoa_extra = ?,
            max_parcelas = ?,
            chave_pix = ?,
            formas_pagamento = ?,
            chaves_pix = ?
        WHERE id = 1
    ");
    $stmt->execute([$titulo, $valorBase, $valorExtra, $maxParcelas, $chavePix, $formasPagamento, $chavesPix]);

    jsonResponse(['status' => 'success', 'message' => 'Configurações atualizadas com sucesso!']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Método HTTP não suportado.'], 405);
}
