<?php
/**
 * Clase para interactuar con la API de Metricool
 * Documentación: https://app.metricool.com/resources/apidocs/
 */
class MetricoolAPI {
    private $baseUrl = 'https://app.metricool.com/api';
    private $userToken;
    private $userId;
    private $blogId;
    
    /**
     * Constructor
     * @param string $userToken Token de autenticación (desde Account Settings > API)
     * @param string $userId ID del usuario
     * @param string $blogId ID de la marca (opcional, se puede setear después)
     */
    public function __construct($userToken, $userId, $blogId = null) {
        $this->userToken = $userToken;
        $this->userId = $userId;
        $this->blogId = $blogId;
    }
    
    /**
     * Establecer el blogId (ID de marca)
     */
    public function setBlogId($blogId) {
        $this->blogId = $blogId;
    }
    
    /**
     * Realizar una petición GET a la API
     */
    private function makeRequest($endpoint, $params = []) {
        // Agregar parámetros obligatorios
        $params['userId'] = $this->userId;
        if ($this->blogId) {
            $params['blogId'] = $this->blogId;
        }
        
        // Construir URL con parámetros
        $url = $this->baseUrl . $endpoint . '?' . http_build_query($params);
        
        // Configurar headers
        $headers = [
            'X-Mc-Auth: ' . $this->userToken,
            'Content-Type: application/json'
        ];
        
        // Inicializar cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        // Ejecutar petición
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Manejar errores
        if ($error) {
            throw new Exception("Error en cURL: " . $error);
        }
        
        if ($httpCode !== 200) {
            throw new Exception("Error HTTP {$httpCode}: " . $response);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Obtener todas las marcas del usuario
     */
    public function getBrands() {
        return $this->makeRequest('/admin/simpleProfiles');
    }
    
    /**
     * Obtener posts de Instagram
     * @param string $startDate Fecha inicio (Y-m-d)
     * @param string $endDate Fecha fin (Y-m-d)
     */
    public function getInstagramPosts($startDate, $endDate) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido. Use setBlogId()");
        }
        
        return $this->makeRequest('/instagram/posts', [
            'init_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
    
    /**
     * Obtener posts de Facebook
     */
    public function getFacebookPosts($startDate, $endDate) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest('/facebook/posts', [
            'init_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
    
    /**
     * Obtener posts de X (Twitter)
     */
    public function getXPosts($startDate, $endDate) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest('/x/posts', [
            'init_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
    
    /**
     * Obtener posts de LinkedIn
     */
    public function getLinkedInPosts($startDate, $endDate) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest('/linkedin/posts', [
            'init_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
    
    /**
     * Obtener analytics de una red social específica
     * @param string $network Nombre de la red (instagram, facebook, twitter, etc)
     * @param string $startDate Fecha inicio
     * @param string $endDate Fecha fin
     */
    public function getAnalytics($network, $startDate, $endDate) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest("/{$network}/analytics", [
            'init_date' => $startDate,
            'end_date' => $endDate
        ]);
    }
    
    /**
     * Obtener posts programados
     */
    public function getScheduledPosts() {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest('/scheduler/posts');
    }
    
    /**
     * Obtener competidores
     */
    public function getCompetitors() {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest('/competitors/list');
    }
    
    /**
     * Obtener mejor hora para publicar
     * @param string $network Red social (instagram, facebook, etc)
     */
    public function getBestTimeToPost($network) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest("/{$network}/best-time-to-post");
    }
    
    /**
     * Obtener métricas disponibles para una red social
     */
    public function getAvailableMetrics($network) {
        if (!$this->blogId) {
            throw new Exception("blogId es requerido");
        }
        
        return $this->makeRequest("/{$network}/available-metrics");
    }
}
