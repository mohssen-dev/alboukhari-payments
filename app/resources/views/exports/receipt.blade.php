@php
    $locale = app()->getLocale();
    $dir = $locale === 'ar' ? 'rtl' : 'ltr';
@endphp
<!DOCTYPE html>
<html lang="{{ $locale }}" dir="{{ $dir }}">
<head>
    <meta charset="UTF-8">
    <title>{{ __('exports.receipt') }} #{{ $payment->id }}</title>
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}?v=6.0">
    <style>
        body { background: #fbfaf7; padding: 24px; }
        .receipt-shell { max-width: 640px; margin: 0 auto; }
        .receipt {
            background: white;
            padding: 36px 40px;
            border-radius: 16px;
            border: 1px solid #e8e1d2;
            box-shadow: 0 12px 28px -12px rgba(39, 114, 69, 0.25), 0 4px 12px -4px rgba(15, 23, 42, 0.08);
            position: relative;
            overflow: hidden;
        }
        .receipt::before {
            content: "";
            position: absolute; top: 0; left: 0; right: 0; height: 6px;
            background: linear-gradient(90deg, #277245 0%, #b48e63 50%, #277245 100%);
        }
        .receipt-header { text-align: center; margin-bottom: 24px; }
        .receipt-logo {
            width: 72px; height: 72px;
            border-radius: 16px;
            object-fit: cover;
            padding: 4px;
            background: white;
            box-shadow: 0 4px 12px -4px rgba(39, 114, 69, 0.25), 0 0 0 1px rgba(180, 142, 99, 0.35);
        }
        .receipt-title { font-size: 22px; margin: 12px 0 4px; color: #1b5733; font-weight: 700; }
        .receipt-meta { color: #64748b; font-size: 12px; }
        .receipt-id {
            display: inline-block;
            background: #e8f1ec;
            color: #1b5733;
            padding: 4px 12px;
            border-radius: 999px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.5px;
            margin-top: 6px;
        }
        .receipt-body { margin-top: 18px; }
        .row {
            display: flex; justify-content: space-between; align-items: center;
            padding: 12px 0; border-bottom: 1px dashed #e8e1d2; font-size: 14px;
        }
        .row .key { color: #64748b; font-weight: 500; }
        .row .val { font-weight: 600; color: #0f172a; text-align: end; }
        .total-row {
            margin-top: 16px;
            padding: 18px 20px;
            background: linear-gradient(135deg, #e8f1ec 0%, #f5ede1 100%);
            border-radius: 12px;
            border: 1px solid rgba(39, 114, 69, 0.18);
            display: flex; justify-content: space-between; align-items: center;
        }
        .total-row .total-label { color: #1b5733; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; font-size: 13px; }
        .total-row .total-value { font-size: 28px; font-weight: 800; color: #1b5733; }
        .stamp {
            margin-top: 24px; text-align: center;
            color: #94a3b8; font-size: 11px;
            border-top: 1px dashed #e8e1d2;
            padding-top: 16px;
        }
        .stamp strong { color: #277245; }
        .actions { text-align: center; margin-top: 18px; display: flex; gap: 8px; justify-content: center; }
        .actions .btn { padding: 10px 20px; font-size: 14px; }
        @media print {
            body { background: white; padding: 0; }
            .receipt-shell { max-width: none; }
            .receipt { box-shadow: none; border: 0; padding: 0; border-radius: 0; }
            .receipt::before { display: none; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
<div class="receipt-shell">
    <div class="receipt">
        <div class="receipt-header">
            <img src="{{ asset('assets/img/logo.jpeg') }}" alt="Al Boukhari" class="receipt-logo">
            <h1 class="receipt-title">{{ __('school_name') }}</h1>
            <div class="receipt-meta">{{ __('exports.receipt') }}</div>
            <div class="receipt-id">#{{ str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT) }}</div>
        </div>

        <div class="receipt-body">
            <div class="row">
                <span class="key">{{ __('panel.tabs.profile') }}</span>
                <span class="val">{{ $payment->student?->name ?? '—' }}</span>
            </div>
            @if($payment->student?->family?->guardian_name)
                <div class="row">
                    <span class="key">{{ __('family.title') }}</span>
                    <span class="val">{{ $payment->student->family->guardian_name }}</span>
                </div>
            @endif
            <div class="row">
                <span class="key">{{ __('payment.date') }}</span>
                <span class="val">{{ $payment->paid_at?->format('Y-m-d') }}</span>
            </div>
            <div class="row">
                <span class="key">{{ __('reports.month') }}</span>
                <span class="val">{{ ($months[$payment->period_month] ?? $payment->period_month) }} {{ $payment->period_year }}</span>
            </div>
            <div class="row">
                <span class="key">{{ __('payment.method') }}</span>
                <span class="val">{{ $payment->methodIcon() }} {{ $payment->methodLabel() }}</span>
            </div>
            @if($payment->note)
                <div class="row">
                    <span class="key">{{ __('payment.note') }}</span>
                    <span class="val">{{ $payment->note }}</span>
                </div>
            @endif
        </div>

        <div class="total-row">
            <span class="total-label">{{ __('payment.amount') }}</span>
            <span class="total-value">{{ number_format((float)$payment->amount, 2) }} €</span>
        </div>

        <div class="stamp">
            {{ now()->format('Y-m-d H:i') }} · <strong>{{ __('school_name') }}</strong>
        </div>
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
