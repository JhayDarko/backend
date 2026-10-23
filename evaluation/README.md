# Evaluación de calidad del veredicto

Este directorio contiene el dataset y los artefactos de evaluación de FactNode. El contenido de los artículos no se guarda en Git: `text_cache_path` apunta a archivos locales ignorados en `evaluation/cache/`. No añadas claves, capturas ni copias completas de artículos al repositorio.

## Dataset

- `dataset/schema.json`: contrato de cada registro JSONL.
- `dataset/cases.jsonl`: un caso JSON por línea. Los tres primeros registros son ejemplos (`"example": true`); a continuación se conserva el piloto de 14 casos.
- `dataset/splits.json`: política versionada de splits, semilla y mínimos recomendados.
- `LABELING_GUIDE.md`: criterios de anotación y resolución de desacuerdos.

Cada caso usa una de cuatro clases en `expected_verdict`: `credible`, `misleading`, `false` o `inconclusive`. Es una etiqueta del dataset, no necesariamente el literal almacenado por el sistema. El mapeo se describe en la guía de etiquetado.

El esquema conserva metadatos de curación como `status`, `claim_group`, `label_strength`, `verify_notes` e `input_text_hint`. Los candidatos pueden tener `split: null`; no se les asigna un split hasta la fase de división. `published_at` admite `AAAA-MM`, `AAAA-MM-DD` o `null`. Las fechas ausentes, los estados distintos de `verificado` y las URL nulas de fuentes se reportan como advertencias. `--strict` también falla por estas advertencias y por cobertura inferior a los mínimos.

`input_text_hint` debe ser una referencia breve para localizar o reconocer la entrada, no el texto íntegro del artículo. Para evaluar texto, el contenido completo se mantiene local en `evaluation/cache/<id>.txt`, ignorado por Git.

El piloto importado contiene 14 candidatos sin verificar y sin split asignado. Se conservan sus campos, incluidas etiquetas, grupos y anotaciones nulas; la validación muestra su distribución bajo `sin asignar` y advierte por estado, URLs de fuentes nulas y fechas ausentes.

## Validar

```powershell
php artisan eval:validate
```

El comando comprueba el esquema, los identificadores, las URLs duplicadas y el tamaño de los textos, e informa la distribución por clase y split. Advierte sobre casos no verificados, fuentes sin URL y fechas ausentes. Los casos de ejemplo se excluyen por defecto. Para incluirlos explícitamente:

```powershell
php artisan eval:validate --include-examples
```

Los mínimos por split y por combinación de clase/split son advertencias para que el dataset parcial siga siendo verificable. Por defecto, las advertencias no impiden validar; `--strict` hace que cualquier advertencia produzca un código de salida fallido:

```powershell
php artisan eval:validate --strict
```

Se puede validar otro JSONL con el mismo esquema indicando una ruta:

```powershell
php artisan eval:validate --dataset=evaluation/dataset/cases.jsonl
```

## Asignar splits

`eval:split` asigna cada grupo completo a `dev` o `test`, estratificando por la clase (o composición de clases) del grupo. Usa `claim_group`; si falta, trata cada `id` como grupo independiente. La semilla y proporción de test se toman de `dataset/splits.json` (`20261022` y `0.3` por defecto). El comando actualiza el `split` de los casos y guarda asignaciones y distribución en ese mismo manifiesto:

```powershell
php artisan eval:split
```

Se pueden indicar rutas de trabajo y parámetros reproducibles explícitos:

```powershell
php artisan eval:split --dataset=tests/Fixtures/Evaluation/split_cases.jsonl --splits-file=evaluation/dataset/splits.json --seed=20261022 --test-ratio=0.3
```

Los ejemplos se excluyen y conservan su split original salvo que se use `--include-examples`. Los mínimos por split/clase generan advertencias; `--strict` devuelve fallo y no escribe cambios si no se alcanzan. Las pruebas de este comando solo usan fixtures. Antes de aplicarlo al dataset real, revisa la propuesta y confirma que los grupos y etiquetas están listos.

## Incorporar casos

1. Obtén y conserva los textos solo de acuerdo con los permisos aplicables, localmente en `evaluation/cache/`; no los incluyas en un commit.
2. Escribe una afirmación resumida con palabras propias, completa los campos según `dataset/schema.json` y deja `split: null` mientras sea un candidato sin verificar.
3. Etiqueta usando `LABELING_GUIDE.md`. Registra fuentes de verificación y el motivo corto de la etiqueta; no copies artículos ni veredictos ajenos como si fueran texto propio.
4. Añade un objeto JSON por línea en `dataset/cases.jsonl`; usa un `id` estable y único.
5. Ejecuta `php artisan eval:validate`. Atiende errores de esquema y duplicados; las advertencias de mínimos señalan cobertura aún insuficiente.

No incluyas secretos, texto íntegro de artículos ni capturas en fuentes versionadas. `url` y `label_sources[].url` identifican las fuentes, no sustituyen el resumen propio ni la justificación.

## Recolectar observaciones (E2)

`eval:collect` escribe los artefactos en `evaluation/runs/<run-id>/<mode>/`. Una ejecución real requiere una base separada; el comando falla antes de migrar o escribir si faltan los ajustes explícitos de `EVAL_DB_*` o si el host y nombre de base coinciden con la conexión predeterminada. Para SQLite, indica explícitamente `EVAL_DB_CONNECTION=sqlite` y una ruta de archivo distinta de la base normal. Para PostgreSQL/MySQL, configura explícitamente `EVAL_DB_CONNECTION`, `EVAL_DB_HOST`, `EVAL_DB_PORT`, `EVAL_DB_DATABASE`, `EVAL_DB_USERNAME` y `EVAL_DB_PASSWORD`.

La vista previa del piloto no conecta a la base ni llama a proveedores:

```powershell
php artisan eval:collect --dry-run --mode=production --run-id=pilot-dry-run
```

El default es `production`; ese modo no aplica exclusiones del caso. `--mode=blind` aplica `excluded_domains` a la búsqueda web y filtra Fact Check por publicador y host. `--no-factcheck` desactiva únicamente Google Fact Check y puede combinarse con cualquiera de los modos. Manifiestos e instantáneas incluyen modo y flags; no incluyen `expected_verdict`. El worker corre en un proceso hijo con cola `database` y cache propio de evaluación. `--case-timeout` limita cada caso; `--drain-timeout` limita el drenaje de jobs/batches en ciclos. Los fallos y jobs agotados se registran, sin detener los casos siguientes.

No lances una corrida real sin autorización explícita. La vista previa puede indicar llamadas estimadas, pero el costo sigue pendiente hasta disponer de precios verificados en `pricing.json`.

## Estado de las fases

E1/P1/P3 establecen esquema, guía, validación y división. E2 implementa recolección aislada, modos, cola con worker hijo, filtros, guardas, métricas y snapshots. E3/E4/E5/E6 (scoring offline, métricas, protección de test y CI de evaluación) siguen pendientes. No se ha ejecutado una corrida real del piloto ni se han llamado proveedores durante la implementación de E2.
