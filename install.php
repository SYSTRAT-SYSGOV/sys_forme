<?php
// install.php - Assistente de Instalação Automática para HostGator
$message = '';
$statusType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? 'veigap76_formatura');
    $dbUser = trim($_POST['db_user'] ?? 'veigap76_adminFormatura');
    $dbPass = trim($_POST['db_pass'] ?? 'Rica126618@1');
    $adminPass = trim($_POST['admin_pass'] ?? 'admin123');

    try {
        $dsn = "mysql:host=$dbHost;charset=utf8mb4";
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);

        // Cria o banco de dados se não existir
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbName`");

        // Executa o Schema
        $schemaSql = file_get_contents(__DIR__ . '/schema.sql');
        $pdo->exec($schemaSql);

        // Atualiza a senha do admin se especificada
        if (!empty($adminPass)) {
            $hash = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE usuarios SET senha_hash = ? WHERE usuario = 'admin'");
            $stmt->execute([$hash]);
        }

        // Atualiza arquivo api/config.php com as credenciais inseridas
        $configContent = '<?php
// api/config.php - Gerado pelo Instalador HostGator
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define("DB_HOST", "' . $dbHost . '");
define("DB_NAME", "' . $dbName . '");
define("DB_USER", "' . $dbUser . '");
define("DB_PASS", "' . $dbPass . '");

function getPDO() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        jsonResponse(["status" => "error", "message" => "Erro de Banco de Dados: " . $e->getMessage()], 500);
    }
    return $pdo;
}

function jsonResponse($data, $statusCode = 200) {
    header("Content-Type: application/json; charset=utf-8");
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function requireAuth() {
    if (empty($_SESSION["user_id"])) {
        jsonResponse(["status" => "unauthorized", "message" => "Sessão expirada."], 401);
    }
    return $_SESSION["user_id"];
}
';
        file_put_contents(__DIR__ . '/api/config.php', $configContent);

        $message = "🎉 Instalação concluída com sucesso! Banco `$dbName` configurado e tabelas criadas.";
        $statusType = "success";
    } catch (Exception $e) {
        $message = "❌ Erro na instalação: " . $e->getMessage();
        $statusType = "danger";
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <title>Instalação do Sistema de Formatura - HostGator</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f172a; color: #f8fafc; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .box { background: #1e293b; padding: 2rem; border-radius: 16px; border: 1px solid rgba(255,255,255,0.1); width: 100%; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.5); }
        h2 { margin-top: 0; color: #818cf8; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; margin-bottom: 0.3rem; font-size: 0.9rem; color: #94a3b8; }
        input { width: 100%; padding: 0.75rem; border-radius: 8px; border: 1px solid rgba(255,255,255,0.1); background: #0f172a; color: #fff; box-sizing: border-box; }
        button { width: 100%; padding: 0.8rem; border-radius: 8px; border: none; background: #10b981; color: white; font-weight: bold; font-size: 1rem; cursor: pointer; }
        button:hover { background: #059669; }
        .alert { padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; text-align: center; font-weight: bold; }
        .alert-success { background: rgba(16, 185, 129, 0.2); color: #34d399; border: 1px solid #10b981; }
        .alert-danger { background: rgba(239, 68, 68, 0.2); color: #fca5a5; border: 1px solid #ef4444; }
        .btn-app { display: block; text-align: center; text-decoration: none; background: #6366f1; color: white; padding: 0.8rem; border-radius: 8px; margin-top: 1rem; font-weight: bold; }
    </style>
</head>
<body>
    <div class="box">
        <h2>🚀 Instalador - HostGator</h2>
        <p style="color: #94a3b8; font-size: 0.9rem;">Preencha os dados do banco MySQL criados no cPanel da HostGator.</p>

        <?php if ($message): ?>
            <div class="alert alert-<?= $statusType ?>">
                <?= $message ?>
            </div>
            <?php if ($statusType === 'success'): ?>
                <a href="public/index.php" class="btn-app">👉 Acessar o Sistema de Formatura</a>
            <?php endif; ?>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Servidor MySQL (Host)</label>
                <input type="text" name="db_host" value="localhost" required>
            </div>
            <div class="form-group">
                <label>Nome do Banco de Dados</label>
                <input type="text" name="db_name" value="veigap76_formatura" required>
            </div>
            <div class="form-group">
                <label>Usuário do Banco MySQL</label>
                <input type="text" name="db_user" value="veigap76_adminFormatura" required>
            </div>
            <div class="form-group">
                <label>Senha do Banco MySQL</label>
                <input type="password" name="db_pass" value="Rica126618@1" required>
            </div>
            <div class="form-group">
                <label>Senha do Usuário Administrador (Usuário: admin)</label>
                <input type="password" name="admin_pass" value="admin123" required>
            </div>
            <button type="submit">Executar Instalação Automática</button>
        </form>
    </div>
</body>
</html>
