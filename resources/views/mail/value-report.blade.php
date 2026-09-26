<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Value delivered — {{ $tenant->name }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#111827;">
<div style="max-width:640px;margin:32px auto;background:#fff;border-radius:12px;overflow:hidden;">
  <div style="background:#0f766e;padding:28px 36px;">
    <h1 style="color:#fff;font-size:21px;margin:0;">Value delivered — {{ $monthLabel }}</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:14px;">{{ $tenant->name }}</p>
  </div>
  <div style="padding:24px 36px;">
    <table style="width:100%;border-collapse:collapse;font-size:14px;">
      @foreach($report['kpis'] ?? [] as $kpi)
      <tr>
        <td style="padding:8px 0;border-bottom:1px solid #f3f4f6;color:#6b7280;">{{ $kpi['label'] }}</td>
        <td style="padding:8px 0;border-bottom:1px solid #f3f4f6;text-align:right;font-weight:700;">{{ $kpi['value'] }}</td>
      </tr>
      @endforeach
    </table>
    @if(!empty($report['note']))
      <p style="margin:16px 0 0;font-size:12px;color:#6b7280;line-height:1.5;">{{ $report['note'] }}</p>
    @endif
    <p style="margin:16px 0 0;font-size:14px;">The full report is attached (PDF).</p>
  </div>
  <div style="padding:0 36px 28px;">
    <a href="{{ $panelUrl }}" style="display:inline-block;background:#0f766e;color:#fff;text-decoration:none;padding:11px 26px;border-radius:8px;font-weight:600;">Open reports in Autnyx</a>
  </div>
  <div style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:16px 36px;font-size:12px;color:#9ca3af;">
    Sent on the first day of each month to {{ $tenant->name }}'s administrators. An administrator can turn it off in the tenant settings.
  </div>
</div>
</body>
</html>
