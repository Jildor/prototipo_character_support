# Guía de integración para la web

Esta guía explica cómo debe implementar la web real el sistema **Character Support** de NaerEmu. El prototipo
de este repositorio es una referencia que funciona: la lógica que hay que reproducir está en
[`src/CharacterSupport.php`](../src/CharacterSupport.php).

## 1. Idea general

La web **no** modifica personajes. Solo deja una petición en una tabla y el servidor del juego (worldserver)
la ejecuta en su siguiente ciclo, que se repite cada minuto.

```
 Web                                   BD characters                       worldserver
  │ 1. comprobaciones previas (SELECT)       │                                   │
  │ 2. INSERT character_support ───────────► │ status = 0 (pendiente)            │
  │                                          │ ◄──────── 3. cada minuto lee las  │
  │                                          │           pendientes, las valida  │
  │                                          │           y las ejecuta           │
  │                                          │ status = 1/2, result_code ◄────── │ 4. cierra cada fila
  │ 5. consulta el resultado (SELECT) ◄───── │                                   │
```

Reglas básicas:

- La web **solo** hace `SELECT` e `INSERT` en `character_support`. `status`, `result_code` y `processed_at`
  los escribe siempre el worldserver.
- Las comprobaciones de la web sirven para avisar al usuario al momento. El worldserver las **repite todas**
  y su resultado (`result_code`) es el definitivo.
- El resultado no es instantáneo: tarda **hasta 1 minuto**.

## 2. La tabla `characters.character_support`

| Columna | La escribe | Significado |
|---|---|---|
| `id` | BD | Id de la petición. Obtenerlo con `lastInsertId()` tras insertar |
| `guid` | Web | `characters.guid` del personaje |
| `type` | Web | Acción (ver tipos) |
| `params` | Web | JSON con los parámetros del tipo, o `NULL` |
| `requested_by` | Web | Quién lo pide, para auditoría (máx. 64 caracteres). Por ejemplo `web:<id usuario>` o `staff:<nombre>` |
| `status` | worldserver | 0 pendiente, 1 hecho, 2 error |
| `result_code` | worldserver | Motivo del resultado (ver códigos) |
| `created_at` | BD | Fecha de creación |
| `processed_at` | worldserver | Fecha de cierre (`NULL` mientras está pendiente) |
| `pending_guid` | BD | Columna interna para el índice único. No se escribe nunca |

Solo puede haber **una petición pendiente del mismo tipo por personaje**. Un segundo `INSERT` falla con el
error **1062** (clave duplicada). Las peticiones ya cerradas no cuentan.

## 3. Tipos

| `type` | Acción | `params` |
|---|---|---|
| 10 | **Cambio de cuenta**: pasa el personaje a otra cuenta. Si está conectado, el servidor lo expulsa | `{"account": <id de la cuenta destino>}` |
| 11 | **Desatascar**: mueve el personaje a Páramos de Poniente (Alianza) o a Los Baldíos (Horda). Tiene que estar desconectado | `NULL` |
| 12 | **Restaurar personaje borrado**: vuelve a su cuenta original con un nombre temporal y el jugador tiene que elegir otro al entrar | `NULL` |

`params` se genera **siempre** con `json_encode`, nunca concatenando texto, y los ids van como número:

```php
$params = json_encode(['account' => (int) $targetAccountId]);   // {"account":465777}
```

## 4. Códigos de resultado

Los mensajes de ejemplo están en `CharacterSupport::RESULT_MESSAGES`.

| Código | Tipo | Significado | Mensaje de ejemplo |
|---|---|---|---|
| 0 | todos | Hecho | Hecho. |
| 1 | todos | El personaje no existe o está borrado | El personaje no existe o está borrado. |
| 2 | todos | Parámetros inválidos | Los datos de la petición no son válidos. |
| 3 | todos | Caducada: ha estado pendiente más de 24 h | La petición ha caducado sin poder procesarse. Vuelve a intentarlo. |
| 4 | 11 | Conectado, entrando al mundo o salido hace menos de 60 s | Sal del juego, espera 1 minuto y vuelve a pedirlo. |
| 20 | 10, 12 | La cuenta destino no existe (en el 12, la cuenta original) | La cuenta de destino no existe. |
| 21 | 10 | El personaje ya está en esa cuenta | El personaje ya está en esa cuenta. |
| 22 | 10, 12 | La cuenta destino no tiene huecos (en el 12, la cuenta original) | La cuenta de destino no tiene huecos libres. |
| 23 | 10, 12 | La cuenta destino ya tiene el máximo de Caballeros de la Muerte (en el 12, la original) | … |
| 24 | 10, 12 | La cuenta del personaje tiene un ban activo | … |
| 25 | 10 | La cuenta destino tiene un ban activo | … |
| 40 | 11 | En ruta de vuelo | El personaje estaba en una ruta de vuelo. |
| 41 | 11 | En campo de batalla o arena | … |
| 42 | 11 | Caballero de la Muerte en su zona de inicio | … |
| 43 | 11 | Mapa no válido | … |
| 44 | 11 | Ya se desatascó hace menos de 60 min | Espera antes de volver a pedirlo. |
| 60 | 12 | El personaje no está borrado | El personaje no está borrado. |
| 61 | 12 | El nombre temporal ya existe (casi imposible) | Contacta con el staff. |

Una petición con error **no se reintenta**: queda cerrada y el usuario puede volver a pedirla en el momento.
Un error no cuenta para el tiempo de espera del desatasco.

## 5. Flujo de cada acción

### 5.1 Desatascar (tipo 11)

1. El usuario elige uno de **sus** personajes y pulsa "Desatascar".
2. La web comprueba (consultas en `CharacterSupport::findCharacter` y `checkUnstuck`):
   - el personaje es del usuario: `characters.account` = id de la cuenta del usuario conectado;
   - no está borrado: `deleteDate IS NULL`;
   - no está conectado: `online = 0`;
   - salió hace al menos 60 s: `UNIX_TIMESTAMP() - logout_time >= 60`;
   - no está en vuelo: `taxi_path` vacío;
   - no se desatascó en los últimos 60 min (`unstuckCooldownRemaining`);
   - no tiene otra petición de desatasco pendiente.
3. Si todo es correcto, inserta la petición y muestra "Petición enviada. Se procesará en menos de 1 minuto".
4. Consulta el resultado (ver §6) y muestra el mensaje del código.

Estar en la pantalla de selección de personajes **no** impide desatascar. Los casos de mapa (campo de batalla,
zona de inicio de los Caballeros de la Muerte, mapa no válido) no hace falta comprobarlos en la web: el
servidor devuelve 41, 42 o 43.

### 5.2 Cambio de cuenta (tipo 10)

1. El usuario, o el staff, elige el personaje y la cuenta destino.
2. La web comprueba (`checkChangeAccount`):
   - el personaje es del usuario, o quien lo pide es staff;
   - el personaje no está borrado;
   - la cuenta destino existe y no es la misma;
   - ninguna de las dos cuentas tiene un ban activo;
   - la cuenta destino tiene huecos (`CharactersPerRealm`) y, si es Caballero de la Muerte, no supera
     `DeathKnightsPerRealm`;
   - no hay otro cambio de cuenta pendiente.
3. Inserta la petición, avisando de que si el personaje está conectado será expulsado del juego.
4. Consulta y muestra el resultado.

### 5.3 Restaurar personaje borrado (tipo 12)

**Sustituye al `UPDATE characters ...` que hace ahora la web para restaurar.** Ese `UPDATE` deja el personaje
bien en la BD, pero el servidor del juego no se entera hasta el siguiente reinicio: los GM no lo encuentran
(`.pinfo` responde "player not found") y otras funciones lo ven sin cuenta. Además no comprueba los huecos de la
cuenta y falla si su nombre ya lo usa otro personaje. **La web no debe volver a modificar `characters`.**

1. El usuario ve la lista de sus personajes borrados (`deleteInfos_Account` = su cuenta y `deleteDate` no nulo)
   y pulsa "Restaurar" en uno.
2. La web comprueba (`checkRestore`):
   - el personaje está borrado y `deleteInfos_Account` es la cuenta del usuario;
   - la cuenta no tiene un ban activo;
   - la cuenta tiene huecos (`CharactersPerRealm`) y, si es Caballero de la Muerte, no supera
     `DeathKnightsPerRealm`;
   - no hay otra restauración pendiente.
3. Inserta la petición (`type = 12`, `params = NULL`) y avisa de lo que va a pasar: el personaje vuelve con un
   **nombre temporal** (su nombre recortado + su guid en hexadecimal, por ejemplo `Jildorpa1096`) y **tendrá que
   elegir un nombre nuevo** al entrar al juego. `CharacterSupport::restoreTempName` calcula ese nombre para
   mostrarlo.
4. Consulta y muestra el resultado. Con `status = 1` el personaje ya está en la cuenta.

El servidor siempre restaura en la cuenta original y siempre obliga a cambiar el nombre. La web no elige ni
la cuenta ni el nombre.

## 6. Consultas

```sql
-- Crear petición (consultas preparadas)
INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, 10, ?, ?);   -- params = json_encode(...)
INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, 11, NULL, ?);
INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, 12, NULL, ?);

-- Resultado de una petición
SELECT status, result_code, created_at, processed_at FROM character_support WHERE id = ?;

-- Historial de un personaje
SELECT id, type, status, result_code, created_at, processed_at
FROM character_support WHERE guid = ? ORDER BY id DESC;
```

Para mostrar el resultado hay dos opciones:

- **Consultar periódicamente** la petición por su `id` (cada 5–10 s) hasta que `status` deje de ser 0. Pasados
  unos minutos, deja de consultar y muestra "Se procesará en breve, vuelve a mirarlo más tarde".
- O, más sencillo, mostrar una lista de "Mis peticiones" que el usuario actualiza a mano.

## 7. Seguridad

- **Propiedad del personaje**: comprobarla siempre en el servidor web, nunca fiarse del `guid` que llega del
  formulario.
- **CSRF**: proteger los formularios con un token, como el resto de acciones de la web.
- **Consultas preparadas** para todo y `params` con `json_encode`.
- **Usuario de BD propio de la web** con los permisos mínimos:

  ```sql
  GRANT SELECT, INSERT ON characters.character_support TO 'web'@'host';
  GRANT SELECT ON characters.characters TO 'web'@'host';
  GRANT SELECT ON auth.account TO 'web'@'host';
  GRANT SELECT ON auth.account_banned TO 'web'@'host';
  ```

  **Nunca** `UPDATE` ni `DELETE` sobre `character_support`.
- **Abusos**: la restricción de una petición pendiente por personaje y el tiempo de espera del desatasco ya
  limitan el ritmo. Si se quiere, la web puede añadir un límite por usuario (por ejemplo, N peticiones al día).
  Si las acciones cuestan créditos, ver §8.

## 8. Cobro con créditos

Si el cambio de cuenta o la restauración cuestan créditos, el cobro tiene que cumplir tres cosas:
- cobrar **solo** lo que el servidor llega a hacer;
- **nunca** cobrar dos veces;
- **nunca** devolver dos veces.

El prototipo lo implementa en [`src/Credits.php`](../src/Credits.php) y en `createRequest` de
[`public/api.php`](../public/api.php). La sección "4. Créditos" de la página muestra cada movimiento.

### 8.1 Por qué no basta con descontar al insertar

Los créditos están en la BD de la web y la petición en la BD `characters`. Son conexiones distintas y no se
pueden meter en una misma transacción. Además, el resultado llega hasta un minuto después y puede ser un error
(personaje conectado, cuenta llena, caducada…). Si se descuenta al pedir y no se mira el resultado, se cobran
peticiones que el servidor ha rechazado.

### 8.2 Flujo: reservar, insertar, liquidar

Cada cargo tiene un estado: `reserved` (reservado), `charged` (cobrado) o `refunded` (devuelto).

1. **Reservar** (BD web, en una transacción):
   ```sql
   UPDATE wallet SET credits = credits - :coste WHERE user_id = :usuario AND credits >= :coste;
   -- si afecta a 0 filas: "créditos insuficientes", no se sigue
   INSERT INTO credit_charge (user_id, guid, type, amount, state) VALUES (..., 'reserved');
   ```
   El `UPDATE` con `credits >= :coste` es atómico. Si llegan dos peticiones a la vez con saldo para una sola,
   solo pasa una. **No** leer el saldo con un `SELECT` y restar después en PHP.
2. **Insertar** la petición con `requested_by = 'web:<usuario>:charge:<id del cargo>'` y guardar su `id` en el
   cargo (`credit_charge.request_id`). Si el `INSERT` falla:
   - **Error 1062** (ya había una pendiente): seguro que no se insertó, así que se **devuelve** al momento.
   - **Otro error**, por ejemplo se corta la conexión: no se sabe si llegó a insertarse. El cargo se queda
     reservado y lo resuelve el paso 3.
3. **Liquidar**, en un cron cada minuto y también al abrir "Mis peticiones". Para cada cargo `reserved`, mirar su
   petición:

   | Petición | Qué hacer con el cargo |
   |---|---|
   | `status = 0` | Nada: sigue pendiente |
   | `status = 1` | `charged`. Ya está cobrado; no se toca el saldo |
   | `status = 2`, con cualquier código (también el 3, caducada) | `refunded` y devolver el importe |
   | Sin `request_id` después de 5 min | Buscar la petición por `guid` + `requested_by`. Si existe, se enlaza y se sigue como arriba. Si no, `refunded` |
   | La fila ya no existe | No adivinar: que lo revise el staff (no debería pasar, ver §8.3) |

   Cada cambio de estado se hace **solo si el cargo sigue reservado**, y la devolución va en la misma
   transacción:
   ```sql
   START TRANSACTION;
   UPDATE credit_charge SET state = 'refunded', result_code = ?, settled_at = NOW() WHERE id = ? AND state = 'reserved';
   -- solo si ha afectado a 1 fila:
   UPDATE wallet SET credits = credits + <importe del cargo> WHERE user_id = <usuario del cargo>;
   COMMIT;
   ```
   Así, aunque el cron y la página liquiden a la vez, o se repita la liquidación, cada cargo se cobra o se devuelve
   una sola vez.

### 8.3 Abusos y casos a tener en cuenta

- **Doble clic o pestañas a la vez**: el `UPDATE` condicional impide gastar el mismo saldo dos veces. El índice
  único de `character_support` impide dos peticiones pendientes iguales, y su error 1062 devuelve el cargo.
- **Precio y personaje los decide el servidor web**: el coste sale de la configuración de la web según el tipo,
  nunca del formulario. La propiedad del personaje se comprueba siempre (§7).
- **Quién paga**: la cuenta dueña del personaje en el momento de pedirlo. En la restauración es la dueña
  original (`deleteInfos_Account`), que es también la única que puede pedirla.
- **Devolver solo por el resultado del servidor**: la web no puede cancelar peticiones (§12), así que no hay
  devoluciones por cancelación. Un cargo solo se devuelve con `status = 2`, con el error 1062 o con el caso del
  cargo huérfano.
- **Todos los errores se devuelven**, también los que causa el propio jugador (por ejemplo, el código 4 por estar
  conectado). Es lo más sencillo de explicar y no da ninguna ventaja: puede repetir la petición, pero volverá a
  pagar.
- **Peticiones que se hacen y luego se deshacen** (restaurar, volver a borrar y volver a restaurar; pasar un
  personaje de A a B y de vuelta a A): cada petición correcta se cobra. No hace falta tratarlas aparte.
- **Liquidar antes de que el servidor purgue el historial**: el worldserver borra las peticiones cerradas a los
  30 días (`HistoryDays`). Con un cron cada minuto no hay problema, pero si el cron se para semanas, los cargos que
  queden reservados tendrá que revisarlos el staff.
- **Peticiones del staff**: no se cobran ni se apunta cargo. Usar un `requested_by` distinto (`staff:<nombre>`).
- **Tipos gratis** (desatascar, por defecto): no se apunta cargo.

## 9. Casos que hay que tener en cuenta

- **Error 1062 al insertar**: ya hay una petición pendiente de ese tipo para el personaje (por ejemplo, un
  doble clic). Mostrar "Ya tienes una petición en curso", no un error genérico.
- **Servidor del juego parado**: las peticiones se quedan pendientes. Cuando arranca, las procesa; las que
  llevan más de 24 h pendientes se cierran con el código 3 sin ejecutarse.
- **Historial**: el servidor borra las peticiones cerradas a los 30 días. Si la web necesita guardarlas más
  tiempo, que lo haga en una tabla suya.
- **Fechas**: `created_at` y `processed_at` son `TIMESTAMP`, así que se muestran en la zona horaria de la
  conexión. Para tiempos relativos ("hace 3 min", "faltan 20 min") calcularlos en SQL con `NOW()` y
  `TIMESTAMPDIFF`, como hace el prototipo, y no comparando con la hora de PHP.
- **Lista de personajes de la web**: tras un cambio de cuenta, el personaje aparece en la nueva cuenta en
  cuanto `status = 1`. Tras un desatasco, `map`, `zone` y la posición ya están actualizados en ese momento.

## 10. Valores que deben coincidir con el worldserver

La web usa estos valores en sus comprobaciones previas. Deben ser los mismos que los del `worldserver.conf`
del reino. Si no coinciden no pasa nada grave, porque el servidor manda, pero el usuario vería mensajes
incoherentes (la web le deja pedirlo y el servidor lo rechaza, o al revés).

| Valor en la web | Opción del `worldserver.conf` | Por defecto |
|---|---|---|
| Personajes por cuenta | `CharactersPerRealm` | 10 |
| Caballeros de la Muerte por cuenta | `DeathKnightsPerRealm` | 1 |
| Espera entre desatascos (min) | `CharacterSupport.Unstuck.Cooldown` | 60 |
| Caducidad de las peticiones (h) | `CharacterSupport.ExpireHours` | 24 |
| Ciclo del servidor (min) | `CharacterSupport.Interval` | 1 |

## 11. Tipos nuevos en el futuro

La tabla está pensada para crecer sin cambiar su estructura (cambio de nombre, cambio de líder de hermandad…):

- Cada tipo nuevo necesita su parte en el emulador. **La web no debe insertar tipos que el emulador no
  conozca**: se quedarían pendientes y caducarían a las 24 h con el código 3.
- Los datos propios de cada tipo van en `params` como objeto JSON, con claves en minúsculas y números como
  número. Por ejemplo, un cambio de nombre podría ser `{"name": "Nuevonombre"}`.
- Los números de tipo y de código se acuerdan con el equipo del emulador. Reparto actual de códigos: 0–19
  comunes, 20–39 cambio de cuenta, 40–59 desatascar, 60–79 restaurar. Cada tipo nuevo recibe su propio bloque de 20.

## 12. Preguntas frecuentes

**¿Por qué no es instantáneo?**
El servidor del juego revisa la tabla cada minuto. Así la web no necesita conectarse al servidor del juego ni
tocar los datos del personaje mientras el juego los usa.

**¿Por qué comprueba cosas la web si el servidor ya las comprueba?**
Para responder al momento. Sin ellas, el usuario esperaría un minuto para enterarse de que, por ejemplo, su
personaje estaba conectado.

**Una petición lleva mucho tiempo pendiente.**
Lo normal es que se procese en menos de un minuto. Si pasan varios minutos, probablemente el servidor del
juego está parado o reiniciando. A las 24 h se cierra sola con el código 3.

**¿Puede la web cancelar una petición pendiente?**
No, porque la web no puede hacer `UPDATE` ni `DELETE`. Como se procesa en menos de un minuto, no hace falta.

**¿Qué pasa si el personaje entra al juego justo después de pedir el desatasco?**
El servidor lo detecta y la cierra con el código 4. Basta con salir del juego, esperar un minuto y volver a
pedirlo.
