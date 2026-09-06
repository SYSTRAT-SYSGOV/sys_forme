<?php
// api/config.php - Conexão PDO MySQL & Auxiliares do Sistema
// Otimizado para Hospedagem Compartilhada HostGator & Auto-Inicialização de Tabelas

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Configurações do Banco de Dados fornecidas pelo usuário para o HostGator
define('DB_HOST', 'localhost');
define('DB_NAME', 'veigap76_formatura');
define('DB_USER', 'veigap76_adminFormatura');
define('DB_PASS', 'Rica126618@1');

// Conexão Singleton PDO
function getPDO() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    try {
        // Tenta conexão MySQL principal (HostGator)
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

        // Auto-inicialização transparente de tabelas e migração de colunas
        initMysqlTables($pdo);

    } catch (PDOException $e) {
        // Fallback local se o servidor MySQL HostGator não for acessível localmente
        try {
            $sqlitePath = __DIR__ . '/../data_formatura.sqlite';
            $pdo = new PDO("sqlite:" . $sqlitePath);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            initSqliteDb($pdo);
        } catch (Exception $ex) {
            jsonResponse([
                'status' => 'error',
                'message' => 'Falha na conexão com o Banco de Dados: ' . $e->getMessage()
            ], 500);
        }
    }
    return $pdo;
}

// Auto-criação das tabelas no MySQL HostGator com migração da coluna 'perfil'
function initMysqlTables($pdo) {
    try {
        // 1. Tabela configuracoes
        $pdo->exec("CREATE TABLE IF NOT EXISTS `configuracoes` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `titulo_formatura` VARCHAR(250) NOT NULL DEFAULT 'Formatura 2026',
          `valor_total_base` DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
          `valor_pessoa_extra` DECIMAL(10,2) NOT NULL DEFAULT 80.00,
          `max_parcelas` INT NOT NULL DEFAULT 12,
          `chave_pix` VARCHAR(250) DEFAULT 'pix@formatura2026.com',
          `chaves_pix` TEXT DEFAULT NULL,
          `formas_pagamento` VARCHAR(500) NOT NULL DEFAULT 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE `configuracoes` ADD COLUMN `formas_pagamento` VARCHAR(500) NOT NULL DEFAULT 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'");
        } catch (Exception $exCols) {}

        try {
            $pdo->exec("ALTER TABLE `configuracoes` ADD COLUMN `chaves_pix` TEXT DEFAULT NULL");
        } catch (Exception $exCols) {}

        // 2. Tabela usuarios (com suporte ao perfil: admin ou comum)
        $pdo->exec("CREATE TABLE IF NOT EXISTS `usuarios` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `usuario` VARCHAR(100) NOT NULL UNIQUE,
          `senha_hash` VARCHAR(255) NOT NULL,
          `nome` VARCHAR(150) NOT NULL,
          `perfil` VARCHAR(20) NOT NULL DEFAULT 'admin',
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Adiciona coluna perfil se tabela usuarios já existia antes
        try {
            $pdo->exec("ALTER TABLE `usuarios` ADD COLUMN `perfil` VARCHAR(20) NOT NULL DEFAULT 'admin'");
        } catch (Exception $exCols) {}

        // 3. Tabela formandos
        $pdo->exec("CREATE TABLE IF NOT EXISTS `formandos` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `numero_aluno` INT DEFAULT NULL,
          `nome` VARCHAR(200) NOT NULL,
          `cgm` VARCHAR(50) DEFAULT NULL,
          `turma` VARCHAR(100) NOT NULL,
          `telefone` VARCHAR(50) DEFAULT NULL,
          `convidados_baseline` INT DEFAULT 0,
          `convidados_extra` INT DEFAULT 0,
          `participa_formatura` INT DEFAULT 1,
          `observacoes` TEXT DEFAULT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE `formandos` ADD COLUMN `participa_formatura` INT DEFAULT 1");
        } catch (Exception $exCols) {}

        try {
            $pdo->exec("ALTER TABLE `formandos` ADD COLUMN `cgm` VARCHAR(50) DEFAULT NULL");
        } catch (Exception $exCols) {}

        try {
            $pdo->exec("ALTER TABLE `formandos` ADD COLUMN `numero_aluno` INT DEFAULT NULL");
        } catch (Exception $exCols) {}

        // 4. Tabela pagamentos
        $pdo->exec("CREATE TABLE IF NOT EXISTS `pagamentos` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `formando_id` INT NOT NULL,
          `numero_parcela` INT NOT NULL,
          `data_pagamento` DATE NOT NULL,
          `valor` DECIMAL(10,2) NOT NULL,
          `forma_pagamento` VARCHAR(50) NOT NULL DEFAULT 'Pix',
          `chave_pix` VARCHAR(250) DEFAULT NULL,
          `observacao` VARCHAR(255) DEFAULT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP,
          FOREIGN KEY (`formando_id`) REFERENCES `formandos`(`id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        try {
            $pdo->exec("ALTER TABLE `pagamentos` ADD COLUMN `chave_pix` VARCHAR(250) DEFAULT NULL");
        } catch (Exception $exCols) {}

        // 5. Tabela turmas
        $pdo->exec("CREATE TABLE IF NOT EXISTS `turmas` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `nome` VARCHAR(100) NOT NULL UNIQUE,
          `observacao` VARCHAR(255) DEFAULT NULL,
          `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $checkTurmas = $pdo->query("SELECT COUNT(*) FROM `turmas`")->fetchColumn();
        if ($checkTurmas == 0) {
            $pdo->exec("INSERT INTO `turmas` (`nome`) VALUES ('3º A'), ('3º B'), ('3º C')");
        }

        // 6. Configuração Inicial
        $checkCfg = $pdo->query("SELECT COUNT(*) FROM `configuracoes`")->fetchColumn();
        if ($checkCfg == 0) {
            $pdo->exec("INSERT INTO `configuracoes` (`id`, `titulo_formatura`, `valor_total_base`, `valor_pessoa_extra`, `max_parcelas`, `chave_pix`) VALUES (1, 'Formatura 2026', 1500.00, 80.00, 12, 'formatura2026@escola.com')");
        }

        // 6. Usuario Admin Padrão (admin / admin123)
        $checkUser = $pdo->query("SELECT COUNT(*) FROM `usuarios`")->fetchColumn();
        if ($checkUser == 0) {
            $hash = password_hash('admin123', PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO `usuarios` (`id`, `usuario`, `senha_hash`, `nome`, `perfil`) VALUES (1, 'admin', ?, 'Administrador', 'admin')");
            $stmt->execute([$hash]);
        }

        // 7. Formandos de exemplo
        $checkFormandos = $pdo->query("SELECT COUNT(*) FROM `formandos`")->fetchColumn();
        if ($checkFormandos == 0) {
            $pdo->exec("INSERT INTO `formandos` (`id`, `nome`, `turma`, `telefone`, `convidados_extra`) VALUES (1, 'Kauan da Silva', '3º B', '(41) 99248-9676', 8)");
            $pdo->exec("INSERT INTO `formandos` (`id`, `nome`, `turma`, `telefone`, `convidados_extra`) VALUES (2, 'Matheus Henrique', '3º B', '(41) 99555-5815', 5)");
            $pdo->exec("INSERT INTO `pagamentos` (`id`, `formando_id`, `numero_parcela`, `data_pagamento`, `valor`, `forma_pagamento`) VALUES (1, 2, 1, CURRENT_DATE, 200.00, 'Pix')");
        }

    } catch (Exception $e) {
        // Ignora erros caso as tabelas já estejam criadas
    }
}

// Inicializador para ambiente de teste local SQLite
function initSqliteDb($pdo) {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS configuracoes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            titulo_formatura TEXT NOT NULL DEFAULT 'Formatura 2026',
            valor_total_base REAL NOT NULL DEFAULT 1500.00,
            valor_pessoa_extra REAL NOT NULL DEFAULT 80.00,
            max_parcelas INTEGER NOT NULL DEFAULT 12,
            chave_pix TEXT DEFAULT 'formatura2026@escola.com',
            chaves_pix TEXT DEFAULT NULL,
            formas_pagamento TEXT NOT NULL DEFAULT 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'
        );

        CREATE TABLE IF NOT EXISTS usuarios (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            usuario TEXT NOT NULL UNIQUE,
            senha_hash TEXT NOT NULL,
            nome TEXT NOT NULL,
            perfil TEXT NOT NULL DEFAULT 'admin',
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS formandos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            numero_aluno INTEGER,
            nome TEXT NOT NULL,
            cgm TEXT,
            turma TEXT NOT NULL,
            telefone TEXT,
            convidados_baseline INTEGER DEFAULT 0,
            convidados_extra INTEGER DEFAULT 0,
            participa_formatura INTEGER DEFAULT 1,
            observacoes TEXT,
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS pagamentos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            formando_id INTEGER NOT NULL,
            numero_parcela INTEGER NOT NULL,
            data_pagamento TEXT NOT NULL,
            valor REAL NOT NULL,
            forma_pagamento TEXT NOT NULL DEFAULT 'Pix',
            chave_pix TEXT DEFAULT NULL,
            observacao TEXT,
            criado_em TEXT DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (formando_id) REFERENCES formandos(id) ON DELETE CASCADE
        );
    ");

    try {
        $pdo->exec("ALTER TABLE formandos ADD COLUMN cgm TEXT");
    } catch (Exception $exCols) {}

    try {
        $pdo->exec("ALTER TABLE formandos ADD COLUMN numero_aluno INTEGER");
    } catch (Exception $exCols) {}

    try {
        $pdo->exec("ALTER TABLE configuracoes ADD COLUMN formas_pagamento TEXT DEFAULT 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'");
    } catch (Exception $exCols) {}

    try {
        $pdo->exec("ALTER TABLE configuracoes ADD COLUMN chaves_pix TEXT DEFAULT NULL");
    } catch (Exception $exCols) {}

    try {
        $pdo->exec("ALTER TABLE pagamentos ADD COLUMN chave_pix TEXT DEFAULT NULL");
    } catch (Exception $exCols) {}

    $stmt = $pdo->query("SELECT COUNT(*) FROM configuracoes");
    if ($stmt->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO configuracoes (id, titulo_formatura, valor_total_base, valor_pessoa_extra, max_parcelas, chave_pix) VALUES (1, 'Formatura 2026', 1500.00, 80.00, 12, 'pix@formatura2026.com')");
    }

    $stmt = $pdo->query("SELECT COUNT(*) FROM usuarios");
    if ($stmt->fetchColumn() == 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $stmtUser = $pdo->prepare("INSERT INTO usuarios (usuario, senha_hash, nome, perfil) VALUES ('admin', ?, 'Administrador', 'admin')");
        $stmtUser->execute([$hash]);

        $pdo->exec("INSERT INTO formandos (nome, turma, telefone, convidados_extra) VALUES ('Kauan da Silva', '3º B', '(41) 99248-9676', 8)");
        $pdo->exec("INSERT INTO formandos (nome, turma, telefone, convidados_extra) VALUES ('Matheus Henrique', '3º B', '(41) 99555-5815', 5)");
        $pdo->exec("INSERT INTO pagamentos (formando_id, numero_parcela, data_pagamento, valor, forma_pagamento) VALUES (2, 1, date('now'), 200.00, 'Pix')");
    }
}

// Auxiliar de Resposta JSON
function jsonResponse($data, $statusCode = 200) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

// Verifica Autenticação
function requireAuth() {
    if (empty($_SESSION['user_id'])) {
        jsonResponse(['status' => 'unauthorized', 'message' => 'Sessão expirada ou não autenticado.'], 401);
    }
    return $_SESSION['user_id'];
}

// Verifica Perfil Administrador
function requireAdmin() {
    requireAuth();
    if (($_SESSION['user_perfil'] ?? 'admin') !== 'admin') {
        jsonResponse(['status' => 'forbidden', 'message' => 'Acesso negado. Apenas administradores podem realizar esta ação.'], 403);
    }
}
