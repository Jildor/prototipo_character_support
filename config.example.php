<?php
// Copia este fichero a config.php y rellénalo. config.php NO se sube a git.
return [
    'db' => [
        // Extremo local del túnel SSH (ver README). Usar 127.0.0.1, no "localhost".
        'host'          => '127.0.0.1',
        'port'          => 13306,
        'user'          => 'usuario',
        'password'      => 'contraseña',
        'characters_db' => 'characters',
        // BD de cuentas, solo si es accesible con esta misma conexión. null = sin ella: la cuenta destino se
        // escribe por id y los bans y la existencia de la cuenta solo los comprueba el worldserver.
        'auth_db'       => null,
    ],

    // Copia de los valores del worldserver.conf del reino, para las comprobaciones previas de la web
    'realm' => [
        'characters_per_realm'     => 10, // CharactersPerRealm
        'death_knights_per_realm'  => 1,  // DeathKnightsPerRealm
        'unstuck_cooldown_minutes' => 60, // CharacterSupport.Unstuck.Cooldown
    ],

    // Se guarda en character_support.requested_by
    'requested_by' => 'prototipo',
];
