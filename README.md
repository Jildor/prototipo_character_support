# prototipo_character_support

Prototipo de ejemplo del sistema **Character Support** de NaerEmu: la web pide acciones sobre personajes
(desatascar, cambiar de cuenta) insertando filas en `characters.character_support`, y el worldserver las procesa.

El objetivo es que el equipo web vea el funcionamiento completo y sepa qué tiene que implementar. **No es código
para producción**: no tiene inicio de sesión y deja saltarse las comprobaciones para hacer pruebas.

## Cómo funciona

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

- La web **solo** hace `SELECT` e `INSERT`. El estado lo escribe siempre el worldserver.
- Las comprobaciones de la web sirven para responder al momento. El worldserver las repite todas y su
  resultado (`result_code`) es el definitivo.

## Ejecutarlo en local (Debian en WSL2 + Docker)

Todo se hace desde la consola de **Debian**.

1. Ir a la carpeta y crear la configuración:
   ```bash
   cd /mnt/d/git/ComunidadRean/prototipo_character_support
   cp config.example.php config.php
   nano config.php        # usuario y contraseña de MariaDB del reino de desarrollo
   ```
2. Abrir el túnel SSH a la máquina de desarrollo, en **otra consola de Debian** (déjala abierta):
   ```bash
   ssh -N -L 127.0.0.1:13306:127.0.0.1:3306 usuario_ssh@IP_MAQUINA_DESARROLLO
   # si el SSH no está en el puerto 22:  ssh -N -p 2222 -L ... usuario_ssh@IP_MAQUINA_DESARROLLO
   ```
   - `usuario_ssh@IP_MAQUINA_DESARROLLO`: los mismos datos con los que entras por SSH (o los de la pestaña SSH
     de SQLyog). Ahí va la IP o el dominio de la máquina.
   - `127.0.0.1:13306` (primera parte): el puerto local por el que se conecta el prototipo (`host` y `port` de
     `config.php`). No hace falta tocarlo.
   - `127.0.0.1:3306` (segunda parte): dónde está MariaDB **visto desde la máquina de desarrollo**. Si MariaDB
     está en esa misma máquina se deja así; si no, pon su IP y puerto.
   - Pedirá la contraseña o usará tu clave SSH y se quedará "colgado" sin mostrar nada: eso es que funciona.
3. Arrancar el prototipo:
   ```bash
   docker compose up --build
   ```
4. Abrir en el navegador de Windows: **http://localhost:8090**

Para pararlo, `Ctrl+C` (o `docker compose down`). Para usar otro puerto: `PORT=8091 docker compose up`.

<details>
<summary>Sin Docker</summary>

```bash
sudo apt install php-cli php-mysql
php -S 0.0.0.0:8090 -t public
```
</details>

Si `localhost:8090` no responde desde Windows, prueba con la IP de Debian (`hostname -I`).

### Permisos del usuario de BD

El prototipo, como la web, solo necesita:

```sql
GRANT SELECT, INSERT ON characters.character_support TO 'usuario'@'%';
GRANT SELECT ON characters.characters TO 'usuario'@'%';
-- Solo si se usa auth_db:
GRANT SELECT ON auth.account TO 'usuario'@'%';
GRANT SELECT ON auth.account_banned TO 'usuario'@'%';
```

**Nunca** `UPDATE` ni `DELETE` sobre `character_support`.

### BD auth (opcional)

La BD de cuentas puede estar en otro servidor (por ejemplo, compartida entre reinos). Con `auth_db = null`
el prototipo funciona solo con `characters`:

- La cuenta destino se escribe por **id**, no por nombre, y no se comprueba que exista.
- No se comprueban los bans.

No se pierde nada: el worldserver repite esas comprobaciones y devuelve los códigos 20, 24 y 25. La **web
real** sí tiene acceso a las cuentas (es donde inicia sesión el usuario), así que debe hacerlas antes de
insertar para avisar al momento. Las consultas están en `CharacterSupport::findAccount` e `isAccountBanned`.

## Uso

1. **Buscar personaje** por nombre o guid. Muestra cuenta, estado (conectado o borrado), posición, última salida
   y si puede desatascarse.
2. **Pedir soporte**: desatascar o cambiar de cuenta. La web hace sus comprobaciones previas y, si pasan, inserta
   la petición.
   - *Saltar las comprobaciones de la web* inserta igualmente, para comprobar que el servidor rechaza los casos
     inválidos por su cuenta.
3. **Peticiones**: lista que se actualiza cada 3 s. Verás la fila en *Pendiente* hasta el siguiente ciclo del
   worldserver y luego en *Hecho* o *Error* con el motivo.
4. **SQL de la última acción**: las consultas exactas que ha lanzado la web, con sus parámetros.
5. **Inserción manual**: inserta sin comprobar nada (JSON mal formado, parámetros inválidos, tipos desconocidos).

## Contrato con la BD

### Tabla `characters.character_support`

| Columna | La escribe | Significado |
|---|---|---|
| `id` | BD | Id de la petición (`LAST_INSERT_ID()` tras insertar) |
| `guid` | Web | `characters.guid` del personaje |
| `type` | Web | 10 = cambio de cuenta, 11 = desatascar |
| `params` | Web | JSON con los parámetros del tipo, o `NULL`. Generarlo **siempre** con `json_encode` |
| `requested_by` | Web | Quién lo pide (cuenta web, GM…), para auditoría |
| `status` | worldserver | 0 pendiente, 1 hecho, 2 error |
| `result_code` | worldserver | Motivo (tabla de abajo) |
| `created_at` / `processed_at` | BD / worldserver | Fechas de creación y de cierre |

Solo puede haber **una petición pendiente** del mismo tipo por personaje. Un segundo `INSERT` falla con el
error **1062** ("ya tienes una petición en curso"). Las peticiones cerradas no cuentan.

### Tipos

| `type` | Acción | `params` |
|---|---|---|
| 10 | Cambiar el personaje de cuenta (si está conectado, se le expulsa) | `{"account": <id cuenta destino>}` |
| 11 | Desatascar: Alianza → Páramos de Poniente, Horda → Los Baldíos. Debe estar desconectado | `NULL` |

### Códigos de resultado

| Código | Significado |
|---|---|
| 0 | Hecho |
| 1 | El personaje no existe o está borrado |
| 2 | Parámetros inválidos |
| 3 | Caducada (más de `CharacterSupport.ExpireHours` pendiente) |
| 4 | Conectado, entrando al mundo o desconectado hace menos de 60 s (en la selección de personajes sí se permite) |
| 20 | La cuenta destino no existe |
| 21 | Ya está en esa cuenta |
| 22 | Cuenta destino sin huecos |
| 23 | Cuenta destino con el máximo de Caballeros de la Muerte |
| 24 | Cuenta origen con ban activo |
| 25 | Cuenta destino con ban activo |
| 40 | En ruta de vuelo |
| 41 | En campo de batalla o arena |
| 42 | Caballero de la Muerte en su zona de inicio |
| 43 | Mapa no válido |
| 44 | Desatascado hace menos de `CharacterSupport.Unstuck.Cooldown` minutos (60 por defecto) |

### Consultas

```sql
-- Crear petición
INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, 10, ?, ?);  -- params = json_encode(['account' => 456])
INSERT INTO character_support (guid, type, params, requested_by) VALUES (?, 11, NULL, ?);

-- Resultado de una petición
SELECT status, result_code, created_at, processed_at FROM character_support WHERE id = ?;

-- Historial de un personaje
SELECT id, type, status, result_code, created_at, processed_at FROM character_support WHERE guid = ? ORDER BY id DESC;
```

El resto de consultas (comprobaciones previas) están en `src/CharacterSupport.php`, comentadas.

## Qué debe hacer la web real

1. **Comprobar que el personaje es del usuario conectado** (o que quien lo pide es staff). El prototipo no lo
   hace porque no tiene inicio de sesión.
2. **Comprobaciones previas** antes de insertar, para avisar al momento:
   - **Desatascar**: no borrado, desconectado desde hace al menos 60 s, sin tiempo de espera pendiente y sin
     otra petición en curso.
   - **Cambio de cuenta**: la cuenta destino existe, sin bans en ninguna de las dos cuentas, con huecos libres y
     con el límite de Caballeros de la Muerte.
3. **Insertar** con consultas preparadas y `params` generado con `json_encode`.
4. **Mostrar el resultado**: consultar la petición por su `id` hasta que `status` deje de ser 0, y traducir
   `result_code` a un mensaje. Los textos del prototipo están en `CharacterSupport::RESULT_MESSAGES`.
5. **Informar de la espera**: el servidor procesa las peticiones cada minuto.

## Ficheros

| Fichero | Qué es |
|---|---|
| `src/CharacterSupport.php` | **Lógica de la web**: comprobaciones, inserción y consulta. Es lo que hay que reproducir |
| `src/Db.php` | Conexión PDO. Registra el SQL ejecutado solo para mostrarlo en el prototipo |
| `public/api.php` | API JSON que usa la página |
| `public/index.html`, `app.js`, `style.css` | Interfaz |
| `config.example.php` | Plantilla de configuración (`config.php` no se sube a git) |
