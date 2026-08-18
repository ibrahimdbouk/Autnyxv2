<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Anomaly Alert — {{ $tenant->name }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f3f4f6; color: #111827; }
  .wrapper { max-width: 640px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .header { background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%); padding: 32px 40px; }
  .header h1 { color: #fff; font-size: 22px; font-weight: 700; letter-spacing: -0.3px; }
  .header p { color: rgba(255,255,255,.75); margin-top: 4px; font-size: 14px; }
  .summary { display: flex; gap: 16px; padding: 24px 40px; border-bottom: 1px solid #f3f4f6; }
  .stat { flex: 1; text-align: center; background: #fafafa; border-radius: 8px; padding: 16px; }
  .stat .num { font-size: 32px; font-weight: 800; line-height: 1; }
  .stat .label { font-size: 12px; color: #6b7280; margin-top: 4px; text-transform: uppercase; letter-spacing: .5px; }
  .stat.high .num   { color: #dc2626; }
  .stat.medium .num { color: #d97706; }
  .content { padding: 24px 40px; }
  .content h2 { font-size: 13px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: .5px; margin-bottom: 16px; }
  table { width: 100%; border-collapse: collapse; font-size: 14px; }
  th { text-align: left; font-size: 11px; font-weight: 600; color: #9ca3af; text-transform: uppercase; letter-spacing: .4px; padding: 0 0 8px; border-bottom: 1px solid #e5e7eb; }
  td { padding: 12px 0; border-bottom: 1px solid #f3f4f6; vertical-align: top; }
  td:first-child { width: 80px; }
  td:nth-child(2) { width: 130px; }
  .badge { display: inline-block; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; text-transform: capitalize; }
  .badge-high   { background: #fee2e2; color: #dc2626; }
  .badge-medium { background: #fef3c7; color: #d97706; }
  .badge-low    { background: #dbeafe; color: #2563eb; }
  .rule-badge { background: #f3f4f6; color: #4b5563; font-size: 11px; font-weight: 500; padding: 2px 7px; border-radius: 4px; }
  .sku { font-family: monospace; font-size: 12px; color: #6b7280; margin-top: 2px; }
  .desc { color: #374151; line-height: 1.5; }
  .cta { padding: 24px 40px 32px; text-align: center; }
  .cta a { display: inline-block; background: #7c3aed; color: #fff; text-decoration: none; padding: 12px 32px; border-radius: 8px; font-weight: 600; font-size: 15px; }
  .cta a:hover { background: #6d28d9; }
  .footer { background: #f9fafb; border-top: 1px solid #e5e7eb; padding: 20px 40px; font-size: 12px; color: #9ca3af; text-align: center; }
  .footer a { color: #7c3aed; text-decoration: none; }
</style>
</head>
<body>
<div class="wrapper">

  {{-- Header --}}
  <div class="header">
    <h1>Anomaly Alert</h1>
    <p>{{ $tenant->name }} · {{ now()->format('l, F j, Y') }}</p>
  </div>

  {{-- Summary counts --}}
  <div class="summary">
    @if($highCount > 0)
    <div class="stat high">
      <div class="num">{{ $highCount }}</div>
      <div class="label">High Severity</div>
    </div>
    @endif
    @if($mediumCount > 0)
    <div class="stat medium">
      <div class="num">{{ $mediumCount }}</div>
      <div class="label">Medium Severity</div>
    </div>
    @endif
    <div class="stat">
      <div class="num">{{ $anomalies->count() }}</div>
      <div class="label">Total Anomalies</div>
    </div>
  </div>

  {{-- Anomaly table --}}
  <div class="content">
    <h2>New Anomalies Detected</h2>
    <table>
      <thead>
        <tr>
          <th>Severity</th>
          <th>Rule</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        @foreach($anomalies as $anomaly)
        <tr>
          <td>
            <span class="badge badge-{{ $anomaly->severity }}">{{ $anomaly->severity }}</span>
          </td>
          <td>
            <span class="rule-badge">{{ $ruleLabel($anomaly->rule_type) }}</span>
            @if($anomaly->sku)
            <div class="sku">{{ $anomaly->sku }}</div>
            @endif
          </td>
          <td class="desc">{{ $anomaly->description }}</td>
        </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  {{-- CTA --}}
  <div class="cta">
    <a href="{{ $panelUrl }}">View &amp; Investigate in Autnyx →</a>
  </div>

  {{-- Footer --}}
  <div class="footer">
    <p>You're receiving this because anomaly notifications are enabled for <strong>{{ $tenant->name }}</strong>.</p>
    <p style="margin-top:4px;">To adjust your alert preferences, visit <a href="{{ $panelUrl }}">Autnyx Settings</a>.</p>
  </div>

</div>
</body>
</html>
