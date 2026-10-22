<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Informe de contraste #{{ $report['analysis_id'] }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #0B1626; line-height: 1.45; }
    h1 { font-size: 20px; margin: 0 0 2px; }
    h2 { font-size: 14px; border-bottom: 2px solid #2547F5; padding-bottom: 3px; margin: 22px 0 8px; }
    .kicker { color: #2547F5; font-size: 10px; letter-spacing: 1px; }
    .muted { color: #4a5568; }
    .verdict { border: 1px solid #0B1626; padding: 10px 12px; margin-top: 10px; }
    .score { font-size: 30px; font-weight: bold; }
    table { width: 100%; border-collapse: collapse; }
    td, th { border: 1px solid #cbd5e0; padding: 4px 6px; text-align: left; vertical-align: top; }
    th { background: #EEF2F7; }
    .claim { border: 1px solid #cbd5e0; padding: 8px 10px; margin-bottom: 8px; page-break-inside: avoid; }
    .tag { font-weight: bold; }
    .central { background: #2547F5; color: #fff; padding: 1px 5px; font-size: 9px; }
    ul { margin: 4px 0 0 16px; padding: 0; }
    .footer { margin-top: 24px; font-size: 9px; color: #4a5568; border-top: 1px solid #cbd5e0; padding-top: 6px; }
</style>
</head>
<body>
    <div class="kicker">FACTNODE · INFORME DE CONTRASTE</div>
    <h1>{{ $report['article']['title'] ?? 'Análisis de información' }}</h1>
    <div class="muted">
        @if ($report['article']['source']) {{ $report['article']['source'] }} · @endif
        @if ($report['article']['url']) {{ $report['article']['url'] }} @else Texto ingresado manualmente @endif
    </div>

    <div class="verdict">
        <div class="score">{{ $report['verdict']['score'] !== null ? round($report['verdict']['score']) : '—' }}<span style="font-size:12px"> / 100</span></div>
        <div><span class="tag">Veredicto:</span> {{ $report['verdict']['label'] }}
            @if ($report['verdict']['truth_assessment']) · <span class="tag">Veracidad:</span> {{ $report['verdict']['truth_assessment'] }} @endif
        </div>
        @if ($report['verdict']['confidence'] !== null)
            <div class="muted">Confianza de las señales: {{ round($report['verdict']['confidence'] * 100) }}%</div>
        @endif
    </div>

    @if ($report['verdict']['summary'])
        <h2>Síntesis</h2>
        <p>{{ $report['verdict']['summary'] }}</p>
        @if ($report['verdict']['rationale']) <p class="muted">{{ $report['verdict']['rationale'] }}</p> @endif
    @endif

    <p class="muted">Cobertura de evidencia: <strong>{{ round($report['coverage']['evidence'] * 100) }}%</strong> ·
        Verificación directa por fact-checkers: <strong>{{ round($report['coverage']['fact_check'] * 100) }}%</strong></p>

    @if (count($report['key_findings']) || count($report['red_flags']))
        <h2>Hallazgos</h2>
        <ul>
            @foreach (array_merge($report['key_findings'], $report['red_flags']) as $finding)
                <li>{{ $finding }}</li>
            @endforeach
        </ul>
    @endif

    <h2>Puntaje y señales</h2>
    <table>
        <tr><th>Señal</th><th style="width:70px">Valor (0–100)</th></tr>
        @foreach ($report['subscores'] as $row)
            <tr><td>{{ $row['label'] }}</td><td>{{ $row['value'] !== null ? round($row['value']) : '—' }}</td></tr>
        @endforeach
    </table>

    <h2>Afirmaciones y evidencias</h2>
    @forelse ($report['claims'] as $claim)
        <div class="claim">
            @if ($claim['is_central']) <span class="central">AFIRMACIÓN PRINCIPAL</span> @endif
            <span class="tag">{{ $claim['included_in_verdict'] ? $claim['stance'] : 'No entra al veredicto' }}</span>
            <div>{{ $claim['text'] }}</div>
            @if ($claim['reasoning']) <div class="muted">{{ $claim['reasoning'] }}</div> @endif
            @if (count($claim['fact_checks']) || count($claim['evidence']))
                <ul>
                    @foreach ($claim['fact_checks'] as $check)
                        <li>Verificador {{ $check['publisher'] ?? $check['domain'] ?? '' }}: {{ $check['title'] ?? '' }} ({{ $check['rating'] ?? '' }}) — {{ $check['url'] ?? '' }}</li>
                    @endforeach
                    @foreach (array_slice($claim['evidence'], 0, 5) as $item)
                        <li>{{ $item['title'] ?? $item['url'] }} — {{ $item['url'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @empty
        <p class="muted">No se identificaron afirmaciones para revisar.</p>
    @endforelse

    <h2>Metodología</h2>
    <ul>
        @foreach ($report['methodology']['notes'] as $note) <li>{{ $note }}</li> @endforeach
    </ul>
    @if (count($report['methodology']['weights']))
        <p class="muted">Pesos del puntaje:
            @foreach ($report['methodology']['weights'] as $name => $weight) {{ $name }} {{ round($weight * 100) }}%@if (! $loop->last), @endif @endforeach
        </p>
    @endif

    <div class="footer">
        Generado el {{ \Carbon\Carbon::parse($report['generated_at'])->format('d/m/Y H:i') }} · Análisis #{{ $report['analysis_id'] }} ·
        Versión del algoritmo {{ $report['algorithm_version'] }}. Este informe es automático: revisa las fuentes enlazadas antes de sacar conclusiones.
    </div>
</body>
</html>
