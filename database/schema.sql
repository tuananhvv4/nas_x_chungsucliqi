

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'super_admin') NOT NULL DEFAULT 'user',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS integrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    nas_base_url VARCHAR(255) NOT NULL DEFAULT 'https://nastele.online',
    nas_api_key TEXT NULL,
    target_base_url VARCHAR(255) NOT NULL DEFAULT 'https://chungsucliqi.com/api/v1.php',
    target_api_key TEXT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    nas_last_ok_at DATETIME NULL,
    target_last_ok_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_integrations_user (user_id),
    CONSTRAINT fk_integrations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS target_products (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    target_type ENUM('order', 'retail') NOT NULL,
    external_id VARCHAR(100) NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    metadata_json LONGTEXT NULL,
    synced_at DATETIME NOT NULL,
    UNIQUE KEY uq_target_product (user_id, target_type, external_id),
    KEY idx_target_products_user (user_id),
    CONSTRAINT fk_target_products_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS product_mappings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    nas_product_id BIGINT UNSIGNED NOT NULL,
    nas_product_name VARCHAR(255) NOT NULL,
    event_code_field_key VARCHAR(120) NOT NULL,
    phone_field_key VARCHAR(120) NULL,
    note_field_key VARCHAR(120) NULL,
    target_type ENUM('order', 'retail_order') NOT NULL DEFAULT 'order',
    target_product_id BIGINT UNSIGNED NULL,
    target_product_name VARCHAR(255) NULL,
    target_pack VARCHAR(100) NULL,
    target_qty INT UNSIGNED NOT NULL DEFAULT 1,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_mapping_product (user_id, nas_product_id),
    KEY idx_mapping_active (user_id, is_active),
    CONSTRAINT fk_mappings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    mapping_id BIGINT UNSIGNED NULL,
    nas_order_id BIGINT UNSIGNED NOT NULL,
    nas_bot_id BIGINT UNSIGNED NULL,
    nas_product_id BIGINT UNSIGNED NOT NULL,
    nas_product_name VARCHAR(255) NOT NULL,
    assigned_admin VARCHAR(190) NULL,
    customer_telegram_id VARCHAR(50) NULL,
    customer_username VARCHAR(190) NULL,
    customer_first_name VARCHAR(190) NULL,
    customer_last_name VARCHAR(190) NULL,
    total_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
    source_status VARCHAR(40) NOT NULL DEFAULT 'processing',
    processing_status VARCHAR(40) NOT NULL DEFAULT 'received',
    source_sync_status VARCHAR(40) NOT NULL DEFAULT 'pending',
    notification_sent TINYINT(1) NULL,
    source_payload LONGTEXT NULL,
    form_fields_json LONGTEXT NULL,
    event_codes_json LONGTEXT NULL,
    mapping_snapshot_json LONGTEXT NULL,
    last_error TEXT NULL,
    retry_count INT UNSIGNED NOT NULL DEFAULT 0,
    next_retry_at DATETIME NULL,
    ready_at DATETIME NULL,
    source_created_at DATETIME NULL,
    source_updated_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_order_source (user_id, nas_order_id),
    KEY idx_orders_work (user_id, processing_status, next_retry_at),
    KEY idx_orders_product (user_id, nas_product_id),
    CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_orders_mapping FOREIGN KEY (mapping_id) REFERENCES product_mappings(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS target_orders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    client_ref VARCHAR(64) NOT NULL,
    target_type ENUM('order', 'retail_order') NOT NULL,
    target_order_code VARCHAR(100) NULL,
    target_product_id BIGINT UNSIGNED NULL,
    target_pack VARCHAR(100) NULL,
    target_qty INT UNSIGNED NOT NULL DEFAULT 1,
    event_codes_json LONGTEXT NOT NULL,
    price DECIMAL(15,2) NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    progress_percent INT UNSIGNED NULL,
    tickets_done INT UNSIGNED NULL,
    tickets_total INT UNSIGNED NULL,
    message TEXT NULL,
    request_payload LONGTEXT NULL,
    response_payload LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_target_client_ref (client_ref),
    UNIQUE KEY uq_target_code (target_order_code),
    KEY idx_target_status (status),
    CONSTRAINT fk_target_orders_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS sync_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NULL,
    order_id BIGINT UNSIGNED NULL,
    channel VARCHAR(50) NOT NULL,
    level ENUM('info', 'warning', 'error') NOT NULL DEFAULT 'info',
    message VARCHAR(500) NOT NULL,
    context_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_logs_user_created (user_id, created_at),
    KEY idx_logs_order (order_id),
    CONSTRAINT fk_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_logs_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB;

