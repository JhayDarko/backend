# Estado de la fase 3: evaluación de calidad del veredicto

**Actualizado:** 2026-10-23  
**Alcance implementado:** E1, ajustes P1/P3 y E2
**Estado:** E2 está implementada y probada con providers falsos; no se ha ejecutado una corrida real sobre el piloto ni se han llamado proveedores durante E2.

## Resumen ejecutivo

Se creó la base para preparar y dividir un dataset de evaluación sin modificar el algoritmo, sus pesos o umbrales, ni las rutas de producción. El piloto de 14 registros se incorporó conservando sus líneas JSONL y se mantiene sin split asignado. Hay comandos para validar el dataset y asignar splits de forma determinista, con pruebas aisladas mediante fixtures.

La puntuación offline (`eval:score`), los reportes de métricas (`eval:report`), la protección del split test y el CI de evaluación todavía no están implementados. P2, P4 y P5 siguen pendientes. No se ha ejecutado `eval:split` sobre el piloto ni se ha iniciado una corrida real.

## Trabajo realizado

### E1 y compatibilidad P1

- Se añadió el esquema del caso en [`dataset/schema.json`](./dataset/schema.json), con cuatro clases de etiqueta: `credible`, `misleading`, `false` e `inconclusive`.
- Se escribieron la guía de etiquetado [`LABELING_GUIDE.md`](./LABELING_GUIDE.md) y las instrucciones de uso en [`README.md`](./README.md).
- Se añadieron tres casos sintéticos con `example: true`; el validador y el divisor los excluyen por defecto.
- Se implementó `php artisan eval:validate` en [`EvalValidate.php`](../app/Console/Commands/EvalValidate.php). Comprueba campos, tipos, enumeraciones, URLs, fechas, IDs repetidos, URLs duplicadas, texto demasiado largo y distribución por clase/split.
- Se incorporaron como JSONL los 14 casos del piloto en [`dataset/cases.jsonl`](./dataset/cases.jsonl). Los campos originales del adjunto se conservaron; no se completaron ni cambiaron las etiquetas, fechas, fuentes o anotaciones.
- El esquema acepta los metadatos del piloto: `status`, `claim_group`, `label_strength`, `verify_notes` e `input_text_hint`. Acepta `split: null` para candidatos `candidato_sin_verificar`, `published_at` nulo o con formato `AAAA-MM`/`AAAA-MM-DD`, y URL nula en `label_sources`.
- El validador advierte sobre casos que no tienen estado `verificado`, URL nula en una fuente y fecha `published_at` ausente. Los mínimos de tamaño/clase también son advertencias. `--strict` convierte todas las advertencias en fallo.
- Los casos del piloto permanecen en `split: null`; la distribución actual del piloto es `credible=3`, `misleading=3`, `false=5`, `inconclusive=3`. Todos tienen estado `candidato_sin_verificar`.
- Se actualizaron las exclusiones de Git para `evaluation/runs/`, `evaluation/cache/` y `evaluation/data/`.

### P3: división estratificada sin fuga

- Se añadió `php artisan eval:split` en [`EvalSplit.php`](../app/Console/Commands/EvalSplit.php).
- Usa `claim_group` como unidad indivisible; si falta, usa el `id` del caso. Los grupos se estratifican por su composición de clases y reciben una asignación determinista derivada de la semilla.
- La semilla predeterminada es `20261022`; la proporción de test predeterminada es `0.3`, registrada en [`dataset/splits.json`](./dataset/splits.json). Se pueden sobreescribir mediante opciones del comando.
- Actualiza las asignaciones del JSONL y guarda la semilla, los parámetros, las asignaciones por grupo/ID y la distribución en el manifiesto de splits.
- Los ejemplos se conservan sin modificación y se excluyen por defecto. Las advertencias de mínimos no bloquean la escritura salvo con `--strict`; en ese modo no se escriben los archivos.
- El comando solo se probó contra fixtures. No se ejecutó sobre `dataset/cases.jsonl`; el piloto continúa sin splits.

### E2: recolección aislada

- Se añadió `php artisan eval:collect` con filtrado por split/caso, límite de casos, reanudación, dry-run, límite de ritmo, run ID, modos, `--no-factcheck`, timeout por caso y timeout de drenaje.
- La conexión `evaluation` exige variables `EVAL_DB_*` explícitas y guarda host/base separados de la conexión predeterminada; SQLite solo se acepta expresamente y con archivo distinto.
- Se crea un usuario técnico estable por `(run_id, mode)`. `--resume` reutiliza identidad y omite snapshots completos.
- Cache de evaluación usa store `database`, conexión `evaluation`, tabla de cache allí mismo y prefijo `eval:`. El alias de cola `redis`, el default de cola, el batching y `failed_jobs` se remapean solo durante la ejecución de evaluación.
- Cada caso corre `queue:work --stop-when-empty` en un proceso hijo. El recolector drena trabajos diferidos en ciclos, aplica límites acumulados y registra intentos/fallos agotados. BERT puede fallar agotando reintentos sin bloquear la finalización del batch (`allowFailures`).
- `production` no aplica exclusiones; `blind` filtra dominios en búsqueda y Google Fact Check; `--no-factcheck` desactiva solo esa etapa. El manifiesto y cada snapshot registran modo/flags, sin `expected_verdict`.
- Las instantáneas se escriben atómicamente, omiten el texto completo y eliminan secretos/cabeceras no permitidas. `response_headers` conserva solo cabeceras de límite y usa `null` para los campos conocidos ausentes. `pricing.json` conserva precios nulos y el dry-run informa `costo pendiente`.
- Prueba de humo incorporada al conjunto: dos casos, worker hijo real, proveedores falsos, un caso completo con BERT agotado no crítico y otro con extracción agotada; verifica `failed_jobs`, snapshots, manifiesto, aislamiento de cache/cola y ausencia de secretos en los archivos.

### Archivos principales añadidos o actualizados

- [`evaluation/README.md`](./README.md)
- [`evaluation/LABELING_GUIDE.md`](./LABELING_GUIDE.md)
- [`evaluation/ESTADO_FASE3.md`](./ESTADO_FASE3.md)
- [`evaluation/dataset/schema.json`](./dataset/schema.json)
- [`evaluation/dataset/cases.jsonl`](./dataset/cases.jsonl)
- [`evaluation/dataset/splits.json`](./dataset/splits.json)
- [`app/Console/Commands/EvalValidate.php`](../app/Console/Commands/EvalValidate.php)
- [`app/Console/Commands/EvalSplit.php`](../app/Console/Commands/EvalSplit.php)
- [`tests/Feature/Evaluation/EvalValidateTest.php`](../tests/Feature/Evaluation/EvalValidateTest.php)
- [`tests/Feature/Evaluation/EvalSplitTest.php`](../tests/Feature/Evaluation/EvalSplitTest.php)
- [`tests/Fixtures/Evaluation/split_cases.jsonl`](../tests/Fixtures/Evaluation/split_cases.jsonl)
- `.gitignore` (exclusiones de datos locales de evaluación)
- [`app/Console/Commands/EvalCollect.php`](../app/Console/Commands/EvalCollect.php), [`EvaluationRuntime.php`](../app/Services/Evaluation/EvaluationRuntime.php), [`EvaluationSnapshotSanitizer.php`](../app/Services/Evaluation/EvaluationSnapshotSanitizer.php) y [`EvaluationLogSanitizer.php`](../app/Logging/EvaluationLogSanitizer.php)
- `app/Services/Evaluation/` (providers falsos), [`config/evaluation.php`](../config/evaluation.php), configuración de DB/cache/queue/logging y [`evaluation/pricing.json`](./pricing.json)
- Pruebas E2: [`EvalCollectSmokeTest.php`](../tests/Feature/Evaluation/EvalCollectSmokeTest.php), [`EvalModeFilteringTest.php`](../tests/Feature/Evaluation/EvalModeFilteringTest.php) y [`EvaluationRuntimeTest.php`](../tests/Unit/Services/Evaluation/EvaluationRuntimeTest.php)

## Verificación realizada

Verificación de evaluación previa y línea base completa ejecutada el 2026-10-23:

- Pruebas de `tests/Feature/Evaluation`: **9 pasaron**, 45 aserciones.
- Pint, modo `--test`: pasó.
- Larastan (`composer analyse`): pasó sin errores.
- `php artisan eval:validate`: acepta los casos del piloto y muestra las advertencias esperadas.
- `php artisan eval:validate --strict`: falla cuando quedan advertencias, según lo requerido.
- Pruebas de `eval:split`: comprueban determinismo con la misma semilla, estratificación en el fixture, que el grupo repetido no se divide, exclusión de ejemplos y que `--strict` no escriba cambios.
- Se verificó que los 14 registros del piloto permanecen con `split: null`.
- Línea base previa a E2: `composer ci` pasó; 208 pruebas detectadas, 207 aprobadas, 1 omitida y 809 aserciones; PHPStan y Pint pasaron.
- Validación final posterior a E2: `composer ci` pasó; 216 pruebas detectadas, 215 aprobadas, 1 omitida y 876 aserciones; PHPStan pasó sin errores y Pint pasó.
- Vista previa ejecutada: `php artisan eval:collect --dry-run --mode=production --run-id=pilot-dry-run`; mostró 14 casos, estimaciones nominales y `costo pendiente`. El dry-run no conecta a DB ni llama proveedores.
- No se ejecutó ninguna corrida real ni se llamó a proveedores.
- Al inicio de E2 el árbol estaba limpio. En la revisión final se detectó además el cambio ajeno de CI registrado al final de este documento.

## Decisiones de E2 implementadas

### Parámetros y línea base

El default de `test_ratio` de `eval:split` es `0.3` en `dataset/splits.json` y se refleja en el README y en el ejemplo del comando. La línea base completa anterior a E2 pasó antes de comenzar la implementación.

### Configuración y aislamiento de base de datos

1. La conexión Laravel `evaluation` usa variables `EVAL_DB_*`; la configuración impide usar host/base de la conexión predeterminada. SQLite se admite solo si `EVAL_DB_CONNECTION=sqlite` está configurado explícitamente y la ruta es distinta.
2. `eval:collect` falla antes de migrar, crear datos o despachar jobs si faltan ajustes requeridos o la conexión de evaluación no está aislada. Las pruebas cubren variables ausentes, host/base iguales y rutas SQLite coincidentes.
3. La conexión predeterminada de DB, cache y queue se cambia al contexto `evaluation` durante el comando. Migraciones, artículos, análisis, jobs, batches, cache y failed jobs se guardan en esa base; el texto completo local permanece fuera de Git.
4. Se usa un usuario técnico por `(run_id, mode)`, creado idempotentemente con identidad estable. Así el unique `(article_id, user_id)` permite una observación por caso y modo; separa corridas/modos sin borrar datos. `--resume` reutiliza identidad y snapshots completos del mismo `run_id`/modo.

### Cola, reintentos y fidelidad de jobs

Se compararon dos opciones:

- **(a) Llamar `handle()` directamente:** tiene menor complejidad inicial, pero evita el worker y pierde el ciclo de reintentos (`tries`/`backoff`), la aplicación del timeout, `failed()` al agotar intentos, y la semántica del batch (`then`/`finally`/`allowFailures`). Emular esas partes manualmente duplicaría comportamiento y sería menos fiel.
- **(b) Cola `database` de evaluación:** los jobs pasan por el worker Laravel, por lo que conserva intentos, backoff, timeout, callbacks de fallo y batches. Tiene complejidad moderada y riesgo de configuración de conexiones/tablas; se limita al proceso del comando y a tablas de evaluación.

**Elección implementada: (b).** La cola `evaluation` usa driver `database` y la conexión de base `evaluation`, con tablas de jobs, batches y `failed_jobs` de evaluación. Durante `eval:collect` se remapea en memoria `redis` y el default de cola, así los jobs explícitos y los batches usan la cola aislada. Producción no cambia. `queue:work --connection=redis --stop-when-empty` corre en un proceso hijo Symfony Process.

`--stop-when-empty` por sí solo puede salir mientras aún hay reintentos diferidos por backoff. El comando drena en ciclos hasta que jobs/batches sean terminales o se alcance `--drain-timeout`; `--case-timeout` termina al hijo si un caso excede su límite, marca el caso fallido y continúa. Los jobs que agotan reintentos quedan en `failed_jobs` de evaluación y en la instantánea con clase, motivo, intentos y tiempo; también se ejecuta su método `failed()`.

El recolector reutiliza los jobs/servicios de producción. No se presupone orden temporal entre las tareas del batch. Se conserva `allowFailures()`: si BERT agota reintentos, se registra `classifier_unavailable` y la finalización sigue; se prueba en la cola real con el worker hijo. En caso de fallos permitidos, el recolector reconoce batch terminal cuando todos los jobs han finalizado o fallado, incluso cuando el framework conserva `pending_jobs` para jobs fallidos.

### Opciones, modos y aislamiento de etiquetas

Opciones: `--split`, `--case`, `--limit`, `--resume`, `--dry-run`, `--max-cases-per-minute`, `--run-id`, `--mode=production|blind` y `--no-factcheck`. Los casos se procesan por ID en orden determinista. `--dry-run` no conecta a base de datos ni hace descargas o solicitudes HTTP; informa las llamadas estimadas y `costo pendiente`.

- `production`: todas las etapas activas y ningún dominio excluido, incluso si el caso enumera `excluded_domains`.
- `blind`: aplicar los `excluded_domains` del caso tanto a la búsqueda web como a resultados de Google Fact Check. En búsqueda, solicitar la exclusión al proveedor si la API lo admite y filtrar localmente por host y subdominios. En fact-check, filtrar por `publisher_site` y por el host de la URL del resultado antes de persistir.
- `--no-factcheck`: desactiva solo la etapa de Google Fact Check. Es combinable con ambos modos; búsqueda, extracción, BERT y scoring continúan activos.
- El manifiesto de corrida y cada instantánea registran `mode` y flags efectivos (incluyendo `no_factcheck`, exclusiones aplicadas y etapa de fact-check activa). `expected_verdict` nunca se envía al pipeline ni se almacena en una instantánea de predicción, en ningún modo; `blind` no redefine esa protección.

Para `input_type=text`, se lee `evaluation/cache/<id>.txt` si existe; en caso contrario se usa `input_text_hint`. La entrada obtenida desde URL se registra como `original`; se guarda el hash y la fuente (`original|hint`), no el texto completo.

### Filtro de dominios excluidos

Esta política solo se aplica en modo `blind`; `production` no aplica exclusiones. Si el caso no tiene exclusiones, `blind` no filtra dominios. Las pruebas cubren el filtro por publisher y host en Fact Check, el filtro por host/subdominio de búsqueda y `--no-factcheck`.

### Instantáneas y manifiesto

Escribir una instantánea atómica por caso en `evaluation/runs/<run_id>/<mode>/cases/<id>.json`, sin almacenar el texto completo del artículo. Contiene, cuando están disponibles:

- hash del contenido de entrada y origen `original|hint`;
- claims, evidencias y verificaciones por claim;
- predicción BERT o su ausencia;
- score y veredicto originales del pipeline;
- modo y flags efectivos (en cada instantánea y en el manifiesto), proveedor, modelo y versiones;
- `algorithm_version` y commit Git;
- tokens observados, latencia total de pared, reintentos por job, errores y marcas de tiempo.
- campos/cabeceras de límites de tasa que el proveedor entregue (por ejemplo `x-ratelimit-*`), conservados por proveedor; registrar `null` cuando no existan.

El manifiesto por corrida registra configuración, versiones, parámetros, modo/flags, conteos por estado y referencias a instantáneas. Cada entrada de caso conserva modo y flags efectivos. Un fallo de caso no detiene el resto. La estimación nominal por caso (hasta 10 claims) es orientativa; reintentos/fallbacks pueden aumentarla.

### Estimación de llamadas, tokens y costo

No inventar uso, límites ni tarifas: extraer tokens y campos de rate limit solo cuando los proveedores los entreguen; si no, registrar `null` (no cero). Añadir `evaluation/pricing.json` versionado con todos los precios inicialmente nulos. `--dry-run` mostrará literalmente `costo pendiente`. Informar solo límites/cabeceras observados y errores de cuota/rate-limit; no inferir saldo ni costo financiero.

### Pruebas y controles de ejecución

- `GeminiModelCircuit` usa `Cache::has/put`; su default se cambia a store `evaluation` dentro del comando y worker hijo. `EvalCollect` y los listeners de jobs guardan explícitamente intentos y agotamientos con `Cache::store('evaluation')`. El store usa driver `database`, conexión `evaluation`, tabla `cache` y prefijo `eval:<run_id>:<mode>:`; también `Cache::lock` usaría ese store por defecto. El cache y circuit breaker de producción no cambian.
- `RateLimiter` solo aparece en `AuthenticateApiKey`; `eval:collect` es Artisan y no ejecuta ese middleware. Si una etapa usa `RateLimiter`, el default activo es el store `evaluation` tanto en comando como en hijo. No hay uso directo de `Redis::` ni locks de aplicación en `app/`.
- Las conexiones explícitas `onConnection('redis')` están en `StartAnalysis`, `AnalysisController`, `AnalyzeArticleJob` y `ExtractClaimsJob`, incluidos jobs de batch y callbacks. El runtime de evaluación remapea en memoria el alias `redis` a la cola database de `evaluation`, y cambia `queue.default`, `queue.batching.database` y `queue.failed.database`; `jobs`, `job_batches` y `failed_jobs` no tocan Redis ni la DB normal. Producción conserva sus conexiones.
- La prueba de humo simula fallo de proveedor y comprueba que el estado del circuit breaker de producción no cambia; los providers son falsos y el test prohíbe solicitudes HTTP no simuladas.
- Las pruebas cubren guardas DB, ambos modos, `--no-factcheck`, filtrado, worker hijo, cola/cache, reintentos y batch tolerante, failed jobs, telemetría, sanitización, snapshots y manifiesto.
- La validación completa posterior a E2 está registrada arriba.

### 8. Operación del piloto (P5)

1. Validar el piloto.
2. Se puede ejecutar la vista previa `php artisan eval:collect --dry-run --mode=production --run-id=pilot-dry-run`; no conecta a DB ni a proveedores.
3. Toda corrida real requiere autorización explícita para llamar proveedores y una base de evaluación aislada. Tras una corrida autorizada, reportar solicitudes, tokens/cabeceras realmente disponibles, latencia y errores.
4. Antes de correr el piloto en `production` y en `blind`, esperar autorización explícita para cada secuencia P5. Son usuarios distintos por modo aunque compartan `run_id`; modo y flags quedan en manifiesto e instantáneas.

No se ajustarán pesos ni se interpretarán resultados durante esas corridas.

## Pendiente posterior

- **E3:** scoring offline puro desde instantáneas y prueba de paridad.
- **P2 / E4:** métricas y reportes que incluyan solo `status=verificado` por defecto; `--include-unverified` amplía la selección y el reporte debe contar incluidos/excluidos. Separar vista estricta de cuatro clases y vista gruesa donde `misleading` y `false` se agregan. `inconclusive` predicho para una clase respondible se cuenta como abstención y reduce cobertura, no como error de clase.
- **E5:** bloquear reportes de test sin `--final` y registrar cada uso del test.
- **P4:** lectura local del texto cacheado o fallback al hint y separación de métricas por `input_source`.
- **E6:** smoke de CI sin red y documentación final.

## Cambios ajenos a E2

Durante el trabajo apareció un cambio en [`.github/workflows/ci.yml`](../.github/workflows/ci.yml) que cambia `aquasecurity/trivy-action@0.36.0` a `aquasecurity/trivy-action@v0.36.0`. No forma parte de E2 y se excluirá de sus commits.

## Decisiones de veredictos

El scoring actual puede devolver `credible`, `probably_credible`, `inconclusive`, `probably_misleading` y `false`. El dataset conserva cuatro etiquetas:

- `credible` y `probably_credible` se agrupan como `credible`;
- `probably_misleading` se agrupa como `misleading`;
- `false` permanece `false`;
- `inconclusive` permanece `inconclusive`.

El veredicto original del sistema debe conservarse en la instantánea. El reporte futuro mostrará tanto las cuatro clases estrictas como la vista de tres clases que agrupa `misleading` y `false`.
