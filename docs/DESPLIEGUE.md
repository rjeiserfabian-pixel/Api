# Guía de despliegue a producción

Esta guía lleva el sistema desde "funciona en mi PC" hasta "atiende clientes reales". Está pensada para hacerla **cuando tenga el servidor**; todo lo que se puede preparar antes (herramientas, configuraciones de ejemplo, respaldos, verificación) ya está en el proyecto.

> **Regla de oro:** no abra el servicio al público hasta que `php bin/verificar.php --produccion` termine sin `[ERROR]` y todas las pruebas (`docs/probar*.php`) salgan en `[OK]` **en el servidor nuevo**.

## 0. Resumen en una página

1. Contratar un VPS (Linux) y un dominio.
2. Instalar Apache + PHP + PostgreSQL (sección 2).
3. Copiar el proyecto, generar claves y crear el `.env` de producción (sección 3).
4. Activar HTTPS con certbot (sección 4).
5. Publicar los sitios con `deploy/apache-sitios.conf` (sección 5).
6. Dejar el worker como servicio (sección 6) y programar los respaldos (sección 7).
7. Verificar (sección 8), probar con llaves `sk_test_` (sección 8) y recién entonces crear clientes y llaves de producción.

**Disposición recomendada** (dos nombres, un solo proyecto):

| Dirección | Contenido |
|---|---|
| `https://www.midominio.com` | Página pública: promoción, planes, contacto y **verificación de documentos** (carpeta `web/`) |
| `https://api.midominio.com` | API (`/v1`), portal de clientes (`/portal`), panel del operador (`/operador`) y documentación (`/docs`) |
| `http://127.0.0.1:8081` | Motor interno. Solo el propio servidor lo ve; la API le habla por aquí |

## 1. Qué servidor contratar

- **VPS Linux** con 2 vCPU, 4 GB de RAM y 40 GB de disco para empezar. Los comprobantes (XML/CDR/PDF) crecen todo el tiempo y la ley exige conservarlos varios años: planifique disco y respaldos.
- **Sistema**: Ubuntu 20.04/22.04 o Debian 11/12.
- **Ubicación**: antes de comprometerse a un contrato largo, compruebe que **desde ese servidor se llega a SUNAT** (emita una boleta de prueba con la llave `sk_test_`). Algunos proveedores o países pueden tener restricciones o más latencia; un VPS en Perú o Latinoamérica suele ser lo más seguro.
- **Dominio** con acceso a su panel de DNS: necesitará dos registros `A` (`www` y `api`) hacia la IP del servidor.
- **Windows/XAMPP no se recomienda** para producción (hay una opción en la sección 6, pero Linux es más estable y seguro).

> **Sobre la versión de PHP.** El sistema se desarrolló y se probó con **PHP 7.2**. El código nuevo (API, portal, panel, sitio) usa solo funciones compatibles con 7.2 en adelante, pero el **motor antiguo** (`post.php`, Greenter y las librerías de firma/PDF) **no se ha probado con PHP 8**. Lo más seguro es instalar **PHP 7.4** (Ubuntu 20.04 y Debian 11 lo traen; en Ubuntu 22.04 se obtiene con `ppa:ondrej/php`). Si prefiere PHP 8.x, ejecute **todas** las pruebas antes de abrir al público y esté preparado para ajustes.

## 2. Instalar el software (Ubuntu/Debian, ejemplo con PHP 7.4)

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install apache2 postgresql git unzip curl ufw fail2ban certbot python3-certbot-apache
# PHP 7.4 con PHP-FPM y las extensiones que usa el sistema
sudo apt -y install php7.4-fpm php7.4-cli php7.4-curl php7.4-mbstring php7.4-gd php7.4-zip php7.4-soap php7.4-xml php7.4-pgsql php7.4-sqlite3
sudo a2enmod rewrite headers ssl proxy proxy_fcgi setenvif
```

**Cortafuegos y acceso** (haga esto el primer día):

```bash
sudo ufw default deny incoming && sudo ufw allow OpenSSH && sudo ufw allow 80 && sudo ufw allow 443 && sudo ufw enable
# Entre por SSH con llave (no con contraseña) y desactive el acceso de root:
#   /etc/ssh/sshd_config -> PasswordAuthentication no, PermitRootLogin no
```

**PostgreSQL** (recomendado para producción):

```bash
sudo -u postgres psql <<'SQL'
CREATE USER api_sunat WITH PASSWORD 'UNA-CONTRASENA-LARGA-Y-UNICA';
CREATE DATABASE api_sunat OWNER api_sunat ENCODING 'UTF8' TEMPLATE template0;
SQL
```

**php.ini** (`/etc/php/7.4/fpm/php.ini` y `/etc/php/7.4/cli/php.ini`):

```ini
display_errors = Off
expose_php = Off
memory_limit = 256M
max_execution_time = 120
```

Reinicie: `sudo systemctl restart php7.4-fpm apache2`.

## 3. Instalar el proyecto

```bash
sudo mkdir -p /var/www/api-sunat && sudo chown $USER: /var/www/api-sunat
git clone <URL-DE-SU-REPOSITORIO> /var/www/api-sunat
cd /var/www/api-sunat

# Claves (genera APP_KEY e INTERNAL_TOKEN en .env). GUARDE UNA COPIA DE APP_KEY EN SU GESTOR DE CONTRASEÑAS.
php bin/admin.php init
```

> **APP_KEY es insustituible.** Con ella se cifran las claves SOL, los secretos GRE y las llaves privadas de los certificados. Si se pierde, esos datos no se pueden recuperar. Tampoco debe cambiarse una vez en uso (los códigos QR/enlaces de verificación ya impresos dejarían de funcionar).

Complete el `.env` a partir de `deploy/env.produccion.example` (dominios, base de datos, `BLOQUEAR_LEGACY=1`, `PORTAL_REQUIERE_HTTPS=1`, etc.):

```bash
nano .env              # use deploy/env.produccion.example como guía
chmod 600 .env
```

**Permisos** (el servidor web y el worker deben poder escribir en estas carpetas):

```bash
sudo chown -R www-data:www-data storage logs files
sudo chmod -R u+rwX,g-w,o-rwx storage logs
```

**Pasar de SQLite a PostgreSQL** (si ya tenía datos de pruebas que quiere conservar; si empieza de cero, basta con poner `DB_DSN` y ejecutar `php bin/admin.php init`):

```bash
php bin/admin.php db:copiar --dsn="pgsql:host=127.0.0.1;port=5432;dbname=api_sunat" --user=api_sunat --pass='LA-CONTRASENA'
```

**Datos iniciales:**

```bash
php bin/admin.php plan:sembrar                         # crea los planes recomendados
php bin/admin.php operador:crear --email=usted@correo.com --nombre="Su nombre"   # su cuenta del panel /operador/
```

Después entre al panel del operador: ajuste **precios**, **días de prueba**, **plan al vencer** e **instrucciones de pago** (menús *Planes* y *Ajustes*).

## 4. HTTPS (certificados gratuitos de Let's Encrypt)

Con los registros DNS ya apuntando al servidor:

```bash
sudo certbot --apache -d api.midominio.com -d www.midominio.com -d midominio.com
sudo systemctl list-timers | grep certbot     # renovación automática activa
```

## 5. Publicar los sitios (Apache)

```bash
sudo cp deploy/apache-sitios.conf /etc/apache2/sites-available/api-sunat.conf
sudo nano /etc/apache2/sites-available/api-sunat.conf      # cambie midominio.com, rutas y el socket de PHP-FPM (ls /run/php/)
sudo a2dissite 000-default
sudo a2ensite api-sunat
sudo apache2ctl configtest && sudo systemctl reload apache2
```

Compruebe: `curl https://api.midominio.com/v1/health` y abra `https://www.midominio.com`.

*Nginx*: hay una plantilla de referencia en `deploy/nginx-sitios.conf`, pero **no está probada** (Nginx no lee los `.htaccess` del proyecto). Úsela solo si tiene motivos; el verificador (sección 8) comprueba por red que nada sensible quede expuesto.

## 6. El worker (obligatorio)

El worker reintenta los envíos fallidos, entrega los webhooks, consulta los tickets de guías y **procesa los vencimientos de las suscripciones**. Sin él, nada de eso ocurre; el panel del operador avisa en rojo si está detenido.

**Linux (systemd):**

```bash
sudo cp deploy/api-sunat-worker.service /etc/systemd/system/
sudo systemctl daemon-reload && sudo systemctl enable --now api-sunat-worker
systemctl status api-sunat-worker          # "active (running)"
```

**Windows** (solo si debe): `powershell -ExecutionPolicy Bypass -File deploy\windows-tareas.ps1 -Php "C:\php\php.exe" -Proyecto "C:\sitios\api-sunat"`.

Para detenerlo con calma cree el archivo `storage/worker.stop`.

## 7. Respaldos (obligatorio)

Qué se respalda: la **base de datos**, el **`.env`** (con `APP_KEY`), los **comprobantes** (XML/CDR) y los **logos** de las empresas (`storage/logos`, para sus PDF). Todo con huellas SHA-256 y verificación automática.

```bash
# Probar a mano
php bin/respaldo.php crear --clave="UNA-FRASE-LARGA" --retener=14
php bin/respaldo.php listar
php bin/respaldo.php verificar --clave="UNA-FRASE-LARGA"
```

- **Programarlo cada día:** `deploy/api-sunat-respaldo.service` + `.timer` (instrucciones dentro del archivo). La frase de cifrado va en `/etc/api-sunat-respaldo.env` (permisos 600) **y en su gestor de contraseñas**.
- **Copias fuera del servidor (indispensable):** un respaldo en el mismo disco no sirve si el servidor se pierde. Copie la carpeta de respaldos a otro lugar, por ejemplo con `rclone` (Google Drive, S3, Backblaze) o `rsync` a otro equipo, y compruebe de vez en cuando que llegó.
- **Comprobantes:** la ley exige conservarlos (consulte con su contador el plazo vigente; suele ser de 5 años o más). Los respaldos con `--retener=14` guardan 14 días de copias completas; para el archivo histórico de comprobantes conserve además una copia mensual.
- **PostgreSQL:** el respaldo usa `pg_dump` (se instala con `postgresql-client`). Debe poder conectarse con las credenciales del `.env`.
- **Pruebe restaurar** al menos una vez al mes (sección 10): un respaldo que nunca se probó no es un respaldo.

## 8. Verificar antes de abrir al público

```bash
php bin/verificar.php --produccion
```

Revisa: extensiones de PHP, `.env` (claves, HTTPS, motor bloqueado), base de datos, permisos, espacio en disco, que `.env`/la base/los logs/el código **no se puedan descargar por web**, que el motor rechace peticiones directas, que el worker esté vivo, que exista un operador, certificados por vencer y respaldo reciente. Debe terminar sin `[ERROR]`.

Luego, con una llave de **pruebas** (`sk_test_`), ejecute todas las pruebas en el servidor:

```bash
php docs/probar.php --key=sk_test_XXXX && php docs/probar_numeracion.php --key=sk_test_XXXX && php docs/probar_reintentos.php --key=sk_test_XXXX
php docs/probar_limites.php && php docs/probar_portal.php --base=https://api.midominio.com && php docs/probar_operador.php --base=https://api.midominio.com
php docs/probar_cobros.php --base=https://api.midominio.com && php docs/probar_respaldo.php
php docs/probar_verificacion.php --base=https://api.midominio.com --key=sk_test_XXXX && php docs/probar_pdf.php --base=https://api.midominio.com --key=sk_test_XXXX
```

(Las pruebas del portal/operador/cobros crean y borran empresas de laboratorio; la de respaldo no toca nada del sistema.) Si algo falla, **no abra** hasta resolverlo.

**Primer cliente real:** cree su empresa (panel del operador → *Empresas → Nuevo cliente*), que el cliente cargue su certificado digital y credenciales SOL desde su portal, emita primero con `sk_test_` y solo después cree su llave `sk_live_`.

## 9. Operación diaria

| Qué | Cómo |
|---|---|
| **Monitoreo** | Un servicio externo (UptimeRobot, Better Stack…) que consulte `https://api.midominio.com/v1/health` cada minuto y le avise por correo/WhatsApp. El panel del operador muestra el estado del worker. |
| **Logs** | `logs/php_errors.log`, `logs/worker.log` y los de Apache. Ejemplo de rotación en la sección 11. |
| **Actualizar** | `git pull` → `php bin/verificar.php --produccion` → `sudo systemctl restart api-sunat-worker php7.4-fpm`. Los cambios de base de datos se aplican solos al primer uso. **Antes de actualizar, haga un respaldo.** |
| **Volver atrás** | `git checkout <version-anterior>` y reinicie el worker. Si la actualización cambió la base de datos, restaure el respaldo previo (sección 10). |
| **Contactos y cobros** | Revise a diario el panel: *Contactos del sitio*, *Cobros* (por vencer, en gracia) y *Solicitudes de plan*. No hay correos automáticos. |
| **Certificados de clientes** | El verificador avisa los que vencen en menos de 30 días: avise al cliente. |

## 10. Restaurar un respaldo (recuperación ante desastres)

El comando **nunca pisa** el sistema en uso: deja todo en una carpeta nueva.

```bash
php bin/respaldo.php restaurar --desde=/var/backups/api-sunat/20261113-023000 --destino=/var/restauracion --clave="LA-FRASE"
# deja:  /var/restauracion/base.sqlite (o base.sql), /var/restauracion/.env y /var/restauracion/files/
```

Para ponerlo en servicio en un servidor nuevo:

1. Instale el software (sección 2) y copie el proyecto (sección 3, **sin** ejecutar `init`).
2. Copie `/var/restauracion/.env` a `/var/www/api-sunat/.env` (`chmod 600`) — contiene la `APP_KEY` original.
3. **SQLite:** copie `base.sqlite` a `storage/api.sqlite`. **PostgreSQL:** `psql -U api_sunat -h 127.0.0.1 api_sunat < base.sql`.
4. Copie `files/` a `/var/www/api-sunat/files/` y corrija permisos (`chown -R www-data:www-data storage logs files`).
5. Si la restauración trajo `storage/logos/`, déjelo en `/var/www/api-sunat/storage/logos/` (con los permisos del servidor web).
6. Inicie el worker, ejecute `php bin/verificar.php --produccion` y las pruebas de la sección 8.

## 11. Rotación de logs (opcional pero recomendada)

`/etc/logrotate.d/api-sunat`:

```
/var/www/api-sunat/logs/*.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
```

## 12. Lista de seguridad final

- [ ] HTTPS activo y renovándose (`certbot`), `PORTAL_REQUIERE_HTTPS=1`.
- [ ] `BLOQUEAR_LEGACY=1` y `php bin/verificar.php --produccion` sin errores.
- [ ] Acceso SSH solo con llave, cortafuegos activo (`ufw status`), `fail2ban` instalado.
- [ ] Panel del operador limitado a sus IP (`OPERADOR_IPS_PERMITIDAS`) y con contraseña larga y única.
- [ ] **Credenciales expuestas rotadas:** clave SOL y `client_id`/`client_secret` de GRE que alguna vez estuvieron en un JSON de prueba o en el historial de git.
- [ ] Repositorio de git **privado** y sin secretos (`.env`, certificados) en el historial.
- [ ] Respaldos diarios funcionando, **copiados fuera del servidor** y con una restauración de prueba exitosa.
- [ ] Actualizaciones de seguridad del sistema operativo (`unattended-upgrades`).
- [ ] Páginas legales (privacidad y términos) publicadas en el sitio.
- [ ] Monitoreo externo de `/v1/health` configurado.
