-- ============================================================
-- CONFERÊNCIA DE ROMANEIOS — SCHEMA
-- ============================================================

CREATE TABLE IF NOT EXISTS `conferencias` (
  `id`                 INT           AUTO_INCREMENT PRIMARY KEY,
  `id_romaneio`        INT           NOT NULL,
  `data_saida`         VARCHAR(20)   DEFAULT NULL,
  `placa`              VARCHAR(20)   DEFAULT NULL,
  `motorista`          VARCHAR(100)  DEFAULT NULL,
  `transportadora`     VARCHAR(100)  DEFAULT NULL,
  `status`             ENUM('em_andamento','finalizada') NOT NULL DEFAULT 'em_andamento',
  `total_itens`        INT           NOT NULL DEFAULT 0,
  `total_conferidos`   INT           NOT NULL DEFAULT 0,
  `total_divergencias` INT           NOT NULL DEFAULT 0,
  `created_at`         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
  `updated_at`         TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_romaneio` (`id_romaneio`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `conferencia_itens` (
  `id`              INT            AUTO_INCREMENT PRIMARY KEY,
  `id_conferencia`  INT            NOT NULL,
  `codigo_produto`  VARCHAR(60)    NOT NULL,
  `descricao`       VARCHAR(255)   DEFAULT NULL,
  `ncm`             VARCHAR(20)    DEFAULT NULL,
  `unidade`         VARCHAR(10)    DEFAULT NULL,
  `qtd_esperada`    DECIMAL(12,4)  NOT NULL DEFAULT 0,
  `qtd_conferida`   DECIMAL(12,4)  DEFAULT NULL,
  `status`          ENUM('pendente','ok','divergencia') NOT NULL DEFAULT 'pendente',
  `observacao`      TEXT           DEFAULT NULL,
  `updated_at`      TIMESTAMP      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_conf_prod` (`id_conferencia`, `codigo_produto`),
  FOREIGN KEY (`id_conferencia`) REFERENCES `conferencias`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
