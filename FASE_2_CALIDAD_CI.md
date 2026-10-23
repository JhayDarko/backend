# FactNode — Fase 2: calidad automatizada y CI

**Estado:** tareas C1–C6 implementadas  
**Fecha del informe:** 2026-10-22  
**Alcance:** pruebas, análisis estático, locks reproducibles, seguridad automatizada,
automatización CI y documentación. No se modificó el algoritmo de scoring ni sus pesos
o umbrales.

## Resumen ejecutivo

La fase incorporó una pipeline de integración continua con cinco jobs independientes:
`backend`, `frontend`, `python`, `openapi` y `security`. El backend se valida en CI con
PostgreSQL real; los dos microservicios Python tienen locks hasheados y pruebas aisladas
de los modelos; PHPStan/Larastan y Angular ESLint/typecheck se ejecutan automáticamente;
OpenAPI se lintea y contrasta con rutas Laravel; las dependencias, configuraciones Docker
y secretos se escanean en CI.

La validación local de los cambios terminó con:

- Backend: `composer ci` pasó con **199 tests, 198 aprobados, 1 omitido y 764 assertions**.
- Frontend limpio con Node 24: lint, typecheck y build de producción pasaron; **17 tests
  aprobados**.
- Python: suites previas de **11 tests ML y 13 tests OCR**, con Ruff aprobado.
- Composer, npm, pip-audit, Gitleaks del árbol actual y Trivy filesystem fueron
  comprobados localmente, con las excepciones temporales de Transformers descritas en
  este documento.
- El ciclo contra PostgreSQL 16 y las migraciones también se comprobó localmente.

**Pendiente de aceptación externa:** no se ejecutó esta versión en GitHub Actions ni se
confirmó un check remoto verde. Además, siguen pendientes la rotación/revocación de dos
credenciales históricas y la revisión de la excepción temporal de Transformers.

## Versiones configuradas

| Componente | Versión usada/configurada | Evidencia |
|---|---:|---|
| PHP | 8.4 | `composer.json`, `.github/workflows/ci.yml` |
| PostgreSQL | 16 (`postgres:16-alpine`) | `docker-compose.yml`, `.github/workflows/ci.yml` |
| Redis | 7 (`redis:7-alpine`) | `docker-compose.yml`, `.github/workflows/ci.yml` |
| Node.js | 24 | `.github/workflows/ci.yml` |
| npm declarado por el frontend | 11.1.0 | `frontend/package.json` (`packageManager`) |
| Python | 3.11 | Dockerfiles, `pyproject.toml`, `.github/workflows/ci.yml` |
| `uv` para regenerar locks | 0.13.5 | `README.md` |
| Larastan | 3.13.0 | `composer.lock` |
| PHPStan | 2.3.1 | `composer.lock` |
| Nivel PHPStan | 5 | `phpstan.neon` |
| Angular ESLint | 21.4.0 | `frontend/package.json` |
| Redocly CLI | 2.67.1 | `.github/workflows/ci.yml` |
| Gitleaks CLI | 8.30.1 | `.github/workflows/ci.yml` |
| Trivy | 0.76.1 | `.github/workflows/ci.yml` |
| `pip-audit` | 2.10.1 | `.github/workflows/ci.yml` |

Las acciones GitHub se referencian al menos por versión mayor: checkout 7, setup-node 7,
setup-python 7, cache 6, upload-artifact 7, setup-php 2 y trivy-action 0.36.0. Sus
actualizaciones semanales se administran con Dependabot.

## Cambios por tarea

### C1 — Suite PHP contra PostgreSQL

**Cambios**

- El job `backend` de `.github/workflows/ci.yml` crea servicios PostgreSQL 16 y Redis 7.
- La suite PHP de CI usa `DB_CONNECTION=pgsql`; se mantuvo SQLite en memoria como
  configuración rápida para ejecución local.
- Se añadió la secuencia de verificación de migraciones:
  `migrate --force`, `migrate:rollback --step=2 --force`, `migrate --force` y
  `migrate:fresh --force`.
- PHPUnit guarda un reporte JUnit en `reports/phpunit.xml`, que se sube como artefacto
  incluso si el job falla.

**Resultado**

- Se ejecutaron las pruebas contra PostgreSQL 16: **198 tests, 197 aprobados y 1
  omitido**. No se encontró un defecto causado por diferencias entre PostgreSQL y SQLite.
- La secuencia de migraciones se ejecutó localmente contra PostgreSQL.
- Tras C4, la suite completa quedó en **199 tests, 198 aprobados, 1 omitido y 764
  assertions**.

**Archivos principales:** `.github/workflows/ci.yml`, el reporte JUnit y la suite
PHP/migraciones ejecutada por el workflow. Esta tarea no requirió editar las
migraciones.

### C2 — Servicios Python

**Cambios**

- Se añadieron archivos de restricciones editables (`requirements.in` y
  `requirements-test.in`) y locks `requirements.txt` y `requirements-test.txt` para
  `ml_service/` y `ocr_service/`.
- Los locks se generaron con `uv 0.13.5`, incluyen hashes y se instalan en Docker con
  `pip install --require-hashes`.
- `ml_service/tests/` incorpora pruebas del contrato de salud y clasificación,
  validación y errores; el clasificador se sustituye por un mock para evitar descargar
  pesos o requerir GPU.
- `ocr_service/tests/` cubre la API, datos inválidos y límites con PaddleOCR simulado.
- `pyproject.toml` configura Ruff (lint y formato).
- El job `python` corre una matriz por servicio con Python 3.11, caché de pip, Ruff y
  pytest.
- `README.md` explica cómo regenerar los locks y cómo instalarlos.

**Resultado**

- ML: **11 tests**; OCR: **13 tests**. Las suites y `ruff check`/`ruff format --check`
  pasaron.
- Las pruebas no descargan modelos ni contactan proveedores externos.

**Archivos principales:** `ml_service/requirements*.in`,
`ml_service/requirements*.txt`, `ml_service/tests/`, `ml_service/Dockerfile`,
`ocr_service/requirements*.in`, `ocr_service/requirements*.txt`,
`ocr_service/tests/`, `ocr_service/Dockerfile`, `pyproject.toml` y
`.github/workflows/ci.yml`.

### C3 — Análisis estático PHP y frontend

**Cambios**

- Se añadieron Larastan/PHPStan como dependencias de desarrollo y configuración en
  `phpstan.neon`, con nivel 5.
- Se generó `phpstan-baseline.neon` para registrar los hallazgos que ya existían antes
  de esta configuración. CI aplica el baseline para que fallos nuevos no queden
  ocultos.
- Se configuró Angular ESLint con `angular-eslint` en `frontend/eslint.config.js` y
  `frontend/angular.json`.
- El job `frontend` comprueba lint, TypeScript para aplicaciones y tests, build de
  producción y pruebas unitarias.

**Resultado**

- Larastan pasó con **0 errores nuevos** respecto del baseline.
- El baseline contiene **192 hallazgos preexistentes**. El grupo mayor identificado
  fueron **133 errores de propiedades dinámicas o no definidas**; no se corrigieron
  como parte de esta tarea de infraestructura.
- Angular ESLint y typecheck pasaron; el build de producción terminó correctamente y
  Vitest aprobó **17 tests**.

**Archivos principales:** `composer.json`, `composer.lock`, `phpstan.neon`,
`phpstan-baseline.neon`, `frontend/package.json`, `frontend/package-lock.json`,
`frontend/angular.json`, `frontend/eslint.config.js` y
`.github/workflows/ci.yml`.

### C4 — Contrato OpenAPI

**Cambios**

- Se incorporó `symfony/yaml` como dependencia de desarrollo.
- `tests/Feature/Api/OpenApiRouteCoverageTest.php` compara métodos y rutas públicas
  `api/v1` registradas en Laravel con `public/api-docs/openapi.yaml`, normalizando los
  parámetros de ruta.
- Se añadió `redocly.yaml` y el job `openapi`, que ejecuta Redocly CLI 2.67.1.
- Se completó el documento OpenAPI con los operation IDs, licencia y respuestas
  correspondientes al comportamiento documentado.

**Resultado**

- El lint de Redocly pasó sin warnings.
- La prueba PHPUnit de cobertura de rutas pasó dentro de la suite backend.

**Archivos principales:** `composer.json`, `composer.lock`,
`tests/Feature/Api/OpenApiRouteCoverageTest.php`, `redocly.yaml`,
`public/api-docs/openapi.yaml` y `.github/workflows/ci.yml`.

### C5 — Auditorías, secretos y Trivy

**Cambios**

- El job `security` ejecuta `composer audit`, `npm audit --audit-level=high`,
  `pip-audit` mediante OSV, Gitleaks y Trivy filesystem; no construye imágenes.
- Se añadieron `.github/dependabot.yml` con actualizaciones semanales para Composer,
  npm, pip, GitHub Actions y Docker; `gitleaks.toml`; `.trivyignore` y
  `SECURITY-EXCEPTIONS.md`.
- Se añadieron overrides de frontend para resolver advisories en
  `@modelcontextprotocol/sdk` (1.32.1) y Vite (7.3.7).
- Trivy identificó que las imágenes ML/OCR corrían como root. Los Dockerfiles ahora
  usan `appuser` (UID 10001). El caché de PaddleOCR se movió a
  `/home/appuser/.paddlex` y el volumen de Compose se renombró
  `paddleocr_cache_v2`.

**Resultado**

| Escáner | Resultado |
|---|---|
| `composer audit --locked` | Sin advisories |
| `npm audit --audit-level=high` | 0 vulnerabilidades al finalizar |
| `pip-audit` OCR (runtime y tests) | Sin vulnerabilidades reportadas |
| `pip-audit` ML | Sin vulnerabilidades no exceptuadas; 11 entradas ignoradas por aliases de advisory |
| Gitleaks sobre árbol actual | Sin secretos detectados |
| Trivy filesystem | 0 hallazgos HIGH/CRITICAL después de excepciones documentadas; ambos Dockerfiles sin misconfiguraciones HIGH/CRITICAL |

**Excepción de Transformers:** el lock ML conserva `transformers==4.57.6`. `pip-audit`
requiere ignorar explícitamente seis IDs `PYSEC`: `PYSEC-2026-2289`,
`PYSEC-2026-2288`, `PYSEC-2026-2290`, `PYSEC-2026-4174`, `PYSEC-2026-3929` y
`PYSEC-2025-217`. Trivy también reportó `CVE-2026-4372` (corregido según Trivy en
5.3.0), `CVE-2026-5241` (5.5.0), `CVE-2026-80047` (afectado; sin versión corregida
indicada) y `CVE-2026-9856` (5.10.0). Las correcciones listadas son de la versión
major 5; el cambio de major quedó fuera de esta fase por el riesgo de compatibilidad
con la carga y clasificación del modelo. La excepción se debe revisar antes del
**2026-11-22**. Esto no demuestra que los caminos vulnerables sean inalcanzables.

**Hallazgo histórico Gitleaks:** el escaneo de historial encontró valores con formato
de credencial para `BRAVE_API_KEY` y `GOOGLE_CLOUD_VISION_API_KEY` en revisiones
antiguas de `.env.example`, en los commits
`3da92c2a2dd88470f8b0583999cca10e6eb09ebd` y
`aeed88873b2921dbcaa7835d7a98036490ebb99b`, respectivamente. Los valores no se
incluyeron en el informe. El `.env.example` actual ya no contiene esos valores, pero
siguen recuperables de Git. Se acordó conservar el historial por ahora; falta revocar
o rotar las credenciales antes de reutilizarlas. No se hizo reescritura ni force-push.
El escaneo Gitleaks de CI cubre el árbol actual (`--no-git`), no el historial completo.

**Archivos principales:** `.github/workflows/ci.yml`, `.github/dependabot.yml`,
`frontend/package.json`, `frontend/package-lock.json`, `ml_service/requirements*.in`,
`ml_service/requirements*.txt`, `ocr_service/requirements*.in`,
`ocr_service/requirements*.txt`, ambos Dockerfiles, `docker-compose.yml`,
`gitleaks.toml`, `.trivyignore` y `SECURITY-EXCEPTIONS.md`.

### C6 — Estructura y documentación del CI

**Cambios**

- El workflow único `.github/workflows/ci.yml` contiene cinco jobs con IDs estables:
  `backend`, `frontend`, `python`, `openapi` y `security`.
- Define permisos `contents: read`, concurrencia cancelable, timeout en cada job,
  acciones versionadas y caché de Composer, npm y pip.
- No se añadieron workflows ni pasos de despliegue.
- `composer.json` expone los comandos `composer test`, `composer analyse` y
  `composer ci`; este último combina tests, análisis estático y Pint.
- `README.md` ahora incluye badges, responsabilidades de los jobs, comandos locales,
  actualización de locks y procedimiento prudente para actualizar el baseline PHPStan.

**Resultado**

- `composer validate --no-check-publish` pasó.
- `composer ci` pasó en SQLite local. Para dejar Pint en verde se formatearon archivos
  PHP nuevos o alterados que ya estaban presentes en la rama.
- Se validaron la sintaxis YAML del workflow y Dependabot, `docker compose config
  --quiet` y `git diff --check`.
- La ejecución local de PostgreSQL y las demás validaciones no sustituyen la
  confirmación de la pipeline remota; falta ejecutar o verificar el workflow en una
  rama de GitHub.

## Archivos incorporados o actualizados

### CI, seguridad y documentación

- `.github/workflows/ci.yml`
- `.github/dependabot.yml`
- `.trivyignore`
- `gitleaks.toml`
- `SECURITY-EXCEPTIONS.md`
- `README.md`
- `FASE_2_CALIDAD_CI.md` (este informe)
- `redocly.yaml`

### PHP y contrato API

- `composer.json`, `composer.lock`
- `phpstan.neon`, `phpstan-baseline.neon`
- `public/api-docs/openapi.yaml`
- `tests/Feature/Api/OpenApiRouteCoverageTest.php`
- Cambios de formato Pint aplicados a archivos PHP que ya estaban siendo modificados
  en esta rama.

### Frontend

- `frontend/package.json`, `frontend/package-lock.json`
- `frontend/angular.json`, `frontend/eslint.config.js`
- Ajustes de tests HTTP relacionados con el lint/configuración.

### Python y contenedores

- `pyproject.toml`
- `ml_service/requirements.in`, `ml_service/requirements-test.in`,
  `ml_service/requirements.txt`, `ml_service/requirements-test.txt`,
  `ml_service/tests/`
- `ocr_service/requirements.in`, `ocr_service/requirements-test.in`,
  `ocr_service/requirements.txt`, `ocr_service/requirements-test.txt`,
  `ocr_service/tests/`
- `ml_service/Dockerfile`, `ocr_service/Dockerfile`, `docker-compose.yml`

## Comandos útiles

Desde la raíz del repositorio:

```bash
composer test
composer analyse
php vendor/bin/pint --test
composer ci
npm --prefix frontend run lint
npm --prefix frontend run typecheck
npm --prefix frontend run build
npm --prefix frontend test -- --watch=false
npx --yes @redocly/cli@2.67.1 lint public/api-docs/openapi.yaml
```

Para pruebas Python, ejecutar dentro de `ml_service/` u `ocr_service/`:

```bash
python -m pip install --require-hashes -r requirements-test.txt
ruff check .
ruff format --check .
python -m pytest -q
```

La prueba PostgreSQL usa `migrate:fresh` y destruye las tablas de la base configurada;
solo debe ejecutarse contra una base de datos desechable de pruebas.

## Pendientes y límites conocidos

1. Verificar el workflow completo en GitHub y confirmar los checks en una rama de prueba.
2. Revocar/rotar las dos credenciales históricas; coordinar por separado cualquier
   eventual purga de commits compartidos.
3. Revisar y resolver o renovar antes del 2026-11-22 la excepción de Transformers y
   las entradas asociadas en `SECURITY-EXCEPTIONS.md`, `.trivyignore` y CI.
4. Mantener visibles los 192 errores heredados en el baseline PHPStan y reducirlo
   gradualmente; no sustituir el baseline sin revisar cada diferencia.
5. La auditoría de paquetes no equivale a una revisión de seguridad completa de la
   lógica de la aplicación. Las pruebas de esta fase usan mocks/fakes y no validan
   llamadas reales a proveedores ni descargan modelos.

No se creó ningún commit como parte de esta fase.
