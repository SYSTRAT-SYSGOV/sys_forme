<?php
// api/formandos.php - CRUD de Formandos & Importação Inteligente de Alunos
require_once __DIR__ . '/config.php';

$pdo = getPDO();
$method = $_SERVER['REQUEST_METHOD'];

// Busca configurações globais
$stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
$config = $stmtConfig->fetch() ?: [
    'valor_total_base' => 0.00,
    'valor_pessoa_extra' => 80.00,
    'max_parcelas' => 12
];
$valorExtraGlobal = (float)($config['valor_pessoa_extra'] ?? 80.00);
if ($valorExtraGlobal <= 0 && (float)($config['valor_total_base'] ?? 0) > 0) {
    $valorExtraGlobal = (float)$config['valor_total_base'];
}
$valorBaseGlobal = 0.00;

if ($method === 'GET') {
    $id = $_GET['id'] ?? null;
    $turmaFiltro = $_GET['turma'] ?? null;
    $busca = trim($_GET['busca'] ?? '');
    $listaCompleta = isset($_GET['lista_completa']) && $_GET['lista_completa'] === '1';

    if ($id) {
        $stmt = $pdo->prepare("SELECT * FROM formandos WHERE id = ?");
        $stmt->execute([$id]);
        $formando = $stmt->fetch();

        if (!$formando) {
            jsonResponse(['status' => 'error', 'message' => 'Formando não encontrado.'], 404);
        }

        $stmtPag = $pdo->prepare("SELECT SUM(valor) as total_pago FROM pagamentos WHERE formando_id = ?");
        $stmtPag->execute([$id]);
        $totalPago = (float)($stmtPag->fetchColumn() ?: 0);

        $convidadosExtra = (int)$formando['convidados_extra'];
        if ($convidadosExtra <= 0) $convidadosExtra = 1;
        $valorTotalAPagar = $convidadosExtra * $valorExtraGlobal;
        $saldoDevedor = max(0, $valorTotalAPagar - $totalPago);

        $formando['convidados_extra'] = $convidadosExtra;
        $formando['valor_base'] = 0;
        $formando['valor_extra_unitario'] = $valorExtraGlobal;
        $formando['valor_total_a_pagar'] = $valorTotalAPagar;
        $formando['total_pago'] = $totalPago;
        $formando['saldo_devedor'] = $saldoDevedor;
        $formando['participa_formatura'] = (int)($formando['participa_formatura'] ?? 1);

        $stmtListPag = $pdo->prepare("SELECT * FROM pagamentos WHERE formando_id = ? ORDER BY numero_parcela ASC, data_pagamento ASC");
        $stmtListPag->execute([$id]);
        $formando['pagamentos'] = $stmtListPag->fetchAll();

        jsonResponse(['status' => 'success', 'formando' => $formando]);
    } else {
        $sql = "SELECT f.*, 
                COALESCE(SUM(p.valor), 0) as total_pago
                FROM formandos f
                LEFT JOIN pagamentos p ON f.id = p.formando_id";
        $params = [];
        $where = [];

        // Se NÃO for solicitação da lista completa de importação, filtra apenas participantes da formatura
        if (!$listaCompleta) {
            $where[] = "(f.participa_formatura = 1 OR f.participa_formatura IS NULL)";
        }

        if (!empty($turmaFiltro)) {
            $where[] = "f.turma = ?";
            $params[] = $turmaFiltro;
        }

        if (!empty($busca)) {
            $where[] = "(f.nome LIKE ? OR f.telefone LIKE ? OR f.turma LIKE ? OR f.cgm LIKE ? OR f.numero_aluno LIKE ?)";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
            $params[] = "%$busca%";
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        $sql .= " GROUP BY f.id ORDER BY f.turma ASC, CASE WHEN f.numero_aluno IS NULL OR f.numero_aluno = 0 THEN 99999 ELSE f.numero_aluno END ASC, f.nome ASC";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $lista = $stmt->fetchAll();

        $resumoTurma = [
            'total_formandos' => count($lista),
            'total_extras' => 0,
            'total_pessoas_evento' => 0,
            'total_previsto' => 0,
            'total_arrecadado' => 0,
            'total_pendente' => 0
        ];

        foreach ($lista as &$f) {
            $convExtra = (int)$f['convidados_extra'];
            if ($convExtra <= 0) $convExtra = 1;
            $valTotal = $convExtra * $valorExtraGlobal;
            $totalPago = (float)$f['total_pago'];
            $saldoDev = max(0, $valTotal - $totalPago);

            $f['numero_aluno'] = isset($f['numero_aluno']) && $f['numero_aluno'] !== null ? (int)$f['numero_aluno'] : null;
            $f['convidados_extra'] = $convExtra;
            $f['valor_total_a_pagar'] = $valTotal;
            $f['total_pago'] = $totalPago;
            $f['saldo_devedor'] = $saldoDev;
            $f['participa_formatura'] = (int)($f['participa_formatura'] ?? 1);

            $resumoTurma['total_extras'] += $convExtra;
            $resumoTurma['total_previsto'] += $valTotal;
            $resumoTurma['total_arrecadado'] += $totalPago;
            $resumoTurma['total_pendente'] += $saldoDev;
        }

        $resumoTurma['total_pessoas_evento'] = $resumoTurma['total_extras'];

        jsonResponse([
            'status' => 'success',
            'config' => [
                'valor_base' => 0.00,
                'valor_extra' => $valorExtraGlobal,
                'max_parcelas' => (int)($config['max_parcelas'] ?? 12),
                'chave_pix' => $config['chave_pix'] ?? 'formatura2026@escola.com',
                'chaves_pix' => $config['chaves_pix'] ?? '',
                'formas_pagamento' => $config['formas_pagamento'] ?? 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'
            ],
            'resumo' => $resumoTurma,
            'formandos' => $lista
        ]);
    }
} elseif ($method === 'POST') {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $action = $input['action'] ?? '';

    // Importação Inteligente em Lote (com Prevenção de Duplicatas)
    if ($action === 'import_bulk' || isset($input['alunos_importados'])) {
        $alunos = $input['alunos_importados'] ?? [];
        if (!is_array($alunos) || empty($alunos)) {
            jsonResponse(['status' => 'error', 'message' => 'Nenhum aluno válido informado para importação.'], 400);
        }

        $stmtCheckTurma = $pdo->prepare("SELECT id FROM turmas WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))");
        $stmtInsertTurma = $pdo->prepare("INSERT INTO turmas (nome) VALUES (?)");

        // Queries para buscar aluno existente
        $stmtFindExactCgm = $pdo->prepare("SELECT id FROM formandos WHERE cgm = ? AND cgm IS NOT NULL AND cgm != '' LIMIT 1");
        $stmtFindNomeTurma = $pdo->prepare("SELECT id FROM formandos WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?)) AND LOWER(TRIM(turma)) = LOWER(TRIM(?)) LIMIT 1");

        $stmtInsertAluno = $pdo->prepare("
            INSERT INTO formandos (numero_aluno, nome, cgm, turma, telefone, convidados_extra, participa_formatura, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ");

        $stmtUpdateAluno = $pdo->prepare("
            UPDATE formandos SET
                numero_aluno = CASE WHEN ? IS NOT NULL THEN ? ELSE numero_aluno END,
                cgm = CASE WHEN ? != '' THEN ? ELSE cgm END,
                turma = CASE WHEN ? != '' THEN ? ELSE turma END,
                telefone = CASE WHEN ? != '' THEN ? ELSE telefone END,
                convidados_extra = CASE WHEN ? > 0 THEN ? ELSE convidados_extra END,
                participa_formatura = CASE WHEN ? = 1 THEN 1 ELSE participa_formatura END
            WHERE id = ?
        ");

        $insertedCount = 0;
        $updatedCount = 0;

        foreach ($alunos as $item) {
            $nome = trim($item['nome'] ?? '');
            $cgm = trim($item['cgm'] ?? '');
            $turma = trim($item['turma'] ?? '');
            $telefone = trim($item['telefone'] ?? '');
            $numAluno = isset($item['numero_aluno']) && $item['numero_aluno'] !== '' ? (int)$item['numero_aluno'] : (isset($item['numero']) && $item['numero'] !== '' ? (int)$item['numero'] : null);
            $participa = isset($item['participa_formatura']) ? (int)$item['participa_formatura'] : 0;
            $extras = (int)($item['convidados_extra'] ?? 0);

            if (empty($nome) || empty($turma)) continue;

            // Insere turma automaticamente se não existir
            $stmtCheckTurma->execute([$turma]);
            if (!$stmtCheckTurma->fetch()) {
                try {
                    $stmtInsertTurma->execute([$turma]);
                } catch (Exception $e) {}
            }

            // Pesquisa por duplicatas (por CGM ou Nome + Turma)
            $existente = null;
            if (!empty($cgm)) {
                $stmtFindExactCgm->execute([$cgm]);
                $existente = $stmtFindExactCgm->fetch();
            }
            if (!$existente) {
                $stmtFindNomeTurma->execute([$nome, $turma]);
                $existente = $stmtFindNomeTurma->fetch();
            }

            if ($existente) {
                $stmtUpdateAluno->execute([
                    $numAluno, $numAluno,
                    $cgm, $cgm,
                    $turma, $turma,
                    $telefone, $telefone,
                    $extras, $extras,
                    $participa,
                    $existente['id']
                ]);
                $updatedCount++;
            } else {
                $stmtInsertAluno->execute([$numAluno, $nome, $cgm, $turma, $telefone, $extras, $participa]);
                $insertedCount++;
            }
        }

        $totalProcessados = $insertedCount + $updatedCount;
        $mensagem = "$insertedCount novo(s) aluno(s) inserido(s)";
        if ($updatedCount > 0) {
            $mensagem .= " e $updatedCount existente(s) atualizado(s)";
        }
        $mensagem .= " sem duplicadas!";

        jsonResponse([
            'status' => 'success',
            'message' => $mensagem,
            'imported_count' => $totalProcessados,
            'inserted_count' => $insertedCount,
            'updated_count' => $updatedCount
        ], 200);
    }

    // Cadastro Individual Padrão
    $nome = trim($input['nome'] ?? '');
    $cgm = trim($input['cgm'] ?? '');
    $turma = trim($input['turma'] ?? '');
    $telefone = trim($input['telefone'] ?? '');
    $numeroAluno = isset($input['numero_aluno']) && $input['numero_aluno'] !== '' ? (int)$input['numero_aluno'] : null;
    $convidadosExtra = (int)($input['convidados_extra'] ?? 0);
    $observacoes = trim($input['observacoes'] ?? '');
    $participaFormatura = isset($input['participa_formatura']) ? (int)$input['participa_formatura'] : 1;

    if (empty($nome) || empty($turma)) {
        jsonResponse(['status' => 'error', 'message' => 'Nome e Turma são obrigatórios.'], 400);
    }

    // Garante que a turma exista no cadastro de turmas
    $stmtCheckTurma = $pdo->prepare("SELECT id FROM turmas WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))");
    $stmtCheckTurma->execute([$turma]);
    if (!$stmtCheckTurma->fetch()) {
        try {
            $stmtInsertTurma = $pdo->prepare("INSERT INTO turmas (nome) VALUES (?)");
            $stmtInsertTurma->execute([$turma]);
        } catch (Exception $e) {}
    }

    $stmt = $pdo->prepare("
        INSERT INTO formandos (numero_aluno, nome, cgm, turma, telefone, convidados_extra, participa_formatura, observacoes, criado_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
    ");
    $stmt->execute([$numeroAluno, $nome, $cgm, $turma, $telefone, $convidadosExtra, $participaFormatura, $observacoes]);
    $newId = $pdo->lastInsertId();

    jsonResponse(['status' => 'success', 'message' => 'Formando cadastrado com sucesso!', 'id' => $newId], 201);
} elseif ($method === 'PUT') {
    requireAdmin();
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $id = (int)($input['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'ID do formando inválido.'], 400);
    }

    // Alternância rápida de participação com dados do modal (telefone + convidados extra + cgm + numero_aluno + nome + turma)
    if (isset($input['only_participacao']) && $input['only_participacao']) {
        $participa = (int)($input['participa_formatura'] ?? 0);
        $telefone = trim($input['telefone'] ?? '');
        $convidadosExtra = (int)($input['convidados_extra'] ?? 0);
        $cgm = trim($input['cgm'] ?? '');
        $numAluno = isset($input['numero_aluno']) && $input['numero_aluno'] !== '' ? (int)$input['numero_aluno'] : null;
        $nomeOpt = trim($input['nome'] ?? '');
        $turmaOpt = trim($input['turma'] ?? '');

        if (!empty($turmaOpt)) {
            $stmtCheckTurma = $pdo->prepare("SELECT id FROM turmas WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))");
            $stmtCheckTurma->execute([$turmaOpt]);
            if (!$stmtCheckTurma->fetch()) {
                try {
                    $stmtInsertTurma = $pdo->prepare("INSERT INTO turmas (nome) VALUES (?)");
                    $stmtInsertTurma->execute([$turmaOpt]);
                } catch (Exception $e) {}
            }
        }

        $stmt = $pdo->prepare("
            UPDATE formandos SET
                participa_formatura = ?,
                telefone = ?,
                convidados_extra = ?,
                cgm = CASE WHEN ? != '' THEN ? ELSE cgm END,
                numero_aluno = CASE WHEN ? IS NOT NULL THEN ? ELSE numero_aluno END,
                nome = CASE WHEN ? != '' THEN ? ELSE nome END,
                turma = CASE WHEN ? != '' THEN ? ELSE turma END
            WHERE id = ?
        ");
        $stmt->execute([$participa, $telefone, $convidadosExtra, $cgm, $cgm, $numAluno, $numAluno, $nomeOpt, $nomeOpt, $turmaOpt, $turmaOpt, $id]);

        jsonResponse(['status' => 'success', 'message' => 'Status de participação do aluno atualizado com sucesso!']);
    }

    $nome = trim($input['nome'] ?? '');
    $cgm = trim($input['cgm'] ?? '');
    $turma = trim($input['turma'] ?? '');
    $telefone = trim($input['telefone'] ?? '');
    $numeroAluno = isset($input['numero_aluno']) && $input['numero_aluno'] !== '' ? (int)$input['numero_aluno'] : null;
    $convidadosExtra = (int)($input['convidados_extra'] ?? 0);
    $observacoes = trim($input['observacoes'] ?? '');
    $participaFormatura = isset($input['participa_formatura']) ? (int)$input['participa_formatura'] : 1;

    if (empty($nome) || empty($turma)) {
        jsonResponse(['status' => 'error', 'message' => 'Nome e Turma são obrigatórios.'], 400);
    }

    // Garante que a turma exista no cadastro de turmas ao editar
    $stmtCheckTurma = $pdo->prepare("SELECT id FROM turmas WHERE LOWER(TRIM(nome)) = LOWER(TRIM(?))");
    $stmtCheckTurma->execute([$turma]);
    if (!$stmtCheckTurma->fetch()) {
        try {
            $stmtInsertTurma = $pdo->prepare("INSERT INTO turmas (nome) VALUES (?)");
            $stmtInsertTurma->execute([$turma]);
        } catch (Exception $e) {}
    }

    $stmt = $pdo->prepare("
        UPDATE formandos SET
            numero_aluno = ?,
            nome = ?,
            cgm = ?,
            turma = ?,
            telefone = ?,
            convidados_extra = ?,
            participa_formatura = ?,
            observacoes = ?
        WHERE id = ?
    ");
    $stmt->execute([$numeroAluno, $nome, $cgm, $turma, $telefone, $convidadosExtra, $participaFormatura, $observacoes, $id]);

    jsonResponse(['status' => 'success', 'message' => 'Formando atualizado com sucesso!']);
} elseif ($method === 'DELETE') {
    requireAdmin();
    $limparTodos = (isset($_GET['limpar_todos']) && $_GET['limpar_todos'] === '1');
    
    if ($limparTodos) {
        $pdo->exec("DELETE FROM pagamentos");
        $pdo->exec("DELETE FROM formandos");
        jsonResponse(['status' => 'success', 'message' => 'Todos os alunos e histórico de pagamentos foram limpos com sucesso!']);
    }

    $id = (int)($_GET['id'] ?? 0);

    if ($id <= 0) {
        jsonResponse(['status' => 'error', 'message' => 'ID do formando inválido.'], 400);
    }

    $stmt = $pdo->prepare("DELETE FROM formandos WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse(['status' => 'success', 'message' => 'Formando excluído com sucesso!']);
} else {
    jsonResponse(['status' => 'error', 'message' => 'Método HTTP não suportado.'], 405);
}
