# Guía de etiquetado

## Unidad que se etiqueta

Etiqueta la afirmación factual principal que presenta el caso, usando `claim_summary` redactado con palabras propias. La etiqueta describe lo que las fuentes de verificación permiten concluir sobre esa afirmación y no la calidad estilística del artículo, la intención del autor ni la reputación general del medio.

`label_sources` registra fuentes que sustentan la decisión: nombre, URL de la revisión y veredicto original de esa fuente. No inventes fuentes ni copies su texto. Si no hay evidencia suficiente para una conclusión estable, usa `inconclusive` y explica brevemente el límite en `label_rationale`.

## Clases

- **`credible`**: la afirmación central está sustancialmente respaldada por evidencia fiable, directa y suficientemente independiente. No exige que cada detalle secundario sea perfecto.
  - *Ejemplo*: una afirmación sobre el resultado de una elección coincide con los resultados oficiales publicados y con cobertura independiente concordante.
- **`misleading`**: existe una base factual, pero el encuadre, la omisión de contexto, una cifra presentada sin denominador o una generalización cambia materialmente la interpretación.
  - *Ejemplo*: una cifra real de un periodo se presenta como si correspondiera a otro periodo, llevando a una conclusión distinta.
- **`false`**: la afirmación central contradice evidencia fiable y suficiente, o una comprobación directa demuestra que el hecho afirmado no ocurrió.
  - *Ejemplo*: una cita atribuida a una persona es desmentida por el registro original íntegro y por una verificación fiable.
- **`inconclusive`**: no hay información fiable suficiente para confirmar o refutar la afirmación, las fuentes disponibles se contradicen sin resolución, o la afirmación no puede comprobarse de forma objetiva.
  - *Ejemplo*: una afirmación depende de datos privados no publicados y no se aportan documentos verificables.

Los ejemplos son esquemáticos; no son afirmaciones reales ni fuentes etiquetadas del dataset.

## Casos especiales

- **Sátira y ficción**: no etiquetes como `false` una pieza claramente presentada como sátira o ficción solo porque sus hechos literales no sean ciertos. Si el caso evalúa una afirmación viral que circula fuera de ese contexto, etiqueta esa afirmación y documenta el contexto. Si no se puede establecer qué se está afirmando, usa `inconclusive`.
- **Opinión**: juicios de valor, preferencias y opiniones no comprobables no son verdaderos ni falsos. Para esos casos, usa `inconclusive`; no evalúes la postura política o moral del autor.
- **Predicciones**: una predicción futura aún no comprobable se etiqueta `inconclusive` hasta que exista evidencia pertinente.
- **Afirmaciones compuestas**: céntrate en la afirmación central resumida en `claim_summary`. Si una parte verdadera se usa para sostener una conclusión materialmente engañosa, usa `misleading`; si no se puede aislar qué parte es central, usa `inconclusive`.
- **Ausencia de evidencia**: que no aparezcan resultados de búsqueda no demuestra que la afirmación sea falsa. Usa `inconclusive` salvo que exista evidencia positiva suficiente en contra.
- **Veredictos externos**: conserva el texto breve y literal en `original_verdict`; la clase del dataset debe reflejar la evidencia revisada, no una traducción automática del veredicto.

## Desacuerdos y registro

Dos personas etiquetan cada caso de forma independiente en `labeler_a` y `labeler_b`. `agreement` indica si llegaron a la misma clase antes de discutir el caso.

1. Si hay acuerdo, registra `agreement: true` y una justificación concisa.
2. Si hay desacuerdo, registra `agreement: false`; no ocultes el desacuerdo reemplazando las dos etiquetas por una decisión no trazable.
3. Revisad conjuntamente la afirmación, fecha, contexto y fuentes originales. Priorizad fuentes primarias y verificaciones independientes; no contéis copias de una misma fuente como corroboración independiente.
4. Si la evidencia no resuelve el desacuerdo, escaladlo a una tercera persona. Si persiste incertidumbre factual, la etiqueta final del caso debe ser `inconclusive` y `notes` debe describir brevemente la limitación.
5. Actualizad la etiqueta final acordada y conservad en `notes` una nota corta sobre la adjudicación. No guardéis conversaciones extensas ni material con derechos de autor en el dataset.

`labeler_a` y `labeler_b` son identificadores seudónimos; no incluyas datos personales.
