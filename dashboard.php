<?php
require_once 'MetricoolAPI.php';

// Configuración
$userToken = 'TU_USER_TOKEN_AQUI';
$userId = 'TU_USER_ID_AQUI';

// Inicializar variables
$dashboardData = [];
$error = null;

try {
    $metricool = new MetricoolAPI($userToken, $userId);
    $brands = $metricool->getBrands();
    
    if (!empty($brands)) {
        $blogId = $brands[0]['id'];
        $metricool->setBlogId($blogId);
        
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
        
        // Obtener datos de todas las redes
        $instagramPosts = $metricool->getInstagramPosts($startDate, $endDate);
        $facebookPosts = $metricool->getFacebookPosts($startDate, $endDate);
        $scheduledPosts = $metricool->getScheduledPosts();
        
        $dashboardData = [
            'brand' => $brands[0],
            'instagram' => [
                'posts' => $instagramPosts,
                'total' => count($instagramPosts),
                'total_likes' => array_sum(array_column($instagramPosts, 'likes')),
                'total_comments' => array_sum(array_column($instagramPosts, 'comments'))
            ],
            'facebook' => [
                'posts' => $facebookPosts,
                'total' => count($facebookPosts)
            ],
            'scheduled' => count($scheduledPosts),
            'period' => ['start' => $startDate, 'end' => $endDate]
        ];
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Metricool</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            border-radius: 15px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            color: #667eea;
            margin-bottom: 10px;
        }
        
        .header .brand-info {
            color: #666;
            font-size: 14px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            transition: transform 0.3s;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
        }
        
        .stat-card .label {
            color: #888;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 36px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-card.instagram { border-left: 5px solid #E1306C; }
        .stat-card.facebook { border-left: 5px solid #1877F2; }
        .stat-card.scheduled { border-left: 5px solid #10B981; }
        
        .posts-section {
            background: white;
            border-radius: 15px;
            padding: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .posts-section h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .post-card {
            border: 1px solid #eee;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        
        .post-card:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .post-card .post-caption {
            color: #333;
            margin-bottom: 10px;
            line-height: 1.5;
        }
        
        .post-card .post-stats {
            display: flex;
            gap: 20px;
            color: #666;
            font-size: 14px;
        }
        
        .post-card .post-stats span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .error-box {
            background: #fee;
            border: 1px solid #fcc;
            color: #c00;
            padding: 20px;
            border-radius: 10px;
            margin: 20px 0;
        }
        
        .loading {
            text-align: center;
            padding: 50px;
            color: white;
            font-size: 18px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($error): ?>
            <div class="error-box">
                <strong>Error:</strong> <?= htmlspecialchars($error) ?>
            </div>
        <?php elseif (empty($dashboardData)): ?>
            <div class="loading">Cargando datos...</div>
        <?php else: ?>
            
            <div class="header">
                <h1>📊 Dashboard de Metricool</h1>
                <div class="brand-info">
                    Marca: <strong><?= htmlspecialchars($dashboardData['brand']['name']) ?></strong> | 
                    Período: <?= $dashboardData['period']['start'] ?> - <?= $dashboardData['period']['end'] ?>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card instagram">
                    <div class="label">📸 Posts de Instagram</div>
                    <div class="value"><?= $dashboardData['instagram']['total'] ?></div>
                </div>
                
                <div class="stat-card instagram">
                    <div class="label">❤️ Total de Likes</div>
                    <div class="value"><?= number_format($dashboardData['instagram']['total_likes']) ?></div>
                </div>
                
                <div class="stat-card instagram">
                    <div class="label">💬 Total de Comentarios</div>
                    <div class="value"><?= number_format($dashboardData['instagram']['total_comments']) ?></div>
                </div>
                
                <div class="stat-card facebook">
                    <div class="label">📘 Posts de Facebook</div>
                    <div class="value"><?= $dashboardData['facebook']['total'] ?></div>
                </div>
                
                <div class="stat-card scheduled">
                    <div class="label">⏰ Posts Programados</div>
                    <div class="value"><?= $dashboardData['scheduled'] ?></div>
                </div>
            </div>
            
            <div class="posts-section">
                <h2>📱 Últimos Posts de Instagram</h2>
                <?php foreach (array_slice($dashboardData['instagram']['posts'], 0, 10) as $post): ?>
                    <div class="post-card">
                        <div class="post-caption">
                            <?= htmlspecialchars(substr($post['caption'] ?? 'Sin descripción', 0, 200)) ?>
                            <?= strlen($post['caption'] ?? '') > 200 ? '...' : '' ?>
                        </div>
                        <div class="post-stats">
                            <span>❤️ <?= number_format($post['likes'] ?? 0) ?> likes</span>
                            <span>💬 <?= number_format($post['comments'] ?? 0) ?> comentarios</span>
                            <span>📅 <?= date('d/m/Y', strtotime($post['date'] ?? 'now')) ?></span>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-refresh cada 5 minutos
        setTimeout(() => {
            location.reload();
        }, 300000);
    </script>
</body>
</html>
