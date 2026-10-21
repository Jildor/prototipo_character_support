<?php
declare(strict_types=1);

/**
 * API JSON del prototipo. Cada respuesta incluye "sql": las consultas ejecutadas, para que el equipo web vea
 * exactamente qué se lanza contra la BD en cada acción.
 */

require_once __DIR__ . '/../src/CharacterSupport.php';

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    respond(false, null, ['Falta config.php: copia config.example.php a config.php y rellénalo.']);
}
$config = require $configFile;

try {
    $db = new Db($config['db']);
} catch (PDOException $e) {
    respond(false, null, ['No se puede conectar a la BD (¿está abierto el túnel SSH?): ' . $e->getMessage()]);
}

$cs = new CharacterSupport($db, $config['db']['auth_db'], $config['realm']);
$input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$action = $_GET['action'] ?? '';
$requestedBy = (string) ($config['requested_by'] ?? 'prototipo');

try {
    switch ($action) {
        case 'status':
            $version = $db->query('SELECT VERSION()')->fetchColumn();
            $hasV2 = (bool) $db->query("SHOW COLUMNS FROM character_support LIKE 'params'")->fetch();
            respond(true, ['version' => $version, 'table_v2' => $hasV2, 'realm' => $config['realm']],
                $hasV2 ? [] : ['La tabla character_support no es la v2: aplica el SQL 2026_10_21_100_characters_character_support.sql.']);

        case 'character':
            $char = $cs->findCharacter((string) ($_GET['q'] ?? ''));
            if (!$char) {
                respond(false, null, ['Personaje no encontrado.']);
            }
            $char['unstuck_cooldown'] = $cs->unstuckCooldownRemaining($char['guid']);
            $char['unstuck_checks'] = $cs->checkUnstuck($char);
            respond(true, $char);

        case 'unstuck':
            $guid = (int) ($input['guid'] ?? 0);
            $skipChecks = !empty($input['skip_checks']);
            $char = $cs->findCharacter((string) $guid);
            if (!$char && !$skipChecks) {
                respond(false, null, ['Personaje no encontrado.']);
            }
            if (!$skipChecks && ($errors = $cs->checkUnstuck($char))) {
                respond(false, null, $errors);
            }
            $id = $cs->insertRequest($guid, CharacterSupport::TYPE_UNSTUCK, null, $requestedBy);
            respond(true, $cs->getRequest($id));

        case 'change_account':
            $guid = (int) ($input['guid'] ?? 0);
            $skipChecks = !empty($input['skip_checks']);
            $char = $cs->findCharacter((string) $guid);
            $target = $cs->findAccount((string) ($input['account'] ?? ''));
            if (!$skipChecks) {
                if (!$char) {
                    respond(false, null, ['Personaje no encontrado.']);
                }
                if ($errors = $cs->checkChangeAccount($char, $target)) {
                    respond(false, null, $errors);
                }
            }
            // Sin comprobaciones se manda lo que se haya escrito, para probar ACCOUNT_NOT_FOUND en el servidor
            $accountId = $target['id'] ?? (int) ($input['account'] ?? 0);
            $id = $cs->insertRequest($guid, CharacterSupport::TYPE_CHANGE_ACCOUNT, ['account' => $accountId], $requestedBy);
            respond(true, $cs->getRequest($id));

        case 'raw':
            // Inserción manual sin ninguna comprobación (casos de prueba de parámetros inválidos, tipos desconocidos...)
            $params = trim((string) ($input['params'] ?? ''));
            $id = $cs->insertRaw((int) ($input['guid'] ?? 0), (int) ($input['type'] ?? 0), $params === '' ? null : $params, $requestedBy);
            respond(true, $cs->getRequest($id));

        case 'requests':
            $guid = (int) ($_GET['guid'] ?? 0);
            respond(true, $cs->listRequests($guid ?: null, 50));

        default:
            respond(false, null, ['Acción desconocida.']);
    }
} catch (DuplicatePendingRequest $e) {
    respond(false, null, [$e->getMessage()]);
} catch (PDOException $e) {
    respond(false, null, ['Error de BD: ' . $e->getMessage()]);
}

function respond(bool $ok, mixed $data, array $errors = []): never
{
    global $db;
    echo json_encode([
        'ok'     => $ok,
        'data'   => $data,
        'errors' => $errors,
        'sql'    => isset($db) ? $db->log() : [],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
