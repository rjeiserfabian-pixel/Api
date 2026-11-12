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
## 7. Certificado digital (producción)

Cada empresa firma con **su propio certificado**. Para emitir en producción, regístrelo con una llave `live`:

```bash
php -r 'echo json_encode(["pfx_base64"=>base64_encode(file_get_contents("empresa.pfx")),"password"=>"CLAVE"]);' |
  curl -s -X POST http://localhost/API_SUNAT/v1/certificado -H "Authorization: Bearer sk_live_XXXX" -H "Content-Type: application/json" --data-binary @-
```

Se valida que sea RSA, que la llave corresponda al certificado, que esté vigente y que contenga el RUC de la empresa. La llave privada se guarda cifrada. `GET /certificado` o `GET /me` muestran los días restantes. Sin certificado de producción, una llave `live` responde `422 certificado_requerido` (no se envía nada).

## 8. Probar que todo funciona

```bash
# Prueba real: ejecuta los ejemplos contra la API y verifica los resultados (usa SUNAT beta)
php docs/probar.php --key=sk_test_XXXXXXXX [--base=http://localhost/API_SUNAT/v1]

# Prueba de la numeración (series, reservas, concurrencia, reintentos, sincronización): usa series de nombre aleatorio
php docs/probar_numeracion.php --key=sk_test_XXXXXXXX
```

Rechaza llaves `sk_live_` a propósito. Úsela **después de cada cambio**: si todo sale `[OK]`, la API sigue funcionando.

También puede importar `postman/API_SUNAT_v1.postman_collection.json` en Postman (37 peticiones con tests automáticos): ponga su llave de prueba en la variable `api_key` y ejecute primero «00 Iniciar tanda de numeros».

## 9. Administración (solo línea de comandos)

```bash
php bin/admin.php init                       # prepara base de datos y claves (una vez)
php bin/admin.php empresa:crear --ruc=… --razon="…" --nombre="…" --direccion="…" --ubigeo=150101 --dep=LIMA --prov=LIMA --dist=LIMA --sol-user=… --sol-pass=… [--gre-id=… --gre-secret=…]
php bin/admin.php key:crear --ruc=… --entorno=test|live --nombre="Mi sistema"
php bin/admin.php key:listar | key:revocar --id=N
php bin/admin.php cert:subir --ruc=… --entorno=live --pfx=archivo.pfx --pass=…
php bin/admin.php cert:ver
php bin/admin.php serie:crear --ruc=… --entorno=test|live --tipo=03 --serie=B001 [--modo=cliente|api] [--ultimo=150]
php bin/admin.php serie:listar [--ruc=…] [--entorno=test|live]
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

## 10. Regenerar la documentación

Los ejemplos viven en `docs/ejemplos.php` (única fuente). Si cambia algo:

```bash
php docs/gen_openapi.php          # docs/openapi.json
php postman/gen_v1.php            # colección de Postman
php docs/probar.php --key=sk_test_…   # confirma que los ejemplos siguen funcionando
```

## 11. Limitaciones conocidas

- Un número **reservado** que nunca se envía queda como hueco en la serie (todavía no hay una operación para liberarlo).
- Sin límite de peticiones configurado todavía.
- La consulta de tickets depende de SUNAT y puede devolver `504 tiempo_agotado`: reintente.
- Un certificado por empresa y entorno; sin aviso automático de vencimiento (se ve en `/me`).
