-- Base de datos para Dashboard de Metricool
CREATE DATABASE IF NOT EXISTS metricool_dashboard CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE metricool_dashboard;

-- Tabla de marcas/brands
CREATE TABLE brands (
    id INT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY (id)
);

-- Tabla de posts de Instagram
CREATE TABLE instagram_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100) NOT NULL,
    brand_id INT NOT NULL,
    caption TEXT,
    likes INT DEFAULT 0,
    comments INT DEFAULT 0,
    shares INT DEFAULT 0,
    saves INT DEFAULT 0,
    reach INT DEFAULT 0,
    impressions INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    post_type VARCHAR(50),
    post_url VARCHAR(500),
    media_url VARCHAR(500),
    published_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (post_id, brand_id),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_date (brand_id, published_date),
    INDEX idx_published_date (published_date)
);

-- Tabla de posts de Facebook
CREATE TABLE facebook_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100) NOT NULL,
    brand_id INT NOT NULL,
    message TEXT,
    likes INT DEFAULT 0,
    comments INT DEFAULT 0,
    shares INT DEFAULT 0,
    reactions INT DEFAULT 0,
    reach INT DEFAULT 0,
    impressions INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    post_type VARCHAR(50),
    post_url VARCHAR(500),
    published_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (post_id, brand_id),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_date (brand_id, published_date)
);

-- Tabla de posts de X (Twitter)
CREATE TABLE x_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100) NOT NULL,
    brand_id INT NOT NULL,
    text TEXT,
    likes INT DEFAULT 0,
    retweets INT DEFAULT 0,
    replies INT DEFAULT 0,
    quotes INT DEFAULT 0,
    impressions INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    post_url VARCHAR(500),
    published_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (post_id, brand_id),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_date (brand_id, published_date)
);

-- Tabla de posts de LinkedIn
CREATE TABLE linkedin_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100) NOT NULL,
    brand_id INT NOT NULL,
    content TEXT,
    likes INT DEFAULT 0,
    comments INT DEFAULT 0,
    shares INT DEFAULT 0,
    impressions INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    post_url VARCHAR(500),
    published_date DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_post (post_id, brand_id),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_date (brand_id, published_date)
);

-- Tabla de analytics diarios por red social
CREATE TABLE daily_analytics (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    brand_id INT NOT NULL,
    network VARCHAR(50) NOT NULL,
    date DATE NOT NULL,
    followers INT DEFAULT 0,
    following INT DEFAULT 0,
    posts_count INT DEFAULT 0,
    likes INT DEFAULT 0,
    comments INT DEFAULT 0,
    shares INT DEFAULT 0,
    reach INT DEFAULT 0,
    impressions INT DEFAULT 0,
    engagement_rate DECIMAL(5,2) DEFAULT 0,
    profile_views INT DEFAULT 0,
    website_clicks INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_analytics (brand_id, network, date),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_network_date (brand_id, network, date)
);

-- Tabla de posts programados
CREATE TABLE scheduled_posts (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    post_id VARCHAR(100),
    brand_id INT NOT NULL,
    network VARCHAR(50) NOT NULL,
    content TEXT,
    media_url VARCHAR(500),
    scheduled_date DATETIME NOT NULL,
    status VARCHAR(50) DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_scheduled (brand_id, scheduled_date),
    INDEX idx_status (status)
);

-- Tabla de competidores
CREATE TABLE competitors (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    brand_id INT NOT NULL,
    network VARCHAR(50) NOT NULL,
    competitor_name VARCHAR(255) NOT NULL,
    competitor_handle VARCHAR(255),
    competitor_url VARCHAR(500),
    followers INT DEFAULT 0,
    posts_count INT DEFAULT 0,
    last_synced DATETIME,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_competitor (brand_id, network, competitor_handle),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_network (brand_id, network)
);

-- Tabla de histórico de sincronización
CREATE TABLE sync_history (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    brand_id INT NOT NULL,
    sync_type VARCHAR(100) NOT NULL,
    start_date DATE,
    end_date DATE,
    records_processed INT DEFAULT 0,
    status VARCHAR(50) DEFAULT 'completed',
    error_message TEXT,
    duration_seconds INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    INDEX idx_brand_type (brand_id, sync_type, created_at)
);

-- Vista para resumen general
CREATE VIEW dashboard_summary AS
SELECT 
    b.id as brand_id,
    b.name as brand_name,
    (SELECT COUNT(*) FROM instagram_posts WHERE brand_id = b.id) as total_instagram_posts,
    (SELECT SUM(likes) FROM instagram_posts WHERE brand_id = b.id) as total_instagram_likes,
    (SELECT COUNT(*) FROM facebook_posts WHERE brand_id = b.id) as total_facebook_posts,
    (SELECT SUM(likes) FROM facebook_posts WHERE brand_id = b.id) as total_facebook_likes,
    (SELECT COUNT(*) FROM x_posts WHERE brand_id = b.id) as total_x_posts,
    (SELECT COUNT(*) FROM scheduled_posts WHERE brand_id = b.id AND status = 'pending') as pending_scheduled
FROM brands b;

-- Vista para posts con mejor rendimiento (Instagram)
CREATE VIEW top_instagram_posts AS
SELECT 
    ip.*,
    b.name as brand_name,
    (ip.likes + ip.comments * 2 + ip.saves * 3) as engagement_score
FROM instagram_posts ip
JOIN brands b ON ip.brand_id = b.id
ORDER BY engagement_score DESC
LIMIT 100;

-- Procedimiento para limpiar datos antiguos (opcional)
DELIMITER //
CREATE PROCEDURE cleanup_old_data(IN days_to_keep INT)
BEGIN
    DELETE FROM instagram_posts WHERE published_date < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    DELETE FROM facebook_posts WHERE published_date < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    DELETE FROM x_posts WHERE published_date < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    DELETE FROM linkedin_posts WHERE published_date < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
    DELETE FROM daily_analytics WHERE date < DATE_SUB(CURDATE(), INTERVAL days_to_keep DAY);
    DELETE FROM sync_history WHERE created_at < DATE_SUB(NOW(), INTERVAL days_to_keep DAY);
END //
DELIMITER ;
