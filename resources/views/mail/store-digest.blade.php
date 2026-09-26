<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Your store — {{ $tenant->name }}</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;color:#111827;">
<div style="max-width:640px;margin:24px auto;background:#fff;border-radius:12px;overflow:hidden;">
  <div style="background:#6d28d9;padding:24px 32px;">
    <h1 style="color:#fff;font-size:20px;margin:0;">{{ implode(', ', $d['store_names'] ?? []) }}</h1>
    <p style="color:rgba(255,255,255,.8);margin:4px 0 0;font-size:14px;">{{ $tenant->name }} · {{ now()->format('l j F') }}</p>
  </div>

  <div style="padding:20px 32px 4px;">
    <table style="width:100%;border-collapse:collapse;text-align:center;">
      <tr>
        <td style="padding:10px;background:#faf5ff;border-radius:8px;"><div style="font-size:26px;font-weight:800;">{{ $d['finding_count'] ?? 0 }}</div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">to check</div></td>
        <td style="width:10px;"></td>
        <td style="padding:10px;background:#faf5ff;border-radius:8px;"><div style="font-size:26px;font-weight:800;">{{ $d['count_total'] ?? 0 }}</div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">to count</div></td>
        <td style="width:10px;"></td>
        <td style="padding:10px;background:#f0fdf4;border-radius:8px;"><div style="font-size:26px;font-weight:800;color:#15803d;">{{ $money($d['recovered_mtd'] ?? 0) }}</div><div style="font-size:11px;color:#6b7280;text-transform:uppercase;">recovered this month</div></td>
      </tr>
    </table>
  </div>

  <div style="padding:16px 32px;text-align:center;">
    <a href="{{ $sheetUrl }}" style="display:inline-block;background:#6d28d9;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:700;">Open your store sheet</a>
    <div style="font-size:12px;color:#6b7280;margin-top:6px;">Confirm findings and enter your counts — no password needed. The link works for {{ \App\Services\Stores\StoreDigestService::LINK_DAYS }} days.</div>
  </div>

  @if(! empty($d['findings']) && count($d['findings']))
  <div style="padding:8px 32px 16px;">
    <h2 style="font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin:0 0 8px;">To check{{ ($d['new_count'] ?? 0) ? ' · ' . $d['new_count'] . ' new' : '' }}</h2>
    @foreach($d['findings'] as $a)
      <div style="border-top:1px solid #f3f4f6;padding:10px 0;">
        <div style="font-size:12px;color:#6b7280;">
          <strong style="color:{{ $a->severity === 'high' ? '#dc2626' : '#d97706' }};text-transform:uppercase;">{{ $a->severity }}</strong>
          · {{ $ruleLabel($a) }} · {{ $d['store_names'][$a->store_id] ?? '' }}
          @if(($d['since'] ?? null) === null || ($a->detected_at && $d['since'] && $a->detected_at->gt($d['since']))) · <strong style="color:#6d28d9;">NEW</strong>@endif
        </div>
        <div style="font-size:14px;line-height:1.45;margin-top:2px;">{{ $a->description }}</div>
        <div style="font-size:12px;margin-top:4px;color:#6b7280;">
          @if($a->feedback)
            You said: <strong>{{ $a->feedback === 'real' ? 'real' : 'not real' }}</strong>
          @else
            Real problem? <a href="{{ $feedbackUrl($a, 'real') }}" style="color:#16a34a;font-weight:700;text-decoration:none;">👍 Yes</a> ·
            <a href="{{ $feedbackUrl($a, 'not_real') }}" style="color:#dc2626;font-weight:700;text-decoration:none;">👎 No</a>
          @endif
        </div>
      </div>
    @endforeach
    @if(($d['finding_count'] ?? 0) > count($d['findings']))
      <div style="font-size:12px;color:#6b7280;padding-top:6px;">…and {{ $d['finding_count'] - count($d['findings']) }} more on your store sheet.</div>
    @endif
  </div>
  @endif

  @if(! empty($d['counts']) && count($d['counts']))
  <div style="padding:8px 32px 20px;">
    <h2 style="font-size:13px;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin:0 0 8px;">To count</h2>
    <table style="width:100%;border-collapse:collapse;font-size:13px;">
      @foreach($d['counts']->take(10) as $c)
      <tr style="border-top:1px solid #f3f4f6;">
        <td style="padding:6px 0;font-family:monospace;">{{ $c->sku }}</td>
        <td style="padding:6px 0;color:#6b7280;">{{ $c->reasonLabel() }}</td>
        <td style="padding:6px 0;text-align:right;">system says {{ rtrim(rtrim(number_format((float) $c->system_qty, 2), '0'), '.') ?: '0' }}</td>
      </tr>
      @endforeach
    </table>
    <div style="font-size:12px;color:#6b7280;padding-top:6px;">Enter what you find on your <a href="{{ $sheetUrl }}" style="color:#6d28d9;">store sheet</a>.</div>
  </div>
  @endif

  <div style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:14px 32px;font-size:12px;color:#9ca3af;">
    You get this because you are linked to {{ implode(', ', $d['store_names'] ?? []) }} in Autnyx. <a href="{{ $unsubscribe }}" style="color:#6d28d9;">Stop the store digest</a>.
  </div>
</div>
</body>
</html>
