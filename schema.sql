-- Schema do Banco de Dados para Controle de Formatura
-- Compatível com MySQL 5.7+ / MySQL 8.0 / MariaDB (HostGator)

CREATE TABLE IF NOT EXISTS `configuracoes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `titulo_formatura` VARCHAR(250) NOT NULL DEFAULT 'Formatura 2026',
  `valor_total_base` DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
  `valor_pessoa_extra` DECIMAL(10,2) NOT NULL DEFAULT 80.00,
  `max_parcelas` INT NOT NULL DEFAULT 12,
  `chave_pix` VARCHAR(250) DEFAULT 'pix@formatura2026.com',
  `chaves_pix` TEXT DEFAULT NULL,
  `formas_pagamento` VARCHAR(500) NOT NULL DEFAULT 'Pix, Dinheiro, Cartão de Crédito, Cartão de Débito, Boleto'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `usuarios` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `usuario` VARCHAR(100) NOT NULL UNIQUE,
  `senha_hash` VARCHAR(255) NOT NULL,
  `nome` VARCHAR(150) NOT NULL,
  `perfil` VARCHAR(20) NOT NULL DEFAULT 'admin', -- 'admin' ou 'comum'
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `formandos` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `numero_aluno` INT DEFAULT NULL,
  `nome` VARCHAR(200) NOT NULL,
  `cgm` VARCHAR(50) DEFAULT NULL,
  `turma` VARCHAR(100) NOT NULL,
  `telefone` VARCHAR(50) DEFAULT NULL,
  `convidados_baseline` INT DEFAULT 0,
  `convidados_extra` INT DEFAULT 0,
  `observacoes` TEXT DEFAULT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `pagamentos` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `turmas` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `nome` VARCHAR(100) NOT NULL UNIQUE,
  `observacao` VARCHAR(255) DEFAULT NULL,
  `criado_em` DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inserir Configuração Padrão se não existir
INSERT INTO `configuracoes` (`id`, `titulo_formatura`, `valor_total_base`, `valor_pessoa_extra`, `max_parcelas`, `chave_pix`)
SELECT 1, 'Formatura 2026', 1500.00, 80.00, 12, 'formatura2026@escola.com'
WHERE NOT EXISTS (SELECT 1 FROM `configuracoes` WHERE `id` = 1);

-- Inserir Usuário Admin Padrão
INSERT INTO `usuarios` (`id`, `usuario`, `senha_hash`, `nome`, `perfil`)
SELECT 1, 'admin', '$2y$10$wE8wY06Y/gO1X4083z2OQeJ00iR7K4Zt86R4X/j0sR.z8j8Q4w60G', 'Administrador', 'admin'
WHERE NOT EXISTS (SELECT 1 FROM `usuarios` WHERE `usuario` = 'admin');

-- Inserir Turmas Padrão
INSERT INTO `turmas` (`nome`)
SELECT '3º A' WHERE NOT EXISTS (SELECT 1 FROM `turmas` WHERE `nome` = '3º A');
INSERT INTO `turmas` (`nome`)
SELECT '3º B' WHERE NOT EXISTS (SELECT 1 FROM `turmas` WHERE `nome` = '3º B');
INSERT INTO `turmas` (`nome`)
SELECT '3º C' WHERE NOT EXISTS (SELECT 1 FROM `turmas` WHERE `nome` = '3º C');

-- Inserir Dados Exemplo
INSERT INTO `formandos` (`id`, `nome`, `turma`, `telefone`, `convidados_extra`)
SELECT 1, 'Kauan da Silva', '3º B', '(41) 99248-9676', 8
WHERE NOT EXISTS (SELECT 1 FROM `formandos` WHERE `id` = 1);

INSERT INTO `formandos` (`id`, `nome`, `turma`, `telefone`, `convidados_extra`)
SELECT 2, 'Matheus Henrique', '3º B', '(41) 99555-5815', 5
WHERE NOT EXISTS (SELECT 1 FROM `formandos` WHERE `id` = 2);

INSERT INTO `pagamentos` (`formando_id`, `numero_parcela`, `data_pagamento`, `valor`, `forma_pagamento`)
SELECT 2, 1, CURRENT_DATE, 200.00, 'Pix'
WHERE NOT EXISTS (SELECT 1 FROM `pagamentos` WHERE `formando_id` = 2 AND `numero_parcela` = 1);
