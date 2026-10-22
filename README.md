# prototipo_character_support

Prototipo de ejemplo del sistema **Character Support** de NaerEmu: la web pide acciones sobre personajes
(desatascar, cambiar de cuenta) insertando filas en `characters.character_support`, y el worldserver las procesa.

El objetivo es que el equipo web vea el funcionamiento completo y sepa qué tiene que implementar. **No es código
para producción**: no tiene inicio de sesión y deja saltarse las comprobaciones para hacer pruebas.

**Equipo web:** empezad por la [guía de integración](docs/INTEGRACION.md).

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

## Para el equipo web

Todo lo necesario para implementarlo en la web real (tabla, tipos, códigos de resultado, consultas, flujo de cada
acción, seguridad y casos especiales) está en **[docs/INTEGRACION.md](docs/INTEGRACION.md)**.

## Ficheros

| Fichero | Qué es |
|---|---|
| `docs/INTEGRACION.md` | **Guía para el equipo web** |
| `src/CharacterSupport.php` | **Lógica de la web**: comprobaciones, inserción y consulta. Es lo que hay que reproducir |
| `src/Db.php` | Conexión PDO. Registra el SQL ejecutado solo para mostrarlo en el prototipo |
| `public/api.php` | API JSON que usa la página |
| `public/index.html`, `app.js`, `style.css` | Interfaz |
| `config.example.php` | Plantilla de configuración (`config.php` no se sube a git) |
