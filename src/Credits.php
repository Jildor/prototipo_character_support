<?php
declare(strict_types=1);

require_once __DIR__ . '/Db.php';
require_once __DIR__ . '/CharacterSupport.php';

/**
 * Créditos de la web: cobro de las peticiones de character_support sin cobrar de más ni permitir abusos.
 *
 * Los créditos viven en la BD de la web, que NO es la BD characters: no hay una transacción común entre las dos.
 * Por eso el cobro va en tres pasos, cada uno seguro por sí solo:
 *
 *  1. Reservar: se descuentan los créditos con un UPDATE condicional (saldo >= coste) y se apunta un cargo
 *     "reserved". Dos peticiones a la vez no pueden gastar el mismo saldo.
 *  2. Insertar la petición en character_support con requested_by = "<quien>:charge:<id del cargo>", y guardar el
 *     id de la petición en el cargo.
 *  3. Liquidar, cuando el worldserver cierra la petición: status 1 → "charged" (cobrado); status 2 (cualquier
 *     error, también la caducidad) → "refunded" (se devuelve el saldo).
 *
 * Cada cambio de estado se hace con "WHERE state = 'reserved'" y comprobando las filas afectadas, así que liquidar
 * dos veces (doble clic, dos procesos a la vez, cron + página) no devuelve ni cobra dos veces.
 *
 * En el prototipo el saldo es por cuenta de juego y se guarda en un SQLite local (data/web.sqlite). En la web real
 * irá en su propia BD y por usuario de la web.
 */
final class Credits
{
    public const STATE_RESERVED = 'reserved';
    public const STATE_CHARGED  = 'charged';
    public const STATE_REFUNDED = 'refunded';

    public const STATE_NAMES = [
        self::STATE_RESERVED => 'Reservado',
        self::STATE_CHARGED  => 'Cobrado',
        self::STATE_REFUNDED => 'Devuelto',
    ];

    /** Cargo reservado sin id de petición: pasado este tiempo se revisa (el INSERT falló o la web se cayó). */
    private const ORPHAN_SECONDS = 300;

    /**
     * @param array<int, int> $costs Coste por tipo de petición (0 o ausente = gratis, no se apunta cargo).
     */
    public function __construct(
        private Db $db,
        private int $initialBalance,
        private array $costs,
    ) {
        $db->exec('CREATE TABLE IF NOT EXISTS wallet (
            account_id INTEGER PRIMARY KEY,
            credits    INTEGER NOT NULL CHECK (credits >= 0)
        )');
        $db->exec("CREATE TABLE IF NOT EXISTS credit_charge (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            account_id  INTEGER NOT NULL,
            guid        INTEGER NOT NULL,
            type        INTEGER NOT NULL,
            amount      INTEGER NOT NULL,
            state       TEXT    NOT NULL DEFAULT 'reserved',
            request_id  INTEGER UNIQUE,
            result_code INTEGER,
            note        TEXT,
            created_at  INTEGER NOT NULL,
            settled_at  INTEGER
        )");
    }

    public function cost(int $type): int
    {
        return max(0, (int) ($this->costs[$type] ?? 0));
    }

    public function costs(): array
    {
        return $this->costs;
    }

    public function balance(int $accountId): int
    {
        $this->ensureWallet($accountId);
        return (int) $this->db->query('SELECT credits FROM wallet WHERE account_id = ?', [$accountId])->fetchColumn();
    }

    /** Solo para pruebas: fija el saldo de una cuenta. */
    public function setBalance(int $accountId, int $credits): void
    {
        $this->ensureWallet($accountId);
        $this->db->query('UPDATE wallet SET credits = ? WHERE account_id = ?', [max(0, $credits), $accountId]);
    }

    /** Comprobación previa para avisar al usuario. La que cuenta es la de reserve(). */
    public function checkBalance(int $accountId, int $type): array
    {
        $cost = $this->cost($type);
        $balance = $cost ? $this->balance($accountId) : 0;
        return $balance < $cost ? ["Créditos insuficientes: cuesta $cost y la cuenta tiene $balance."] : [];
    }

    /**
     * Paso 1: descuenta el coste y apunta el cargo. Devuelve el id del cargo, o null si el tipo es gratis.
     * @throws InsufficientCredits
     */
    public function reserve(int $accountId, int $guid, int $type): ?int
    {
        $cost = $this->cost($type);
        if (!$cost) {
            return null;
        }
        $this->ensureWallet($accountId);

        return $this->db->transaction(function () use ($accountId, $guid, $type, $cost) {
            // Descuento condicional: si dos peticiones llegan a la vez, solo pasa la que encuentra saldo suficiente
            $stmt = $this->db->query(
                'UPDATE wallet SET credits = credits - ? WHERE account_id = ? AND credits >= ?',
                [$cost, $accountId, $cost]
            );
            if ($stmt->rowCount() !== 1) {
                throw new InsufficientCredits("Créditos insuficientes: cuesta $cost.");
            }
            $this->db->query(
                'INSERT INTO credit_charge (account_id, guid, type, amount, created_at) VALUES (?, ?, ?, ?, ?)',
                [$accountId, $guid, $type, $cost, time()]
            );
            return $this->db->lastInsertId();
        });
    }

    /** Texto para character_support.requested_by: enlaza la petición con su cargo aunque no se llegue a attach(). */
    public static function requestTag(string $requestedBy, ?int $chargeId): string
    {
        return $chargeId ? substr($requestedBy, 0, 40) . ':charge:' . $chargeId : $requestedBy;
    }

    /** Paso 2: guarda el id de la petición en el cargo. */
    public function attach(int $chargeId, int $requestId): void
    {
        $this->db->query('UPDATE credit_charge SET request_id = ? WHERE id = ? AND request_id IS NULL', [$requestId, $chargeId]);
    }

    /** Cobro definitivo. Devuelve false si el cargo ya no estaba reservado (otro proceso lo liquidó antes). */
    public function confirm(int $chargeId, int $resultCode): bool
    {
        return $this->db->query(
            "UPDATE credit_charge SET state = 'charged', result_code = ?, settled_at = ? WHERE id = ? AND state = 'reserved'",
            [$resultCode, time(), $chargeId]
        )->rowCount() === 1;
    }

    /** Devuelve el saldo. El cambio de estado y la devolución van juntos: o pasan los dos o ninguno. */
    public function refund(int $chargeId, ?int $resultCode, string $note): bool
    {
        return $this->db->transaction(function () use ($chargeId, $resultCode, $note) {
            $changed = $this->db->query(
                "UPDATE credit_charge SET state = 'refunded', result_code = ?, note = ?, settled_at = ?
                 WHERE id = ? AND state = 'reserved'",
                [$resultCode, $note, time(), $chargeId]
            )->rowCount() === 1;
            if ($changed) {
                $this->db->query(
                    'UPDATE wallet SET credits = credits + (SELECT amount FROM credit_charge WHERE id = ?)
                     WHERE account_id = (SELECT account_id FROM credit_charge WHERE id = ?)',
                    [$chargeId, $chargeId]
                );
            }
            return $changed;
        });
    }

    /**
     * Paso 3: liquida los cargos reservados según el resultado del worldserver. Se puede llamar las veces que se
     * quiera (cron cada minuto, al abrir "Mis peticiones"...): lo ya liquidado no se toca.
     * @return int Cargos liquidados en esta llamada.
     */
    public function settle(CharacterSupport $cs, string $requestedBy): int
    {
        $settled = 0;
        $reserved = $this->db->query("SELECT id, guid, request_id, created_at FROM credit_charge WHERE state = 'reserved'")->fetchAll();

        foreach ($reserved as $charge) {
            $chargeId = (int) $charge['id'];
            $requestId = $charge['request_id'] !== null ? (int) $charge['request_id'] : null;

            if ($requestId === null) {
                // Reservado pero sin petición enlazada. Se espera un poco por si la petición web sigue en curso
                if (time() - (int) $charge['created_at'] < self::ORPHAN_SECONDS) {
                    continue;
                }
                // ¿Llegó a insertarse? (p. ej. se perdió la conexión justo después del INSERT)
                $requestId = $cs->findRequestByTag((int) $charge['guid'], self::requestTag($requestedBy, $chargeId));
                if ($requestId === null) {
                    $settled += (int) $this->refund($chargeId, null, 'La petición no llegó a crearse');
                    continue;
                }
                $this->attach($chargeId, $requestId);
            }

            $request = $cs->getRequestStatus($requestId);
            if ($request === null) {
                // La fila ya no existe (borrada a mano o purgada por antigüedad): no se adivina, lo revisa el staff
                continue;
            }
            if ($request['status'] === CharacterSupport::STATUS_DONE) {
                $settled += (int) $this->confirm($chargeId, $request['result_code']);
            } elseif ($request['status'] === CharacterSupport::STATUS_ERROR) {
                $settled += (int) $this->refund($chargeId, $request['result_code'], 'El servidor rechazó la petición');
            }
        }
        return $settled;
    }

    /** Movimientos de un personaje (o de todos si $guid es null), del más reciente al más antiguo. */
    public function charges(?int $guid, int $limit = 50): array
    {
        $rows = $guid
            ? $this->db->query('SELECT * FROM credit_charge WHERE guid = ? ORDER BY id DESC LIMIT ?', [$guid, $limit])->fetchAll()
            : $this->db->query('SELECT * FROM credit_charge ORDER BY id DESC LIMIT ?', [$limit])->fetchAll();

        foreach ($rows as &$r) {
            foreach (['id', 'account_id', 'guid', 'type', 'amount', 'created_at'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['request_id'] = $r['request_id'] !== null ? (int) $r['request_id'] : null;
            $r['type_name']  = CharacterSupport::TYPE_NAMES[$r['type']] ?? "Tipo {$r['type']}";
            $r['state_name'] = self::STATE_NAMES[$r['state']] ?? $r['state'];
            $r['created']    = date('Y-m-d H:i:s', $r['created_at']);
        }
        return $rows;
    }

    private function ensureWallet(int $accountId): void
    {
        $this->db->query('INSERT OR IGNORE INTO wallet (account_id, credits) VALUES (?, ?)', [$accountId, $this->initialBalance]);
    }
}

final class InsufficientCredits extends RuntimeException
{
}
