# 🗞️ FakeNews Detector (FN) — Manual de Capacidades y Arquitectura

> **Documento vivo del sistema**  
> **Versión actual:** v1.3.1  
> **Última actualización:** 2026-09-22  
> **Estado de la suite de pruebas:** 100 tests automatizados · 99 passing · 1 skipped · 365 aserciones

---

## 1. Resumen Ejecutivo y Misión

**FakeNews Detector (FN)** es una plataforma de análisis integral diseñada para evaluar la **credibilidad y veracidad de noticias, artículos de prensa y rumores de redes sociales** mediante la agregación y triangulación de múltiples fuentes independientes de evidencia.

### Principio Fundamental de Diseño
> **Ningún modelo de inteligencia artificial individual determina la veracidad de una noticia.**  
> La decisión final no es una "caja negra"; surge de la convergencia de señales estadísticas, verificación contra bases de datos de fact-checking acreditadas, búsqueda activa de evidencias web independientes, análisis de reputación de fuentes y un motor matemático determinista y auditable.

---

## 2. Diagrama de Flujo del Pipeline

```text
                        ┌──────────────────────────────┐
                        │            INPUT             │
                        │    URL Web  o  Texto Libre   │
                        └──────────────┬───────────────┘
                                       │
                                       ▼
                        ┌──────────────────────────────┐
                        │   POST /api/v1/articles      │
                        │  Scraping / Deduplicación    │
                        └──────────────┬───────────────┘
                                       │
                                       ▼
                        ┌──────────────────────────────┐
                        │ POST /articles/{id}/analyze  │
                        │      AnalyzeArticleJob       │
                        └──────────────┬───────────────┘
                                       │
                       ┌───────────────┴───────────────┐
                       │                               │
                       ▼                               ▼
             ┌───────────────────┐           ┌───────────────────┐
             │ ExtractClaimsJob  │           │ClassifyArticleJob │
             │  (Gemini Flash)   │           │   (BERT Español)  │
             └─────────┬─────────┘           └─────────┬─────────┘
                       │                               │
                Claims extraídos                       │
                       │                               │
            ┌──────────┴──────────┐                    │
            ▼                     ▼                    │
   ┌─────────────────┐   ┌─────────────────┐           │
   │ VerifyClaimJob  │   │SearchEvidenceJob│           │
   │Google Fact Check│   │  Tavily / Brave │           │
   └────────┬────────┘   └────────┬────────┘           │
            │                     │                    │
            └──────────┬──────────┘                    │
                       ▼                               │
             Evidencias recolectadas                   │
                       │                               │
                       └───────────────┬───────────────┘
                                       │
                                       ▼
                        ┌──────────────────────────────┐
                        │     FinalizeAnalysisJob      │
                        ├──────────────────────────────┤
                        │ 1. SourceAnalysisService     │
                        │ 2. GeminiAnalystService      │
                        │ 3. CredibilityScoreService   │
                        └──────────────┬───────────────┘
                                       │
                                       ▼
                        ┌──────────────────────────────┐
                        │      Score & Veredicto       │
                        │   Analysis: 'completed'      │
                        └──────────────────────────────┘
```

---

## 3. Capacidades y Componentes Detallados

### 3.1 Ingesta y Normalización (`ArticleIngestionService`)
El sistema soporta dos modalidades de entrada:
1. **Modo URL**:
   - Descarga el HTML y utiliza `Symfony DomCrawler` para extraer título, descripción, autor, fecha y cuerpo del texto limpio.
   - Parsea estructuras semánticas **JSON-LD** y metadatos **OpenGraph**.
   - Normaliza el dominio de origen (remueve `www.`, subdominios innecesarios) y crea/asocia la entidad `Source`.
   - Normaliza automáticamente el idioma (`normalizeLanguage()`), soportando esquemas complejos como los de BBC Mundo.
2. **Modo Texto Libre / Cadenas de Mensajería**:
   - Permite enviar textos pegados directamente desde WhatsApp, Telegram o redes sociales sin requerir una URL externa.
   - Si no se provee un título, deriva un resumen sintético a partir de los primeros 80 caracteres.
3. **Deduplicación Estricta**:
   - Todo contenido genera una huella SHA-256 (`content_hash`) y se compara contra la URL canónica.
   - Si un artículo ya existe en base de datos, el sistema lo reutiliza en lugar de duplicarlo.

### 3.2 Extracción Estructurada de Claims (`GeminiProvider`)
* **Modelo principal**: Google Gemini (`gemini-3.6-flash`, con fallbacks dinámicos a `gemini-3.5-flash` y `gemini-3.5-flash-lite`).
* **Structured Output (`responseSchema`)**: La IA responde obligatoriamente en un formato JSON estricto con:
  * `claims`: Arreglo de afirmaciones atómicas verificables.
  * `type`: Categoría (`factual`, `statistical`, `causal`, `attribution`, `quote`, `prediction`, `opinion`).
  * `importance`: Nivel de relevancia para la noticia (0.0 a 1.0).
  * `context`: Oración o párrafo de respaldo dentro del artículo.
  * `verifiable`: Indicador booleano de si la afirmación es contrastable con hechos del mundo real.
* **Huella Digital (`fingerprint`)**: Cada claim genera un hash SHA-256 normalizado para evitar verificar dos veces la misma afirmación.

### 3.3 Clasificación Estadística (`BertFakeNewsProvider`)
* Consulta en paralelo a un microservicio en Python (FastAPI + PyTorch) ejecutando el modelo `Juanillaberia/spanish-fake-news-classifier`.
* Devuelve una predicción estadística (`REAL` o `FAKE`) y una probabilidad (0.00 a 1.00).
* **Rol en el sistema**: Funciona como señal auxiliar de estilo lingüístico; **nunca anula por sí solo las evidencias factuales**.

### 3.4 Fact-Checking Externo Acreditado (`GoogleFactCheckProvider`)
* Consulta la API de **Google Fact Check Tools** para cada claim verificable.
* Normaliza las evaluaciones de decenas de organizaciones acreditadas (Chequeado, Maldita.es, Newtral, AFP Factual, EFE Verifica, Reuters Fact Check, Snopes, etc.).
* Asigna un puntaje normalizado (0.0 a 1.0) y almacena las fuentes oficiales de desmentido o ratificación.

### 3.5 Búsqueda Web de Evidencias (`EvidenceSearchService`)
* Orquestado mediante `SearchProviderManager` (con proveedor principal **Tavily Search** y fallback automático a **Brave Search**).
* Para afirmaciones no cubiertas por fact-checkers tradicionales, el sistema busca en la web abierta coberturas periodísticas y documentos oficiales.
* Aplica canonicalización de URLs, deduplicación de fuentes y extracción de fragmentos textuales (`snippets`).

### 3.6 Análisis Heurístico de Fuentes (`SourceAnalysisService`)
* Evalúa la reputación del medio o dominio emisor mediante reglas deterministas:
  * **Verificadores acreditados**: Puntuación 0.90 – 0.95 (AFP, Chequeado, Maldita, Newtral, Reuters, AP, etc.).
  * **Prensa internacional establecida**: Puntuación 0.78 – 0.88 (El País, BBC, The Guardian, NYT, Clarín, etc.).
  * **TLDs institucionales y académicos**: Detección prioritaria de extensiones `.gov`, `.gob.*`, `.edu`, `.edu.*`, `.mil`.
  * **Puntuación configurable**: Soporte para sobreescribir reputación en base de datos.
  * **Envíos sin dominio (texto directo)**: Asignación de nivel neutro (`unknown`, reputación 0.50) sin penalización arbitraria.

### 3.7 Síntesis Semántica del Analista (`GeminiAnalystProvider`)
* Se le presenta el expediente completo de auditoría: artículo original, claims extraídos, fact-checks encontrados, evidencias web y predicción de BERT.
* Genera una síntesis cualitativa fundamentada con prompt anti-alucinación:
  * Evaluación de postura por claim (`corroborated`, `refuted`, `mixed`, `unsupported`).
  * Identificación de contradicciones y discrepancias clave.
  * Detección de posibles falacias lógicas, omisiones de contexto o sesgos evidentes.

### 3.8 Motor de Puntuación Determinista (`CredibilityScoreService`)
Calcula matemáticamente **7 subscores (0 a 100)** basados en fórmulas transparentes (configurables en `config/scoring.php`):

| Subscore | Peso sugerido | Qué mide |
| :--- | :---: | :--- |
| `evidence_score` | 25% | Proporción y balance de afirmaciones respaldadas vs. refutadas por fact-checks y noticias web. |
| `source_quality_score` | 15% | Reputación del medio emisor y presencia de dominio verificado o institucional. |
| `corroboration_score` | 15% | Grado de corroboración en múltiples medios independientes no vinculados entre sí. |
| `independence_score` | 15% | Diversidad de dominios y ausencia de endogamia de citas. |
| `consistency_score` | 10% | Coherencia semántica interna entre las diferentes afirmaciones del texto. |
| `language_score` | 10% | Integración de la señal de BERT (estilo periodístico vs. sensacionalista/clickbait). |
| `transparency_score` | 10% | Presencia de autor identificado, fecha de publicación y mención de fuentes primarias. |

**Veredicto Final (`final_verdict`)**:
* `credible`: Score ≥ 75
* `probably_credible`: Score entre 60 y 74.99
* `inconclusive`: Score entre 45 y 59.99 (o baja densidad de evidencia)
* `probably_misleading`: Score entre 30 y 44.99
* `false`: Score < 30

**Índice de Confianza (`confidence_score`)**:
* Valor entre `0.00` y `1.00` que refleja la **cantidad y densidad de señales** disponibles. Un análisis con pocas evidencias tendrá un confidence score menor que uno ampliamente cubierto en la web.

---

## 4. Resiliencia y Tolerancia a Fallos

1. **Reintentos con Backoff Exponencial en IA**:
   - `GeminiProvider` y `GeminiAnalystProvider` capturan errores 503 (alta demanda) y status 0 (timeout de socket), reintentando a los 4s, 16s y 60s.
   - Si el modelo principal está indisponible, conmuta automáticamente a modelos de respaldo compatibles.
2. **Tolerancia en Lotes (`Bus::batch`)**:
   - En `ExtractClaimsJob`, la verificación de claims usa `allowFailures()`. Si una búsqueda web falla para un claim específico, el resto del proceso continúa y calcula el veredicto final con las evidencias que sí se obtuvieron.
3. **Dead Letter Queue (DLQ) y Estados de Falla**:
   - Si un Job agota todos sus reintentos (`tries = 3`), el hook `failed()` actualiza `analyses.status = 'failed'` y guarda la traza en `analyses.error` para inspección.
4. **Endpoint de Reintento (`retry`)**:
   - Los análisis en estado `failed` pueden relanzarse en cualquier momento mediante la API sin recrear el artículo.

---

## 5. Catálogo Completo de la API REST (`/api/v1`)

### Artículos
| Método | Endpoint | Descripción | Rate limit |
| :--- | :--- | :--- | :---: |
| `GET` | `/api/v1/articles` | Listado paginado con búsqueda (`?search=palabra`) y filtro (`?domain=bbc.com`). | 60/min |
| `POST` | `/api/v1/articles` | Ingesta de artículo mediante URL (`url`) o texto libre (`content`). | 10/min |
| `GET` | `/api/v1/articles/{id}` | Información detallada del artículo, fuente y lista de análisis asociados. | 60/min |
| `POST` | `/api/v1/articles/{id}/analyze` | Encola el análisis asíncrono para el artículo (devuelve HTTP 202). | 20/min |

### Análisis
| Método | Endpoint | Descripción | Rate limit |
| :--- | :--- | :--- | :---: |
| `GET` | `/api/v1/analyses` | Listado paginado de análisis con filtros (`?status=completed`, `?final_verdict=false`). | 60/min |
| `GET` | `/api/v1/analyses/{id}` | Expediente completo de auditoría (artículo, claims, fact-checks, evidencias, score). | 60/min |
| `GET` | `/api/v1/analyses/{id}/status` | **Polling liviano** para frontend (estado, etapa actual, tiempo transcurrido, scores). | 120/min |
| `POST` | `/api/v1/analyses/{id}/retry` | Re-encola un análisis fallido (devuelve 409 si está en proceso). | 10/min |

---

## 6. Stack Tecnológico e Infraestructura

* **Backend**: Laravel 11 / PHP 8.4.
* **Base de Datos**: PostgreSQL 16 (con soporte JSONB para metadata y esquemas dinámicos) / SQLite en memoria para tests.
* **Colas y Caché**: Redis 7 Alpine.
* **Inteligencia Artificial**:
  * Google Gemini API (Structured Outputs vía `v1beta`).
  * Google Fact Check Tools API.
  * Modelo BERT en español: `Juanillaberia/spanish-fake-news-classifier`.
* **Microservicio ML**: Python 3.11 + FastAPI + PyTorch + Hugging Face Transformers.
* **Integración Continua**: GitHub Actions (`.github/workflows/ci.yml`).
* **Contenedores**: `docker-compose.yml` unificado para PostgreSQL, Redis y ML Service.

---

## 7. Puesta en Marcha Rápida (Entorno Local)

```bash
# 1. Clonar el repositorio y entrar al backend
cd backend

# 2. Configurar variables de entorno
cp .env.example .env

# 3. Levantar los servicios auxiliares (PostgreSQL, Redis y Servicio ML)
docker compose up -d

# 4. Instalar dependencias de PHP
composer install

# 5. Generar llave de aplicación y correr migraciones
php artisan key:generate
php artisan migrate

# 6. Iniciar el procesador de colas (si QUEUE_CONNECTION=redis)
php artisan queue:work

# 7. Iniciar el servidor de desarrollo
php artisan serve
```

---

## 8. Frontend y Hoja de Ruta

El desarrollo del **Frontend SPA** se encuentra en marcha en el directorio `frontend/`. 
El stack tecnológico elegido es **React 19 + TypeScript + Vite + Tailwind CSS v4**.

### Vistas a desarrollar:
1. **Vista de Ingesta (Entrada)**: Caja de texto con pestañas para "Pegar Enlace" o "Pegar Texto / Rumor".
2. **Vista de Progreso (Polling)**: Barra de carga interactiva conectada a `GET /api/v1/analyses/{id}/status` reflejando las fases en tiempo real.
3. **Vista de Resultados (Dashboard)**:
   * Semáforo de veredicto (`credible`, `probably_credible`, `inconclusive`, `probably_misleading`, `false`).
   * Medidor radial con el puntaje de credibilidad (0-100).
   * Acordeón de claims analizados con sus fuentes y enlaces directos a las evidencias.
   * Gráfico de radar o barras con los 7 subscores.
   * Resumen explicativo generado por el analista cualitativo.
4. **Vista de Historial/Exploración**: Buscador de noticias ya verificadas para consumo público.

### Futuras Mejoras (Post-MVP)
* **OCR para Imágenes**: Extracción de texto de capturas de pantalla de chats o redes sociales mediante Gemini Vision.
