<?php
/**
 * Spider Híbrido Avanzado (Curación y Clasificación por IA JSON)
 * Flujo: Scraping -> Filtros -> Análisis IA -> Clasificación -> Detección Duplicados -> Organización -> Actualización
 */

set_time_limit(180);
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (ob_get_level() == 0) ob_start();
ob_implicit_flush(1);

$md_file = __DIR__ . '/base_conocimiento.md';
$urls_file = __DIR__ . '/rutas_oficiales.txt';
$ignore_file = __DIR__ . '/rutas_ignoradas.txt';

if (file_exists(__DIR__ . '/claves.php')) {
    require_once __DIR__ . '/claves.php';
} else {
    $API_KEYS = [];
}


function llamar_gemini_json($prompt) {
    global $API_KEYS;
    $api = $API_KEYS[array_rand($API_KEYS)];
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . $api['model'] . ":generateContent?key=" . $api['key'];
    
    $payload = [
        "contents" => [["parts" => [["text" => $prompt]]]],
        "generationConfig" => ["temperature" => 0.1, "responseMimeType" => "application/json"]
    ];
    
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE),
        CURLOPT_TIMEOUT => 40,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    
    $res = curl_exec($ch);
    curl_close($ch);
    
    $datos = json_decode($res, true);
    if (isset($datos['candidates'][0]['content']['parts'][0]['text'])) {
        $json_text = $datos['candidates'][0]['content']['parts'][0]['text'];
        // A veces la IA envuelve en markdown de código
        $json_text = preg_replace('/```json\s*/i', '', $json_text);
        $json_text = preg_replace('/```\s*/i', '', $json_text);
        return json_decode(trim($json_text), true);
    }
    return null;
}

function es_ruta_prohibida($url) {
    $prohibidas = ['/noticias', '/noticia', '/eventos', '/galeria', '/resoluciones', '/actas', '/blog', '/comunicados'];
    $extensiones = ['.pdf', '.jpg', '.png', '.doc', '.docx', '.zip', '.rar', '.xls'];
    $url_lower = strtolower($url);
    
    foreach ($extensiones as $ext) { if (substr($url_lower, -strlen($ext)) === $ext) return true; }
    foreach ($prohibidas as $p) { if (strpos($url_lower, $p) !== false) return true; }
    if (strpos($url_lower, 'uan.edu.co') === false) return true;
    
    return false;
}

function extraer_datos_web($url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_USERAGENT => 'UAN-RAG-Bot/2.0',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) return null;

    $enlaces = [];
    preg_match_all('/href=["\'](https?:\/\/[^"\']+)["\']/i', $html, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $link) {
            $link = strtok($link, '#');
            $enlaces[] = rtrim($link, '/');
        }
    }

    $html = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', "", $html);
    $html = preg_replace('/<style\b[^>]*>(.*?)<\/style>/is', "", $html);
    $html = preg_replace('/<nav\b[^>]*>(.*?)<\/nav>/is', "", $html);
    $html = preg_replace('/<header\b[^>]*>(.*?)<\/header>/is', "", $html);
    $html = preg_replace('/<footer\b[^>]*>(.*?)<\/footer>/is', "", $html);
    
    $text = strip_tags($html);
    $text = preg_replace('/\s+/', ' ', $text);
    return ['texto' => trim($text), 'enlaces' => array_unique($enlaces)];
}

function cargar_lista($archivo) {
    if (!file_exists($archivo)) return [];
    $lineas = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $lista = [];
    foreach ($lineas as $l) {
        $u = trim($l);
        if (!empty($u)) $lista[$u] = true;
    }
    return $lista;
}

function guardar_lista($archivo, $lista_array) {
    file_put_contents($archivo, implode(PHP_EOL, array_keys($lista_array)));
}

function armar_bloque_md($ruta_id, $json_ia, $url) {
    $bloque = "\n---\n\n## RUTA: " . $ruta_id . "\n";
    $bloque .= "**Público Objetivo:** " . htmlspecialchars($json_ia['publico_objetivo']) . "\n";
    $bloque .= "**Descripción Oficial:** " . htmlspecialchars($json_ia['descripcion']) . "\n";
    $bloque .= "**Palabras Clave (SEO interno):** " . htmlspecialchars($json_ia['palabras_clave']) . "\n";
    $bloque .= "**Enlaces Oficiales:** [Información Oficial](" . $url . ")\n";
    return $bloque;
}

echo "<h2>Spider RAG UAN (Análisis y Actualización JSON)</h2>";

$oficiales = cargar_lista($urls_file);
$ignoradas = cargar_lista($ignore_file);
$contenido_md = file_exists($md_file) ? file_get_contents($md_file) : "";
$bloques_existentes = explode('---', $contenido_md);

// --- 1. FASE DE AUDITORÍA (MÚLTIPLES RUTAS) ---
if (empty($oficiales)) die("No hay rutas oficiales.");

// Elegir hasta 5 rutas al azar para auditar en esta ejecución
$rutas_a_auditar = array_rand($oficiales, min(5, count($oficiales)));
if (!is_array($rutas_a_auditar)) $rutas_a_auditar = [$rutas_a_auditar];

$candidatos_totales = [];

foreach ($rutas_a_auditar as $url_auditar) {
    echo "<hr><b>🕵️‍♂️ AUDITANDO URL CONOCIDA:</b> <a href='$url_auditar' target='_blank'>$url_auditar</a><br>";
    ob_flush(); flush();

$datos_web = extraer_datos_web($url_auditar);
if ($datos_web) {
    // Buscar si ya tenemos un bloque para esta URL
    $bloque_actual = "";
    $idx_bloque = -1;
    foreach ($bloques_existentes as $idx => $b) {
        if (strpos($b, "(" . $url_auditar . ")") !== false) {
            $bloque_actual = trim($b);
            $idx_bloque = $idx;
            break;
        }
    }
    
    $texto_recortado = mb_substr($datos_web['texto'], 0, 8000);
    
    $prompt_auditor = "Actúa como Auditor de Base de Datos para un Chatbot universitario.
Te daré el CONOCIMIENTO ACTUAL del bot sobre una URL, y el TEXTO NUEVO recién extraído de esa misma URL hoy.
Tu trabajo es comparar. Si el TEXTO NUEVO tiene actualizaciones importantes (nuevos costos, fechas, información vital no presente en el conocimiento actual), debes devolver 'requiere_actualizacion': true y generar el resumen actualizado. Si la información sigue siendo idéntica o no hay cambios útiles, devuelve 'requiere_actualizacion': false.

CONOCIMIENTO ACTUAL DEL BOT:
\"" . ($bloque_actual ?: "Ninguno") . "\"

TEXTO NUEVO EXTRAÍDO HOY:
\"" . $texto_recortado . "\"

Devuelve ÚNICAMENTE un objeto JSON con este formato estricto:
{
  \"requiere_actualizacion\": true/false,
  \"es_relevante\": true,
  \"publico_objetivo\": \"Aspirantes, Estudiantes, Docentes, Egresados o Administrativos\",
  \"descripcion\": \"[El resumen estructurado completo y actualizado, redactado formalmente, omitiendo noticias pasajeras]\",
  \"palabras_clave\": \"[10 palabras clave separadas por comas]\"
}";

    $json_auditoria = llamar_gemini_json($prompt_auditor);
    
    if ($json_auditoria) {
        if (isset($json_auditoria['requiere_actualizacion']) && $json_auditoria['requiere_actualizacion'] === true && $json_auditoria['es_relevante'] === true) {
            echo "<span style='color:blue'>✅ La IA detectó CAMBIOS. Actualizando base de conocimientos...</span><br>";
            $nuevo_bloque = armar_bloque_md("actualizacion_" . md5($url_auditar), $json_auditoria, $url_auditar);
            
            if ($idx_bloque >= 0) {
                $bloques_existentes[$idx_bloque] = "\n" . trim($nuevo_bloque) . "\n";
            } else {
                $bloques_existentes[] = "\n" . trim($nuevo_bloque) . "\n";
            }
            
            // Reensamblar y guardar
            $nuevo_contenido = implode("\n---\n", array_filter(array_map('trim', $bloques_existentes)));
            file_put_contents($md_file, $nuevo_contenido . "\n\n---\n");
            
        } else {
            echo "<span style='color:green'>✔️ La IA determinó que la información sigue vigente (Sin cambios).</span><br>";
        }
    } else {
        echo "<span style='color:orange'>Error consultando IA Auditora.</span><br>";
    }
    
    // --- 2. FASE DE DESCUBRIMIENTO ---
    echo "<br><b>🔭 FASE DE DESCUBRIMIENTO:</b> Buscando enlaces nuevos...<br>";
    $candidatos = [];
    foreach ($datos_web['enlaces'] as $link) {
        if (!isset($oficiales[$link]) && !isset($ignoradas[$link]) && !es_ruta_prohibida($link)) {
            $candidatos[] = $link;
        }
    }
    
    if (!empty($candidatos)) {
        shuffle($candidatos);
        $url_nueva = $candidatos[0]; // Solo revisamos 1 para no saturar
        
        echo "Candidato nuevo encontrado: $url_nueva<br>";
        ob_flush(); flush();
        
        $info_candidato = extraer_datos_web($url_nueva);
        if ($info_candidato && mb_strlen($info_candidato['texto']) > 50) {
            $texto_candidato = mb_substr($info_candidato['texto'], 0, 8000);
            
            $prompt_descubridor = "Eres un Analista Clasificador de un Chatbot. Lee el siguiente texto crudo extraído de una web universitaria.
Evalúa si la página contiene información estática de valor enciclopédico (costos, fechas, pénsum, normativa).
Si es solo una noticia, evento pasajero, galería o información sin utilidad de consulta, devuleve es_relevante: false.

Devuelve ÚNICAMENTE un JSON estricto:
{
  \"es_relevante\": true/false,
  \"publico_objetivo\": \"Aspirantes, Estudiantes, Docentes, Egresados o Administrativos (si es relevante)\",
  \"descripcion\": \"[Tu resumen estructurado de la info útil, si es relevante]\",
  \"palabras_clave\": \"[10 palabras clave]\"
}

TEXTO:
\"" . $texto_candidato . "\"";

            $json_descubrimiento = llamar_gemini_json($prompt_descubridor);
            
            if ($json_descubrimiento) {
                if (isset($json_descubrimiento['es_relevante']) && $json_descubrimiento['es_relevante'] === true) {
                    echo "<span style='color:blue'>🌟 ¡NUEVA PÁGINA ÚTIL DESCUBIERTA POR IA!</span><br>";
                    $oficiales[$url_nueva] = true;
                    
                    $nuevo_bloque = armar_bloque_md("auto_spider_" . md5($url_nueva), $json_descubrimiento, $url_nueva);
                    file_put_contents($md_file, "\n---\n" . trim($nuevo_bloque) . "\n", FILE_APPEND);
                    
                } else {
                    echo "<span style='color:gray'>❌ La IA determinó que la página no tiene valor enciclopédico. Movida a Lista Negra.</span><br>";
                    $ignoradas[$url_nueva] = true;
                }
            }
        } else {
            $ignoradas[$url_nueva] = true;
        }
    } else {
        echo "<span style='color:gray'>No se encontraron URLs desconocidas válidas en esta página.</span><br>";
    }
} else {
    echo "<span style='color:orange'>⚠️ No se pudo acceder a la página web o tardó mucho en responder. Saltando auditoría por esta vez.</span><br>";
}

} // FIN DEL FOREACH DE RUTAS A AUDITAR

guardar_lista($urls_file, $oficiales);
guardar_lista($ignore_file, $ignoradas);

echo "<br><b>✅ Proceso finalizado.</b>";
?>
