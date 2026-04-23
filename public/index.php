<?php
declare(strict_types=1);

// ── CORS ──────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ── .env ──────────────────────────────────────────────────────
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$key, $val] = explode('=', $line, 2) + [1 => ''];
        $_ENV[trim($key)] = trim($val);
    }
}

// ── Autoload ──────────────────────────────────────────────────
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/NfeParser.php';
require_once __DIR__ . '/../src/Sefaz.php';
require_once __DIR__ . '/../routes/nfe.php';
require_once __DIR__ . '/../routes/conferencia.php';

// ── Roteador ──────────────────────────────────────────────────
$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$uri    = '/' . ltrim(substr($uri, strlen($base)), '/');
$method = $_SERVER['REQUEST_METHOD'];

try {
    // POST /nfe/itens  → busca e agrupa itens de múltiplas NF-e na SEFAZ
    if ($method === 'POST' && $uri === '/nfe/itens') {
        route_nfe_itens();
        exit;
    }

    // GET /conferencias[?id_romaneio=X]
    if ($method === 'GET' && $uri === '/conferencias') {
        route_listar_conferencias();
        exit;
    }

    // POST /conferencias  → cria nova conferência com itens esperados
    if ($method === 'POST' && $uri === '/conferencias') {
        route_criar_conferencia();
        exit;
    }

    // GET|PUT /conferencias/{id}
    if (preg_match('#^/conferencias/(\d+)$#', $uri, $m)) {
        $id = (int)$m[1];
        if ($method === 'GET') {
            route_buscar_conferencia($id);
        } elseif ($method === 'PUT') {
            route_salvar_itens($id);
        } else {
            http_response_code(405);
            echo json_encode(['success' => false, 'erro' => 'Método não permitido.']);
        }
        exit;
    }

    // PUT /conferencias/{id}/finalizar
    if (preg_match('#^/conferencias/(\d+)/finalizar$#', $uri, $m) && $method === 'PUT') {
        route_finalizar_conferencia((int)$m[1]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['success' => false, 'erro' => 'Rota não encontrada.']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'erro' => $e->getMessage()]);
}
