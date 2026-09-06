<?php
// api/limpar_alunos.php - Script Standalone para Limpeza Geral da Lista de Alunos
require_once __DIR__ . '/config.php';

$pdo = getPDO();

// Verifica se o usuário é administrador
requireAdmin();

$confirm = $_REQUEST['confirm'] ?? null;

if ($confirm === 'sim' || $_SERVER['REQUEST_METHOD'] === 'POST' || $_SERVER['REQUEST_METHOD'] === 'DELETE') {
    try {
        $pdo->exec("DELETE FROM pagamentos");
        $pdo->exec("DELETE FROM formandos");

        $isJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
        if ($isJson || isset($_GET['json'])) {
            jsonResponse([
                'status' => 'success',
                'message' => '🧹 Todos os alunos e pagamentos cadastrados foram excluídos com sucesso!'
            ]);
        } else {
            echo "<!DOCTYPE html>
            <html lang='pt-BR'>
            <head>
                <meta charset='UTF-8'>
                <title>Limpeza Concluída</title>
                <style>
                    body { font-family: sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
                    .card { background: #1e293b; padding: 2rem; border-radius: 12px; text-align: center; border: 1px solid #334155; max-width: 450px; }
                    .btn { display: inline-block; margin-top: 1.5rem; background: #6366f1; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: bold; }
                </style>
            </head>
            <body>
                <div class='card'>
                    <h2 style='color: #34d399;'>✨ Limpeza Concluída!</h2>
                    <p>Todos os alunos e histórico de pagamentos foram removidos do banco de dados.</p>
                    <a href='../public/' class='btn'>Voltar ao Sistema</a>
                </div>
            </body>
            </html>";
            exit;
        }
    } catch (Exception $e) {
        jsonResponse(['status' => 'error', 'message' => 'Erro ao limpar banco: ' . $e->getMessage()], 500);
    }
} else {
    // Se acessado diretamente pelo navegador via GET sem confirmação, exibe aviso
    echo "<!DOCTYPE html>
    <html lang='pt-BR'>
    <head>
        <meta charset='UTF-8'>
        <title>Confirmar Limpeza de Alunos</title>
        <style>
            body { font-family: sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
            .card { background: #1e293b; padding: 2rem; border-radius: 12px; text-align: center; border: 1px solid #ef4444; max-width: 450px; }
            .btn-danger { background: #ef4444; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: bold; margin-right: 0.5rem; }
            .btn-secondary { background: #475569; color: white; padding: 0.75rem 1.5rem; border-radius: 8px; text-decoration: none; font-weight: bold; }
        </style>
    </head>
    <body>
        <div class='card'>
            <h2 style='color: #fca5a5;'>⚠️ Confirmar Exclusão de Todos os Alunos?</h2>
            <p>Esta ação apagará <strong>TODOS OS ALUNOS CADASTRADOS</strong> e seus históricos de pagamentos de forma irreversível.</p>
            <div style='margin-top: 1.5rem;'>
                <a href='limpar_alunos.php?confirm=sim' class='btn-danger'>🗑️ Sim, Limpar Tudo</a>
                <a href='../public/' class='btn-secondary'>Cancelar</a>
            </div>
        </div>
    </body>
    </html>";
}
