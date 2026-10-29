<?php
declare(strict_types=1);

/**
 * Conexión PDO mínima. Guarda cada consulta ejecutada para que el prototipo pueda mostrar
 * el SQL exacto que lanzaría la web (no es necesario en la web real).
 *
 * El registro es común a todas las conexiones para conservar el orden real entre la BD characters y la BD de
 * la web (créditos).
 */
final class Db
{
    /** @var list<array{db: string, sql: string, params: array}> */
    private static array $log = [];

    private function __construct(private PDO $pdo, private string $label)
    {
    }

    /** BD characters del reino (a través del túnel SSH). */
    public static function mysql(array $cfg): self
    {
        // host = 127.0.0.1 (no "localhost"): con "localhost" PDO intenta un socket Unix en vez del túnel TCP
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'], $cfg['characters_db']);

        return new self(new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]), 'characters');
    }

    /** BD propia de la web, simulada con un fichero SQLite local. */
    public static function sqlite(string $file): self
    {
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        return new self(new PDO('sqlite:' . $file, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 5, // espera si otra petición tiene la BD bloqueada
        ]), 'web');
    }

    /** Ejecuta una consulta preparada. Los enteros se enlazan como enteros (necesario para LIMIT ?). */
    public function query(string $sql, array $params = []): PDOStatement
    {
        self::$log[] = ['db' => $this->label, 'sql' => $sql, 'params' => $params];

        $stmt = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($i + 1, $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    /**
     * Ejecuta $fn dentro de una transacción. Solo se usa en la BD de la web (SQLite): BEGIN IMMEDIATE bloquea la
     * escritura desde el inicio. En MySQL/MariaDB sería START TRANSACTION.
     */
    public function transaction(callable $fn): mixed
    {
        $this->query('BEGIN IMMEDIATE');
        try {
            $result = $fn();
            $this->query('COMMIT');
            return $result;
        } catch (Throwable $e) {
            $this->query('ROLLBACK');
            throw $e;
        }
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public static function log(): array
    {
        return self::$log;
    }
}
