<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Payslip {{ $row['unique_id'] ?? '' }} {{ $row['month'] ?? '' }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #111; font-size: 13px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        h2 { font-size: 14px; margin: 18px 0 8px; }
        .muted { color: #555; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #333; font-size: 11px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { text-align: left; padding: 6px 0; border-bottom: 1px solid #ddd; }
        th { color: #555; font-weight: normal; }
        td.num, th.num { text-align: right; }
        .total td { font-weight: bold; border-bottom: none; font-size: 15px; }
    </style>
</head>
<body>
    <h1>Anaya payslip</h1>
    <p class="muted">{{ \Illuminate\Support\Carbon::createFromFormat('Y-m', $row['month'])->timezone('Asia/Kolkata')->format('F Y') }}</p>
    @if(!empty($row['frozen']))
        <p><span class="badge">Frozen</span>
            @if(!empty($row['frozen_at']))
                {{ \Illuminate\Support\Carbon::parse($row['frozen_at'])->timezone('Asia/Kolkata')->format('d M Y H:i') }} IST
            @endif
        </p>
    @else
        <p class="muted">Live figure — not frozen</p>
    @endif

    <table>
        <tr><th>Employee</th><td>{{ $row['name'] ?? '' }}</td></tr>
        <tr><th>Employee ID</th><td>{{ $row['unique_id'] ?? '' }}</td></tr>
        <tr><th>Month</th><td>{{ $row['month'] ?? '' }}</td></tr>
    </table>

    <h2>Earnings</h2>
    <table>
        <tr><th>Base salary</th><td class="num">{{ number_format((float) ($row['base'] ?? 0), 2) }}</td></tr>
        <tr><th>Paid leave used</th><td class="num">{{ $row['paid_leave_used'] ?? 0 }} / {{ $row['paid_leave_quota'] ?? 1 }} day</td></tr>
        <tr><th>Unpaid leave</th><td class="num">{{ $row['unpaid_leave_days'] ?? 0 }} day(s)</td></tr>
        <tr><th>Leave deduction</th><td class="num">− {{ number_format((float) ($row['leave_deduction'] ?? 0), 2) }}</td></tr>
        <tr><th>Overtime hours</th><td class="num">{{ number_format((float) ($row['overtime_hours'] ?? 0), 2) }}</td></tr>
        <tr><th>Overtime pay (2×)</th><td class="num">{{ number_format((float) ($row['overtime_pay'] ?? 0), 2) }}</td></tr>
        <tr class="total"><td>Net pay</td><td class="num">{{ number_format((float) ($row['net'] ?? 0), 2) }}</td></tr>
    </table>
</body>
</html>
