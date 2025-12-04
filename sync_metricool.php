<?php
/**
 * Script de sincronización automática con Metricool
 * Ejecutar con cron cada hora: 0 * * * * /usr/bin/php /path/to/sync_metricool.php
 */

require_once 'MetricoolAPI.php';

// Configuración
define('DB_HOST', 'localhost');
define('DB_NAME', 'metricool_dashboard');
define('DB_USER', 'tu_usuario');
define('DB_PASS', 'tu_contraseña');

define('METRICOOL_TOKEN', 'TU_USER_TOKEN');
define('METRICOOL_USER_ID', 'TU_USER_ID');

// Días a sincronizar (últimos N días)
define('SYNC_DAYS', 30);

class MetricoolSync {
    private $pdo;
    private $api;
    private $logger;
    
    public function __construct($pdo, $api) {
        $this->pdo = $pdo;
        $this->api = $api;
        $this->logger = [];
    }
    
    private function log($message, $level = 'INFO') {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[{$timestamp}] [{$level}] {$message}";
        $this->logger[] = $logMessage;
        echo $logMessage . "\n";
    }
    
    /**
     * Sincronizar todas las marcas
     */
    public function syncAll() {
        $startTime = time();
        $this->log("=== Iniciando sincronización completa ===");
        
        try {
            // Obtener marcas
            $brands = $this->api->getBrands();
            $this->log("Encontradas " . count($brands) . " marcas");
            
            foreach ($brands as $brand) {
                $this->syncBrand($brand);
            }
            
            $duration = time() - $startTime;
            $this->log("=== Sincronización completada en {$duration} segundos ===", 'SUCCESS');
            
        } catch (Exception $e) {
            $this->log("Error en sincronización: " . $e->getMessage(), 'ERROR');
            $this->saveSyncHistory(null, 'all', 'error', $e->getMessage());
        }
        
        // Guardar log en archivo
        $this->saveLog();
    }
    
    /**
     * Sincronizar una marca específica
     */
    private function syncBrand($brand) {
        $brandId = $brand['id'];
        $brandName = $brand['name'];
        $startTime = time();
        
        $this->log("--- Sincronizando marca: {$brandName} (ID: {$brandId}) ---");
        
        try {
            // Guardar/actualizar marca en BD
            $this->saveBrand($brand);
            
            // Establecer marca actual
            $this->api->setBlogId($brandId);
            
            // Calcular fechas
            $endDate = date('Y-m-d');
            $startDate = date('Y-m-d', strtotime('-' . SYNC_DAYS . ' days'));
            
            // Sincronizar cada red social
            $networks = [
                'instagram' => 'syncInstagramPosts',
                'facebook' => 'syncFacebookPosts',
                'x' => 'syncXPosts',
                'linkedin' => 'syncLinkedInPosts'
            ];
            
            $totalRecords = 0;
            
            foreach ($networks as $network => $method) {
                try {
                    $count = $this->$method($brandId, $startDate, $endDate);
                    $totalRecords += $count;
                    $this->log("  ✓ {$network}: {$count} posts sincronizados");
                } catch (Exception $e) {
                    $this->log("  ✗ {$network}: " . $e->getMessage(), 'WARN');
                }
            }
            
            // Sincronizar posts programados
            try {
                $scheduledCount = $this->syncScheduledPosts($brandId);
                $this->log("  ✓ Posts programados: {$scheduledCount}");
            } catch (Exception $e) {
                $this->log("  ✗ Posts programados: " . $e->getMessage(), 'WARN');
            }
            
            // Guardar historial
            $duration = time() - $startTime;
            $this->saveSyncHistory($brandId, 'full_sync', 'completed', null, $totalRecords, $duration);
            
        } catch (Exception $e) {
            $this->log("Error sincronizando marca {$brandName}: " . $e->getMessage(), 'ERROR');
            $this->saveSyncHistory($brandId, 'full_sync', 'error', $e->getMessage());
        }
    }
    
    /**
     * Guardar marca en base de datos
     */
    private function saveBrand($brand) {
        $stmt = $this->pdo->prepare("
            INSERT INTO brands (id, name) 
            VALUES (:id, :name)
            ON DUPLICATE KEY UPDATE name = VALUES(name)
        ");
        
        $stmt->execute([
            'id' => $brand['id'],
            'name' => $brand['name']
        ]);
    }
    
    /**
     * Sincronizar posts de Instagram
     */
    private function syncInstagramPosts($brandId, $startDate, $endDate) {
        $posts = $this->api->getInstagramPosts($startDate, $endDate);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO instagram_posts (
                post_id, brand_id, caption, likes, comments, shares, saves, 
                reach, impressions, engagement_rate, post_type, post_url, 
                media_url, published_date
            ) VALUES (
                :post_id, :brand_id, :caption, :likes, :comments, :shares, :saves,
                :reach, :impressions, :engagement_rate, :post_type, :post_url,
                :media_url, :published_date
            )
            ON DUPLICATE KEY UPDATE
                likes = VALUES(likes),
                comments = VALUES(comments),
                shares = VALUES(shares),
                saves = VALUES(saves),
                reach = VALUES(reach),
                impressions = VALUES(impressions),
                engagement_rate = VALUES(engagement_rate)
        ");
        
        foreach ($posts as $post) {
            $stmt->execute([
                'post_id' => $post['id'] ?? uniqid(),
                'brand_id' => $brandId,
                'caption' => $post['caption'] ?? null,
                'likes' => $post['likes'] ?? 0,
                'comments' => $post['comments'] ?? 0,
                'shares' => $post['shares'] ?? 0,
                'saves' => $post['saves'] ?? 0,
                'reach' => $post['reach'] ?? 0,
                'impressions' => $post['impressions'] ?? 0,
                'engagement_rate' => $post['engagement_rate'] ?? 0,
                'post_type' => $post['type'] ?? 'post',
                'post_url' => $post['url'] ?? null,
                'media_url' => $post['media_url'] ?? null,
                'published_date' => $post['date'] ?? date('Y-m-d H:i:s')
            ]);
        }
        
        return count($posts);
    }
    
    /**
     * Sincronizar posts de Facebook
     */
    private function syncFacebookPosts($brandId, $startDate, $endDate) {
        $posts = $this->api->getFacebookPosts($startDate, $endDate);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO facebook_posts (
                post_id, brand_id, message, likes, comments, shares, reactions,
                reach, impressions, engagement_rate, post_type, post_url, published_date
            ) VALUES (
                :post_id, :brand_id, :message, :likes, :comments, :shares, :reactions,
                :reach, :impressions, :engagement_rate, :post_type, :post_url, :published_date
            )
            ON DUPLICATE KEY UPDATE
                likes = VALUES(likes),
                comments = VALUES(comments),
                shares = VALUES(shares),
                reactions = VALUES(reactions),
                reach = VALUES(reach),
                impressions = VALUES(impressions)
        ");
        
        foreach ($posts as $post) {
            $stmt->execute([
                'post_id' => $post['id'] ?? uniqid(),
                'brand_id' => $brandId,
                'message' => $post['message'] ?? null,
                'likes' => $post['likes'] ?? 0,
                'comments' => $post['comments'] ?? 0,
                'shares' => $post['shares'] ?? 0,
                'reactions' => $post['reactions'] ?? 0,
                'reach' => $post['reach'] ?? 0,
                'impressions' => $post['impressions'] ?? 0,
                'engagement_rate' => $post['engagement_rate'] ?? 0,
                'post_type' => $post['type'] ?? 'post',
                'post_url' => $post['url'] ?? null,
                'published_date' => $post['date'] ?? date('Y-m-d H:i:s')
            ]);
        }
        
        return count($posts);
    }
    
    /**
     * Sincronizar posts de X (Twitter)
     */
    private function syncXPosts($brandId, $startDate, $endDate) {
        $posts = $this->api->getXPosts($startDate, $endDate);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO x_posts (
                post_id, brand_id, text, likes, retweets, replies, quotes,
                impressions, engagement_rate, post_url, published_date
            ) VALUES (
                :post_id, :brand_id, :text, :likes, :retweets, :replies, :quotes,
                :impressions, :engagement_rate, :post_url, :published_date
            )
            ON DUPLICATE KEY UPDATE
                likes = VALUES(likes),
                retweets = VALUES(retweets),
                replies = VALUES(replies),
                impressions = VALUES(impressions)
        ");
        
        foreach ($posts as $post) {
            $stmt->execute([
                'post_id' => $post['id'] ?? uniqid(),
                'brand_id' => $brandId,
                'text' => $post['text'] ?? null,
                'likes' => $post['likes'] ?? 0,
                'retweets' => $post['retweets'] ?? 0,
                'replies' => $post['replies'] ?? 0,
                'quotes' => $post['quotes'] ?? 0,
                'impressions' => $post['impressions'] ?? 0,
                'engagement_rate' => $post['engagement_rate'] ?? 0,
                'post_url' => $post['url'] ?? null,
                'published_date' => $post['date'] ?? date('Y-m-d H:i:s')
            ]);
        }
        
        return count($posts);
    }
    
    /**
     * Sincronizar posts de LinkedIn
     */
    private function syncLinkedInPosts($brandId, $startDate, $endDate) {
        $posts = $this->api->getLinkedInPosts($startDate, $endDate);
        
        $stmt = $this->pdo->prepare("
            INSERT INTO linkedin_posts (
                post_id, brand_id, content, likes, comments, shares,
                impressions, engagement_rate, post_url, published_date
            ) VALUES (
                :post_id, :brand_id, :content, :likes, :comments, :shares,
                :impressions, :engagement_rate, :post_url, :published_date
            )
            ON DUPLICATE KEY UPDATE
                likes = VALUES(likes),
                comments = VALUES(comments),
                shares = VALUES(shares),
                impressions = VALUES(impressions)
        ");
        
        foreach ($posts as $post) {
            $stmt->execute([
                'post_id' => $post['id'] ?? uniqid(),
                'brand_id' => $brandId,
                'content' => $post['content'] ?? null,
                'likes' => $post['likes'] ?? 0,
                'comments' => $post['comments'] ?? 0,
                'shares' => $post['shares'] ?? 0,
                'impressions' => $post['impressions'] ?? 0,
                'engagement_rate' => $post['engagement_rate'] ?? 0,
                'post_url' => $post['url'] ?? null,
                'published_date' => $post['date'] ?? date('Y-m-d H:i:s')
            ]);
        }
        
        return count($posts);
    }
    
    /**
     * Sincronizar posts programados
     */
    private function syncScheduledPosts($brandId) {
        $posts = $this->api->getScheduledPosts();
        
        // Primero marcamos todos como cancelados
        $this->pdo->prepare("
            UPDATE scheduled_posts 
            SET status = 'cancelled' 
            WHERE brand_id = :brand_id AND status = 'pending'
        ")->execute(['brand_id' => $brandId]);
        
        // Luego insertamos/actualizamos los posts activos
        $stmt = $this->pdo->prepare("
            INSERT INTO scheduled_posts (
                post_id, brand_id, network, content, media_url, scheduled_date, status
            ) VALUES (
                :post_id, :brand_id, :network, :content, :media_url, :scheduled_date, :status
            )
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                content = VALUES(content),
                scheduled_date = VALUES(scheduled_date)
        ");
        
        foreach ($posts as $post) {
            $stmt->execute([
                'post_id' => $post['id'] ?? uniqid(),
                'brand_id' => $brandId,
                'network' => $post['network'] ?? 'unknown',
                'content' => $post['content'] ?? null,
                'media_url' => $post['media_url'] ?? null,
                'scheduled_date' => $post['scheduled_date'] ?? date('Y-m-d H:i:s'),
                'status' => 'pending'
            ]);
        }
        
        return count($posts);
    }
    
    /**
     * Guardar historial de sincronización
     */
    private function saveSyncHistory($brandId, $syncType, $status, $errorMsg = null, $records = 0, $duration = 0) {
        $stmt = $this->pdo->prepare("
            INSERT INTO sync_history (
                brand_id, sync_type, start_date, end_date, records_processed, 
                status, error_message, duration_seconds
            ) VALUES (
                :brand_id, :sync_type, :start_date, :end_date, :records_processed,
                :status, :error_message, :duration_seconds
            )
        ");
        
        $stmt->execute([
            'brand_id' => $brandId,
            'sync_type' => $syncType,
            'start_date' => date('Y-m-d', strtotime('-' . SYNC_DAYS . ' days')),
            'end_date' => date('Y-m-d'),
            'records_processed' => $records,
            'status' => $status,
            'error_message' => $errorMsg,
            'duration_seconds' => $duration
        ]);
    }
    
    /**
     * Guardar log en archivo
     */
    private function saveLog() {
        $logDir = __DIR__ . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        $logFile = $logDir . '/sync_' . date('Y-m-d') . '.log';
        file_put_contents($logFile, implode("\n", $this->logger) . "\n", FILE_APPEND);
    }
}

// Ejecutar sincronización
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    $api = new MetricoolAPI(METRICOOL_TOKEN, METRICOOL_USER_ID);
    $sync = new MetricoolSync($pdo, $api);
    
    $sync->syncAll();
    
} catch (Exception $e) {
    echo "Error fatal: " . $e->getMessage() . "\n";
    exit(1);
}
