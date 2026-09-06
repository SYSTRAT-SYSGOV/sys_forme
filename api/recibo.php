<?php
// api/recibo.php - Recibo de Pagamento Oficial de Formatura
// Otimizado para Hospedagem Compartilhada HostGator & Compatibilidade Universal (PHP 8+)

ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/config.php';

$fpdfPath = __DIR__ . '/../lib/fpdf/fpdf.php';
$hasFpdf = file_exists($fpdfPath);
if ($hasFpdf) {
    require_once $fpdfPath;
}

// Função auxiliar de conversão UTF-8 para ISO-8859-1 (FPDF) de forma 100% segura
if (!function_exists('toIso')) {
    function toIso($text) {
        if ($text === null || $text === '') return '';
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding((string)$text, 'ISO-8859-1', 'UTF-8');
        }
        return utf8_decode((string)$text);
    }
}

if (!function_exists('formatarTelefonePdf')) {
    function formatarTelefonePdf($fone) {
        if (!$fone) return 'Não informado';
        $digits = preg_replace('/\D/', '', (string)$fone);
        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        } elseif (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }
        return $fone;
    }
}

if (!function_exists('generatePixPayloadPHP')) {
    function generatePixPayloadPHP($chavePix, $nomeRecebedor = 'FORMATURA 2026', $cidade = 'CURITIBA', $valor = 0, $txid = '***') {
        if (empty($chavePix)) return '';
        $chavePix = trim($chavePix);
        $nomeRecebedor = strtoupper(substr(preg_replace('/[^a-zA-Z0-9 ]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $nomeRecebedor)), 0, 25)) ?: 'FORMATURA 2026';
        $cidade = strtoupper(substr(preg_replace('/[^a-zA-Z0-9 ]/', '', iconv('UTF-8', 'ASCII//TRANSLIT', $cidade)), 0, 15)) ?: 'CURITIBA';

        $formatTLV = function($id, $value) {
            $len = str_pad(strlen($value), 2, '0', STR_PAD_LEFT);
            return $id . $len . $value;
        };

        $gui = $formatTLV('00', 'br.gov.bcb.pix');
        $key = $formatTLV('01', $chavePix);
        $merchantAccountInfo = $formatTLV('26', $gui . $key);

        $payloadFormatIndicator = $formatTLV('00', '01');
        $merchantCategoryCode = $formatTLV('52', '0000');
        $transactionCurrency = $formatTLV('53', '986');
        $transactionAmount = $valor > 0 ? $formatTLV('54', number_format($valor, 2, '.', '')) : '';
        $countryCode = $formatTLV('58', 'BR');
        $merchantName = $formatTLV('59', $nomeRecebedor);
        $merchantCity = $formatTLV('60', $cidade);
        $additionalDataField = $formatTLV('62', $formatTLV('05', $txid));

        $raw = $payloadFormatIndicator . $merchantAccountInfo . $merchantCategoryCode . $transactionCurrency . $transactionAmount . $countryCode . $merchantName . $merchantCity . $additionalDataField . '6304';

        $crc = 0xFFFF;
        for ($i = 0; $i < strlen($raw); $i++) {
            $crc ^= (ord($raw[$i]) << 8);
            for ($j = 0; $j < 8; $j++) {
                if (($crc & 0x8000) !== 0) {
                    $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
                } else {
                    $crc = ($crc << 1) & 0xFFFF;
                }
            }
        }
        return $raw . strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
    }
}

function renderErrorPage($msg) {
    while (ob_get_level()) {
        ob_end_clean();
    }
    http_response_code(200);
    echo '<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Recibo de Formatura</title>';
    echo '<style>body{font-family:sans-serif;background:#0f172a;color:#fff;display:grid;place-items:center;height:100vh;margin:0;padding:1rem;}';
    echo '.box{background:#1e293b;padding:2rem;border-radius:16px;border:1px solid rgba(239, 68, 68, 0.4);max-width:500px;text-align:center;box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5);}';
    echo 'h2{color:#fca5a5;margin-top:0;} p{color:#cbd5e1;line-height:1.6;} .btn-group{display:flex;gap:0.75rem;justify-content:center;margin-top:1.5rem;flex-wrap:wrap;}';
    echo '.btn-main{background:#6366f1;color:#fff;padding:0.75rem 1.25rem;border-radius:8px;text-decoration:none;font-weight:bold;}';
    echo '.btn-close{background:rgba(255,255,255,0.1);color:#cbd5e1;padding:0.75rem 1.25rem;border-radius:8px;border:1px solid rgba(255,255,255,0.2);cursor:pointer;font-weight:bold;}</style></head><body>';
    echo '<div class="box"><h2>⚠️ Não foi possível gerar o recibo</h2><p>' . htmlspecialchars($msg) . '</p>';
    echo '<div class="btn-group">';
    echo '<a href="../public/index.php" class="btn-main">← Voltar ao Sistema</a>';
    echo '<button onclick="window.close()" class="btn-close">✖ Fechar Aba</button>';
    echo '</div></div></body></html>';
    exit;
}

$rawId = trim($_GET['id'] ?? '0');
$outputMode = trim($_GET['output'] ?? 'html'); // 'html' (padrão universal) ou 'pdf' (FPDF binário)

try {
    $pdo = getPDO();

    // 1. Busca pelo ID do pagamento
    $pagamento = null;
    if (!empty($rawId) && $rawId !== '0') {
        $stmt = $pdo->prepare("
            SELECT p.*, f.nome as formando_nome, f.cgm, f.numero_aluno, f.turma, f.telefone, f.convidados_extra
            FROM pagamentos p
            JOIN formandos f ON p.formando_id = f.id
            WHERE p.id = ?
        ");
        $stmt->execute([$rawId]);
        $pagamento = $stmt->fetch();
    }

    // 2. Fallback: Pega o último pagamento se não achou pelo ID
    if (!$pagamento) {
        $stmtFallback = $pdo->query("
            SELECT p.*, f.nome as formando_nome, f.cgm, f.numero_aluno, f.turma, f.telefone, f.convidados_extra
            FROM pagamentos p
            JOIN formandos f ON p.formando_id = f.id
            ORDER BY p.id DESC LIMIT 1
        ");
        $pagamento = $stmtFallback->fetch();
    }

    if (!$pagamento) {
        renderErrorPage("Nenhum registro de pagamento foi encontrado no banco de dados.");
    }

    // Busca configurações globais
    $stmtConfig = $pdo->query("SELECT * FROM configuracoes WHERE id = 1");
    $config = $stmtConfig->fetch() ?: [
        'titulo_formatura' => 'Formatura 2026',
        'valor_total_base' => 1500.00,
        'valor_pessoa_extra' => 80.00,
        'chave_pix' => 'formatura2026@escola.com'
    ];

    // Calcula totais do formando
    $stmtTotais = $pdo->prepare("SELECT SUM(valor) FROM pagamentos WHERE formando_id = ?");
    $stmtTotais->execute([$pagamento['formando_id']]);
    $totalPagoAteAgora = (float)$stmtTotais->fetchColumn();

    $valorExtraUnit = (float)($config['valor_pessoa_extra'] ?? 80.00);
    if ($valorExtraUnit <= 0 && (float)($config['valor_total_base'] ?? 0) > 0) {
        $valorExtraUnit = (float)$config['valor_total_base'];
    }
    $convExtra = (int)$pagamento['convidados_extra'];
    if ($convExtra <= 0) $convExtra = 1;
    $valorTotalComExtras = $convExtra * $valorExtraUnit;
    $saldoDevedor = max(0, $valorTotalComExtras - $totalPagoAteAgora);

    // MODO PDF BINÁRIO FPDF (SE SOLICITADO & DISPONÍVEL)
    if ($outputMode === 'pdf' && $hasFpdf) {
        if (!class_exists('PDF_Recibo')) {
            #[\AllowDynamicProperties]
            class PDF_Recibo extends FPDF {
                function Header() {
                    $this->SetFillColor(30, 41, 59);
                    $this->Rect(0, 0, 210, 28, 'F');
                    $this->SetFont('Helvetica', 'B', 16);
                    $this->SetTextColor(255, 255, 255);
                    $this->SetY(8);
                    $this->Cell(0, 8, toIso('RECIBO DE PAGAMENTO'), 0, 1, 'C');
                    $this->SetFont('Helvetica', '', 10);
                    $this->Cell(0, 5, toIso('COMISSÃO DE FORMATURA - 2026'), 0, 1, 'C');
                    $this->Ln(10);
                }
                function Footer() {
                    $this->SetY(-18);
                    $this->SetFont('Helvetica', 'I', 8);
                    $this->SetTextColor(100, 116, 139);
                    $this->Cell(0, 4, toIso('Recibo gerado eletronicamente pelo Sistema de Controle de Formatura'), 0, 1, 'C');
                }
            }
        }

        $pdf = new PDF_Recibo('P', 'mm', 'A4');
        $pdf->SetTitle(toIso('Recibo #' . $pagamento['id'] . ' - ' . $pagamento['formando_nome']));
        $pdf->SetMargins(15, 15, 15);
        $pdf->AddPage();

        // Card Identificação
        $pdf->SetFillColor(241, 245, 249);
        $pdf->SetDrawColor(203, 213, 225);
        $pdf->Rect(15, 35, 180, 22, 'DF');
        $pdf->SetY(38);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(15, 23, 42);
        $pdf->Cell(90, 6, toIso('RECIBO Nº: #' . str_pad($pagamento['id'], 6, '0', STR_PAD_LEFT)), 0, 0, 'L');
        $pdf->Cell(90, 6, toIso('EMISSÃO: ' . date('d/m/Y H:i')), 0, 1, 'R');

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(90, 6, toIso('PARCELA Nº: ' . $pagamento['numero_parcela'] . 'ª Parcela'), 0, 0, 'L');
        $pdf->Cell(90, 6, toIso('FORMA DE PAGTO: ' . strtoupper($pagamento['forma_pagamento'])), 0, 1, 'R');

        $pdf->Ln(8);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 8, toIso('DADOS DO FORMANDO(A)'), 'B', 1, 'L');

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(40, 7, toIso('Formando(a):'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(140, 7, toIso($pagamento['formando_nome']), 0, 1, 'L');

        if (!empty($pagamento['cgm'])) {
            $pdf->SetFont('Helvetica', '', 11);
            $pdf->Cell(40, 7, toIso('CGM:'), 0, 0, 'L');
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->Cell(140, 7, toIso($pagamento['cgm']), 0, 1, 'L');
        }

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(40, 7, toIso('Turma:'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(50, 7, toIso($pagamento['turma']), 0, 0, 'L');

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->Cell(40, 7, toIso('Telefone:'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(50, 7, toIso(formatarTelefonePdf($pagamento['telefone'])), 0, 1, 'L');

        $pdf->Ln(6);
        $pdf->SetFillColor(236, 253, 245);
        $pdf->SetDrawColor(16, 185, 129);
        $pdf->Rect(15, $pdf->GetY(), 180, 24, 'DF');

        $yPag = $pdf->GetY() + 4;
        $pdf->SetY($yPag);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->SetTextColor(6, 78, 59);
        $pdf->Cell(90, 7, toIso('VALOR PAGO NESTA PARCELA:'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 14);
        $pdf->Cell(90, 7, toIso('R$ ' . number_format($pagamento['valor'], 2, ',', '.')), 0, 1, 'R');

        $pdf->Ln(15);
        $pdf->SetFont('Helvetica', 'B', 12);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(0, 8, toIso('RESUMO FINANCEIRO ATUALIZADO'), 'B', 1, 'L');

        $pdf->Ln(3);
        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(120, 6, toIso('Valor Total da Formatura (Base + Extra):'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->Cell(60, 6, toIso('R$ ' . number_format($valorTotalComExtras, 2, ',', '.')), 0, 1, 'R');

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->Cell(120, 6, toIso('Total Acumulado Pago até o Momento:'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor(16, 185, 129);
        $pdf->Cell(60, 6, toIso('R$ ' . number_format($totalPagoAteAgora, 2, ',', '.')), 0, 1, 'R');

        $pdf->SetFont('Helvetica', '', 10);
        $pdf->SetTextColor(30, 41, 59);
        $pdf->Cell(120, 6, toIso('Saldo Restante a Pagar:'), 0, 0, 'L');
        $pdf->SetFont('Helvetica', 'B', 10);
        $pdf->SetTextColor($saldoDevedor > 0 ? 225 : 16, $saldoDevedor > 0 ? 29 : 185, $saldoDevedor > 0 ? 72 : 129);
        $pdf->Cell(60, 6, toIso('R$ ' . number_format($saldoDevedor, 2, ',', '.')), 0, 1, 'R');

        $pdfData = $pdf->Output('S');

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($pdfData));
        header('Content-Disposition: inline; filename="recibo_parcela_' . $pagamento['numero_parcela'] . '.pdf"');
        echo $pdfData;
        exit;
    }

    // MODO HTML OFICIAL UNIVERSAL (RECOMENDADO / IMPRESSÃO DIRETA & SALVAR EM PDF)
    while (ob_get_level()) {
        ob_end_clean();
    }

    $foneRaw = preg_replace('/\D/', '', (string)$pagamento['telefone']);
    $foneClean = (strlen($foneRaw) > 0 && !str_starts_with($foneRaw, '55')) ? '55' . $foneRaw : $foneRaw;
    $valFormated = number_format($pagamento['valor'], 2, ',', '.');
    $dateFormated = date('d/m/Y', strtotime($pagamento['data_pagamento']));
    $saldoFormated = number_format($saldoDevedor, 2, ',', '.');

    $cgmMsg = !empty($pagamento['cgm']) ? "📋 *CGM:* " . $pagamento['cgm'] . "\n" : "";
    $waMessage = rawurlencode(
        "🎓 *RECIBO DE PAGAMENTO - FORMATURA 2026*\n\n" .
        "Olá *" . $pagamento['formando_nome'] . "*!\n" .
        $cgmMsg .
        "Confirmamos o recebimento da sua *" . $pagamento['numero_parcela'] . "ª Parcela*.\n\n" .
        "💵 *Valor Pago:* R$ " . $valFormated . "\n" .
        "📅 *Data:* " . $dateFormated . "\n" .
        "💳 *Forma:* " . $pagamento['forma_pagamento'] . "\n" .
        "📊 *Saldo Restante a Pagar:* R$ " . $saldoFormated . "\n\n" .
        "Obrigado! Comissão Organizadora de Formatura."
    );
    $waUrl = !empty($foneClean) ? "https://api.whatsapp.com/send?phone={$foneClean}&text={$waMessage}" : "#";

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recibo #<?= str_pad($pagamento['id'], 6, '0', STR_PAD_LEFT) ?> - <?= htmlspecialchars($pagamento['formando_nome']) ?></title>
    <style>
        :root {
            --primary: #4f46e5;
            --success: #10b981;
            --slate-800: #1e293b;
            --slate-100: #f1f5f9;
        }
        * { box-sizing: border-box; font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; }
        body { background: #0f172a; color: #334155; margin: 0; padding: 20px; display: flex; flex-direction: column; align-items: center; min-height: 100vh; }
        
        /* Barra de Ações (Oculta na Impressão) */
        .actions-bar {
            width: 100%;
            max-width: 800px;
            background: #1e293b;
            border: 1px solid rgba(255,255,255,0.1);
            padding: 1rem 1.5rem;
            border-radius: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.5);
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.75rem 1.25rem;
            border-radius: 8px;
            font-weight: 700;
            font-size: 0.9rem;
            text-decoration: none;
            cursor: pointer;
            border: none;
            transition: all 0.2s;
        }
        .btn-print { background: #6366f1; color: #fff; }
        .btn-print:hover { background: #4f46e5; transform: translateY(-1px); }
        .btn-wa { background: #25D366; color: #fff; }
        .btn-wa:hover { background: #1eb956; transform: translateY(-1px); }
        .btn-close { background: rgba(255,255,255,0.1); color: #cbd5e1; border: 1px solid rgba(255,255,255,0.2); }
        
        /* Folha do Recibo */
        .receipt-card {
            width: 100%;
            max-width: 800px;
            background: #ffffff;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);
            color: #0f172a;
        }

        .receipt-header {
            background: #1e293b;
            color: #ffffff;
            margin: -40px -40px 30px -40px;
            padding: 25px;
            text-align: center;
            border-top-left-radius: 12px;
            border-top-right-radius: 12px;
        }
        .receipt-header h1 { margin: 0; font-size: 1.6rem; letter-spacing: 1px; }
        .receipt-header p { margin: 5px 0 0 0; font-size: 0.85rem; color: #94a3b8; }

        .meta-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            margin-bottom: 25px;
            font-size: 0.95rem;
        }
        .meta-box strong { color: #0f172a; }

        .section-title {
            font-size: 1.05rem;
            font-weight: 700;
            color: #1e293b;
            border-bottom: 2px solid #cbd5e1;
            padding-bottom: 6px;
            margin-bottom: 15px;
            text-transform: uppercase;
        }

        .data-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 20px; margin-bottom: 25px; }
        .data-item label { display: block; font-size: 0.8rem; color: #64748b; text-transform: uppercase; font-weight: 600; }
        .data-item span { font-size: 1.05rem; font-weight: 600; color: #0f172a; }

        .payment-highlight {
            background: #ecfdf5;
            border: 2px solid #10b981;
            border-radius: 10px;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }
        .payment-highlight .title { font-size: 1rem; font-weight: 700; color: #064e3b; }
        .payment-highlight .amount { font-size: 1.8rem; font-weight: 900; color: #059669; }

        .summary-table { width: 100%; border-collapse: collapse; margin-bottom: 40px; }
        .summary-table td { padding: 10px 0; border-bottom: 1px solid #f1f5f9; font-size: 0.95rem; }
        .summary-table tr:last-child td { border-bottom: none; }
        .summary-table .val { text-align: right; font-weight: 700; }

        .signatures { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 50px; text-align: center; }
        .sig-line { border-top: 1px solid #94a3b8; padding-top: 8px; font-size: 0.85rem; color: #475569; }

        @media print {
            body { background: #fff; padding: 0; }
            .actions-bar { display: none !important; }
            .receipt-card { box-shadow: none; border-radius: 0; padding: 20px; max-width: 100%; }
            .receipt-header { border-radius: 0; }
        }
    </style>
</head>
<body>

    <!-- Barra de Ações Rápidas -->
    <div class="actions-bar">
        <div>
            <span style="color:#a5b4fc; font-weight:bold; font-size:0.9rem;">Recibo de Pagamento Gerado</span>
        </div>
        <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
            <button onclick="window.print()" class="btn btn-print">🖨️ Imprimir / Salvar em PDF</button>
            <?php if (!empty($foneClean)): ?>
                <a href="<?= $waUrl ?>" target="_blank" class="btn btn-wa">📲 Enviar via WhatsApp</a>
            <?php endif; ?>
            <button onclick="window.close()" class="btn btn-close">✖ Fechar Aba</button>
        </div>
    </div>

    <!-- Folha Oficial do Recibo -->
    <div class="receipt-card">
        <div class="receipt-header">
            <h1>RECIBO DE PAGAMENTO</h1>
            <p>COMISSÃO DE FORMATURA - 2026</p>
        </div>

        <div class="meta-box">
            <div>RECIBO Nº: <strong>#<?= str_pad($pagamento['id'], 6, '0', STR_PAD_LEFT) ?></strong></div>
            <div>PARCELA: <strong><?= $pagamento['numero_parcela'] ?>ª Parcela</strong></div>
            <div>EMISSÃO: <strong><?= date('d/m/Y H:i') ?></strong></div>
        </div>

        <div class="section-title">Dados do Formando(a)</div>
        <div class="data-grid">
            <div class="data-item">
                <label>Formando(a)</label>
                <span>🎓 <?= htmlspecialchars($pagamento['formando_nome']) ?></span>
            </div>
            <?php if (!empty($pagamento['numero_aluno'])): ?>
            <div class="data-item">
                <label>Nº na Chamada</label>
                <span>#<?= htmlspecialchars($pagamento['numero_aluno']) ?></span>
            </div>
            <?php endif; ?>
            <div class="data-item">
                <label>Nº do CGM</label>
                <span><?= htmlspecialchars($pagamento['cgm'] ?: 'Não informado') ?></span>
            </div>
            <div class="data-item">
                <label>Turma</label>
                <span><?= htmlspecialchars($pagamento['turma']) ?></span>
            </div>
            <div class="data-item">
                <label>Telefone</label>
                <span><?= htmlspecialchars(formatarTelefonePdf($pagamento['telefone'])) ?></span>
            </div>
            <div class="data-item">
                <label>Qtd. de Pessoas</label>
                <span><?= $convExtra ?> pessoa(s)</span>
            </div>
        </div>

        <div class="payment-highlight">
            <div>
                <div class="title">VALOR PAGO NESTA PARCELA</div>
                <div style="font-size:0.85rem; color:#047857; margin-top:2px;">
                    Data: <?= $dateFormated ?> | Forma: <?= htmlspecialchars($pagamento['forma_pagamento']) ?>
                </div>
            </div>
            <div class="amount">R$ <?= $valFormated ?></div>
        </div>

        <?php 
        $isPixForma = str_contains(strtolower($pagamento['forma_pagamento']), 'pix');
        if ($isPixForma):
            $effectivePixKey = !empty($pagamento['chave_pix']) ? $pagamento['chave_pix'] : ($config['chave_pix'] ?: 'formatura2026@escola.com');
            $reciboPixPayload = generatePixPayloadPHP(
                $effectivePixKey,
                $config['titulo_formatura'] ?: 'FORMATURA 2026',
                'CURITIBA',
                (float)($pagamento['valor'] ?? 0)
            );
        ?>
        <div style="background:#ecfdf5; border:1px dashed #10b981; border-radius:10px; padding:15px; text-align:center; margin-bottom:25px;">
            <div style="font-weight:700; color:#064e3b; margin-bottom:4px; font-size:0.95rem;">📱 QR Code Pix Válido (Padrão Banco Central BR Code)</div>
            <p style="font-size:0.8rem; color:#047857; margin:0 0 8px 0;">Escaneie direto no app do seu banco (Nubank, Itaú, BB, Inter, etc.)</p>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=<?= urlencode($reciboPixPayload) ?>" alt="QR Code Pix Oficial" style="width:130px; height:130px; border-radius:8px; background:#fff; padding:5px; border:1px solid #cbd5e1; margin-bottom:8px;">
            <div style="font-size:0.85rem; color:#0f172a;">Chave Pix: <strong><?= htmlspecialchars($effectivePixKey) ?></strong></div>
        </div>
        <?php endif; ?>

        <div class="section-title">Resumo Financeiro Atualizado</div>
        <table class="summary-table">
            <tr>
                <td>Valor Total da Formatura (<?= $convExtra ?> pessoa(s) × R$ <?= number_format($valorExtraUnit, 2, ',', '.') ?>):</td>
                <td class="val">R$ <?= number_format($valorTotalComExtras, 2, ',', '.') ?></td>
            </tr>
            <tr>
                <td>Total Acumulado Pago até o Momento:</td>
                <td class="val" style="color:#10b981;">R$ <?= number_format($totalPagoAteAgora, 2, ',', '.') ?></td>
            </tr>
            <tr>
                <td style="font-weight:700;">Saldo Restante a Pagar:</td>
                <td class="val" style="color:<?= $saldoDevedor > 0 ? '#ef4444' : '#10b981' ?>;">R$ <?= $saldoFormated ?></td>
            </tr>
        </table>

        <div class="signatures">
            <div class="sig-line">
                <strong>Comissão Organizadora de Formatura</strong><br>
                Assinatura Responsável
            </div>
            <div class="sig-line">
                <strong><?= htmlspecialchars($pagamento['formando_nome']) ?></strong><br>
                Formando(a)
            </div>
        </div>
    </div>

    <script>
        // Aciona a caixa de diálogo de impressão / salvar em PDF automaticamente ao carregar
        window.addEventListener('load', function() {
            setTimeout(function() {
                window.print();
            }, 300);
        });
    </script>
</body>
</html>
<?php
} catch (Throwable $e) {
    renderErrorPage("Erro no PHP ao gerar recibo: " . $e->getMessage() . " em " . basename($e->getFile()) . " linha " . $e->getLine());
}
