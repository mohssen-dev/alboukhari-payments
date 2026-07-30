@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
    $totalDue = 0; $totalPaid = 0; $totalBal = 0;
    foreach ($rows as $r) { $totalDue += $r['due']; $totalPaid += $r['paid']; $totalBal += max(0, $r['balance']); }
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.statement') }} · {{ $student->name }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v=6.0">
    <style>
        body { background: #fbfaf7; padding: 24px; }
        .doc-shell { max-width: 860px; margin: 0 auto; }
        .doc {
            background: white;
            padding: 36px 40px;
            border-radius: 16px;
            border: 1px solid #e8e1d2;
            box-shadow: 0 12px 28px -12px rgba(39, 114, 69, 0.25), 0 4px 12px -4px rgba(15, 23, 42, 0.08);
            position: relative;
            overflow: hidden;
        }
        .doc::before {
            content: "";
            position: absolute; top: 0; left: 0; right: 0; height: 6px;
            background: linear-gradient(90deg, #277245 0%, #b48e63 50%, #277245 100%);
        }
        .doc-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 18px;
            border-bottom: 1px solid #e8e1d2;
            padding-bottom: 20px;
            margin-bottom: 22px;
            flex-wrap: wrap;
        }
        .doc-brand {
            display: flex; align-items: center; gap: 14px;
        }
        .doc-logo {
            width: 64px; height: 64px;
            border-radius: 14px;
            object-fit: cover;
            padding: 4px;
            background: white;
            box-shadow: 0 4px 12px -4px rgba(39, 114, 69, 0.25), 0 0 0 1px rgba(180, 142, 99, 0.35);
        }
        .doc-brand h1 { margin: 0 0 2px; font-size: 20px; color: #1b5733; }
        .doc-brand small { color: #64748b; font-size: 12px; }
        .doc-meta { color: #64748b; font-size: 12px; text-align: end; }
        .doc-meta .year-badge {
            background: #e8f1ec; color: #1b5733; padding: 4px 14px;
            border-radius: 999px; font-weight: 700; font-size: 13px;
            display: inline-block; margin-bottom: 4px;
        }
        .student-info {
            background: linear-gradient(135deg, #f5ede1 0%, #fbfaf7 100%);
            border: 1px solid #e8e1d2;
            border-radius: 12px;
            padding: 14px 18px;
            margin-bottom: 18px;
            display: flex;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            font-size: 13px;
        }
        .student-info .label { color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700; margin-bottom: 2px; }
        .student-info strong { color: #1b5733; font-size: 14px; }

        table.stmt { width: 100%; border-collapse: collapse; font-size: 13px; }
        table.stmt th, table.stmt td { padding: 10px 12px; border-bottom: 1px solid #e8e1d2; }
        table.stmt th {
            background: #e8f1ec; text-align: start;
            color: #1b5733; font-size: 11px;
            text-transform: uppercase; letter-spacing: 0.5px; font-weight: 700;
        }
        table.stmt tfoot th {
            background: linear-gradient(135deg, #e8f1ec 0%, #f5ede1 100%);
            font-size: 13px;
        }
        .bal-pos { color: #b91c1c; font-weight: 700; }
        .bal-zero { color: #2f7a3f; font-weight: 600; }
        .status-paid { color:#2f7a3f; font-weight:600; } .status-partial { color:#b88641; font-weight:600; }
        .status-unpaid, .status-late { color:#b91c1c; font-weight:600; } .status-not_due { color:#94a3b8; }
        .status-legacy_zero { color:#0e7490; }

        .summary-cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 18px; }
        .summary-card {
            padding: 14px 16px;
            border-radius: 12px;
            border: 1px solid #e8e1d2;
            background: white;
        }
        .summary-card .lbl-s { font-size: 10px; text-transform: uppercase; letter-spacing: 0.6px; color: #64748b; font-weight: 700; }
        .summary-card .val-s { font-size: 20px; font-weight: 800; margin-top: 4px; }
        .summary-card.due { background: linear-gradient(135deg, #f5ede1 0%, white 100%); }
        .summary-card.paid { background: linear-gradient(135deg, #e8f1ec 0%, white 100%); }
        .summary-card.bal { background: linear-gradient(135deg, #fde0e0 0%, white 100%); }

        .actions { text-align: center; margin-top: 18px; display: flex; gap: 8px; justify-content: center; }
        .actions .btn { padding: 10px 20px; font-size: 14px; }
        .stamp { margin-top: 24px; text-align: center; color: #94a3b8; font-size: 11px; border-top: 1px dashed #e8e1d2; padding-top: 14px; }

        @media print {
            body { background: white; padding: 0; }
            .doc-shell { max-width: none; }
            .doc { box-shadow: none; border: 0; padding: 0; border-radius: 0; }
            .doc::before { display: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
<div class="doc-shell">
    <div class="doc">
        <div class="doc-header">
            <div class="doc-brand">
                <img src="{{ asset('assets/img/logo.jpeg') }}" alt="Al Boukhari" class="doc-logo">
                <div>
                    <h1>{{ __('school_name') }}</h1>
                    <small>{{ __('exports.statement') }}</small>
                </div>
            </div>
            <div class="doc-meta">
                <div class="year-badge">{{ $year }}</div>
                <div>{{ now()->format('Y-m-d H:i') }}</div>
            </div>
        </div>

        <div class="student-info">
            <div>
                <div class="label">{{ __('panel.tabs.profile') }}</div>
                <strong>{{ $student->name }}</strong>
                @if($student->external_id)
                    <span style="color:#94a3b8;margin-inline-start:6px;font-size:11px">ID: {{ $student->external_id }}</span>
                @endif
            </div>
            @if($student->family?->guardian_name)
            <div>
                <div class="label">{{ __('family.title') }}</div>
                <strong>{{ $student->family->guardian_name }}</strong>
            </div>
            @endif
            @if($student->phone_primary_e164)
            <div>
                <div class="label">{{ __('columns.phone') }}</div>
                <strong style="direction:ltr;display:inline-block">{{ $student->phone_primary_e164 }}</strong>
            </div>
            @endif
        </div>

        <table class="stmt">
            <thead>
                <tr>
                    <th>{{ __('reports.month') }}</th>
                    <th style="text-align:end">{{ __('payment.due') }}</th>
                    <th style="text-align:end">{{ __('payment.paid_so_far') }}</th>
                    <th style="text-align:end">{{ __('payment.remaining') }}</th>
                    <th>{{ __('columns.status') }}</th>
                </tr>
            </thead>
            <tbody>
            @foreach($rows as $m => $r)
                <tr>
                    <td><strong>{{ $months[$m] ?? $m }}</strong></td>
                    <td style="text-align:end">{{ number_format($r['due'], 2) }}</td>
                    <td style="text-align:end">{{ number_format($r['paid'], 2) }}</td>
                    <td style="text-align:end" class="{{ $r['balance'] > 0 ? 'bal-pos' : 'bal-zero' }}">{{ number_format($r['balance'], 2) }}</td>
                    <td><span class="status-{{ $r['status'] }}">{{ __('status.'.$r['status']) }}</span></td>
                </tr>
            @endforeach
            </tbody>
            <tfoot>
                <tr>
                    <th>{{ __('common.total') }}</th>
                    <th style="text-align:end">{{ number_format($totalDue, 2) }}</th>
                    <th style="text-align:end">{{ number_format($totalPaid, 2) }}</th>
                    <th style="text-align:end" class="{{ $totalBal > 0 ? 'bal-pos' : 'bal-zero' }}">{{ number_format($totalBal, 2) }}</th>
                    <th></th>
                </tr>
            </tfoot>
        </table>

        <div class="summary-cards">
            <div class="summary-card due">
                <div class="lbl-s">{{ __('payment.due') }}</div>
                <div class="val-s">{{ number_format($totalDue, 2) }} €</div>
            </div>
            <div class="summary-card paid">
                <div class="lbl-s">{{ __('payment.paid_so_far') }}</div>
                <div class="val-s" style="color:#2f7a3f">{{ number_format($totalPaid, 2) }} €</div>
            </div>
            <div class="summary-card bal">
                <div class="lbl-s">{{ __('payment.remaining') }}</div>
                <div class="val-s" style="color: {{ $totalBal > 0 ? '#b91c1c' : '#2f7a3f' }}">{{ number_format($totalBal, 2) }} €</div>
            </div>
        </div>

        <div class="stamp">{{ now()->format('Y-m-d H:i') }} · <strong style="color:#277245">{{ __('school_name') }}</strong></div>
    </div>

    <div class="actions">
        <button class="btn btn-primary" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            {{ __('exports.print') }}
        </button>
        <a href="{{ route('home') }}" class="btn">{{ __('common.close') }}</a>
    </div>
</div>
</body>
</html>
