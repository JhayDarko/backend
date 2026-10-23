# Estado de la fase 3: evaluación de calidad del veredicto

**Actualizado:** 2026-10-23  
**Alcance implementado hasta ahora:** E1, ajustes P1 y P3  
**Estado:** no se ha ejecutado el pipeline sobre el piloto ni se han llamado proveedores.

## Resumen ejecutivo

Se creó la base para preparar y dividir un dataset de evaluación sin modificar el algoritmo, sus pesos o umbrales, ni las rutas de producción. El piloto de 14 registros se incorporó conservando sus líneas JSONL y se mantiene sin split asignado. Hay comandos para validar el dataset y asignar splits de forma determinista, con pruebas aisladas mediante fixtures.

La recolección (`eval:collect`), la puntuación offline (`eval:score`), los reportes de métricas (`eval:report`), la protección del split test y el CI de evaluación todavía no están implementados. Por tanto, P2, P4 y P5 siguen pendientes. No se ha ejecutado `eval:split` sobre el piloto ni se ha iniciado una corrida real.

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
- La semilla predeterminada es `20261022`; la proporción de test predeterminada es `0.2`, registrada en [`dataset/splits.json`](./dataset/splits.json). Se pueden sobreescribir mediante opciones del comando.
- Actualiza las asignaciones del JSONL y guarda la semilla, los parámetros, las asignaciones por grupo/ID y la distribución en el manifiesto de splits.
- Los ejemplos se conservan sin modificación y se excluyen por defecto. Las advertencias de mínimos no bloquean la escritura salvo con `--strict`; en ese modo no se escriben los archivos.
- El comando solo se probó contra fixtures. No se ejecutó sobre `dataset/cases.jsonl`; el piloto continúa sin splits.

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

## Verificación realizada

En los cambios de evaluación:

- Pruebas de `tests/Feature/Evaluation`: **9 pasaron**, 45 aserciones.
- Pint, modo `--test`: pasó.
- Larastan (`composer analyse`): pasó sin errores.
- `php artisan eval:validate`: acepta los casos del piloto y muestra las advertencias esperadas.
- `php artisan eval:validate --strict`: falla cuando quedan advertencias, según lo requerido.
- Pruebas de `eval:split`: comprueban determinismo con la misma semilla, estratificación en el fixture, que el grupo repetido no se divide, exclusión de ejemplos y que `--strict` no escriba cambios.
- Se verificó que los 14 registros del piloto permanecen con `split: null`.

No se ejecutó la suite completa del proyecto como parte de P1/P3. El árbol de trabajo ya tenía otros cambios ajenos a estas tareas; no se revirtieron.

## Plan propuesto para E2: recolección aislada

Este plan describe el siguiente trabajo, no implica que esté implementado ni autoriza todavía una corrida real. E2 deberá aprobarse por separado antes de editar.

### 1. Configuración y aislamiento de base de datos

1. Añadir una conexión Laravel `evaluation`, PostgreSQL por defecto, con nombre de base `fn_eval` y variables de entorno `EVAL_DB_*`.
2. Permitir SQLite únicamente cuando se seleccione explícitamente `EVAL_DB_CONNECTION=sqlite`.
3. Antes de cualquier escritura, comprobar que el comando está conectado a `evaluation` y que la base corresponde a la configuración de evaluación. Establecer esa conexión como predeterminada solo dentro del proceso Artisan de evaluación.
4. Ejecutar las migraciones existentes contra la conexión de evaluación y crear/identificar un usuario técnico de evaluación. No reutilizar usuarios ni registros de producción.
5. Persistir los datos necesarios para el pipeline solamente en `fn_eval`. Los archivos locales con texto completo permanecen fuera de Git.

### 2. Reutilizar las mismas etapas del pipeline

El recolector debe reutilizar los jobs y servicios existentes, no duplicar la lógica de extracción, clasificación, búsqueda, verificación ni scoring:

1. Crear el artículo de evaluación a partir de URL o texto local.
2. Invocar `AnalyzeArticleJob`; interceptar el despacho de `ExtractClaimsJob` en lugar de permitir que se publique en Redis.
3. Ejecutar el mismo `ExtractClaimsJob` que usa producción.
4. Recoger el batch creado por ese job y ejecutar los objetos `ClassifyArticleJob`, `VerifyClaimJob` y `SearchClaimEvidenceJob` sobre la conexión de evaluación.
5. Ejecutar `FinalizeAnalysisJob` al terminar la barrera del batch.

En producción las tareas dentro del batch se procesan en paralelo; no se debe afirmar que allí tienen un orden temporal secuencial. La prueba de integración verificará el orden de construcción/despacho del batch usado por producción y que la finalización ocurre después de las etapas de ese batch. Un dispatcher acotado al proceso de evaluación impedirá que cualquier despacho/batch alcance Redis.

### 3. Opciones, modos y ejecución reanudable

Implementar las opciones acordadas:

- `--split`, `--case`, `--limit`, `--resume`, `--dry-run`, `--max-cases-per-minute` y `--run-id`.
- `--mode=production|blind`.
- `--no-factcheck`.

Los casos se procesarán en orden determinista por ID. `--resume` omitirá instantáneas completas y permitirá reintentar las fallidas o incompletas. `--dry-run` no conectará a la base, no descargará artículos y no llamará servicios HTTP.

Para `input_type=text`, se leerá `evaluation/cache/<id>.txt` si existe. Si falta, se usará `input_text_hint` y se registrará `input_source=hint`; si existe el archivo local, se registrará `input_source=original`. Un hint corto no es equivalente al texto completo y debe quedar visible como limitación de esa observación.

La interpretación propuesta de `blind` es ejecutar los mismos proveedores y etapas, pero no exponer `expected_verdict` al pipeline ni incluirlo en la instantánea de predicción. El ID permitirá unir la instantánea con la etiqueta en la fase de reporte. `production` y `blind` se reportarán separados.

### 4. Filtro opcional de dominios excluidos

El hook de exclusión debe estar inactivo si `excluded_domains` no está presente o está vacío, manteniendo el comportamiento de producción.

- Búsqueda web: enviar la lista de exclusión a Tavily cuando el proveedor la acepte y filtrar localmente los resultados devueltos por host y subdominios, incluso si el proveedor no la respeta.
- Google Fact Check: filtrar resultados por dominio del publicador (`publisher_site`) y host de la URL de verificación antes de persistirlos.
- Añadir pruebas de ambos filtros y una prueba explícita de que la ausencia de exclusiones no cambia la política/productos actuales.

### 5. Instantáneas y manifiesto

Escribir una instantánea atómica por caso en `evaluation/runs/<run_id>/cases/<id>.json`, sin almacenar el texto completo del artículo. Debe contener, cuando estén disponibles:

- hash del contenido de entrada y origen `original|hint`;
- claims, evidencias y verificaciones por claim;
- predicción BERT o su ausencia;
- score y veredicto originales del pipeline;
- modo (`production`/`blind`), proveedor, modelo y versiones;
- `algorithm_version` y commit Git;
- tokens observados, latencia, errores y marcas de tiempo.

El manifiesto por corrida registrará la configuración de scoring efectiva, versiones, parámetros, conteos por estado/modo y las referencias a instantáneas. Fallar un caso no debe detener el resto de la corrida; los errores deben quedar claramente registrados, sin simular éxito.

### 6. Estimación de llamadas, tokens y costo

Estimación nominal actual por caso, con hasta 10 claims:

- 1 llamada de extracción a Gemini;
- 1 llamada de síntesis a Gemini;
- 1 llamada al clasificador BERT, normalmente local;
- hasta 10 consultas de búsqueda web;
- hasta 20 consultas de Google Fact Check (puede probar una segunda consulta por claim si la primera no ofrece rating utilizable).

El total orientativo es hasta 33 solicitudes contando BERT, sin ser un máximo estricto. El fetch de URL puede seguir redirecciones; además existen fallbacks y reintentos, así que el número real puede ser superior.

El repositorio no mide actualmente tokens de Gemini ni dispone de tarifas. E2 debe extraer contadores de uso de las respuestas solo cuando el proveedor los entregue; en otro caso registrar `null`, no cero. Se propone añadir `evaluation/pricing.json`, versionado y editable, inicialmente con precios nulos. `--dry-run` mostrará las llamadas estimadas y que el costo está pendiente; no se inventarán precios. El sistema actual tampoco expone el saldo/cuota restante de las cuentas de proveedor, por lo que solo se podrán informar solicitudes, tokens reportados, latencias y respuestas/errores de cuota o rate-limit, no la cuota financiera consumida con precisión.

### 7. Pruebas y controles de ejecución

- Usar `Http::fake`, providers falsos y `preventStrayRequests` en pruebas; ninguna prueba usará red real.
- Probar orden de etapas y ausencia de despacho Redis.
- Probar aislamiento de la conexión; confirmar antes de escribir que no se apunta a la base normal.
- Probar ambos modos, `--no-factcheck`, dominios excluidos en búsqueda y fact-check, resume, límite de casos, errores por caso y escritura atómica.
- Probar `--dry-run` sin invocaciones HTTP ni escrituras de base.
- Ejecutar pruebas enfocadas, Pint y Larastan antes de cerrar E2.

### 8. Operación del piloto (P5)

`eval:collect` todavía no existe, así que no es posible ejecutar P5 ahora. Cuando E2 esté implementado, la secuencia acordada será:

1. Validar el piloto.
2. Ejecutar `eval:collect --dry-run` y mostrar solicitudes estimadas/costo pendiente.
3. Solo con autorización explícita, correr dos casos (uno `false`, uno `credible`) en modo `production` sobre la base de evaluación y detenerse para entregar solicitudes reales por proveedor, tokens disponibles, latencia, errores y datos de cuota que realmente expongan los proveedores.
4. Esperar otra autorización explícita antes de correr los 14 casos en `production` y después en `blind`.

No se ajustarán pesos ni se interpretarán resultados durante esas corridas.

## Pendiente posterior

- **E2:** recolección aislada, modos, exclusiones y snapshots.
- **E3:** scoring offline puro desde instantáneas y prueba de paridad.
- **P2 / E4:** métricas y reportes que incluyan solo `status=verificado` por defecto; `--include-unverified` amplía la selección y el reporte debe contar incluidos/excluidos. Separar vista estricta de cuatro clases y vista gruesa donde `misleading` y `false` se agregan. `inconclusive` predicho para una clase respondible se cuenta como abstención y reduce cobertura, no como error de clase.
- **E5:** bloquear reportes de test sin `--final` y registrar cada uso del test.
- **P4:** lectura local del texto cacheado o fallback al hint y separación de métricas por `input_source`.
- **E6:** smoke de CI sin red y documentación final.

## Decisiones de veredictos

El scoring actual puede devolver `credible`, `probably_credible`, `inconclusive`, `probably_misleading` y `false`. El dataset conserva cuatro etiquetas:

- `credible` y `probably_credible` se agrupan como `credible`;
- `probably_misleading` se agrupa como `misleading`;
- `false` permanece `false`;
- `inconclusive` permanece `inconclusive`.

El veredicto original del sistema debe conservarse en la instantánea. El reporte futuro mostrará tanto las cuatro clases estrictas como la vista de tres clases que agrupa `misleading` y `false`.
