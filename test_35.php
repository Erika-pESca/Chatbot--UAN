<?php
$mensaje = 'hola';
$api_key = 'TU_API_KEY_AQUI';
$api_model = 'gemini-3.5-flash-lite';
$url = "https://generativelanguage.googleapis.com/v1beta/models/" . $api_model . ":generateContent?key=" . $api_key;
$payload = [
    "contents" => [
        [
            "role" => "user",
            "parts" => [["text" => $mensaje]]
        ]
    ]
];
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false
]);
$resp = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP: $http_code\n";
echo "RESPUESTA: $resp\n";
