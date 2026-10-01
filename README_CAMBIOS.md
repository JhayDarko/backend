# Control de cambios

Registro breve de modificaciones del proyecto.

## v1.3.1 - 2026-09-22 (Ingesta de Texto Libre & Rumores para MVP)

### Implementado

- **Soporte de Ingesta de Texto Libre (`POST /api/v1/articles`)**:
  - Habilitada la recepción de noticias, rumores o cadenas de mensajería directamente como texto (`content`), eliminando la obligatoriedad estricta de una URL externa.
  - Campos opcionales admitidos: `title` y `author_name`. Si no se proporciona título, se deriva automáticamente un encabezado sintético a partir de los primeros 80 caracteres del contenido.
  - Validación cruzada en `StoreArticleRequest`: exige al menos `url` o `content` (`required_without`).
  - Creado método `ingestText()` en `ArticleIngestionService` con deduplicación garantizada mediante hash SHA-256 (`content_hash`).
  - Migración `2026_09_22_000001_make_url_nullable_on_articles_table.php` para hacer la columna `url` nullable en PostgreSQL/SQLite.

### Tests Añadidos
- Agregados tests en `ArticleApiTest` para verificar la ingesta de texto libre, deduplicación de cadenas idénticas por hash y validación 422 cuando no se envía ni URL ni texto.
- La suite creció a 100 tests automatizados (99 aprobados, 1 skipped, 365 aserciones).

---

## v1.3.0 - 2026-09-22 (Polling, Paginación, Reintentos, Resiliencia y CI/CD)

### Implementado

- **Endpoint de Polling Liviano (`GET /api/v1/analyses/{id}/status`)**:
  - Creado `AnalysisStatusResource` con respuesta ultra-rápida y ligera para polling recurrente desde el frontend (sin serializar el grafo pesado de claims y evidencias).
  - Reporta el estado (`pending`, `processing`, `completed`, `failed`), el sub-paso actual (`queued`, `extracting_claims`, `verifying_evidence`, `finalizing`, `completed`), conteo de claims y señales, métricas de tiempo transcurrido (`elapsed_seconds`), scores y mensaje de error si existiera.
  - Tasa de limitación optimizada (`throttle:120,1`).

- **Listado y Búsqueda de Artículos (`GET /api/v1/articles`)**:
  - Paginación automática configurable vía query param `per_page` (por defecto 15, máx 100).
  - Búsqueda por texto con parámetro `search` (sobre título, descripción, URL y autor).
  - Filtrado por dominio de fuente con parámetro `domain`.
  - Implementado `ArticleController::show` (`GET /api/v1/articles/{id}`) con carga ansiosa de la fuente y análisis asociados.

- **Listado y Filtrado de Análisis (`GET /api/v1/analyses`)**:
  - Paginación de ejecuciones de análisis ordenadas cronológicamente de forma descendente.
  - Filtros soportados: `status` ('pending', 'processing', 'completed', 'failed'), `final_verdict` ('credible', 'false', etc.) y `article_id`.

- **Mecanismo de Reintento de Análisis (`POST /api/v1/analyses/{id}/retry`)**:
  - Permite relanzar un análisis fallido o incompleto limpiando el estado de error, restableciendo timestamps y despachando nuevamente `AnalyzeArticleJob`.
  - Protección de concurrencia: bloquea con `409 Conflict` si el análisis se encuentra en ejecución activa (`processing`).

- **Resiliencia de Pipeline & Dead Letter Queue (DLQ)**:
  - Añadido hook `failed(Throwable $exception)` a `AnalyzeArticleJob` para registrar la falla permanente en `analyses.error` y `analyses.status = 'failed'` cuando se superan los 3 reintentos.
  - Añadido `allowFailures()` y callback `finally` con fallback en el batch `Bus::batch` de `ExtractClaimsJob`: si un proveedor externo de búsqueda o fact-checking falla en un claim individual, la recolección no se cancela abruptamente y se procede al cálculo final del score con las evidencias recopiladas.
  - Añadido el atributo `error` a `AnalysisResource`.

- **Integración Continua con GitHub Actions (`.github/workflows/ci.yml`)**:
  - Workflow automatizado que valida la suite completa de pruebas en PHP 8.4 bajo Ubuntu con cache de Composer y base de datos SQLite en memoria.

- **Docker Compose Completo (`docker-compose.yml`)**:
  - Incorporado el servicio oficial de `postgres:16-alpine` con healthcheck automático de disponibilidad, integrando en una sola red Docker a PostgreSQL, Redis 7 y el microservicio Python de clasificación BERT.

### Tests Añadidos
- Creados `AnalysisStatusApiTest`, `ArticleApiTest`, `AnalysisListAndRetryApiTest` y `AnalyzeArticleJobTest`.
- La suite creció de 81 a 97 tests automatizados (96 aprobados, 1 skipped, 352 aserciones) con tiempo de ejecución de ~4.5 segundos.

---

## v1.2.2 - 2026-09-08 (Correcciones de ejecución)

### Corregido

- **`ExtractClaimsJob`**: Corregido el nombre de la excepción lanzada cuando un análisis no tiene artículo asociado: `untimeException` pasó a ser `RuntimeException`. El caso ahora falla de forma controlada y permite el flujo de reintentos configurado para el job.

- **Requisito de PHP**: Alineado `composer.json` y la sección `platform` de `composer.lock` con las dependencias instaladas. El proyecto requiere PHP `>=8.4.1 <9.0`; con PHP 8.2 no puede cargarse el autoloader ni ejecutarse Artisan.

### Verificación

- Validada la sintaxis de todos los archivos PHP de `app/`.
- La suite de Artisan queda pendiente de ejecutarse en un entorno con PHP 8.4.1 o superior.

---

## v1.2.1 - 2026-09-02 (Hotfixes & Resiliencia)

### Corregido

- **`EvidenceSearchService`**: Los proveedores `TavilySearchProvider` y `BraveSearchProvider` devuelven objetos `SearchResult` en lugar de arrays. Se añadió manejo polimórfico en el bucle de normalización para soportar tanto instancias de `SearchResult` como arrays.

- **`ArticleExtractionService`**: El campo `inLanguage` de algunos JSON-LD (p.ej. BBC Mundo) viene como array de strings (`["Language", "Spanish", "es"]`). Se añadió el método privado `normalizeLanguage()` que extrae el primer código ISO corto del array, resolviendo el `TypeError` al construir `ExtractedArticleData`.

- **Modelo Gemini deprecado**: `gemini-2.0-flash` fue retirado por Google. Actualizado el modelo por defecto a `gemini-3.6-flash` en `config/ai.php`, `.env` y `.env.example`.

### Mejorado

- **`GeminiProvider` — Retry + Fallback de modelos**: Implementada lógica de reintentos con backoff exponencial (4s, 16s, 60s) ante errores 503 (alta demanda). Si el modelo principal agota reintentos o devuelve 404 (deprecado), el sistema prueba automáticamente en orden: `gemini-2.5-flash` → `gemini-2.5-flash-lite` → `gemini-2.0-flash-lite`.

- **`GeminiAnalystProvider` — Retry + Fallback de modelos**: Misma lógica de resiliencia aplicada al proveedor de síntesis cualitativa.

- **`phpunit.xml`**: Añadida variable de entorno `GEMINI_API_KEY=""` para que el test opcional de integración con Gemini se marque automáticamente como `skipped` en entornos CI sin API key, en lugar de fallar con error 404.

- **`.env` configurado**: Activados `GOOGLE_FACT_CHECK_ENABLED=true`, `SEARCH_PROVIDER_ENABLED=true`, `TAVILY_SEARCH_ENABLED=true` y `BRAVE_SEARCH_ENABLED=true` con credenciales de `.env.example`, y cambiado `QUEUE_CONNECTION=sync` para ejecución local sin worker de colas.

---

## v1.2.0 - 2026-09-02

### Implementado

- **Capa de Análisis de Fuentes (Source Analysis)**:
  - Creado `SourceAnalysisService` y DTO `SourceAnalysisResult` para evaluación heurística y reputacional de fuentes y dominios.
  - Reconocimiento y asignación de confianza para organizaciones de fact-checking acreditadas (AFP Factual, Chequeado, Maldita.es, Newtral, Colombiacheck, EFE Verifica, Reuters, AP, Snopes, etc.).
  - Detección y priorización de TLDs institucionales/académicos (`.gov`, `.gob.*`, `.edu`, `.edu.*`, `.mil`).
  - Soporte de reputación explícita configurada en la entidad `Source`.

- **Capa Gemini Analyst (Síntesis Semántica Cualitativa)**:
  - Creado el contrato `AnalystProviderInterface` y DTOs `AnalystSynthesisResult` y `AnalystClaimEvaluation`.
  - Implementado `GeminiAnalystProvider` con salida estructurada JSON estricta (`responseSchema`) y prompt fundamentado para análisis cruzado de claims, verificaciones de fact-checking, evidencias web y predicciones BERT sin alucinaciones.
  - Implementado `GeminiAnalystService` para orquestar la agregación de contexto y la ejecución del análisis cualitativo.

- **Motor Determinista de Credibilidad (`CredibilityScoreService`)**:
  - Implementado `CredibilityScoreService` y DTO `ScoreResult` para cálculo determinista de subscores (0 a 100) y score final ponderado.
  - Subscores calculados: `evidence_score`, `source_quality_score`, `corroboration_score`, `independence_score`, `consistency_score`, `language_score` y `transparency_score`.
  - Creado archivo de configuración `config/scoring.php` con pesos ajustables, umbrales de veredicto final (`credible`, `probably_credible`, `inconclusive`, `probably_misleading`, `false`) y tablas de normalización.
  - Cálculo de nivel de confianza (`confidence_score`) entre 0.00 y 1.00 en función de la densidad de señales recopiladas.

- **Persistencia de Scores y Migración Incremental**:
  - Añadida la migración `2026_09_02_000001_add_metadata_to_scores_table.php` para almacenar el desglose de métricas, pesos y síntesis del analista en la columna `metadata` (JSON).
  - Actualizado el modelo `Score` con `$fillable`, `HasFactory` y cast de `metadata`.

- **Orquestación del Pipeline Asíncrono**:
  - Creado `FinalizeAnalysisJob` para coordinar de forma asíncrona e idempotente el análisis de fuentes, la síntesis cualitativa, el cálculo determinista de scores y la actualización del estado de `Analysis` a `completed`.
  - Actualizado `AnalyzeArticleJob` para despachar concurrentemente `ExtractClaimsJob` y `ClassifyArticleJob`.
  - Actualizado `ExtractClaimsJob` para despachar `FinalizeAnalysisJob` tras extraer claims y despachar las verificaciones/búsquedas de evidencia.

- **Serialización en la API**:
  - Creado `ScoreResource` para exponer el desglose completo de puntuaciones, versión del algoritmo y metadata explicativa.
  - Creado `ModelPredictionResource` para exponer las predicciones auxiliares del clasificador BERT.
  - Actualizado `AnalysisResource` y `AnalysisController` para eager loading y serialización completa de `article.source`, `score`, `predictions`, y `claims.claimVerifications.evidences`.

- **Inyección de Dependencias**:
  - Registrado `AnalystProviderInterface` vinculado a `GeminiAnalystProvider` en `AppServiceProvider`.

### Verificación

- Ejecutada suite completa de pruebas unitarias y de integración con `php artisan test`.
- Resultados: 80 tests ejecutados, 79 correctos, 1 omitido (integración opcional en vivo), 283 aserciones, exit code 0.
- Validación de tests específicos para:
  - `SourceAnalysisServiceTest`
  - `GeminiAnalystProviderTest`
  - `GeminiAnalystServiceTest`
  - `CredibilityScoreServiceTest`
  - `FinalizeAnalysisJobTest`
  - `AnalysisFullPipelineTest`
  - `ArticleAnalysisPipelineTest`

### Nota

- Se respeta estrictamente el principio arquitectónico fundamental del proyecto: ningún modelo individual (ni BERT ni Gemini) decide el veredicto ni el score. El veredicto final surge de un cálculo matemático transparente y determinista que combina señales independientes y evidencia auditable.

## v1.1.2 - 2026-09-01

### Implementado

- Añadida la capa de clasificación auxiliar con BERT para noticias en español usando el modelo `Juanillaberia/spanish-fake-news-classifier`.
- Creado el contrato `ClassifierProviderInterface` para desacoplar la clasificación del proveedor concreto.
- Implementado `BertFakeNewsProvider` con HTTP hacia un servicio Python/ML, validación de labels `REAL`/`FAKE`, comprobación de rangos de confianza y manejo de errores del proveedor.
- Añadido el DTO `ClassificationResult` para encapsular label, confianza, probabilidad y metadata del modelo.
- Creado `ClassifyArticleJob` para ejecutar la clasificación en cola con la misma regla de idempotencia de la extracción de claims.
- Añadida la entidad `ModelPrediction` y la migración `2026_09_01_000001_create_model_predictions_table.php` para persistir la predicción por análisis.
- Registrado el provider BERT en el contenedor de dependencias de Laravel y añadida la config en `config/ai.php`.
- Añadido el servicio Python `ml_service` con FastAPI, Transformers y soporte de Docker para servir la inferencia del modelo.
- Actualizado `docker-compose.yml` para incluir el servicio `ml-service` junto a Redis.

### Verificación

- Ejecutados tests dirigidos para `BertFakeNewsProvider` y `ClassifyArticleJob` con resultado correcto.
- Resultado verificado: 4 tests, 4 correctos, 9 assertions, exit code 0.

### Nota

- La clasificación BERT se integra como señal auxiliar y no como veredicto final de autenticidad. Sigue siendo una capa de apoyo y no reemplaza la verificación de evidencia ni la puntuación final del artículo.

## v1.1.1 - 2026-09-01

### Implementado

- Creado el job `ExtractClaimsJob` para procesar la extracción de claims en cola con Redis.
- Integrado el flujo `AnalyzeArticleJob` -> `ExtractClaimsJob` -> `ClaimExtractionService` -> `AiProviderInterface` -> `GeminiProvider` -> PostgreSQL.
- El job recupera `Analysis` y `Article`, actualiza su estado, ejecuta la extracción y persiste los claims de forma idempotente.
- Añadido manejo de errores con actualización del estado `failed` y trazado de logs.
- Configurado `tries` y `backoff` para reintentos controlados sin duplicar claims.
- La extracción queda protegida frente a re-ejecuciones repetidas por el mismo `analysis_id`.

### Verificación

- Ejecutados tests del job con `Queue::fake()` y validación del comportamiento de encolado, persistencia y idempotencia.
- Resultado verificado: suite completa ejecutada con `php artisan test`, 39 tests correctos y 110 assertions.

### Nota

- No se ha implementado ninguna búsqueda externa ni verificación con BERT/Google Fact Check, como se solicitó en la fase actual del pipeline.

## v1.1.0 - 2026-09-01

### Implementado

- Capa de abstracción de IA para extracción de claims con contrato `AiProviderInterface`.
- Implementación del proveedor Gemini mediante HTTP y respuesta estructurada con esquema JSON.
- DTOs para `ExtractedClaimsResponse`, `ClaimData`, `EntityData` y `SourceData`.
- Excepciones centralizadas para configuración, timeout, rate limiting, conexión y validación de respuesta.
- Configuración del proveedor en `config/ai.php` con variables de entorno para Gemini y extensibilidad para futuros proveedores.
- Pruebas unitarias para el proveedor Gemini, DTOs y excepciones.

### Verificación

- Ejecutados los tests del proveedor y DTOs con resultado correcto.
- Resultado verificado: 31 tests ejecutados, 31 correctos, 81 assertions.
- Comprobación de secretos: no hay API keys hardcodeadas ni credenciales embebidas en el código.
- La capa de abstracción queda validada y lista para ser utilizada por la siguiente fase del pipeline.

### Nota

- Se ha respetado el bloqueo de flujo indicado por el equipo: la capa de IA queda validada antes de continuar con la siguiente fase del pipeline de extracción.

## v1.0.1 - 2026-08-28

### Corregido

- Corregido el endpoint `GET /api/v1/analyses/{analysis}`.
- `AnalysisController::show()` declaraba `JsonResponse`, pero devolvía un `AnalysisResource` (`JsonResource`), provocando un error de tipo al consultar un análisis existente.
- El método ahora declara correctamente `JsonResource` como tipo de retorno.

### Verificación

- Sintaxis PHP del controlador validada.
- Solicitud `GET /api/v1/analyses/1` reproducida y corregida.

## v1.0.0 - 2026-08-27

### Implementado

- Validación de URLs públicas para proteger la ingesta de artículos.
- Rate limiting para los tres endpoints API.
- Recursos JSON controlados para artículos, fuentes y análisis.
- Respuestas `202 Accepted` para análisis enviados a la cola.
- Restricción única para evitar análisis duplicados por artículo.
- Respuestas JSON con caracteres Unicode sin escapar (`ñ`, tildes y otros caracteres especiales).

## v1.4.0 - 2026-09-30

### Implementado

- Inicialización del repositorio Frontend en el directorio `frontend`.
- Configurado framework **React 19** con **Vite** y **TypeScript**.
- Configurado e integrado **Tailwind CSS v4** mediante `@tailwindcss/vite` para un desarrollo de UI rápido y reactivo.

### Próximos pasos
- Desarrollar los componentes de Ingesta, Polling y Dashboard para consumir la API de Laravel.

## v1.4.1 - 2026-09-30

### Implementado en el Frontend

- **Capa de Servicios**:
  - Configurado `axios` y definidos los contratos (Interfaces TS) completos para los endpoints `/api/v1/analyses`, `/status` y la respuesta completa de análisis (Claims, Evidencias, Scores).
- **Vistas Completadas**:
  - `Home.tsx`: Vista de Ingesta con UI de pestañas para URLs o texto plano. Manejo de estado de carga y errores centralizado.
  - `Analysis.tsx`: Pantalla de *Polling* en tiempo real (`setInterval` cada 3s). Muestra un stepper visual de las fases asíncronas del backend. Redirección automática al finalizar.
  - `Dashboard.tsx`: Vista de Resultados definitiva. Implementado el semáforo visual de credibilidad, indicador radial de score, bloque destacado para la "Síntesis del Analista", y componente expansible (Acordeón) para explorar las afirmaciones extraídas y sus respectivas evidencias web con hipervínculos cruzados.
- **Documentación**:
  - Creado `frontend/README.md` detallando la arquitectura y puesta en marcha del frontend.
