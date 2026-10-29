<?php
declare(strict_types=1);

/**
 * API JSON del prototipo. Cada respuesta incluye "sql": las consultas ejecutadas, para que el equipo web vea
 * exactamente qué se lanza contra la BD en cada acción (characters y la BD propia de la web, con los créditos).
 */

require_once __DIR__ . '/../src/CharacterSupport.php';
require_once __DIR__ . '/../src/Credits.php';

header('Content-Type: application/json; charset=utf-8');

$configFile = __DIR__ . '/../config.php';
if (!is_file($configFile)) {
    respond(false, null, ['Falta config.php: copia config.example.php a config.php y rellénalo.']);
}
$config = require $configFile;

try {
    $db = Db::mysql($config['db']);
} catch (PDOException $e) {
    respond(false, null, ['No se puede conectar a la BD (¿está abierto el túnel SSH?): ' . $e->getMessage()]);
}

$cs = new CharacterSupport($db, ($config['db']['auth_db'] ?? null) ?: null, $config['realm']);
$creditsCfg = $config['credits'] ?? [];
$credits = new Credits(
    Db::sqlite(__DIR__ . '/../data/web.sqlite'),
    (int) ($creditsCfg['initial_balance'] ?? 100),
    $creditsCfg['costs'] ?? [CharacterSupport::TYPE_CHANGE_ACCOUNT => 50, CharacterSupport::TYPE_UNSTUCK => 0, CharacterSupport::TYPE_RESTORE => 30]
);
$input = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
$action = $_GET['action'] ?? '';
$requestedBy = (string) ($config['requested_by'] ?? 'prototipo');

try {
    switch ($action) {
        case 'status':
            $version = $db->query('SELECT VERSION()')->fetchColumn();
            $hasV2 = (bool) $db->query("SHOW COLUMNS FROM character_support LIKE 'params'")->fetch();
            respond(true, ['version' => $version, 'table_v2' => $hasV2, 'auth' => $cs->hasAuth(), 'realm' => $config['realm'],
                           'costs' => $credits->costs()],
                $hasV2 ? [] : ['La tabla character_support no es la v2: aplica el SQL 2026_10_21_100_characters_character_support.sql.']);

        case 'character':
            $char = $cs->findCharacter((string) ($_GET['q'] ?? ''));
            if (!$char) {
                respond(false, null, ['Personaje no encontrado.']);
            }
            $payer = payer($char);
            $char['payer'] = $payer;
            $char['balance'] = $credits->balance($payer);
            $char['unstuck_cooldown'] = $cs->unstuckCooldownRemaining($char['guid']);
            $char['unstuck_checks'] = [...$cs->checkUnstuck($char), ...$credits->checkBalance($payer, CharacterSupport::TYPE_UNSTUCK)];
            $char['change_checks'] = $credits->checkBalance($payer, CharacterSupport::TYPE_CHANGE_ACCOUNT);
            $char['restore_checks'] = $char['deleted']
                ? [...$cs->checkRestore($char), ...$credits->checkBalance($payer, CharacterSupport::TYPE_RESTORE)]
                : [];
            $char['restore_name'] = CharacterSupport::restoreTempName($char['guid'], $char['deleteInfos_Name'] ?? $char['name']);
            respond(true, $char);

        case 'restore':
            $guid = (int) ($input['guid'] ?? 0);
            $skipChecks = !empty($input['skip_checks']);
            $char = $cs->findCharacter((string) $guid);
            if (!$skipChecks) {
                if (!$char) {
                    respond(false, null, ['Personaje no encontrado.']);
                }
                if ($errors = $cs->checkRestore($char)) {
                    respond(false, null, $errors);
                }
            }
            respond(true, createRequest($cs, $credits, $char, $guid, CharacterSupport::TYPE_RESTORE, null, $requestedBy));

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
            respond(true, createRequest($cs, $credits, $char, $guid, CharacterSupport::TYPE_UNSTUCK, null, $requestedBy));

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
            respond(true, createRequest($cs, $credits, $char, $guid, CharacterSupport::TYPE_CHANGE_ACCOUNT, ['account' => $accountId], $requestedBy));

        case 'raw':
            // Inserción manual sin ninguna comprobación ni cobro (casos de prueba de parámetros inválidos, tipos desconocidos...)
            $params = trim((string) ($input['params'] ?? ''));
            $id = $cs->insertRaw((int) ($input['guid'] ?? 0), (int) ($input['type'] ?? 0), $params === '' ? null : $params, $requestedBy);
            respond(true, $cs->getRequest($id));

        case 'requests':
            // Liquidar aquí es solo para el prototipo (se sondea cada 3 s). En la web real: cron y al abrir "Mis peticiones"
            $credits->settle($cs, $requestedBy);
            $guid = (int) ($_GET['guid'] ?? 0);
            $account = (int) ($_GET['account'] ?? 0);
            respond(true, [
                'requests' => $cs->listRequests($guid ?: null, 50),
                'charges'  => $credits->charges($guid ?: null, 50),
                'balance'  => $account ? $credits->balance($account) : null,
            ]);

        case 'set_credits':
            // Solo para pruebas
            $credits->setBalance((int) ($input['account'] ?? 0), (int) ($input['credits'] ?? 0));
            respond(true, ['balance' => $credits->balance((int) ($input['account'] ?? 0))]);

        default:
            respond(false, null, ['Acción desconocida.']);
    }
} catch (DuplicatePendingRequest | InsufficientCredits $e) {
    respond(false, null, [$e->getMessage()]);
} catch (PDOException $e) {
    respond(false, null, ['Error de BD: ' . $e->getMessage()]);
}

/** Cuenta que paga: la dueña del personaje (la original si está borrado). */
function payer(array $char): int
{
    return $char['deleted'] ? (int) $char['deleteInfos_Account'] : (int) $char['account'];
}

/**
 * Reserva los créditos, inserta la petición y enlaza las dos cosas. Es el flujo que debe copiar la web real.
 * Sin personaje (solo con "saltar comprobaciones" y un guid inexistente) no hay a quién cobrar y no se cobra.
 */
function createRequest(CharacterSupport $cs, Credits $credits, ?array $char, int $guid, int $type, ?array $params, string $requestedBy): array
{
    $chargeId = $char ? $credits->reserve(payer($char), $guid, $type) : null;

    try {
        $id = $cs->insertRequest($guid, $type, $params, Credits::requestTag($requestedBy, $chargeId));
    } catch (DuplicatePendingRequest $e) {
        // Seguro que no se insertó: se devuelve ya
        if ($chargeId) {
            $credits->refund($chargeId, null, 'Ya había una petición pendiente');
        }
        throw $e;
    }
    // Con otros errores (p. ej. se corta la conexión) no se sabe si el INSERT llegó a hacerse: el cargo se queda
    // reservado y settle() lo resuelve buscando la petición por requested_by.

    if ($chargeId) {
        $credits->attach($chargeId, $id);
    }
    return $cs->getRequest($id);
}

function respond(bool $ok, mixed $data, array $errors = []): never
{
    echo json_encode([
        'ok'     => $ok,
        'data'   => $data,
        'errors' => $errors,
        'sql'    => Db::log(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
