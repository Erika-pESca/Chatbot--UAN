<?php
/**
 * ============================================================================
 * SCRIPT DE DIAGNÓSTICO - Streaming SSE con Gemini
 * Subir al servidor, abrir en el navegador directamente.
 * Ver la consola del navegador F12 para logs completos.
 * ============================================================================
 */

set_time_limit(0);
ignore_user_abort(false);

// === CONFIGURACIÓN DE PRUEBA ===
$TEST_API_KEY   = "TU_API_KEY_AQUI";
$TEST_MODEL     = "gemini-1.5-flash";
$TEST_PROMPT    = "Di exactamente esto: Prueba de streaming exitosa desde el servidor.";
$LOG_FILE       = __DIR__ . '/diagnostico_log.txt';

file_put_contents($LOG_FILE, "=== DIAGNOSTICO " . date('Y-m-d H:i:s') . " ===\n");

function logD($msg) {
    global $LOG_FILE;
    file_put_contents($LOG_FILE, date('H:i:s') . " - $msg\n", FILE_APPEND);
    echo "data: " . json_encode(["log" => $msg]) . "\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
}

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-transform');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');
header('Content-Encoding: none');
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }
@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', 1);
while (ob_get_level()) { ob_end_flush(); }
ob_implicit_flush(true);

echo ":" . str_repeat(" ", 65536) . "\n\n";
echo "event: ping\ndata: {\"status\": \"iniciando\"}\n\n";
if (ob_get_level() > 0) ob_flush(); flush();

logD("PHP OK. Version: " . PHP_VERSION);
logD("set_time_limit(0) aplicado");

// TEST 1: Conectividad SSL
logD("TEST 1: Conectividad SSL a googleapis.com:443...");
$conn = @fsockopen("ssl://generativelanguage.googleapis.com", 443, $errno, $errstr, 8);
if ($conn) {
    fclose($conn);
    logD("TEST 1 OK: Conectividad SSL funciona");
} else {
    logD("TEST 1 FALLO: No llega a googleapis.com - errno=$errno errstr=$errstr");
    logD("CAUSA: Firewall del servidor bloquea salida a internet (googleapis.com:443)");
}

// TEST 2: cURL info
logD("TEST 2: cURL extension...");
if (function_exists('curl_init')) {
    $cv = curl_version();
    logD("TEST 2 OK: cURL " . $cv['version'] . " SSL: " . $cv['ssl_version']);
} else {
    logD("TEST 2 FALLO: cURL no disponible");
}

// TEST 3: Llamada sincrona Gemini
logD("TEST 3: Llamada sincrona a Gemini...");
$url_s = "https://generativelanguage.googleapis.com/v1beta/models/$TEST_MODEL:generateContent?key=$TEST_API_KEY";
$ch_s = curl_init($url_s);
curl_setopt_array($ch_s, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(["contents" => [["parts" => [["text" => "Di: OK"]]]],"generationConfig" => ["maxOutputTokens" => 10]]),
    CURLOPT_TIMEOUT        => 20,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp_s   = curl_exec($ch_s);
$http_s   = curl_getinfo($ch_s, CURLINFO_HTTP_CODE);
$err_s    = curl_error($ch_s);
$errno_s  = curl_errno($ch_s);
curl_close($ch_s);

logD("TEST 3 HTTP=$http_s errno=$errno_s err=" . ($err_s ?: 'ninguno'));
if ($resp_s) {
    $d = json_decode($resp_s, true);
    $api_err = $d['error']['message'] ?? null;
    $txt = $d['candidates'][0]['content']['parts'][0]['text'] ?? 'sin texto';
    if ($api_err) {
        logD("TEST 3 API ERROR: $api_err");
    } else {
        logD("TEST 3 OK: Gemini respondio -> '$txt'");
    }
} else {
    logD("TEST 3 SIN RESPUESTA. errno=$errno_s");
}

// TEST 4: Llamada streaming Gemini
logD("TEST 4: Llamada STREAMING a Gemini...");
$url_str = "https://generativelanguage.googleapis.com/v1beta/models/$TEST_MODEL:streamGenerateContent?alt=sse&key=$TEST_API_KEY";
$raw = ""; $nchunks = 0;
$ch_str = curl_init($url_str);
curl_setopt_array($ch_str, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(["contents" => [["parts" => [["text" => $TEST_PROMPT]]]],"generationConfig" => ["maxOutputTokens" => 50,"temperature" => 0.1]]),
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_WRITEFUNCTION  => function($ch, $chunk) use (&$raw, &$nchunks) {
        $raw .= $chunk;
        $nchunks++;
        return strlen($chunk);
    }
]);
curl_exec($ch_str);
$http_str  = curl_getinfo($ch_str, CURLINFO_HTTP_CODE);
$err_str   = curl_error($ch_str);
$errno_str = curl_errno($ch_str);
curl_close($ch_str);

logD("TEST 4 HTTP=$http_str errno=$errno_str err=" . ($err_str ?: 'ninguno'));
logD("TEST 4 chunks=$nchunks bytes=" . strlen($raw));
if ($raw) {
    logD("TEST 4 RAW (primeros 300 chars): " . json_encode(substr($raw, 0, 300)));
} else {
    logD("TEST 4 GEMINI NO DEVOLVIO NADA - posible firewall o timeout");
}

logD("=== FIN DIAGNOSTICO ===");

echo "event: done\ndata: " . json_encode(["resultado" => "completado"]) . "\n\n";
if (ob_get_level() > 0) ob_flush(); flush();
