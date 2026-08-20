<?php
// Simple CORS test endpoint
header('Access-Control-Allow-Origin: https://adhyayanguru.shop');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, Authorization');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    // Preflight request
    http_response_code(204);
    exit;
}

echo json_encode([
    'message' => 'CORS test endpoint',
    'headers' => [
        'Access-Control-Allow-Origin' => 'https://adhyayanguru.shop',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS',
        'Access-Control-Allow-Headers' => 'Origin, X-Requested-With, Content-Type, Accept, Authorization',
        'Access-Control-Allow-Credentials' => 'true',
    ]
]);
?>