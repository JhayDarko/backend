# FactNode – Detector de veracidad de noticias (backend + interfaz web)

> **Contrasta. Evidencia. Decide.**

[![CI](https://github.com/JhayDarko/backend/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/JhayDarko/backend/actions/workflows/ci.yml)
![PHP 8.4](https://img.shields.io/badge/PHP-8.4-777BB4?logo=php&logoColor=white)
![Node 24](https://img.shields.io/badge/Node.js-24-339933?logo=nodedotjs&logoColor=white)
![Python 3.11](https://img.shields.io/badge/Python-3.11-3776AB?logo=python&logoColor=white)

FactNode recibe una noticia (URL o texto pegado), extrae sus afirmaciones, las contrasta con
verificadores de datos y búsquedas web, y devuelve un **informe explicable**: puntaje de
credibilidad (0–100), veredicto, evaluación de veracidad en lenguaje natural y las fuentes
usadas para cada afirmación.

Este documento sirve como guía de sustentación: explica **qué hace** el sistema, **cómo
está construido**, **por qué se tomaron las decisiones** y **cuáles son sus límites**.

---

## Tabla de contenido

1. [Qué problema resuelve y qué NO hace](#1-qué-problema-resuelve-y-qué-no-hace)
2. [Stack tecnológico](#2-stack-tecnológico)
3. [Arquitectura](#3-arquitectura)
4. [Flujo completo de un análisis](#4-flujo-completo-de-un-análisis)
5. [Modelo de datos](#5-modelo-de-datos)
6. [Algoritmo de puntaje y veredicto (v1.2.0)](#6-algoritmo-de-puntaje-y-veredicto-v120)
7. [Integraciones externas y resiliencia](#7-integraciones-externas-y-resiliencia)
8. [Política de fuentes de evidencia](#8-política-de-fuentes-de-evidencia)
9. [Colas, concurrencia e idempotencia](#9-colas-concurrencia-e-idempotencia)
10. [API REST](#10-api-rest)
11. [Seguridad](#11-seguridad)
12. [Interfaz web](#12-interfaz-web)
13. [Estructura del código](#13-estructura-del-código)
14. [Configuración (variables de entorno)](#14-configuración-variables-de-entorno)
15. [Instalación y ejecución](#15-instalación-y-ejecución)
16. [Calidad y CI](#16-calidad-y-ci)
17. [Decisiones de diseño y alternativas descartadas](#17-decisiones-de-diseño-y-alternativas-descartadas)
18. [Limitaciones conocidas y trabajo futuro](#18-limitaciones-conocidas-y-trabajo-futuro)
19. [Preguntas frecuentes de sustentación](#19-preguntas-frecuentes-de-sustentación)
20. [Glosario](#20-glosario)

---

## 1. Qué problema resuelve y qué NO hace

**Problema:** una persona lee una noticia y quiere saber si es confiable, sin tener que
buscar manualmente cada dato.

**Qué hace el sistema**

- Acepta una noticia por **URL** (extrae título, cuerpo, autor, fecha, medio) o por **texto
  pegado**.
- Divide la noticia en **afirmaciones (claims)** verificables y no verificables.
- Para cada afirmación verificable consulta **verificadores de datos** (Google Fact Check
  Tools) y **búsquedas web** (Tavily, con Brave de respaldo).
- Un **analista basado en Gemini** evalúa cada afirmación únicamente con la evidencia
  encontrada y redacta una síntesis.
- Un **clasificador BERT** en español aporta una señal auxiliar de estilo/lenguaje.
- Un **servicio de puntaje determinista** combina todas las señales en un puntaje, un
  veredicto y una explicación.

**Qué NO hace (importante al sustentar)**

- No "adivina" la verdad con un modelo: **BERT y los resultados de búsqueda nunca son prueba**
  de un hecho; son pistas o señales auxiliares.
- No entrena ni reentrena modelos con cada petición.
- No garantiza una exactitud medida: **no existe aún un conjunto etiquetado** para calcular
  precisión/recall (ver [limitaciones](#18-limitaciones-conocidas-y-trabajo-futuro)).
- Cuando no hay evidencia suficiente **se abstiene** (`inconclusive`) en vez de forzar un
  veredicto.

---

## 2. Stack tecnológico

| Capa | Tecnología | Por qué |
|---|---|---|
| Lenguaje / framework | PHP ≥ 8.4, **Laravel 13** | Colas, ORM, validación, recursos JSON y pruebas integrados |
| Base de datos | **PostgreSQL 16** (Docker) | Restricciones únicas y JSON; robusta para concurrencia |
| Colas / caché de jobs | **Redis 7** (Docker, cliente `predis`) | Procesamiento asíncrono y varios workers en paralelo |
| Caché de aplicación | Base de datos (`CACHE_STORE=database`) | Suficiente; se usa para el "circuit breaker" de modelos Gemini |
| IA generativa | **Google Gemini** (primario) y **OpenAI `gpt-4o-mini`** (respaldo en extracción) | Salida estructurada (JSON Schema) y alta disponibilidad con rotación de llaves y modelos |
| Clasificador | **BERT** `Juanillaberia/spanish-fake-news-classifier` en un microservicio **FastAPI** (Python, Docker) | Modelo específico para español, aislado del backend |
| OCR y selección de contenido | **PaddleOCR 3.7** local + **Gemini** | PaddleOCR extrae texto; Gemini recibe solo las líneas OCR y separa noticia, texto integrado en la imagen, perfil y otros elementos antes del análisis |
| Verificación | **Google Fact Check Tools API** | Revisiones editoriales reales con rating |
| Búsqueda web | **Tavily** (principal) y **Brave** (respaldo) | Resultados con snippets aptos para análisis |
| Extracción HTML | `symfony/dom-crawler` | Lee JSON-LD, Open Graph, metadatos y párrafos |
| Frontend | **Angular 21** (standalone, signals) en `frontend/` | SPA accesible (WCAG 2.2 AA) con la identidad FactNode; se sirve con Angular CLI en desarrollo |
| Cuentas y API | **Laravel Sanctum** (tokens) + API keys propias | Sesiones de usuario para la app y claves con planes para la API pública |
| Informes | **dompdf** | PDF y JSON descargables de cada análisis |
| Pruebas | **PHPUnit 12**, SQLite en memoria | Rápidas y aisladas de servicios externos (`Http::fake`) |
| CI | GitHub Actions (PHP 8.4, Node 24, Python 3.11) | Valida backend PostgreSQL, frontend, microservicios Python, OpenAPI y seguridad en cada push/PR |

---

## 3. Arquitectura

### 3.1 Vista de componentes

```mermaid
flowchart LR
    U["Usuario (navegador)"] --> W["Frontend Angular (ng serve)"]
    W -->|"REST /api/v1"| A["Laravel API"]
    A --> PG[("PostgreSQL")]
    A -->|"encola jobs"| R[("Redis")]
    A --> OCR["PaddleOCR local (FastAPI)"]
    R --> Q["Workers de cola (varios)"]
    Q --> PG
    Q --> G["Gemini / OpenAI"]
    Q --> B["Servicio BERT (FastAPI)"]
    Q --> F["Google Fact Check"]
    Q --> S["Tavily / Brave"]
```

### 3.2 Capas del backend

```mermaid
flowchart TB
    C["Controllers + FormRequests + Resources (HTTP)"] --> J["Jobs (orquestación asíncrona)"]
    J --> SV["Services (reglas de negocio)"]
    SV --> CT["Contracts / Interfaces"]
    CT --> PR["Providers (Gemini, OpenAI, BERT, Google FC, Tavily, Brave)"]
    SV --> OCR["Servicio PaddleOCR local (FastAPI)"]
    SV --> M["Modelos Eloquent"]
```

- **Controllers**: delgados; validan con `FormRequest`, delegan y responden con `Resource`.
- **Jobs**: cada etapa es un job independiente, reintentable e idempotente.
- **Services**: lógica de negocio (`ClaimExtractionService`, `FactCheckService`,
  `EvidenceSearchService`, `CredibilityScoreService`, `SourceAnalysisService`, …).
- **Contracts**: interfaces (`AiProviderInterface`, `ClassifierProviderInterface`,
  `AnalystProviderInterface`, `FactCheckProviderInterface`, `SearchProviderInterface`).
  `AppServiceProvider` las enlaza a su implementación; así se puede **cambiar de proveedor
  sin tocar la lógica** y simular proveedores en las pruebas (inversión de dependencias).
- **DTOs** (`App\DTO`, `Services\AI\DTO`): objetos inmutables entre capas, en lugar de arrays
  sueltos.

### 3.3 Patrones aplicados

| Patrón | Dónde | Para qué |
|---|---|---|
| Strategy / interfaz + binding | `Contracts/*` + `AppServiceProvider` | Intercambiar proveedores |
| Fallback chain | `FallbackAiProvider`, `SearchProviderManager` | Si el primario falla, usa el siguiente |
| Circuit breaker (ligero) | `GeminiModelCircuit` | Saltar modelos Gemini saturados durante 120 s |
| Batch + callbacks | `Bus::batch(...)->then(...)` | Esperar a todas las verificaciones y luego finalizar |
| Idempotencia | `fingerprint`, `createOrFirst`, comprobaciones al inicio de cada job | Reintentos y concurrencia sin duplicados |
| DTO / Resource | `Http/Resources/*` | Contrato JSON controlado (no se serializan modelos completos) |

---

## 4. Flujo completo de un análisis

### 4.1 Diagrama de secuencia

```mermaid
sequenceDiagram
    participant UI as Frontend
    participant API as API Laravel
    participant R as Redis
    participant W as Workers
    participant AI as Gemini/OpenAI
    participant EXT as FactCheck/Búsqueda
    participant ML as BERT
    UI->>API: POST /articles (url o texto)
    API-->>UI: 201 article
    UI->>API: POST /articles/{id}/analyze
    API->>R: AnalyzeArticleJob
    API-->>UI: 202 analysis (pending)
    loop cada 2.5 s
        UI->>API: GET /analyses/{id}/status
        API-->>UI: step + progress
    end
    R->>W: AnalyzeArticleJob → ExtractClaimsJob
    W->>AI: extraer claims (JSON estructurado)
    W->>R: Batch de jobs
    par en paralelo (varios workers)
        W->>ML: ClassifyArticleJob
        W->>EXT: VerifyClaimJob (por claim)
        W->>EXT: SearchClaimEvidenceJob (por claim)
    end
    R->>W: FinalizeAnalysisJob
    W->>AI: síntesis del analista
    W->>W: CredibilityScoreService
    W-->>API: Score + Analysis completed
    UI->>API: GET /analyses/{id}
    API-->>UI: informe completo
```

### 4.2 Paso a paso

1. **Ingesta** (`ArticleController@store` → `ArticleIngestionService`)
   - URL: `ArticleExtractionService` descarga el HTML (timeout 20 s) y extrae título,
     descripción, cuerpo, autor, fecha, idioma y metadatos. Prioriza **JSON-LD**, luego
     Open Graph/Twitter/meta y por último el HTML.
   - Texto: acepta JSON (`content`, `title`, `author_name`) o `Content-Type: text/plain`.
     Entre 30 y 65 000 caracteres.
   - Se crea/reutiliza el `Source` (dominio normalizado) y se **deduplica** el artículo por
     `canonical_url` o `content_hash` (SHA-256), siempre dentro de la cuenta propietaria.
     Nunca se reutiliza para otra cuenta un artículo creado por un usuario distinto.
2. **Solicitud de análisis** (`ArticleAnalysisController@store`)
   - Hay **un análisis por artículo y usuario** (índice único
     `analyses_article_user_unique`). Si ya existe para esa cuenta, devuelve `200` con el
     existente; si no, crea uno `pending` y responde `202`.
3. **`AnalyzeArticleJob`**: marca `processing`, registra `started_at` y encola
   `ExtractClaimsJob`.
4. **`ExtractClaimsJob`**
   - Llama a Gemini (con `FallbackAiProvider`) pidiendo **salida estructurada** (JSON Schema):
     resumen, claims (texto, importancia 0–1, `verifiable`, tipo, contexto), entidades,
     fuentes citadas y señales de alerta.
   - `ClaimExtractionService` valida y normaliza (tipos permitidos, importancia en rango,
     deduplicación por huella `fingerprint`) y persiste los claims.
   - Crea un **batch**: `ClassifyArticleJob` + por cada claim verificable
     `VerifyClaimJob` y `SearchClaimEvidenceJob`.
   - Si el proveedor no tiene cuota (402) marca el análisis como `failed` con un mensaje
     saneado y **no reintenta**; en otros errores reintenta con espera creciente
     (`60, 180, 600, 900 s`, 4 intentos).
5. **Fase de evidencia (paralela)**
   - `ClassifyArticleJob`: llama al servicio BERT y guarda un `ModelPrediction`
     (REAL/FAKE + confianza). Si BERT no está disponible tras sus reintentos, queda
     registrado en `analyses.metadata.classifier_unavailable` y la finalización continúa
     con la señal de lenguaje neutral.
   - `VerifyClaimJob` → `FactCheckService`: consulta Google Fact Check con el claim exacto
     (≤180 caracteres) y, si no hay rating interpretable, con una **consulta compacta** de
     palabras clave (sin stop-words). Guarda cada `claimReview` con publicador, URL, fecha y
     rating normalizado.
   - `SearchClaimEvidenceJob` → `EvidenceSearchService`: busca en la web, aplica la política de
     dominios (ver §8), canonicaliza URLs (quita `utm_*`), deduplica y guarda las evidencias.
6. **`FinalizeAnalysisJob`** (se dispara al terminar el batch, aunque haya fallos parciales)
   1. `SourceAnalysisService` evalúa el dominio del medio (verificador, institucional,
      prensa reconocida, sin verificar).
   2. `GeminiAnalystService` pide una **evaluación por claim** y una síntesis para el lector.
      Si falla, **se continúa con puntaje determinista** (degradación elegante).
   3. `CredibilityScoreService` calcula el puntaje, la cobertura, el veredicto y la
      confianza.
   4. Se guarda el `Score` (con todo el desglose en `metadata`) y el análisis pasa a
      `completed`.
7. **Consulta**: el frontend sondea `/status` y, al completarse, pide `GET /analyses/{id}`.

### 4.3 Estados y progreso (`/status`)

| Estado | `step` | `progress` |
|---|---|---|
| `pending` | `queued` | 0 |
| `processing`, sin claims | `extracting_claims` | 20 |
| `processing`, con claims | `verifying_evidence` | 60 (75 si ya hay predicción BERT) |
| `processing`, con score | `finalizing` | 90 |
| `completed` | `completed` | 100 |
| `failed` | `failed` | según avance (20/60/75) |

El progreso se **deriva de datos reales** (claims, predicciones, score) en lugar de un
contador artificial; es burdo pero honesto.

### 4.4 Reintentos

`POST /analyses/{id}/retry` devuelve el análisis a `pending` y vuelve a encolarlo (responde
`409` si está en `processing`). Los claims ya persistidos no se duplican (huella) y los jobs
omiten trabajo ya hecho. Si el análisis ya tiene `Score`, la extracción se omite.

---

## 5. Modelo de datos

```mermaid
erDiagram
    SOURCES ||--o{ ARTICLES : publica
    ARTICLES ||--o| ANALYSES : "1 análisis"
    USERS ||--o{ ANALYSES : "futuro (nullable)"
    ANALYSES ||--o{ CLAIMS : contiene
    ANALYSES ||--o{ MODEL_PREDICTIONS : "BERT"
    ANALYSES ||--o| SCORES : resultado
    CLAIMS ||--o{ CLAIM_VERIFICATIONS : verificado_por
    CLAIM_VERIFICATIONS }o--o{ EVIDENCES : "tabla pivote"
```

| Tabla | Campos clave y restricciones |
|---|---|
| `sources` | `domain` (único lógico), `name`, `type`, `reputation_score`, `country` |
| `articles` | `source_id` (nullable para texto pegado), `url` (nullable), `canonical_url`, `title`, `content`, `content_hash`, `language`, `metadata` |
| `analyses` | `article_id` + `user_id` **únicos**, `status` (`pending/processing/completed/failed`), `final_verdict`, `credibility_score`, `confidence_score`, `algorithm_version`, `started_at/completed_at`, `error`, `metadata`, `user_id` nullable |
| `claims` | `analysis_id`, `text`, `type`, `importance` (0–1), `verifiable`, `context`, `fingerprint`, `metadata` |
| `claim_verifications` | `claim_id`, `relation` (`external_fact_check` o `search_evidence`), `provider`, `status` (`matched/no_match`), `raw_rating`, `normalized_rating`, `publisher`, `fact_check_url`, `fingerprint` **único** |
| `evidences` | `url` **único**, `title`, `snippet`, `published_at`, `metadata` (`domain`, `provider`, `trusted`) |
| `verification_evidence` | pivote con `unique(claim_verification_id, evidence_id)` |
| `model_predictions` | `analysis_id`, `prediction_label` (REAL/FAKE), `confidence_score`, `probability`, `model_name` |
| `scores` | subpuntajes, `final_score`, `algorithm_version`, `metadata` JSON con el **desglose completo** |

**Decisiones:** una `Evidence` es única por URL y se reutiliza entre claims (por eso el
pivote); `scores.metadata` guarda el desglose para auditar cualquier resultado; las
restricciones únicas protegen contra duplicados en reintentos y concurrencia.

---

## 6. Algoritmo de puntaje y veredicto (v1.2.0)

Implementado en `CredibilityScoreService`. Es **determinista y auditable**: con las mismas
entradas produce el mismo resultado.

### 6.1 Qué claims cuentan

Un claim **entra al veredicto** si es `verifiable` y su tipo **no** es `opinion` ni
`prediction`. Los demás se muestran con su motivo (`unverifiable_reason`): `opinion`,
`prediction`, `not_checkable` o `no_evidence_found`.

### 6.2 Puntaje de cada claim (0–100)

1. **Con fact-check directo:** promedio de los ratings, **un voto por publicador** (revisiones
   repetidas del mismo medio no cuentan como independientes):

   | Rating | Puntaje |
   |---|---|
   | `true` | 100 |
   | `mostly_true` | 80 |
   | `mixed` / `unknown` | 50 |
   | `mostly_false` | 20 |
   | `false` | 0 |

   Los ratings en texto libre (p. ej. "Engañoso", "Parcialmente cierto", "Misleading") se
   normalizan en `FactCheckRatingNormalizer`.
2. **Sin fact-check pero con evaluación del analista y evidencia de búsqueda vinculada:**

   $$\text{score} = 50 + (\text{postura} - 50)\times 0.55 \times \text{confianza}$$

   con postura `supported = 85`, `refuted = 15`, `mixed/unverified = 50`. El factor 0.55 y el
   tope de confianza (0.65) hacen que **la IA nunca pese tanto como un fact-check real**.
3. **Sin ninguna de las dos:** 50 (neutro) y el claim queda `unverified`.

### 6.3 Ponderación por importancia y por centralidad

Cada claim tiene un **peso**:

- Base: su `importance` (mínimo 0.1).
- **Afirmación principal** (la de mayor importancia, solo si supera a la siguiente por al
  menos `0.1`): peso × **2**. Si hay empate no se elige ninguna (evita sesgo arbitrario).
- **Secundaria sin evidencia:** conserva el **40 %** de su peso (un detalle menor sin fuentes
  no debe hundir el puntaje de una noticia cuyo argumento central sí está respaldado).

El **puntaje de evidencia** es el promedio ponderado de los puntajes de claim.
La **cobertura** es el peso de los claims respaldados dividido entre el peso total
(se calcula aparte la cobertura de **fact-check directo**).

### 6.4 Puntaje final

$$\text{final} = 0.72\,E + 0.03\,L + 0.05\,S + 0.12\,C + 0.03\,I + 0.05\,K + 0\,T$$

| Símbolo | Señal | Peso | Cómo se calcula |
|---|---|---|---|
| E | Evidencia por claim | 72 % | promedio ponderado del §6.3 |
| L | Lenguaje (BERT) | 3 % | REAL → 50 + prob×50; FAKE → 50 − prob×50; sin BERT → 50 |
| S | Reputación del medio | 5 % | reputación del dominio ×100 |
| C | Corroboración | 12 % | cobertura de evidencia ×100 |
| I | Independencia | 3 % | nº de verificadores distintos: 0→50, 1→60, 2→80, ≥3→100 |
| K | Consistencia | 5 % | valor reportado por el analista (0–1) ×100; sin analista → 50 |
| T | Transparencia | 0 % | autor + URL (informativa, no puntúa) |

Los pesos se normalizan si no suman 1 y son configurables (`SCORING_WEIGHT_*`).
**Decisión clave:** BERT, reputación del medio y transparencia suman muy poco a propósito;
una noticia no se vuelve "verdadera" por venir de un medio famoso ni por tener buen estilo.

### 6.5 Veredicto operativo

Reglas evaluadas en orden:

1. Cobertura de evidencia < **60 %** → `inconclusive` (**abstención**).
2. `credible`: puntaje ≥ 85, cobertura ≥ 80 % y fact-check directo ≥ 50 %.
3. `probably_credible`: puntaje ≥ 65 y cobertura ≥ 70 %.
4. `false`: puntaje ≤ 25 y fact-check directo ≥ 50 %.
5. `probably_misleading`: puntaje ≤ 25 y cobertura ≥ 65 %.
6. En cualquier otro caso → `inconclusive`.

### 6.6 Evaluación de veracidad (texto para el usuario)

`insufficient_evidence` si la cobertura es baja; `partially_true` si hay peso relevante
(≥20 %) tanto de claims respaldados como refutados (o mixtos); si no, por puntaje:
≥85 mayormente cierta, ≥70 probablemente cierta, ≥55 parcialmente cierta, ≥35 inconclusa,
≥20 probablemente falsa, <20 mayormente falsa.

### 6.7 Confianza

Heurística (no es una probabilidad calibrada):

$$0.10 + 0.45\cdot\text{cobertura} + 0.30\cdot\text{cobertura\_fact\_check} + 0.15\cdot\min(1,\tfrac{\text{verificadores}}{3})$$

con tope de 0.95.

### 6.8 Principios que justifican el diseño

- **Una pista no es una prueba:** los resultados de búsqueda solo cuentan cuando el analista
  los enlaza a un claim concreto y emite una postura; aun así pesan menos que un fact-check.
- **La ausencia de evidencia no es falsedad:** `no_match` o `unverified` no bajan el puntaje a
  "falso"; reducen la cobertura y empujan hacia `inconclusive`.
- **Explicabilidad:** `score.metadata` contiene pesos, señales, cobertura, centralidad y el
  detalle por claim; el informe muestra fuentes y razonamiento.
- **Reglas conservadoras:** los umbrales son reglas de decisión documentadas, no una exactitud
  demostrada.

---

## 7. Integraciones externas y resiliencia

| Servicio | Uso | Resiliencia |
|---|---|---|
| **Gemini** (extracción y analista) | JSON Schema estructurado | Hasta **3 llaves** (`GEMINI_API_KEY`, `_2`, `_3`) con rotación en 429; lista de **modelos de respaldo**; **circuit breaker**: un modelo con 503 o sin respuesta (timeout 60 s extracción / 150 s analista) se omite 120 s |
| **OpenAI** `gpt-4o-mini` | Respaldo de extracción solo ante *rate limit* | Si no tiene crédito → error 402 saneado, sin reintentos |
| **BERT (FastAPI)** | `POST /classify`, `GET /health` | Texto truncado a 512 tokens; si falla, el puntaje usa lenguaje neutro (50) |
| **PaddleOCR (FastAPI, CPU)** | `POST /ocr`, `GET /health` | Extrae texto y coordenadas localmente; los modelos se guardan en volumen Docker |
| **Gemini** | Clasificación previa de capturas | Recibe solo las líneas OCR para separar noticia, texto integrado, perfil e interfaz antes de crear el artículo; usa las llaves y modelos de respaldo configurados |
| **Google Fact Check** | Revisiones editoriales | Segunda consulta compacta; `no_match` no bloquea una revalidación |
| **Tavily / Brave** | Pistas web | Tavily primero; si falla, Brave; timeouts cortos (10 s) |

Errores de proveedor se traducen a `AiProviderException` con códigos (429 *rate limit*, 402
sin cuota) para decidir si reintentar, usar respaldo o fallar con mensaje claro.

El **análisis nunca depende de un único punto**: si el analista de IA falla, se usa puntaje
determinista; si BERT falla, su señal es neutra; si una búsqueda falla, el batch continúa
(`allowFailures`).

---

## 8. Política de fuentes de evidencia

Implementada en `SourceDomainPolicy` y configurable en `config/search.php`:

- **Se bloquean** redes sociales y plataformas de contenido de usuarios (Facebook, Instagram,
  X/Twitter, TikTok, YouTube, Reddit, Telegram, LinkedIn, Medium, Blogspot, WordPress…):
  se envían como `exclude_domains` a Tavily y además se **filtran siempre** localmente
  (cubre Brave y datos antiguos). También el puntaje y el prompt del analista ignoran esas
  fuentes.
- Se piden 15 resultados y se **conservan 8**, con las fuentes **creíbles primero**:
  verificadores, prensa reconocida, dominios `.gov/.gob/.edu/.mil`, agencias e instituciones
  (`trusted_domains`).
- `SEARCH_TRUSTED_ONLY=true` descarta además cualquier fuente no reconocida (más estricto,
  menos cobertura).
- El analista recibe como máximo 5 evidencias por claim con snippets de 400 caracteres.

**Por qué:** las noticias en redes sociales rara vez son evidencia confiable y contaminan el
análisis; priorizar fuentes institucionales mejora la calidad.

---

## 9. Colas, concurrencia e idempotencia

- Todo el trabajo pesado es **asíncrono** (conexión `redis`, `retry_after = 600 s`).
- La verificación de claims es **paralela**: cada claim genera jobs independientes; la
  velocidad depende del número de workers. `composer dev` levanta `QUEUE_DEV_WORKERS` (3)
  workers; en producción, ejecutar varios `php artisan queue:work redis` (o un supervisor).
- `Bus::batch(...)->allowFailures()`: el batch termina aunque falle algún job y entonces se
  finaliza el análisis con lo disponible (`then` + `finally` si hubo fallos).
- **Reintentos con backoff** por job (`tries`, `backoff`); `failed()` registra el error
  y, donde corresponde, marca el análisis como `failed`.
- **Idempotencia:** huellas (`fingerprint`) en claims y verificaciones, `unique(url)` en
  evidencias, `createOrFirst` (seguro ante carreras entre workers), `unique(article_id)` en
  análisis y comprobaciones previas ("ya existe score/predicción/rating").
- **Dead-letter:** los jobs fallidos quedan en `failed_jobs` (Laravel) con el error original;
  la API solo expone un mensaje saneado.

> Nota (Windows): sin la extensión `pcntl`, el `timeout` de los jobs no se hace cumplir; por
> eso los timeouts HTTP de cada proveedor son la protección real.

---

## 10. API REST

Base: `http://127.0.0.1:8000/api/v1` · JSON · `Accept: application/json`

**Autenticación:** todas las rutas, salvo registro e inicio de sesión, exigen `Authorization: Bearer <token>`
(Laravel Sanctum). Cada usuario solo ve sus artículos y análisis (políticas `ArticlePolicy` y
`AnalysisPolicy`); un artículo puede analizarse una vez por usuario (índice único `article_id + user_id`).

| Método y ruta | Descripción | Límite |
|---|---|---|
| `POST /auth/register` | Crea cuenta (contraseña ≥ 8 con letras y números) y devuelve `token` | 10/min |
| `POST /auth/login` | Inicia sesión y devuelve `token` | 10/min |
| `GET /auth/me` · `POST /auth/logout` | Usuario actual · revoca el token | — |
| `POST /articles/ocr` | Sube la captura; PaddleOCR extrae texto y Gemini organiza solo el OCR antes de crear el artículo. El análisis excluye perfil/interfaz y conserva sus textos en metadatos; errores de proveedor `ocr_unavailable`/`gemini_unavailable` (`503`) | 10/min |
| `GET /analyses/{id}/report/pdf` · `/report/json` | Informe descargable (solo análisis completados; `409` si no) | 20–30/min |
| `GET /sources` · `GET /sources/{id}/profile` | Credibilidad histórica de los medios según los análisis del sistema | 60/min |
| `GET/POST/DELETE /api-keys` | Gestión de API keys y consumo de 30 días | — |

| Método y ruta | Descripción | Límite |
|---|---|---|
| `POST /articles` | Crea artículo (`url` **o** `content`, opcional `title`, `author_name`; también `text/plain`). `201` | 10/min |
| `GET /articles` | Lista (filtros `search`, `domain`; `per_page` ≤100; `pagination=cursor`) | 60/min |
| `GET /articles/{id}` | Detalle con medio y análisis | 60/min |
| `POST /articles/{id}/analyze` | Inicia análisis. `202` nuevo / `200` si ya existía | 20/min |
| `GET /analyses` | Lista (filtros `status`, `final_verdict`, `article_id`) | 60/min |
| `GET /analyses/{id}` | Informe completo (score, claims, verificaciones, evidencias) | 60/min |
| `GET /analyses/{id}/status` | Estado liviano para *polling* (`step`, `progress`, `error`) | 120/min |
| `POST /analyses/{id}/retry` | Reencola un análisis (`409` si está procesando) | 20/min |

Informe (`GET /analyses/{id}`) incluye en `score`: `summary`, `truth_assessment`,
`verdict_rationale`, `evidence_coverage`, `fact_check_coverage` y `claim_evaluations`
(postura, conclusión, razonamiento, peso, `is_central`, `unverifiable_reason`, evidencias).
También expone `language_signal_available`; si es `false`, el scoring conservó su fallback
neutro porque no hubo predicción del clasificador BERT.

Respuestas con `JSON_UNESCAPED_UNICODE` (middleware `UnescapeJsonUnicode`) para conservar
tildes y ñ. Paginación por páginas (por defecto) o **por cursor** (`next_cursor`) para
historiales grandes.

Archivo `requests.http` incluye ejemplos listos para usar.

### 10.1 API pública (API keys y planes)

Base: `/api/public/v1` · cabecera `X-API-Key: fn_live_…` · especificación OpenAPI en `/api-docs/openapi.yaml`.

| Ruta | Descripción |
|---|---|
| `POST /analyses` | Envía `url` o `content` y arranca el análisis en una llamada (`202`) |
| `GET /analyses/{id}` · `/status` | Informe completo · estado y progreso |
| `GET /analyses/{id}/report/json` · `/report/pdf` | Informe descargable |
| `GET /usage` | Consumo de hoy y límites del plan |

- La clave se muestra **una sola vez**; en base de datos solo se guarda su hash SHA-256 y un prefijo.
- **Planes** (`config/plans.php`): gratuito 10 req/min y 10 análisis/día; Pro 60 req/min y 300 análisis/día.
  Se cambia con `php artisan apikeys:plan {id} pro`.
- Respuestas `429` con `Retry-After`; cabeceras `X-RateLimit-Limit/Remaining`.
- Los errores de ingesta devuelven `{code, message}`: `422` para `invalid_input`, `url_blocked`,
  `fetch_failed` o `content_not_extractable`; `500 internal_error` para fallos inesperados.
- **Métricas:** tabla `api_usages` (solicitudes, análisis y errores por clave y día), visibles en la app (sección API).
- Los análisis creados con una clave pertenecen al usuario dueño de la clave.

---

## 11. Seguridad

- **Validación de URL** (`StoreArticleRequest`): solo URLs bien formadas; rechaza `localhost`,
  `.local` y IPs privadas/reservadas (mitigación básica de **SSRF**).
- **Límites de tasa** por ruta (`throttle`).
- **Recursos JSON** explícitos: no se exponen modelos completos ni excepciones internas; el
  error público está saneado.
- **Secretos** solo por variables de entorno; sin llaves en el código.
- **Autenticación y autorización:** tokens Sanctum; políticas por propietario (403 si el análisis o artículo es de otro
  usuario); contraseñas con `hashed` (bcrypt); API keys guardadas como hash.
- **Sin XSS en el frontend:** Angular escapa las plantillas y los enlaces externos se validan (`safeUrl`, solo http/https).
- **Cabeceras de seguridad:** no se incluye una configuración de servidor HTTP de producción;
  configurar y verificar headers de seguridad al elegir la plataforma de despliegue.
- **Subida de imágenes:** validación de tipo real (`image`), tamaño máx. 5 MB y almacenamiento fuera de `public/`.
- Consultas con Eloquent (parametrizadas), sin SQL manual.
- `composer audit` se ejecuta en el CI.

---

## 12. Interfaz web

- SPA **Angular 21** en `frontend/` (componentes standalone, *signals*, carga perezosa por ruta). Páginas: ingresar /
  crear cuenta, analizar (URL, texto o **captura de pantalla con OCR**), resultado con progreso por pasos (*polling* a
  `/status` cada 2.5 s), historial con filtros y paginación, credibilidad de fuentes y gestión de API keys.
- El resultado muestra puntaje, veredicto, confianza, síntesis, **limitaciones**, señales y cada afirmación con su
  estado (Respaldado / Contradicho / Parcialmente cierto / No verificado / Información insuficiente), fact-checks y
  fuentes; permite descargar el **PDF** y el JSON del informe.
- **Accesibilidad (WCAG 2.2 AA):** enlace «Saltar al contenido», *landmarks* (`header`, `nav`, `main`, `footer`),
  foco movido al contenido en cada navegación, etiquetas y mensajes de error asociados a cada campo (`aria-describedby`,
  `role="alert"`), región `aria-live` que anuncia el progreso, estados que no dependen solo del color (icono + texto),
  foco visible, contraste ≥ 4.5:1, objetivos táctiles ≥ 44 px, `lang="es"`, tablas con `caption` y `scope`, y barra de
  preferencias: **tamaño de texto, alto contraste y sin animaciones** (también respeta `prefers-reduced-motion`).
- Identidad visual del **manual de marca FactNode**: Tinta `#0B1626`, Cobalto `#2547F5`, Niebla `#EEF2F7`,
  Turquesa, Ámbar y Ladrillo (oscurecidos para texto accesible); tipografías Space Grotesk e IBM Plex Sans.
- El token se guarda en `localStorage` y un interceptor lo añade a cada petición; ante un `401` se cierra la sesión.
  Trade-off: `localStorage` es vulnerable a XSS, mitigado con CSP y plantillas escapadas.

---

## 13. Estructura del código

```
app/
  Contracts/                 Interfaces de proveedores
  DTO/                       Objetos de transferencia (Scoring, Search, Source, Analyst)
  Exceptions/                AiProviderException, ArticleInputException,
                              IngestionProviderUnavailableException
  Http/
    Controllers/Api/         Auth, Article, ArticleAnalysis, Analysis, Ocr, Report, Source, ApiKey, PublicAnalysis
    Requests/                Validación (StoreArticleRequest, StoreOcrRequest, ...)
    Resources/               Contrato JSON
    Middleware/              UnescapeJsonUnicode, AuthenticateApiKey
  Actions/                   StartAnalysis (crea/reutiliza el análisis y encola el pipeline)
  Policies/                  ArticlePolicy, AnalysisPolicy (propiedad por usuario)
  Jobs/                      AnalyzeArticle, ExtractClaims, ClassifyArticle,
                             VerifyClaim, SearchClaimEvidence, FinalizeAnalysis
  Models/                    Article, Source, Analysis, Claim, ClaimVerification,
    Evidence, ModelPrediction, Score, User, ApiKey, ApiUsage
  Services/
    AI/                      Proveedores Gemini/OpenAI/BERT, analista, GeminiModelCircuit
    Article/                 Ingesta y extracción HTML
    FactCheck/               Google Fact Check + normalizador de ratings
    Search/                  Tavily, Brave, manager, EvidenceSearchService, SourceDomainPolicy
    Scoring/                 CredibilityScoreService
    Source/                  SourceAnalysisService (reputación) y SourceProfileService (historial)
    OCR/                     ImageOCRService (servicio local PaddleOCR)
    Report/                  AnalysisReportBuilder (PDF/JSON)
    Verification/            ClaimExtractionService
config/                      ai, search, scoring, plans, fact_check, queue ...
ml_service/                  Microservicio FastAPI con BERT (Dockerfile)
ocr_service/                 Microservicio FastAPI con PaddleOCR CPU (Dockerfile)
frontend/                    SPA Angular (ver §12)
resources/views/reports/     Plantilla del PDF
tests/                       Unit y Feature
docker-compose.yml           Desarrollo: PostgreSQL, Redis, BERT y PaddleOCR
```

---

## 14. Configuración (variables de entorno)

**Base y colas**

| Variable | Valor típico |
|---|---|
| `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | PostgreSQL (`fn`) |
| `QUEUE_CONNECTION` | `redis` |
| `CACHE_STORE` | `database` |
| `QUEUE_DEV_WORKERS` | `3` |

**IA**

| Variable | Descripción |
|---|---|
| `OCR_SERVICE_URL` | URL del servicio PaddleOCR (`http://127.0.0.1:8002` local, `http://ocr-service:8000` en Docker) |
| `OCR_SERVICE_PORT` | Puerto local expuesto por Docker (`8002`) |
| `OCR_TIMEOUT`, `OCR_CONNECT_TIMEOUT` | Tiempo límite del OCR local (240 s / 10 s por defecto) |
| `GEMINI_API_KEY`, `_2`, `_3` | Llaves (rotación) |
| `GEMINI_MODEL`, `GEMINI_FALLBACK_MODELS` | Modelo principal y respaldos (CSV) |
| `GEMINI_EXTRACTION_TIMEOUT` / `GEMINI_ANALYST_TIMEOUT` | 60 s / 150 s |
| `GEMINI_UNAVAILABLE_COOLDOWN` | 120 s |
| `GEMINI_EXTRACTION_THINKING_LEVEL` | Opcional (`low`) para acelerar |
| `CLAIM_EXTRACTION_MAX_CLAIMS` | 10 |
| `AI_PROVIDER`, `AI_FALLBACK_ENABLED`, `AI_FALLBACK_PROVIDER` | `gemini`, `true`, `openai` |
| `OPENAI_API_KEY`, `OPENAI_MODEL` | Respaldo (`gpt-4o-mini`) |
| `BERT_SERVICE_URL`, `ML_SERVICE_PORT` | `http://127.0.0.1:8001` |

**Verificación y búsqueda**

| Variable | Descripción |
|---|---|
| `GOOGLE_FACT_CHECK_ENABLED`, `GOOGLE_FACT_CHECK_API_KEY` | Google Fact Check |
| `SEARCH_PROVIDER_ENABLED`, `SEARCH_PROVIDER_DEFAULT`, `SEARCH_PROVIDER_FALLBACK` | `true`, `tavily`, `brave` |
| `TAVILY_SEARCH_ENABLED`, `TAVILY_API_KEY`, `BRAVE_SEARCH_ENABLED`, `BRAVE_API_KEY` | Claves de búsqueda |
| `SEARCH_FETCH_RESULTS`, `SEARCH_EVIDENCE_PER_CLAIM` | 15 pedidos, 8 conservados |
| `SEARCH_TRUSTED_ONLY`, `SEARCH_EXTRA_BLOCKED_DOMAINS`, `SEARCH_EXTRA_TRUSTED_DOMAINS` | Política de dominios |

**Puntaje**

`SCORING_WEIGHT_*`, `SCORING_MIN_EVIDENCE_COVERAGE` (0.60), `SCORING_CREDIBLE_COVERAGE` (0.80),
`SCORING_PROBABLY_CREDIBLE_COVERAGE` (0.70), `SCORING_CENTRAL_CLAIM_WEIGHT` (2.0),
`SCORING_CENTRAL_CLAIM_MARGIN` (0.1), `SCORING_UNVERIFIED_SECONDARY_WEIGHT` (0.4).

---

## 15. Instalación y ejecución

Requisitos: PHP 8.4, Composer, Node.js, Docker.

```bash
# 1. Dependencias
composer install
npm --prefix frontend install --legacy-peer-deps

# 2. Entorno
cp .env.example .env
php artisan key:generate          # luego completa tus llaves en .env

# 3. Infraestructura (PostgreSQL, Redis, BERT y PaddleOCR)
docker compose up -d

# 4. Base de datos
php artisan migrate

# 5. API, workers y frontend Angular (todo junto)
composer dev
```

**Atajo (Windows):** `composer start` ejecuta `scripts/start.ps1`: verifica los requisitos,
crea `.env` si falta, instala dependencias, levanta Docker esperando a que esté saludable,
migra y arranca la API, los workers y el frontend Angular.

Los servicios Python usan Python 3.11. Sus dependencias de ejecución están fijadas en
`ml_service/requirements.txt` y `ocr_service/requirements.txt`; las pruebas y Ruff usan los
locks más pequeños `requirements-test.txt` de cada servicio. Para regenerarlos con `uv 0.13.5`,
desde la raíz del repositorio:

```bash
python -m pip install uv==0.13.5
uv pip compile --generate-hashes --emit-index-url --index-strategy unsafe-best-match --output-file ml_service/requirements.txt ml_service/requirements.in
uv pip compile --generate-hashes --output-file ml_service/requirements-test.txt ml_service/requirements-test.in
uv pip compile --generate-hashes --output-file ocr_service/requirements.txt ocr_service/requirements.in
uv pip compile --generate-hashes --output-file ocr_service/requirements-test.txt ocr_service/requirements-test.in
```

El lock de ML combina el índice CPU de PyTorch con PyPI; `unsafe-best-match` es necesario
para resolver el pin `torch==2.14.1+cpu`. Revisa y versiona juntos los archivos `.in` y sus
locks. Los `Dockerfile` instalan las dependencias de ejecución con `--require-hashes`.

El repositorio incluye configuración de contenedores para desarrollo local y CI; no incluye
aprovisionamiento ni despliegue de producción.

`composer dev` (`php artisan dev`) levanta `php artisan serve` (API en `:8000`), **3 workers** de cola y el
servidor de Angular (`:4200`, con *proxy* hacia la API). Abre **http://localhost:4200**. Alternativa manual:

```bash
php artisan serve
php artisan queue:work redis --sleep=1 --tries=4 --timeout=180 --backoff=10   # repetir en varias terminales
npm --prefix frontend start
```

Los cambios en el código PHP requieren reiniciar los workers.

> Seguridad: usa tus propias llaves en `.env`; **no** subas llaves reales a `.env.example`.

Al analizar una captura, PaddleOCR extrae el texto y el backend envía a Gemini únicamente las líneas
reconocidas, sin la imagen ni sus coordenadas, para clasificar automáticamente el texto de la noticia,
el texto integrado en la imagen, el perfil que comparte y otros elementos de interfaz. Solo el texto
de la noticia y el texto integrado en la imagen forman el artículo que se analiza. La selección depende
del modelo y puede equivocarse.

---

## 16. Calidad y CI

El workflow [`.github/workflows/ci.yml`](.github/workflows/ci.yml) se ejecuta en cada
push y pull request. Sus jobs se ejecutan en paralelo:

| Job | Qué valida |
|---|---|
| `backend` | PHP 8.4, PostgreSQL 16 y Redis 7; Pint, Larastan nivel 5, ciclo de migraciones y PHPUnit/JUnit |
| `frontend` | Node 24; instalación reproducible con `npm ci`, ESLint, TypeScript estricto, build de producción y Vitest |
| `python` | Matriz ML/OCR en Python 3.11; instalación con hashes, Ruff y pytest sin descargar modelos ni requerir GPU |
| `openapi` | Lint del contrato con Redocly CLI 2.67.1; el test de backend comprueba también la cobertura de rutas |
| `security` | Composer, npm y pip-audit; Gitleaks sobre el árbol actual y Trivy filesystem para dependencias y configuración |

El workflow tiene concurrencia cancelable, límites de tiempo por job y permisos de solo
lectura para contenidos. No contiene tareas de despliegue. PHPUnit usa SQLite en memoria
por defecto localmente; en CI se ejecuta contra PostgreSQL real, incluido `migrate`,
`rollback` y `migrate:fresh`. Los tests emplean HTTP fakes y mocks: no llaman a Gemini,
OpenAI, Tavily, Brave ni descargan pesos de modelos.

### Comandos locales

Desde la raíz, las comprobaciones PHP se ejecutan con:

```bash
composer test
composer analyse
php vendor/bin/pint --test
composer ci
```

`composer ci` agrupa tests, análisis estático y Pint. Para el frontend:

```bash
npm --prefix frontend ci --legacy-peer-deps --no-audit --no-fund
npm --prefix frontend run lint
npm --prefix frontend run typecheck
npm --prefix frontend run build
npm --prefix frontend test -- --watch=false
npm --prefix frontend audit --audit-level=high
```

`composer ci` usa la configuración local de PHPUnit (SQLite en memoria). Para reproducir
el job de PostgreSQL, levanta `postgres` y `redis` con Docker Compose y configura las
variables `DB_*` para una base **desechable de pruebas**. Sobre esa base se puede ejecutar
el mismo ciclo que en CI:

```bash
php artisan migrate --force
php artisan migrate:rollback --step=2 --force
php artisan migrate --force
php artisan migrate:fresh --force
mkdir -p reports
php vendor/bin/phpunit --log-junit reports/phpunit.xml
```

`migrate:fresh` elimina todas las tablas de la base configurada; nunca ejecutes este bloque
contra una base con datos que debas conservar.

Para cada servicio Python (`ml_service` y `ocr_service`), ejecuta desde su carpeta:

```bash
python -m pip install --require-hashes -r requirements-test.txt
ruff check .
ruff format --check .
python -m pytest -q
```

El contrato OpenAPI se valida desde la raíz con:

```bash
npx --yes @redocly/cli@2.67.1 lint public/api-docs/openapi.yaml
```

Para las auditorías locales instala `pip-audit` y ejecuta Composer y npm así:

```bash
composer audit --locked
npm --prefix frontend audit --audit-level=high
python -m pip install pip-audit==2.10.1
```

Después reproduce los cuatro comandos `pip-audit` del paso `Audit Python dependencies`
en [`.github/workflows/ci.yml`](.github/workflows/ci.yml); los comandos ML incluyen las
excepciones temporales `--ignore-vuln`. Con Docker instalado, los escaneos equivalentes
de Gitleaks y Trivy son:

```bash
docker run --rm --volume "$PWD:/workspace:ro" --workdir /workspace \
  ghcr.io/gitleaks/gitleaks:v8.30.1 detect --source=/workspace --no-git \
  --config=/workspace/gitleaks.toml --redact --exit-code=1

docker run --rm --volume "$PWD:/workspace:ro" \
  aquasec/trivy:0.76.1 fs --scanners vuln,misconfig --severity HIGH,CRITICAL \
  --ignorefile /workspace/.trivyignore --exit-code 1 /workspace
```

Los IDs ignorados temporalmente de Transformers y los hallazgos históricos de
credenciales están documentados en [`SECURITY-EXCEPTIONS.md`](SECURITY-EXCEPTIONS.md).
Gitleaks en CI escanea el árbol de archivos actual, no el historial Git completo.

### Actualizar locks y baseline de Larastan

Para cambiar dependencias Python, edita primero el `.in` correspondiente y regenera
juntos sus locks con `uv 0.13.5` usando los cuatro comandos indicados en
[Instalación y ejecución](#15-instalación-y-ejecución). Conserva los hashes y revisa
los resultados de `pip-audit` antes de aceptar la actualización.

El nivel de Larastan/PHPStan se configura en [`phpstan.neon`](phpstan.neon) y las
incidencias existentes están en [`phpstan-baseline.neon`](phpstan-baseline.neon). Para
revisar nuevos problemas sin sobrescribir el baseline vigente:

```bash
php vendor/bin/phpstan analyse --configuration=phpstan.neon --memory-limit=1G --no-progress
php vendor/bin/phpstan analyse --configuration=phpstan.neon --generate-baseline=phpstan-baseline.new.neon
```

Inspecciona el baseline nuevo y traslada únicamente los errores aceptados a
`phpstan-baseline.neon`; no reemplaces el baseline existente sin revisar el diff. Luego
ejecuta `composer analyse` para confirmar que CI no recibe errores nuevos.

PHPStan carga primero [`phpstan-bootstrap.php`](phpstan-bootstrap.php) mediante
[`phpstan-environment.neon`](phpstan-environment.neon). Ese bootstrap fija el entorno
estático (incluidos `APP_ENV=testing`, SQLite en memoria y la configuración de
proveedores) antes de que Larastan inicialice Laravel; por ello la inferencia del
contenedor no depende del `.env` personal ni de credenciales externas.

---

## 17. Decisiones de diseño y alternativas descartadas

| Decisión | Motivo | Alternativa descartada |
|---|---|---|
| **Pipeline asíncrono con colas** | Un análisis tarda 1–3 min por llamadas externas; no puede bloquear HTTP | Procesamiento síncrono (timeouts y mala UX) |
| **Redis para colas** | Rápido, soporta varios workers y batches | Cola en base de datos (más lenta bajo concurrencia) |
| **Un job por etapa y por claim** | Reintentos finos, paralelismo y aislamiento de fallos | Un job monolítico |
| **Evaluar por claim, no por artículo** | Una noticia mezcla hechos ciertos y falsos; un solo score global lo ocultaría | Clasificar el artículo completo con BERT |
| **BERT solo como señal auxiliar (3 %)** | Es un clasificador de estilo, sin evidencia; puede equivocarse con noticias reales sensacionalistas | Usar BERT como veredicto |
| **Fact-check > IA > búsqueda** | Jerarquía por fiabilidad de la fuente | Tratar todas las señales por igual |
| **LLM con salida estructurada** | Datos validables (esquema) y menos alucinación de formato | Prompts de texto libre |
| **Analista con evidencia obligatoria** | El LLM solo evalúa lo que se le entrega; reglas anti-invención | Dejar que el LLM "sepa" la respuesta |
| **Puntaje determinista, no un modelo entrenado** | No hay corpus etiquetado; reglas auditables y explicables | Entrenar un clasificador sin datos |
| **Abstención por cobertura** | Preferible "no sé" a un falso veredicto | Siempre devolver verdadero/falso |
| **Ponderar el claim principal** | El argumento central define la noticia; los detalles menores no deben dominar | Promedio simple |
| **Filtrar redes sociales** | Baja fiabilidad como evidencia | Aceptar cualquier dominio |
| **Rotación de llaves, modelos y proveedores** | Los límites de cuota y la saturación (503/429) fueron un problema real | Una sola llave y modelo |
| **Circuit breaker de modelos** | Evitar esperar 60 s en cada análisis a un modelo caído | Reintentar siempre 3 veces |
| **Microservicio Python para BERT** | Aísla dependencias pesadas (PyTorch) del backend PHP | Ejecutar el modelo en PHP |
| **Angular en lugar de Blade** | SPA tipada, componentes reutilizables y accesibles; API y frontend desacoplados | Blade + JS (menos mantenible al crecer) |
| **Sanctum con tokens** | Simple para SPA y móvil; sin CSRF ni cookies entre orígenes | Sesiones con cookies; OAuth completo |
| **API keys propias con hash y planes** | Claves revocables, métricas por clave y límites por plan | Reutilizar los tokens de usuario para la API pública |
| **OCR local + selección Gemini por texto** | PaddleOCR extrae el texto; Gemini organiza las líneas OCR sin recibir la imagen | Clasificación solo por geometría OCR sin contexto lingüístico |
| **PDF con dompdf** | PHP puro, sin binarios externos en el contenedor | wkhtmltopdf/Chromium (imágenes pesadas) |
| **Un análisis por artículo y usuario** | Aislamiento por cuenta sin repetir análisis propios | Análisis global compartido (fuga de datos entre usuarios) |

---

## 18. Limitaciones conocidas y trabajo futuro

**Limitaciones**

- **Sin métricas de exactitud:** falta un conjunto de noticias en español etiquetado por
  claim. Los pesos y umbrales son reglas conservadoras, no resultados entrenados.
- **Cobertura de verificadores limitada:** Google Fact Check no cubre todas las noticias; muchas
  quedan `inconclusive` y eso es intencional.
- **Dependencia de terceros:** latencia y cuotas de Gemini/Tavily/Google. La etapa más lenta es
  la síntesis del analista (decenas de segundos a minutos si el modelo está saturado).
- **Confianza heurística:** no es una probabilidad calibrada.
- **SSRF parcial:** se validan host e IP literales, pero no se resuelve el DNS antes de
  descargar (posible *DNS rebinding*).
- **Cuentas básicas:** no hay verificación de correo ni recuperación de contraseña; el plan Pro se asigna a mano
  (`php artisan apikeys:plan`), sin pasarela de pago.
- **Extracción HTML:** sitios con paywall o contenido renderizado por JavaScript pueden fallar;
  el texto pegado es el camino más fiable.
- **OCR:** lee y transcribe el texto, pero no detecta titulares manipulados ni modificaciones visuales (EP19-HU5/7).
  Es síncrono (puede tardar varios segundos) y consume cuota de Gemini.
- **Reanalizar:** reanalizar un artículo ya completado requiere lógica adicional
  (la extracción se omite si ya existe puntaje).
- **Progreso por etapas:** es una aproximación, no un porcentaje exacto.
- **Despliegue:** este repositorio no configura un entorno productivo; la plataforma y su
  operación deben definirse antes de publicar el servicio.
- **No implementado** (Product Backlog): EP21 análisis comparativo, EP22 redes sociales, EP25 multilenguaje y
  correcciones publicadas por medio (EP20-HU4).

**Trabajo futuro**

1. Construir un corpus etiquetado y medir precisión/recall/F1 y calibración; recalibrar pesos
   solo con *validation* y evaluar en *test*.
2. Verificación de correo, recuperación de contraseña y cobro del plan Pro.
3. OCR asíncrono y detección de imágenes manipuladas.
4. Comparar el clasificador de lenguaje con otros modelos NLP (EP15-HU4).
5. Supervisor/Horizon-equivalente para workers en producción y métricas de tiempos.
6. Resolver DNS antes de descargar URLs y permitir actualizar listas de dominios sin cambiar código.

---

## 19. Preguntas frecuentes de sustentación

**¿Cómo decide si una noticia es falsa?**
No lo decide un modelo único. Extrae afirmaciones, las contrasta con fact-checkers y evidencia
web, y combina señales con un puntaje determinista (72 % evidencia). Si no hay evidencia
suficiente (<60 % de cobertura) se abstiene.

**¿Para qué sirve BERT si pesa solo 3 %?**
Aporta una señal de lenguaje (estilo sensacionalista) cuando no hay evidencia. Pesa poco porque
no verifica hechos y puede equivocarse; nunca decide por sí solo.

**¿Por qué evaluar por afirmación y no la noticia entera?**
Las noticias mezclan hechos verdaderos y falsos. El informe por claim es más justo y
explicable, y permite ponderar el argumento principal.

**¿Qué pasa si Gemini está caído o sin cuota?**
Rota entre 3 llaves y varios modelos; el circuit breaker omite modelos saturados; en 429 usa
OpenAI; sin cuota (402) falla con un mensaje claro. En la etapa final, si el analista falla,
se calcula el puntaje sin IA.

**¿Cómo se evita la alucinación del LLM?**
(1) Salida con JSON Schema, (2) el analista solo recibe claims, verificaciones y evidencias del
sistema, (3) instrucciones explícitas de no inventar hechos ni URLs, (4) una postura
"respaldada/refutada" solo cuenta si hay evidencia vinculada al claim, y (5) el puntaje final lo
calcula código determinista, no el LLM.

**¿Por qué usar colas y qué garantiza la consistencia?**
Las llamadas externas son lentas e inestables. Los jobs son idempotentes (huellas, restricciones
únicas, `createOrFirst`) y el batch espera a todos antes de finalizar, aun con fallos parciales.

**¿Cómo se paraleliza?**
Cada claim crea sus propios jobs (fact-check y búsqueda). Con varios workers de Redis se
procesan simultáneamente; el batch dispara la finalización al terminar el último.

**¿Qué significa "inconclusive"?**
Que la evidencia encontrada no alcanza para un veredicto. No equivale a "falso".

**¿Por qué una afirmación sin fuentes no baja tanto el puntaje?**
Si no es la idea principal, un detalle sin fuentes conserva solo el 40 % de su peso. El
argumento central pesa el doble. Así el veredicto refleja el tema principal de la noticia.

**¿Qué hacen con opiniones y predicciones?**
Se muestran con su etiqueta pero no entran al veredicto: no son verificables como hechos.

**¿Cómo se excluyen redes sociales?**
Lista de dominios bloqueados enviada a Tavily y filtrada localmente, además de priorizar
fuentes creíbles; configurable por entorno.

**¿Cómo se asegura la calidad?**
~123 pruebas automatizadas con proveedores simulados y CI en GitHub Actions.

**¿Qué tan exacto es?**
No se ha medido todavía (no hay corpus etiquetado). Se diseñó para ser conservador y
explicable; medir exactitud es el siguiente paso.

**¿Qué haría distinto con más tiempo?**
Corpus etiquetado y métricas, autenticación, SPA, resolución DNS segura, monitorización de
colas y reducir la latencia de la síntesis.

**¿Qué pasa si dos usuarios envían la misma noticia?**
La noticia se deduplica por URL canónica o hash dentro de cada cuenta. El mismo usuario
reutiliza su artículo y su análisis; otra cuenta obtiene un artículo y un análisis propios.

**¿Cómo se versiona el algoritmo?**
`algorithm_version` (actualmente 1.2.0) se guarda en cada `Score` y análisis, y
`prompt_version` identifica el prompt de extracción para trazabilidad.

---

## 20. Glosario

- **Claim / afirmación:** enunciado concreto extraído de la noticia que puede evaluarse.
- **Fact-check:** revisión publicada por un verificador de datos (p. ej. Maldita, Chequeado).
- **Evidencia:** resultado de búsqueda o fact-check vinculado a un claim.
- **Cobertura:** proporción (ponderada) de afirmaciones con respaldo externo.
- **Abstención:** decisión de no emitir veredicto por evidencia insuficiente.
- **Circuit breaker:** mecanismo que deja de llamar temporalmente a un servicio que falla.
- **Idempotencia:** repetir una operación produce el mismo resultado sin duplicar datos.
- **Batch:** conjunto de jobs cuya finalización dispara una acción posterior.
- **BERT:** modelo de lenguaje usado aquí como clasificador REAL/FAKE de estilo.

---

*Licencia del framework base: MIT (Laravel).*
