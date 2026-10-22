<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';

/**
 * Lógica de la web para character_support. ESTA es la parte que el equipo web debe reproducir.
 *
 * Reglas del contrato:
 *  - La web solo hace SELECT e INSERT en character_support. Nunca UPDATE ni DELETE: status,
 *    result_code y processed_at los escribe el worldserver.
 *  - Las comprobaciones previas (check*) sirven para dar una respuesta inmediata al usuario.
 *    El worldserver las repite siempre; su resultado (result_code) es el que manda.
 *  - params es JSON: generarlo siempre con json_encode, nunca concatenando texto.
 */
final class CharacterSupport
{
    public const TYPE_CHANGE_ACCOUNT = 10;
    public const TYPE_UNSTUCK        = 11;

    public const STATUS_PENDING = 0;
    public const STATUS_DONE    = 1;
    public const STATUS_ERROR   = 2;

    public const TYPE_NAMES = [
        self::TYPE_CHANGE_ACCOUNT => 'Cambio de cuenta',
        self::TYPE_UNSTUCK        => 'Desatascar',
    ];

    public const STATUS_NAMES = [
        self::STATUS_PENDING => 'Pendiente',
        self::STATUS_DONE    => 'Hecho',
        self::STATUS_ERROR   => 'Error',
    ];

    /** result_code → mensaje para el usuario. Mismos valores que el enum CharacterSupportResult del worldserver. */
    public const RESULT_MESSAGES = [
        0  => 'Hecho.',
        1  => 'El personaje no existe o está borrado.',
        2  => 'Los datos de la petición no son válidos.',
        3  => 'La petición ha caducado sin poder procesarse. Vuelve a intentarlo.',
        4  => 'El personaje estaba conectado o acababa de salir del juego. Sal del juego, espera 1 minuto y vuelve a pedirlo.',
        20 => 'La cuenta de destino no existe.',
        21 => 'El personaje ya está en esa cuenta.',
        22 => 'La cuenta de destino no tiene huecos libres para más personajes.',
        23 => 'La cuenta de destino ya tiene el máximo de Caballeros de la Muerte.',
        24 => 'La cuenta de origen tiene un ban activo.',
        25 => 'La cuenta de destino tiene un ban activo.',
        40 => 'El personaje estaba en una ruta de vuelo.',
        41 => 'El personaje estaba en un campo de batalla o arena.',
        42 => 'El Caballero de la Muerte todavía está en su zona de inicio.',
        43 => 'El personaje está en un mapa no válido.',
        44 => 'Este personaje ya se desatascó hace poco. Espera antes de volver a pedirlo.',
    ];

    private const ALLIANCE_RACES = [1, 3, 4, 7, 11];
    private const CLASS_DEATH_KNIGHT = 6;

    /**
     * @param string|null $authDb BD de cuentas, accesible con la misma conexión. null = sin acceso: no se resuelven
     *                            nombres de cuenta ni se comprueban la existencia ni los bans (el worldserver sí lo hace).
     */
    public function __construct(
        private Db $db,
        private ?string $authDb,
        private array $realm,
    ) {
        if ($authDb !== null && !preg_match('/^[A-Za-z0-9_]+$/', $authDb)) {
            throw new InvalidArgumentException('Nombre de BD auth no válido');
        }
    }

    public function hasAuth(): bool
    {
        return $this->authDb !== null;
    }

    // ------------------------------------------------------------------ Consultas

    /** Busca por guid (si es un número) o por nombre, incluidos los personajes borrados. */
    public function findCharacter(string $nameOrGuid): ?array
    {
        $nameOrGuid = trim($nameOrGuid);
        $guid = ctype_digit($nameOrGuid) ? (int) $nameOrGuid : 0;

        $row = $this->db->query(
            "SELECT guid, COALESCE(name, deleteInfos_Name) AS name, account, deleteInfos_Account, race, class, level,
                    map, zone, online, logout_time, UNIX_TIMESTAMP() - logout_time AS logout_ago,
                    deleteDate, COALESCE(taxi_path, '') <> '' AS in_flight
             FROM characters
             WHERE guid = ? OR name = ? OR deleteInfos_Name = ?
             ORDER BY deleteDate IS NOT NULL, guid
             LIMIT 1",
            [$guid, $nameOrGuid, $nameOrGuid]
        )->fetch();

        if (!$row) {
            return null;
        }

        foreach (['guid', 'account', 'race', 'class', 'level', 'map', 'zone', 'online', 'logout_ago', 'in_flight'] as $k) {
            $row[$k] = (int) $row[$k];
        }
        $row['deleted']  = $row['deleteDate'] !== null;
        $row['alliance'] = in_array($row['race'], self::ALLIANCE_RACES, true);

        $ownerId = $row['deleted'] ? (int) $row['deleteInfos_Account'] : $row['account'];
        $owner = $this->hasAuth() ? $this->findAccount((string) $ownerId) : null;
        $row['account_name'] = $owner['username'] ?? null;

        return $row;
    }

    /**
     * Busca una cuenta por id o por nombre de usuario.
     * Sin BD auth solo acepta un id numérico y no comprueba que exista (username = null).
     */
    public function findAccount(string $idOrName): ?array
    {
        $idOrName = trim($idOrName);
        $id = ctype_digit($idOrName) ? (int) $idOrName : 0;

        if (!$this->hasAuth()) {
            return $id ? ['id' => $id, 'username' => null] : null;
        }

        $row = $this->db->query(
            "SELECT id, username FROM `{$this->authDb}`.account WHERE id = ? OR username = ? LIMIT 1",
            [$id, $idOrName]
        )->fetch();

        return $row ? ['id' => (int) $row['id'], 'username' => $row['username']] : null;
    }

    /** Misma condición que el worldserver: ban activo y no caducado (unbandate = bandate es permanente). */
    public function isAccountBanned(int $accountId): bool
    {
        return (bool) $this->db->query(
            "SELECT 1 FROM `{$this->authDb}`.account_banned
             WHERE id = ? AND active = 1 AND (unbandate = bandate OR unbandate > UNIX_TIMESTAMP()) LIMIT 1",
            [$accountId]
        )->fetch();
    }

    public function countCharacters(int $accountId, ?int $class = null): int
    {
        $sql = 'SELECT COUNT(*) FROM characters WHERE account = ? AND deleteDate IS NULL';
        $params = [$accountId];
        if ($class !== null) {
            $sql .= ' AND class = ?';
            $params[] = $class;
        }
        return (int) $this->db->query($sql, $params)->fetchColumn();
    }

    public function pendingRequestId(int $guid, int $type): ?int
    {
        $id = $this->db->query(
            'SELECT id FROM character_support WHERE guid = ? AND type = ? AND status = 0 LIMIT 1',
            [$guid, $type]
        )->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** Segundos que faltan para poder desatascar otra vez (0 = ya puede). Se calcula con NOW() de la BD. */
    public function unstuckCooldownRemaining(int $guid): int
    {
        $remaining = $this->db->query(
            'SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(processed_at) + INTERVAL ? MINUTE)
             FROM character_support WHERE guid = ? AND type = ? AND status = ?',
            [(int) $this->realm['unstuck_cooldown_minutes'], $guid, self::TYPE_UNSTUCK, self::STATUS_DONE]
        )->fetchColumn();

        return max(0, (int) $remaining);
    }

    public function getRequest(int $id): ?array
    {
        $rows = $this->listRequestsWhere('cs.id = ?', [$id], 1);
        return $rows[0] ?? null;
    }

    public function listRequests(?int $guid, int $limit = 50): array
    {
        return $guid
            ? $this->listRequestsWhere('cs.guid = ?', [$guid], $limit)
            : $this->listRequestsWhere('1 = 1', [], $limit);
    }

    private function listRequestsWhere(string $where, array $params, int $limit): array
    {
        $params[] = $limit;
        $rows = $this->db->query(
            "SELECT cs.id, cs.guid, COALESCE(c.name, c.deleteInfos_Name) AS name, cs.type, cs.params, cs.status,
                    cs.result_code, cs.requested_by, cs.created_at, cs.processed_at,
                    TIMESTAMPDIFF(SECOND, cs.created_at, COALESCE(cs.processed_at, NOW())) AS wait_seconds
             FROM character_support cs
             LEFT JOIN characters c ON c.guid = cs.guid
             WHERE $where
             ORDER BY cs.id DESC
             LIMIT ?",
            $params
        )->fetchAll();

        foreach ($rows as &$r) {
            foreach (['id', 'guid', 'type', 'status', 'result_code', 'wait_seconds'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['type_name']   = self::TYPE_NAMES[$r['type']] ?? "Tipo {$r['type']}";
            $r['status_name'] = self::STATUS_NAMES[$r['status']] ?? (string) $r['status'];
            $r['message']     = $r['status'] === self::STATUS_PENDING
                ? 'En cola: se procesará en el próximo ciclo del servidor.'
                : (self::RESULT_MESSAGES[$r['result_code']] ?? "Código {$r['result_code']}");
        }
        return $rows;
    }

    // ------------------------------------------------------------------ Comprobaciones previas de la web

    /** @return list<string> Motivos por los que la web no debería aceptar la petición (vacío = se puede). */
    public function checkUnstuck(array $char): array
    {
        $errors = [];
        if ($char['deleted']) {
            $errors[] = self::RESULT_MESSAGES[1];
        }
        if ($char['online']) {
            $errors[] = 'El personaje está conectado. Sal del juego antes de pedirlo.';
        } elseif ($char['logout_ago'] < 60) {
            $errors[] = 'Acabas de salir del juego. Espera ' . (60 - $char['logout_ago']) . ' s.';
        }
        if ($char['in_flight']) {
            $errors[] = self::RESULT_MESSAGES[40];
        }
        if ($remaining = $this->unstuckCooldownRemaining($char['guid'])) {
            $errors[] = 'Ya se desatascó hace poco. Podrás volver a pedirlo en ' . ceil($remaining / 60) . ' min.';
        }
        if ($this->pendingRequestId($char['guid'], self::TYPE_UNSTUCK) !== null) {
            $errors[] = 'Ya hay una petición de desatasco en curso para este personaje.';
        }
        return $errors;
    }

    /** @return list<string> */
    public function checkChangeAccount(array $char, ?array $target): array
    {
        $errors = [];
        if ($char['deleted']) {
            $errors[] = self::RESULT_MESSAGES[1];
        }
        if (!$target) {
            return [...$errors, $this->hasAuth() ? self::RESULT_MESSAGES[20] : 'Sin BD auth, la cuenta destino debe ser un id numérico.'];
        }
        if ($target['id'] === $char['account']) {
            $errors[] = self::RESULT_MESSAGES[21];
        }
        // Los bans están en la BD auth. Sin ella, solo los comprueba el worldserver (códigos 24 y 25)
        if ($this->hasAuth()) {
            if ($this->isAccountBanned($char['account'])) {
                $errors[] = self::RESULT_MESSAGES[24];
            }
            if ($this->isAccountBanned($target['id'])) {
                $errors[] = self::RESULT_MESSAGES[25];
            }
        }
        if ($this->countCharacters($target['id']) >= (int) $this->realm['characters_per_realm']) {
            $errors[] = self::RESULT_MESSAGES[22];
        }
        if ($char['class'] === self::CLASS_DEATH_KNIGHT
            && $this->countCharacters($target['id'], self::CLASS_DEATH_KNIGHT) >= (int) $this->realm['death_knights_per_realm']) {
            $errors[] = self::RESULT_MESSAGES[23];
        }
        if ($this->pendingRequestId($char['guid'], self::TYPE_CHANGE_ACCOUNT) !== null) {
            $errors[] = 'Ya hay una petición de cambio de cuenta en curso para este personaje.';
        }
        return $errors;
    }

    // ------------------------------------------------------------------ Inserción

    /**
     * Crea la petición y devuelve su id.
     * @param array|null $params Se guarda con json_encode (null = sin parámetros).
     * @throws DuplicatePendingRequest si ya hay una pendiente del mismo tipo para el personaje (error 1062).
     */
    public function insertRequest(int $guid, int $type, ?array $params, string $requestedBy): int
    {
        return $this->insertRaw($guid, $type, $params === null ? null : json_encode($params), $requestedBy);
    }

    /** Inserta params tal cual (texto). Solo para probar los casos de error del servidor; la web usa insertRequest. */
    public function insertRaw(int $guid, int $type, ?string $paramsJson, string $requestedBy): int
    {
        try {
            $this->db->query(
                'INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, ?, ?, ?)',
                [$guid, $type, $paramsJson, $requestedBy]
            );
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) === 1062) {
                throw new DuplicatePendingRequest('Ya hay una petición pendiente de ese tipo para este personaje.', 0, $e);
            }
            throw $e;
        }
        return $this->db->lastInsertId();
    }
}

final class DuplicatePendingRequest extends RuntimeException
{
}
