# API SUNAT v1 — Guía de uso

API REST para emitir comprobantes electrónicos a SUNAT (facturas, boletas, notas, retenciones, guías, resúmenes y bajas). Usted envía un JSON; la API valida, firma el XML con el certificado de su empresa, lo envía a SUNAT y devuelve el resultado.

Referencia completa (todos los campos, ejemplos, errores): [`openapi.json`](openapi.json) — se ve en `docs/index.html`.

## 1. Empezar en 5 minutos

```bash
# 1) Compruebe su llave (debe decir "prueba" si es sk_test_)
curl -s http://localhost/API_SUNAT/v1/me -H "Authorization: Bearer sk_test_XXXXXXXX"

# 2) Emita una boleta de prueba (va al beta de SUNAT)
curl -s -X POST http://localhost/API_SUNAT/v1/comprobantes \
  -H "Authorization: Bearer sk_test_XXXXXXXX" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: venta-0001" \
  -d '{
    "cliente": {"codigo_tipo_entidad":"1","numero_documento":"12345678","razon_social_nombres":"JUAN PEREZ"},
    "venta": {"tipo_documento_codigo":"03","serie":"B001","numero":"1","fecha_emision":"2026-11-11","hora_emision":"10:00:00",
              "moneda_id":1,"forma_pago_id":1,"total_gravada":84.75,"total_igv":15.25},
    "items": [{"producto":"PRODUCTO","codigo_sunat":"50202201","cantidad":1,"codigo_unidad":"NIU","precio_base":84.75,"tipo_igv_codigo":"10"}]
  }'
```

Cambie la fecha por la de hoy y el número por uno no usado.

## 2. Llaves y entornos

| Llave | Entorno | Efecto |
|---|---|---|
| `sk_test_…` | Pruebas | Envía al **beta** de SUNAT. Sin efectos tributarios. |
| `sk_live_…` | Producción | Documentos **reales**. Exige certificado digital de producción registrado. |

El entorno lo decide la llave. La clave SOL y el certificado de su empresa están guardados en la API; **no se envían en las peticiones**.

## 3. Cómo leer la respuesta

```json
{ "ok": true, "data": { "id": "doc_…", "estado": "aceptado", "sunat": {"codigo":"0","descripcion":"…"}, "archivos": {"xml":"…","cdr":"…"} }, "request_id": "req_…" }
```

- **HTTP 201 + `estado`**: la API procesó el documento. `aceptado` = SUNAT lo aceptó. `rechazado` = SUNAT lo rechazó (`sunat.descripcion` dice por qué; corrija y reenvíe). `pendiente` = SUNAT devolvió un ticket (resúmenes, bajas). `simulado` = guía con llave `test` (no se envía).
- **HTTP 4xx**: no se procesó y **no se envió nada a SUNAT** (datos inválidos, sin autenticar, duplicado…). `error.details` lista cada campo con su corrección.
- **HTTP 5xx / 504**: fallo temporal. Reintente con la misma `Idempotency-Key`.

Guarde siempre el `request_id` (también viene en la cabecera `X-Request-Id`): es lo que se pide para soporte.

## 4. Reintentos sin duplicar: `Idempotency-Key`

Envíe una `Idempotency-Key` única por operación (por ejemplo, el id de su venta). Si no recibe respuesta, repita **la misma petición con la misma clave**: obtendrá el resultado guardado (`200` + cabecera `Idempotent-Replay: true`) sin volver a enviar a SUNAT. Además, un mismo tipo-serie-número ya aceptado o pendiente responde `409 documento_duplicado`.

## 5. Qué enviar para cada documento

| Documento | Ruta | Notas |
|---|---|---|
| Factura / boleta / nota de crédito / nota de débito | `POST /comprobantes` | `venta.tipo_documento_codigo` = `01` / `03` / `07` / `08` |
| Retención | `POST /retenciones` | Serie `R001` |
| Guía de remisión | `POST /guias` | Con `sk_test` **no se envía** (SUNAT no tiene beta de guías) |
| Resumen diario de boletas | `POST /resumenes` | Devuelve ticket |
| Baja (facturas y notas) | `POST /bajas` | Menos de 7 días; correlativo distinto en cada envío |
| Reversión | `POST /reversiones` | Devuelve ticket |
| Resultado de un ticket | `POST /tickets` | `tipo`: `guia`, `resumen` o `baja` |
| Historial | `GET /documentos`, `GET /documentos/{id}` | Filtros: `tipo`, `estado`, `serie`, `limit`, `offset` |
| Certificado digital | `GET` / `POST /certificado` | PFX o PEM |

Reglas que conviene conocer (la API las valida antes de molestar a SUNAT):

- `precio_base` es el precio unitario **sin IGV**; `total_gravada` es la suma sin IGV y `total_igv` debe ser el 18 % de eso.
- Cada ítem necesita `codigo_sunat` de 8 dígitos (catálogo 25 de SUNAT).
- Una **factura** exige cliente con RUC (`codigo_tipo_entidad` = `6`).
- Una venta **al crédito** exige `cuotas`, con fechas **posteriores** a la emisión.
- El **número**: por defecto lo envía usted; si la serie está en modo automático, la API lo asigna (sección 6).

### Anular documentos
- **Factura**: `POST /bajas` (menos de 7 días) o emita una **nota de crédito** con motivo `01`.
- **Boleta**: no existe baja para boletas; envíela en un **resumen diario** con `status` = `3`.

## 6. Numeración y sincronización con su sistema comercial

Su sistema comercial puede tener **su propia numeración** (series y correlativos) y hasta entregar la boleta al cliente **antes** de enviarla a SUNAT. La API se adapta a eso: cada serie (por empresa y entorno) tiene un **dueño**.

| Modo | Quién numera | Qué hace la API |
|---|---|---|
| `cliente` (por defecto) | Su sistema comercial | Acepta el `venta.numero` que le envíen (aunque salte o llegue desordenado) y **aprende** el último usado (`GET /series`). |
| `api` | La API | Asigna el siguiente número solo, o **reserva** números para usarlos antes de enviar. |

### Escenario A — su sistema numera (el más común)
No cambia nada: siga enviando `venta.numero`. La serie aparece sola en `GET /series` con su `ultimo_numero` y un `siguiente_numero` sugerido. Para **migrar** desde otro sistema, registre antes la serie con el último número que ya usó:

```bash
curl -s -X POST http://localhost/API_SUNAT/v1/series -H "Authorization: Bearer sk_test_XXXX" -H "Content-Type: application/json" \
  -d '{"tipo":"03","serie":"B001","modo":"cliente","ultimo_numero":150}'
```

### Escenario B — la API numera
Registre la serie en modo `api` y **omita `numero`**. Es obligatorio enviar `Idempotency-Key` (así un reintento reutiliza el mismo número):

```bash
curl -s -X POST http://localhost/API_SUNAT/v1/series -H "Authorization: Bearer sk_test_XXXX" -H "Content-Type: application/json" \
  -d '{"tipo":"03","serie":"B001","modo":"api","ultimo_numero":150}'

curl -s -X POST http://localhost/API_SUNAT/v1/comprobantes -H "Authorization: Bearer sk_test_XXXX" -H "Content-Type: application/json" \
  -H "Idempotency-Key: venta-98765" -d '{ ...boleta sin "numero"... }'
# -> "numero": "00000151"
```

### Escenario C — entregar la boleta ahora y enviarla a SUNAT después
1. **Reserve** el número: `POST /series/reservar` con `{"tipo":"03","serie":"B001","cantidad":1}` → `"00000152"`. El documento queda `reservado`.
2. Imprima **B001-00000152** en el comprobante que entrega al cliente.
3. Cuando corresponda, envíelo con `POST /comprobantes` usando **ese mismo** `venta.numero`. Se completa la reserva (no se crea otro documento).
4. El cliente puede **consultarlo en cualquier momento** con `GET /comprobantes/03/B001/152`: responde `reservado` (aún no enviado), `aceptado`, `rechazado`… o `404` si no existe.

### Reglas que protegen contra duplicados
- **Reintentos seguros:** si el envío falla (SUNAT caído, corte de red), repita la petición con la **misma `Idempotency-Key`**: se reutiliza el mismo número y no se duplica nada. El documento queda en estado `error` mientras tanto, conservando su número.
- **Peticiones simultáneas:** nunca reciben el mismo número; dos peticiones idénticas a la vez procesan una sola (la otra recibe `409 peticion_en_curso`).
- **Rechazo de SUNAT:** si rechaza un documento numerado automáticamente, su número se libera y el documento corregido lo reutiliza.
- `150` y `00000150` son **el mismo** número (`409 documento_duplicado` si ya existe).
- En una serie `api`, un número que no fue reservado responde `409 numero_no_reservado` (evita pisar lo que numera la API). Para series `cliente` no hay esa restricción.
- El contador **solo sube**: `ultimo_numero` nunca baja ni queda por debajo de lo ya usado.
- **Resúmenes, bajas y reversiones** usan un correlativo por día: omítalo y se asigna solo (también exige `Idempotency-Key`).
- Cada **entorno** tiene sus propias series: registre también las de **producción** (con el `ultimo_numero` real) antes de emitir con una llave `live`.
## 7. Reintentos automáticos y webhooks

### Qué hace la API por usted
- **Reintenta sola** los envíos que fallan por un problema transitorio de SUNAT o del motor (SUNAT caído, página de error, tiempo agotado). Solo si el envío llegó con **`Idempotency-Key`**. Espera creciente: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h y 12 h. Cada intento usa **el mismo número**: nunca se duplica ni se salta uno. Un **rechazo** de SUNAT no se reintenta (hay que corregir los datos).
- **Le avisa por webhook** cuando un documento queda `aceptado`, `rechazado` o `error` definitivo (sin que usted consulte).
- **Consulta sola el ticket de las guías** hasta que SUNAT las resuelve. Los tickets de resumen, baja y reversión se siguen consultando con `POST /tickets`.

Mientras un documento espera su reintento figura como `error` (con su número guardado). Si usted reintenta antes con la misma `Idempotency-Key`, se envía en ese momento y el reintento automático se cancela (nunca se envían los dos a la vez).

### El worker (proceso en segundo plano)
Todo lo anterior lo ejecuta `bin/worker.php`. **Sin el worker no hay reintentos ni avisos.**

```bash
php bin/worker.php              # queda corriendo (un ciclo cada 5 s)
php bin/worker.php --una-vez    # un solo ciclo y termina (para cron / Programador de tareas)
```

| Sistema | Cómo dejarlo siempre encendido |
|---|---|
| Linux | `systemd` o `supervisor` con `php bin/worker.php`; o `cron` cada minuto con `--una-vez` |
| Windows | Programador de tareas: «Al iniciar el sistema», acción `php.exe` con argumento `C:\ruta\bin\worker.php` |

- `GET /health` informa `"worker": "activo"` (corrió hace menos de 60 s), `"detenido"` o `"sin_iniciar"`.
- Registro: `logs/worker.log`. Para detenerlo con calma, cree el archivo `storage/worker.stop`.
- Se pueden correr varios workers a la vez: cada documento y cada aviso lo toma uno solo.
- En `.env`, en producción defina **`API_BASE_URL`** (dirección pública, p. ej. `https://midominio.com/API_SUNAT`): los enlaces a XML/CDR que genera el worker y viajan en los avisos la usan. Si el servidor web local no responde en `http://127.0.0.1/<carpeta>`, defina también `API_INTERNAL_URL`. El worker lo comprueba al arrancar y avisa.

### Webhooks
```bash
# 1) Registrar (la respuesta trae el secreto UNA sola vez: guárdelo)
curl -s -X POST http://localhost/API_SUNAT/v1/webhooks -H "Authorization: Bearer sk_test_XXXX" -H "Content-Type: application/json" \
  -d '{"url":"https://midominio.com/webhooks/sunat","eventos":["documento.aceptado","documento.rechazado","documento.error"]}'

# 2) Probar que su servidor responde (envía un evento "webhook.prueba" firmado)
curl -s -X POST http://localhost/API_SUNAT/v1/webhooks/wh_XXXX/probar -H "Authorization: Bearer sk_test_XXXX"

# 3) Si algo falla, ver el historial de entregas y el motivo
curl -s http://localhost/API_SUNAT/v1/webhooks/wh_XXXX/entregas -H "Authorization: Bearer sk_test_XXXX"
```

Cada aviso es un `POST` con este cuerpo (no incluye datos del cliente final):
```json
{ "id": "evt_…", "tipo": "documento.aceptado", "entorno": "prueba", "creado": "2026-11-12 10:00:00",
  "data": { "id": "doc_…", "tipo": "03", "serie": "B001", "numero": "00000152", "estado": "aceptado",
            "sunat": { "codigo": "0", "descripcion": "…" }, "archivos": { "xml": "…", "cdr": "…" } } }
```

**Su servidor debe:** (1) verificar la firma, (2) responder **2xx en menos de 10 s**, (3) ignorar eventos repetidos por su `id` (si no recibimos su 2xx, reintentamos: 1 min, 5 min, 15 min, 1 h, 3 h, 6 h, 12 h y 24 h; después queda `fallido` y puede reenviarlo con `POST /webhooks/{id}/entregas/{id}/reenviar`).

**Verificar la firma** (`X-Webhook-Signature: t=<unix>,v1=<hex>`, con `hex = HMAC_SHA256(secreto, "<unix>.<cuerpo exacto>")`):

```php
$cuerpo = file_get_contents('php://input');
$partes = explode(',', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
$t = substr($partes[0], 2); $v1 = substr($partes[1], 3);
$valida = abs(time() - (int) $t) < 300 && hash_equals(hash_hmac('sha256', $t . '.' . $cuerpo, $SECRETO), $v1);
if (!$valida) { http_response_code(400); exit; }
```
```js
// Node.js (Express: use el cuerpo CRUDO, p. ej. express.raw({type: 'application/json'}))
const crypto = require('crypto');
const [t, v1] = req.get('X-Webhook-Signature').split(',').map(x => x.split('=')[1]);
const esperado = crypto.createHmac('sha256', SECRETO).update(t + '.' + req.body).digest('hex');
const valida = Math.abs(Date.now() / 1000 - t) < 300 && crypto.timingSafeEqual(Buffer.from(esperado), Buffer.from(v1));
```

**Seguridad de la URL:** debe ser pública (no se aceptan direcciones privadas, locales ni de metadatos de nube; se vuelve a comprobar al enviar), **HTTPS en producción**, sin usuario/clave y sin redirecciones. Para pruebas locales, el administrador puede poner `WEBHOOKS_PERMITIR_REDES_PRIVADAS=1` en `.env` (**solo desarrollo**).
## 8. Planes, límites de velocidad y cuota

Cada empresa tiene un **plan** que define dos cosas distintas.

### Límite de velocidad (peticiones por minuto) — protege el servicio
Por **API key** y por minuto, en dos clases: **emisiones** (`POST /comprobantes`, `/resumenes`, `/bajas`, `/reversiones`, `/retenciones`, `/guias`, `/tickets`; dependen de SUNAT) y **demás peticiones** (consultas, series, webhooks…; baratas). Además hay un **tope global de emisiones** (`LIMITE_GLOBAL_SUNAT_MIN` en `.env`, por defecto 150/min; `0` = sin tope) para que un cliente no degrade a los demás.

Cada respuesta trae `X-RateLimit-Limit`, `X-RateLimit-Remaining` y `X-RateLimit-Reset`. Si se excede: **`429`** con **`Retry-After`** (segundos): `limite_de_peticiones` (su límite) o `servicio_saturado` (tope global). Reintente tras esa espera, con la misma `Idempotency-Key`.

### Cuota mensual de documentos — lo que se vende
Consumen cuota solo los documentos **emitidos y aceptados** (o recibidos por SUNAT en espera de ticket, como una guía) con llave **`live`**: factura, boleta, notas, retención y guía. **No consumen:** pruebas (`test`), consultas, reservas de números, rechazos de SUNAT, resúmenes/bajas/reversiones ni repetir un documento con la misma `Idempotency-Key`. Es **mensual** (mes calendario, hora de Lima).

- `GET /uso`: plan, documentos usados y restantes, porcentaje y fecha de reinicio.
- Las respuestas de emisión `live` traen `X-Quota-Limit` y `X-Quota-Remaining`.
- Al **80 %** y al **100 %**: aviso por webhook (`cuota.advertencia`, `cuota.agotada`).
- Al agotarse: `402 cuota_agotada` (con la fecha de reinicio) **antes de enviar nada a SUNAT**. Las pruebas, las consultas y repetir documentos ya emitidos siguen funcionando.

### Valores recomendados (medidos en este servidor)
Una emisión real con SUNAT beta tardó **0,76 s en promedio** (mediana 0,56 s) y las consultas simples se atienden a ~**119 por segundo**; pero SUNAT empezó a rechazar conexiones con ~8 envíos simultáneos. El cuello de botella es SUNAT, no el servidor: por eso las emisiones tienen un límite mucho más bajo que las consultas.

| Plan | Cuota/mes | Emisiones/min | Peticiones/min | Webhooks |
|---|---|---|---|---|
| Prueba | 0 (solo llave `test`) | 10 | 30 | 1 |
| Basico | 500 | 20 | 120 | 2 |
| Pro | 5 000 | 60 | 300 | 5 |
| Empresa | 50 000 | 120 | 600 | 5 |

Son un punto de partida **conservador**: SUNAT de producción puede ser más lento que el beta. Ajústelos con datos reales (`plan:crear` los cambia al instante) y suba el tope global solo cuando mida que SUNAT lo tolera.

```bash
php bin/admin.php plan:sembrar                                   # crea los 4 planes de arriba
php bin/admin.php plan:crear --nombre=Basico --cuota=800         # cambia un valor (los demas se conservan)
php bin/admin.php plan:crear --nombre=Aliado --cuota=ilimitada --sunat-min=ilimitado --general-min=ilimitado
php bin/admin.php empresa:plan --ruc=20123456789 --plan=Basico   # asignar ("--plan=ninguno" quita todo limite)
php bin/admin.php plan:listar
php bin/admin.php uso:ver [--ruc=20123456789]                    # consumo del mes
```
Una empresa **sin plan no tiene ningún límite** (compatibilidad): asigne un plan a cada cliente.

### Qué debe hacer el cliente ante un 429 o un 402
- **429:** esperar `Retry-After` segundos y reintentar (con la misma `Idempotency-Key`); no insistir en bucle.
- **402:** avisar al usuario (la respuesta trae la fecha de reinicio) o contratar un plan mayor. No reintentar.
## 9. Certificado digital (producción)

Cada empresa firma con **su propio certificado**. Para emitir en producción, regístrelo con una llave `live`:

```bash
php -r 'echo json_encode(["pfx_base64"=>base64_encode(file_get_contents("empresa.pfx")),"password"=>"CLAVE"]);' |
  curl -s -X POST http://localhost/API_SUNAT/v1/certificado -H "Authorization: Bearer sk_live_XXXX" -H "Content-Type: application/json" --data-binary @-
```

Se valida que sea RSA, que la llave corresponda al certificado, que esté vigente y que contenga el RUC de la empresa. La llave privada se guarda cifrada. `GET /certificado` o `GET /me` muestran los días restantes. Sin certificado de producción, una llave `live` responde `422 certificado_requerido` (no se envía nada).

## 10. Portal de clientes

Direccion: `/portal/`. Cada cliente entra con su correo y contrasena y ve **solo su empresa**: plan y cuota del mes, estado del certificado, documentos (con detalle, XML/CDR/PDF), llaves API, webhooks y sus entregas, certificado digital, credenciales SOL/GRE y actividad.

- **Alta de usuarios** (no hay auto-registro): `php bin/admin.php usuario:crear --ruc=… --email=… --nombre="…" --rol=admin`. Muestra una contrasena temporal **una sola vez**; el usuario debe cambiarla al primer ingreso.
- **Olvido de contrasena**: `php bin/admin.php usuario:reset --email=…` (tambien desbloquea la cuenta).
- **Roles**: `admin` (gestiona llaves, webhooks, certificado, credenciales y ve la actividad) y `lector` (solo consulta).
- **Seguridad**: contrasenas con hash bcrypt; bloqueo de 15 min tras 5 fallos y limite por IP; sesion de 30 min de inactividad (8 h maximo); proteccion CSRF; politica CSP estricta (sin scripts en linea); las llaves API y secretos de webhook se muestran **una sola vez**; las credenciales SUNAT son de solo escritura; acciones sensibles piden la contrasena otra vez; todo queda en el registro de actividad.
- **En produccion** active `PORTAL_REQUIERE_HTTPS=1` (ver `.env.example`) y sirvalo siempre por HTTPS.
- Prueba automatica de seguridad: `php docs/probar_portal.php` (crea y borra dos empresas de laboratorio).
## 11. Panel del operador

Direccion: `/operador/` (solo para usted, el dueno del servicio; es una aplicacion aparte del portal de clientes, con cuentas, cookie y sesiones propias).

- **Crear su cuenta** (una vez, por consola): `php bin/admin.php operador:crear --email=usted@correo.com --nombre="Su nombre"`. Muestra una contrasena temporal una sola vez; al entrar debe cambiarla. Si la olvida: `operador:reset --email=...`.
- **Resumen**: estado del worker (activo / lento / detenido, con el ultimo latido), trabajo pendiente (reintentos y avisos), empresas cerca de su cuota, empresas sin plan.
- **Empresas**: listado con plan y consumo del mes; ficha con datos, certificados, llaves, consumo de meses anteriores, usuarios y actividad. Desde ahi asigna el plan, crea usuarios, restablece contrasenas y activa/desactiva la empresa. **Nuevo cliente** crea la empresa, su plan y su primer administrador en un solo paso (el cliente carga luego sus credenciales SUNAT y su certificado en su portal).
- **Usuarios**: todos los usuarios de clientes; restablecer contrasena (genera una temporal, desbloquea y **cierra las sesiones abiertas** de esa cuenta) y activar/desactivar.
- **Planes**: crear y editar planes (cuota mensual, emisiones/min, peticiones/min, webhooks). Los cambios aplican de inmediato a las empresas que lo tienen. Un plan con empresas no se puede desactivar (una empresa sin plan activo queda sin limites).
- **Como se asigna un plan**:
  1. *Usted* lo asigna en la ficha de la empresa (siempre disponible).
  2. *El cliente lo pide* desde su portal (**Mi plan**). Si el plan esta marcado **autoservicio** se activa al instante; si no, queda como **solicitud** que usted aprueba o rechaza en *Solicitudes de plan*. Mientras no exista cobro automatico, deje los planes de pago sin autoservicio (los activa usted al confirmar el pago) y use autoservicio solo para planes gratuitos (p. ej. Prueba).
  3. *Un pago confirmado* (futuro): la pasarela llamara a la misma funcion (`Suscripciones::asignar`), sin intervencion suya.
- **Seguridad**: bloqueo de 15 min tras 5 fallos y limite por IP; sesion de 30 min de inactividad; CSRF; CSP estricta; las acciones sensibles (crear/restablecer/desactivar usuarios, desactivar empresas, quitar un plan, crear clientes) piden su contrasena otra vez; cada cambio queda en la actividad de la empresa (el cliente ve que fue el operador). Opcional: `OPERADOR_IPS_PERMITIDAS` en `.env` limita el panel a sus IP; en produccion active tambien `PORTAL_REQUIERE_HTTPS=1`.
- **Estado del worker**: el worker escribe un latido en cada ciclo; el panel lo muestra en todas las paginas. El worker **no se enciende desde la web**: se deja programado una vez en el servidor (Windows: Programador de tareas "al iniciar el sistema"; Linux: servicio systemd). Si aparece "DETENIDO", los reintentos y los webhooks estan parados.
- Prueba automatica: `php docs/probar_operador.php` (crea y borra empresas, operador y planes de laboratorio).
## 12. Probar que todo funciona

```bash
# Prueba real: ejecuta los ejemplos contra la API y verifica los resultados (usa SUNAT beta)
php docs/probar.php --key=sk_test_XXXXXXXX [--base=http://localhost/API_SUNAT/v1]

# Prueba de la numeración (series, reservas, concurrencia, reintentos, sincronización): usa series de nombre aleatorio
php docs/probar_numeracion.php --key=sk_test_XXXXXXXX

# Prueba de reintentos automáticos y webhooks: arranca un motor falso y un receptor de avisos, no envía nada a SUNAT
php docs/probar_reintentos.php --key=sk_test_XXXXXXXX

# Prueba de límites, tope global y cuota mensual: crea una empresa de laboratorio (se borra sola) y usa un motor falso
php docs/probar_limites.php
```

Rechaza llaves `sk_live_` a propósito. Úsela **después de cada cambio**: si todo sale `[OK]`, la API sigue funcionando.

También puede importar `postman/API_SUNAT_v1.postman_collection.json` en Postman (44 peticiones con tests automáticos): ponga su llave de prueba en la variable `api_key` y ejecute primero «00 Iniciar tanda de numeros».

## 13. Administración (solo línea de comandos)

```bash
php bin/admin.php init                       # prepara base de datos y claves (una vez)
php bin/admin.php empresa:crear --ruc=… --razon="…" --nombre="…" --direccion="…" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA --sol-user=… --sol-pass=… [--gre-id=… --gre-secret=…]
php bin/admin.php key:crear --ruc=… --entorno=test|live --nombre="Mi sistema"
php bin/admin.php key:listar | key:revocar --id=N
php bin/admin.php cert:subir --ruc=… --entorno=live --pfx=archivo.pfx --pass=…
php bin/admin.php cert:ver
php bin/admin.php serie:crear --ruc=… --entorno=test|live --tipo=03 --serie=B001 [--modo=cliente|api] [--ultimo=150]
php bin/admin.php serie:listar [--ruc=…] [--entorno=test|live]
php bin/worker.php [--una-vez]                # reintentos y webhooks (dejarlo siempre encendido)
php bin/admin.php plan:sembrar | plan:crear | plan:listar | empresa:plan | uso:ver   # planes, limites y cuota (seccion 8)
php bin/admin.php usuario:crear --ruc=… --email=… --nombre="…" [--rol=admin|lector]   # acceso al portal (clave TEMPORAL, se muestra una vez)
php bin/admin.php operador:crear --email=… --nombre="…"   # cuenta del panel /operador/ (clave TEMPORAL); operador:listar | operador:reset --email=…
php bin/admin.php usuario:listar | usuario:reset --email=… | usuario:desactivar --email=… | usuario:activar --email=…
```

**Respalde `APP_KEY`** (archivo `.env`): sin ella no se pueden descifrar las claves SOL ni las llaves de certificados guardadas.

### Base de datos: SQLite hoy, PostgreSQL en producción

Por defecto la API usa SQLite (`storage/api.sqlite`), suficiente para desarrollo y pruebas. Para producción se recomienda **PostgreSQL**; ya está probado (toda la suite, incluida la concurrencia: reservas simultáneas, reintentos e idempotencia). MySQL/MariaDB está previsto en el código pero **no se ha probado**.

Para pasar a PostgreSQL:

```bash
# 1) Cree una base VACIA y un usuario en PostgreSQL (UTF8)
#    CREATE DATABASE api_sunat ENCODING 'UTF8' TEMPLATE template0;
# 2) Copie todos los datos actuales (no modifica el origen; se niega si el destino ya tiene datos)
php bin/admin.php db:copiar --dsn="pgsql:host=127.0.0.1;port=5432;dbname=api_sunat" --user=USUARIO --pass=CLAVE
# 3) Cambie la conexión en .env y listo
#    DB_DSN=pgsql:host=127.0.0.1;port=5432;dbname=api_sunat
#    DB_USER=USUARIO
#    DB_PASS=CLAVE
# 4) Compruebe
php docs/probar.php --key=sk_test_XXXX && php docs/probar_numeracion.php --key=sk_test_XXXX
```

Respalde la base y `APP_KEY` juntas: los secretos guardados solo se descifran con esa llave.

## 14. Regenerar la documentación

Los ejemplos viven en `docs/ejemplos.php` (única fuente). Si cambia algo:

```bash
php docs/gen_openapi.php          # docs/openapi.json
php postman/gen_v1.php            # colección de Postman
php docs/probar.php --key=sk_test_…   # confirma que los ejemplos siguen funcionando
```

## 15. Limitaciones conocidas

- Un número **reservado** que nunca se envía queda como hueco en la serie (todavía no hay una operación para liberarlo).
- Los tickets de **resumen, baja y reversión** no se resuelven solos (solo los de guías): se consultan con `POST /tickets`.
- Los avisos se entregan **al menos una vez** (pueden repetirse; ignore los repetidos por `id`).
- Los reintentos automáticos exigen que el worker esté encendido y que el envío haya llegado con `Idempotency-Key`.
- El límite de velocidad usa ventanas de un minuto (una ráfaga justo en el cambio de minuto puede acercarse al doble durante unos segundos) y la cuota se comprueba antes de emitir (con muchas emisiones simultáneas al final de la cuota puede excederse por unos pocos documentos).
- Todavía no hay cobro por excedente: al agotarse la cuota se bloquea.
- Sin límite de peticiones configurado todavía.
- La consulta de tickets depende de SUNAT y puede devolver `504 tiempo_agotado`: reintente.
- Un certificado por empresa y entorno; sin aviso automático de vencimiento (se ve en `/me`).
