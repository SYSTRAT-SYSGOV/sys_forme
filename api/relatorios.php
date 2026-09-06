<?php
// api/relatorios.php - Endpoint de Relatórios Financeiros e por Turmas
require_once __DIR__ . '/config.php';

$pdo = getPDO();
requireAuth(); // Requer autenticação de usuário

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['status' => 'error', 'message' => 'Método HTTP não suportado.'], 405);
}

$turmaFiltro = trim($_GET['turma'] ?? '');
$dataInicio = trim($_GET['data_inicio'] ?? '');
$dataFim = trim($_GET['data_fim'] ?? '');

// 1. Configurações Globais
$stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
$config = $stmtConfig->fetch() ?: [
    'valor_total_base' => 0.00,
    'valor_pessoa_extra' => 80.00
];
$valorExtraGlobal = (float)($config['valor_pessoa_extra'] ?? 80.00);
if ($valorExtraGlobal <= 0 && (float)($config['valor_total_base'] ?? 0) > 0) {
    $valorExtraGlobal = (float)$config['valor_total_base'];
}

// 2. Query de Formandos Ativos (participantes da formatura)
$sqlFormandos = "SELECT f.* FROM formandos f WHERE (f.participa_formatura = 1 OR f.participa_formatura IS NULL)";
$paramsFormandos = [];

if (!empty($turmaFiltro)) {
    $sqlFormandos .= " AND f.turma = ?";
    $paramsFormandos[] = $turmaFiltro;
}

$sqlFormandos .= " ORDER BY f.turma ASC, CASE WHEN f.numero_aluno IS NULL OR f.numero_aluno = 0 THEN 99999 ELSE f.numero_aluno END ASC, f.nome ASC";

$stmtF = $pdo->prepare($sqlFormandos);
$stmtF->execute($paramsFormandos);
$formandos = $stmtF->fetchAll();

// 3. Query de Pagamentos com filtros
$sqlPagamentos = "SELECT p.*, f.turma, f.nome as nome_formando 
                  FROM pagamentos p 
                  JOIN formandos f ON p.formando_id = f.id 
                  WHERE (f.participa_formatura = 1 OR f.participa_formatura IS NULL)";
$paramsPagamentos = [];

if (!empty($turmaFiltro)) {
    $sqlPagamentos .= " AND f.turma = ?";
    $paramsPagamentos[] = $turmaFiltro;
}
if (!empty($dataInicio)) {
    $sqlPagamentos .= " AND p.data_pagamento >= ?";
    $paramsPagamentos[] = $dataInicio;
}
if (!empty($dataFim)) {
    $sqlPagamentos .= " AND p.data_pagamento <= ?";
    $paramsPagamentos[] = $dataFim;
}

$stmtP = $pdo->prepare($sqlPagamentos);
$stmtP->execute($paramsPagamentos);
$pagamentos = $stmtP->fetchAll();

// Mapeamento de pagamentos por formando_id
$pagamentosPorFormando = [];
foreach ($pagamentos as $pag) {
    $fid = (int)$pag['formando_id'];
    if (!isset($pagamentosPorFormando[$fid])) {
        $pagamentosPorFormando[$fid] = [];
    }
    $pagamentosPorFormando[$fid][] = $pag;
}

// 4. Estruturação dos Resultados
$resumoGeral = [
    'total_formandos' => count($formandos),
    'total_convidados' => 0,
    'valor_total_receber' => 0.00,
    'valor_total_recebido' => 0.00,
    'valor_total_pendente' => 0.00,
    'percentual_arrecadado' => 0.00,
    'qtd_quitados' => 0,
    'qtd_parciais' => 0,
    'qtd_pendentes' => 0
];

$formasPagamentoAgrupadas = [];
$turmasAgrupadas = [];

// Processa pagamentos por forma de pagamento
foreach ($pagamentos as $pag) {
    $forma = trim($pag['forma_pagamento'] ?: 'Pix');
    $val = (float)$pag['valor'];

    if (!isset($formasPagamentoAgrupadas[$forma])) {
        $formasPagamentoAgrupadas[$forma] = [
            'forma_pagamento' => $forma,
            'total_recebido' => 0.00,
            'qtd_transacoes' => 0,
            'percentual' => 0.00
        ];
    }
    $formasPagamentoAgrupadas[$forma]['total_recebido'] += $val;
    $formasPagamentoAgrupadas[$forma]['qtd_transacoes']++;
    $resumoGeral['valor_total_recebido'] += $val;
}

// Processa dados por aluno e por turma
foreach ($formandos as $f) {
    $fid = (int)$f['id'];
    $turma = trim($f['turma'] ?: 'Sem Turma');
    $convExtra = (int)$f['convidados_extra'];
    if ($convExtra <= 0) $convExtra = 1;

    $valTotalPagar = $convExtra * $valorExtraGlobal;
    
    // Soma pagamentos do aluno
    $pagsAluno = $pagamentosPorFormando[$fid] ?? [];
    $totalPagoAluno = 0.00;
    foreach ($pagsAluno as $pa) {
        $totalPagoAluno += (float)$pa['valor'];
    }

    $saldoDevAluno = max(0, $valTotalPagar - $totalPagoAluno);

    // Status do Aluno
    $statusAluno = 'pendente';
    if ($totalPagoAluno >= $valTotalPagar && $valTotalPagar > 0) {
        $statusAluno = 'quitado';
        $resumoGeral['qtd_quitados']++;
    } elseif ($totalPagoAluno > 0) {
        $statusAluno = 'parcial';
        $resumoGeral['qtd_parciais']++;
    } else {
        $resumoGeral['qtd_pendentes']++;
    }

    $resumoGeral['total_convidados'] += $convExtra;
    $resumoGeral['valor_total_receber'] += $valTotalPagar;

    // Agrupamento por Turma
    if (!isset($turmasAgrupadas[$turma])) {
        $turmasAgrupadas[$turma] = [
            'turma' => $turma,
            'total_formandos' => 0,
            'total_convidados' => 0,
            'valor_total_receber' => 0.00,
            'valor_total_recebido' => 0.00,
            'valor_total_pendente' => 0.00,
            'percentual_pago' => 0.00,
            'qtd_quitados' => 0,
            'qtd_parciais' => 0,
            'qtd_pendentes' => 0,
            'alunos' => []
        ];
    }

    $turmasAgrupadas[$turma]['total_formandos']++;
    $turmasAgrupadas[$turma]['total_convidados'] += $convExtra;
    $turmasAgrupadas[$turma]['valor_total_receber'] += $valTotalPagar;
    $turmasAgrupadas[$turma]['valor_total_recebido'] += $totalPagoAluno;
    $turmasAgrupadas[$turma]['valor_total_pendente'] += $saldoDevAluno;

    if ($statusAluno === 'quitado') $turmasAgrupadas[$turma]['qtd_quitados']++;
    elseif ($statusAluno === 'parcial') $turmasAgrupadas[$turma]['qtd_parciais']++;
    else $turmasAgrupadas[$turma]['qtd_pendentes']++;

    $turmasAgrupadas[$turma]['alunos'][] = [
        'id' => $fid,
        'numero_aluno' => isset($f['numero_aluno']) && $f['numero_aluno'] !== null ? (int)$f['numero_aluno'] : null,
        'nome' => $f['nome'],
        'cgm' => $f['cgm'],
        'turma' => $turma,
        'telefone' => $f['telefone'],
        'convidados_extra' => $convExtra,
        'valor_total_a_pagar' => $valTotalPagar,
        'total_pago' => $totalPagoAluno,
        'saldo_devedor' => $saldoDevAluno,
        'status' => $statusAluno,
        'qtd_pagamentos' => count($pagsAluno)
    ];
}

// Calcula saldo pendente global e percentual arrecadado
$resumoGeral['valor_total_pendente'] = max(0, $resumoGeral['valor_total_receber'] - $resumoGeral['valor_total_recebido']);
if ($resumoGeral['valor_total_receber'] > 0) {
    $resumoGeral['percentual_arrecadado'] = round(($resumoGeral['valor_total_recebido'] / $resumoGeral['valor_total_receber']) * 100, 1);
}

// Calcula percentual por Forma de Pagamento
$listaFormas = array_values($formasPagamentoAgrupadas);
foreach ($listaFormas as &$fp) {
    if ($resumoGeral['valor_total_recebido'] > 0) {
        $fp['percentual'] = round(($fp['total_recebido'] / $resumoGeral['valor_total_recebido']) * 100, 1);
    }
}

// Ordena formas de pagamento por maior valor arrecadado
usort($listaFormas, function($a, $b) {
    return $b['total_recebido'] <=> $a['total_recebido'];
});

// Calcula percentual e finaliza cada Turma
$listaTurmas = array_values($turmasAgrupadas);
foreach ($listaTurmas as &$t) {
    if ($t['valor_total_receber'] > 0) {
        $t['percentual_pago'] = round(($t['valor_total_recebido'] / $t['valor_total_receber']) * 100, 1);
    }
}

// Ordena turmas por nome
usort($listaTurmas, function($a, $b) {
    return strnatcasecmp($a['turma'], $b['turma']);
});

jsonResponse([
    'status' => 'success',
    'resumo' => $resumoGeral,
    'formas_pagamento' => $listaFormas,
    'turmas' => $listaTurmas,
    'filtros' => [
        'turma' => $turmaFiltro,
        'data_inicio' => $dataInicio,
        'data_fim' => $dataFim
    ]
]);
