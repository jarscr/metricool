<?php
require_once 'MetricoolAPI.php';

// Configuración - Obtener estos valores desde Metricool Account Settings > API
$userToken = 'TU_USER_TOKEN_AQUI';
$userId = 'TU_USER_ID_AQUI';

try {
    // Inicializar API
    $metricool = new MetricoolAPI($userToken, $userId);
    
    // 1. Obtener todas las marcas
    echo "=== Obteniendo marcas ===\n";
    $brands = $metricool->getBrands();
    
    if (empty($brands)) {
        die("No se encontraron marcas\n");
    }
    
    // Mostrar marcas disponibles
    foreach ($brands as $brand) {
        echo "ID: {$brand['id']} - Nombre: {$brand['name']}\n";
    }
    
    // Seleccionar la primera marca (o la que necesites)
    $blogId = $brands[0]['id'];
    $metricool->setBlogId($blogId);
    
    echo "\n=== Trabajando con marca: {$brands[0]['name']} ===\n\n";
    
    // 2. Definir rango de fechas (últimos 30 días)
    $endDate = date('Y-m-d');
    $startDate = date('Y-m-d', strtotime('-30 days'));
    
    // 3. Obtener datos de Instagram
    echo "=== Posts de Instagram ===\n";
    $instagramPosts = $metricool->getInstagramPosts($startDate, $endDate);
    echo "Total de posts: " . count($instagramPosts) . "\n";
    
    // Mostrar algunos posts
    foreach (array_slice($instagramPosts, 0, 3) as $post) {
        echo "- {$post['caption']} (Likes: {$post['likes']}, Comentarios: {$post['comments']})\n";
    }
    
    // 4. Obtener analytics de Instagram
    echo "\n=== Analytics de Instagram ===\n";
    $analytics = $metricool->getAnalytics('instagram', $startDate, $endDate);
    echo "Followers: {$analytics['followers']}\n";
    echo "Engagement: {$analytics['engagement_rate']}%\n";
    echo "Alcance: {$analytics['reach']}\n";
    
    // 5. Obtener mejor hora para publicar
    echo "\n=== Mejor hora para publicar ===\n";
    $bestTime = $metricool->getBestTimeToPost('instagram');
    print_r($bestTime);
    
    // 6. Obtener posts programados
    echo "\n=== Posts programados ===\n";
    $scheduledPosts = $metricool->getScheduledPosts();
    echo "Posts programados: " . count($scheduledPosts) . "\n";
    
    // 7. Crear un resumen para dashboard
    $dashboardData = [
        'brand_name' => $brands[0]['name'],
        'period' => "{$startDate} a {$endDate}",
        'instagram' => [
            'total_posts' => count($instagramPosts),
            'followers' => $analytics['followers'] ?? 0,
            'engagement_rate' => $analytics['engagement_rate'] ?? 0,
            'reach' => $analytics['reach'] ?? 0
        ],
        'scheduled_posts' => count($scheduledPosts)
    ];
    
    // Guardar datos en JSON para usar en dashboard
    file_put_contents('dashboard_data.json', json_encode($dashboardData, JSON_PRETTY_PRINT));
    echo "\n✓ Datos guardados en dashboard_data.json\n";
    
    // 8. Guardar datos completos en base de datos (ejemplo con PDO)
    /*
    $pdo = new PDO('mysql:host=localhost;dbname=metricool_dashboard', 'usuario', 'contraseña');
    
    // Guardar posts de Instagram
    $stmt = $pdo->prepare("
        INSERT INTO instagram_posts (post_id, caption, likes, comments, date, brand_id) 
        VALUES (:post_id, :caption, :likes, :comments, :date, :brand_id)
        ON DUPLICATE KEY UPDATE 
        likes = VALUES(likes), 
        comments = VALUES(comments)
    ");
    
    foreach ($instagramPosts as $post) {
        $stmt->execute([
            'post_id' => $post['id'],
            'caption' => $post['caption'],
            'likes' => $post['likes'],
            'comments' => $post['comments'],
            'date' => $post['date'],
            'brand_id' => $blogId
        ]);
    }
    */
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
