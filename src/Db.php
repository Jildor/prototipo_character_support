<?php
declare(strict_types=1);

/**
 * Conexión PDO mínima. Guarda cada consulta ejecutada para que el prototipo pueda mostrar
 * el SQL exacto que lanzaría la web (no es necesario en la web real).
 */
final class Db
{
    private PDO $pdo;

    /** @var list<array{sql: string, params: array}> */
    private array $log = [];

    public function __construct(array $cfg)
    {
        // host = 127.0.0.1 (no "localhost"): con "localhost" PDO intenta un socket Unix en vez del túnel TCP
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $cfg['host'], $cfg['port'], $cfg['characters_db']);

        $this->pdo = new PDO($dsn, $cfg['user'], $cfg['password'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
    }

    /** Ejecuta una consulta preparada. Los enteros se enlazan como enteros (necesario para LIMIT ?). */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $this->log[] = ['sql' => $sql, 'params' => $params];

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

    public function lastInsertId(): int
    {
        return (int) $this->pdo->lastInsertId();
    }

    public function log(): array
    {
        return $this->log;
    }
}
