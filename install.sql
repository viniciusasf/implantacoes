-- SQL para Instalação do Banco de Dados 'implantacao'
-- Gerado automaticamente para facilitar a configuração do ambiente

CREATE DATABASE IF NOT EXISTS `implantacao` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `implantacao`;

-- 1. TABELA: clientes
-- Armazena os dados principais dos clientes em processo de implantação
CREATE TABLE IF NOT EXISTS `clientes` (
    `id_cliente` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fantasia` VARCHAR(255) NOT NULL,
    `servidor` VARCHAR(100) DEFAULT NULL,
    `vendedor` VARCHAR(100) DEFAULT NULL,
    `telefone_ddd` VARCHAR(20) DEFAULT NULL,
    `data_inicio` DATE DEFAULT NULL,
    `data_fim` DATE DEFAULT NULL,
    `observacao` TEXT DEFAULT NULL,
    `emitir_nf` ENUM('Sim', 'Não') DEFAULT 'Não',
    `configurado` ENUM('Sim', 'Não') DEFAULT 'Não',
    `num_licencas` INT DEFAULT 0,
    `anexo` TEXT DEFAULT NULL,
    `status` ENUM('EM ANDAMENTO', 'CONCLUIDA', 'CANCELADA') DEFAULT 'EM ANDAMENTO',
    `status_tratativa` VARCHAR(50) DEFAULT 'pendente',
    `data_inicio_tratativa` DATE DEFAULT NULL,
    INDEX `idx_status` (`status`),
    INDEX `idx_vendedor` (`vendedor`),
    INDEX `idx_servidor` (`servidor`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. TABELA: contatos
-- Armazena os contatos vinculados aos clientes
CREATE TABLE IF NOT EXISTS `contatos` (
    `id_contato` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_cliente` INT UNSIGNED NOT NULL,
    `nome` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) DEFAULT NULL,
    `telefone_ddd` VARCHAR(20) DEFAULT NULL,
    `setor` VARCHAR(100) DEFAULT NULL,
    `principal` TINYINT(1) DEFAULT 0,
    KEY `idx_id_cliente` (`id_cliente`),
    CONSTRAINT `fk_contatos_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. TABELA: treinamentos
-- Agenda de sessões de capacitação técnica
CREATE TABLE IF NOT EXISTS `treinamentos` (
    `id_treinamento` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_cliente` INT UNSIGNED NOT NULL,
    `id_contato` INT UNSIGNED DEFAULT NULL,
    `tema` VARCHAR(255) DEFAULT NULL,
    `status` VARCHAR(50) DEFAULT 'PENDENTE',
    `data_treinamento` DATETIME DEFAULT NULL,
    `data_treinamento_encerrado` DATETIME DEFAULT NULL,
    `observacoes` TEXT DEFAULT NULL,
    `google_event_id` VARCHAR(255) DEFAULT NULL,
    `google_event_link` TEXT DEFAULT NULL,
    `google_agenda_link` TEXT DEFAULT NULL,
    `tipo_pendencia_encerramento` VARCHAR(50) DEFAULT NULL,
    KEY `idx_id_cliente` (`id_cliente`),
    KEY `idx_id_contato` (`id_contato`),
    INDEX `idx_status_treinamento` (`status`),
    INDEX `idx_data` (`data_treinamento`),
    CONSTRAINT `fk_treinamentos_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. TABELA: observacoes_cliente
-- Histórico permanente e CRM (Timeline de atividades)
CREATE TABLE IF NOT EXISTS `observacoes_cliente` (
    `id_observacao` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_cliente` INT UNSIGNED NOT NULL,
    `titulo` VARCHAR(255) DEFAULT 'Observação Geral',
    `conteudo` TEXT NOT NULL,
    `tipo` ENUM('INFORMAÇÃO', 'AJUSTE', 'PROBLEMA', 'MELHORIA', 'ATUALIZAÇÃO', 'CONTATO') DEFAULT 'INFORMAÇÃO',
    `tags` VARCHAR(255) DEFAULT NULL,
    `registrado_por` VARCHAR(100) DEFAULT 'Sistema',
    `data_observacao` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_id_cliente` (`id_cliente`),
    INDEX `idx_tipo` (`tipo`),
    INDEX `idx_data_obs` (`data_observacao`),
    CONSTRAINT `fk_obs_cliente` FOREIGN KEY (`id_cliente`) REFERENCES `clientes` (`id_cliente`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. TABELA: pendencias_treinamentos
-- Controle de tarefas pendentes em sistemas externos após encerramento do treino
CREATE TABLE IF NOT EXISTS `pendencias_treinamentos` (
    `id_pendencia` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `id_treinamento` INT UNSIGNED NOT NULL,
    `id_cliente` INT UNSIGNED DEFAULT NULL,
    `status_pendencia` VARCHAR(20) NOT NULL DEFAULT 'ABERTA',
    `observacao_finalizacao` TEXT DEFAULT NULL,
    `referencia_chamado` VARCHAR(255) DEFAULT NULL,
    `observacao_conclusao` TEXT DEFAULT NULL,
    `data_criacao` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `data_atualizacao` DATETIME DEFAULT NULL,
    `data_conclusao` DATETIME DEFAULT NULL,
    UNIQUE KEY `uq_pendencia_treinamento` (`id_treinamento`),
    KEY `idx_status_pendencia` (`status_pendencia`),
    KEY `idx_cliente_pendencia` (`id_cliente`),
    CONSTRAINT `fk_pendencia_treinamento` FOREIGN KEY (`id_treinamento`) REFERENCES `treinamentos` (`id_treinamento`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
