-- Migracion segura para instalaciones existentes.
-- Ejecutar sobre la base configurada antes de publicar la nueva aplicacion.

CREATE TABLE IF NOT EXISTS dashboard_users (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    brand_id INT NULL,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'company',
    display_name VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_dashboard_user_email (email),
    INDEX idx_dashboard_user_brand (brand_id),
    CONSTRAINT fk_dashboard_user_brand
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE
);

-- Las URLs firmadas de Instagram pueden exceder VARCHAR(500).
ALTER TABLE instagram_posts MODIFY COLUMN media_url TEXT NULL;

CREATE TABLE IF NOT EXISTS tiktok_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100) NOT NULL,
    brand_id INT NOT NULL,
    description TEXT,
    likes INT DEFAULT 0,
    comments INT DEFAULT 0,
    shares INT DEFAULT 0,
    views INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    post_type VARCHAR(50),
    post_url VARCHAR(500),
    media_url TEXT,
    duration_seconds INT DEFAULT 0,
    published_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_tiktok_post (post_id, brand_id),
    INDEX idx_tiktok_brand_date (brand_id, published_date),
    CONSTRAINT fk_tiktok_post_brand
        FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE
);

-- La vista existente debe recrearse para incluir TikTok.
CREATE OR REPLACE VIEW dashboard_summary AS
SELECT
    b.id as brand_id,
    b.name as brand_name,
    (SELECT COUNT(*) FROM instagram_posts WHERE brand_id = b.id) as total_instagram_posts,
    (SELECT SUM(likes) FROM instagram_posts WHERE brand_id = b.id) as total_instagram_likes,
    (SELECT COUNT(*) FROM facebook_posts WHERE brand_id = b.id) as total_facebook_posts,
    (SELECT SUM(likes) FROM facebook_posts WHERE brand_id = b.id) as total_facebook_likes,
    (SELECT COUNT(*) FROM tiktok_posts WHERE brand_id = b.id) as total_tiktok_posts,
    (SELECT SUM(likes) FROM tiktok_posts WHERE brand_id = b.id) as total_tiktok_likes,
    (SELECT COUNT(*) FROM scheduled_posts WHERE brand_id = b.id AND status = 'pending') as pending_scheduled
FROM brands b;
