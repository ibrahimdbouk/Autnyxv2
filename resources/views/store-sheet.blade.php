<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Store sheet — {{ $storeNames->implode(', ') }}</title>
<style>
  :root { --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#fff; --page:#f3f4f6; --brand:#6d28d9; --ok:#15803d; --bad:#dc2626; }
  @media (prefers-color-scheme: dark) { :root { --ink:#f3f4f6; --muted:#9ca3af; --line:#374151; --bg:#111827; --page:#030712; } }
  * { box-sizing:border-box; }
  body { margin:0; background:var(--page); color:var(--ink); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; }
  .wrap { max-width:46rem; margin:0 auto; padding:1rem; }
  .card { background:var(--bg); border:1px solid var(--line); border-radius:.9rem; padding:1rem 1.1rem; margin-bottom:1rem; }
  h1 { font-size:1.25rem; margin:.25rem 0; } h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:0 0 .6rem; }
  .muted { color:var(--muted); font-size:.85rem; line-height:1.45; }
  .f { border-top:1px solid var(--line); padding:.7rem 0; } .f:first-of-type { border-top:0; }
  .tag { font-size:.7rem; font-weight:800; text-transform:uppercase; } .high { color:var(--bad); } .medium { color:#d97706; } .low { color:var(--muted); }
  .btns { display:flex; gap:.5rem; margin-top:.45rem; flex-wrap:wrap; }
  button { border:1px solid var(--line); background:var(--bg); color:var(--ink); border-radius:.55rem; padding:.5rem .9rem; font-size:.9rem; font-weight:600; cursor:pointer; }
  button.primary { background:var(--brand); color:#fff; border-color:transparent; width:100%; padding:.75rem; }
  .ok { color:var(--ok); font-weight:700; } .no { color:var(--bad); font-weight:700; }
  table { width:100%; border-collapse:collapse; font-size:.88rem; } td, th { padding:.5rem .3rem; border-top:1px solid var(--line); text-align:left; vertical-align:middle; }
  th { font-size:.68rem; text-transform:uppercase; color:var(--muted); border-top:0; }
  input { width:5.5rem; font-size:1rem; padding:.45rem; border:1px solid var(--line); border-radius:.45rem; background:var(--bg); color:var(--ink); }
  .flash { background:#ecfdf5; color:#065f46; border:1px solid #a7f3d0; border-radius:.6rem; padding:.6rem .8rem; margin-bottom:1rem; }
  .mono { font-family:ui-monospace,monospace; }
</style>
</head>
<body>
<div class="wrap">
  <div class="muted">{{ $tenant->name }} · {{ now()->format('l j F') }}</div>
  <h1>{{ $stores->map(fn ($s) => \App\Services\Stores\StoreDigestService::storeLabel($s))->implode(', ') }}</h1>
  <div class="muted" style="margin-bottom:1rem">Signed in by link as {{ $user->name }}. Your answers are saved against your name.</div>

  @if(session('sheet_message'))<div class="flash">{{ session('sheet_message') }}</div>@endif

  <div class="card">
    <h2>To check ({{ $findings->count() }})</h2>
    @forelse($findings as $a)
      <div class="f">
        <div><span class="tag {{ $a->severity }}">{{ $a->severity }}</span> <span class="muted">· {{ $a->getRuleLabel() }} · {{ $storeNames[$a->store_id] ?? '' }}</span></div>
        <div style="margin-top:.2rem;line-height:1.45">{{ $a->description }}</div>
        @if($a->feedback)
          <div class="muted" style="margin-top:.35rem">You said: <span class="{{ $a->feedback === 'real' ? 'ok' : 'no' }}">{{ $a->feedback === 'real' ? 'real' : 'not real' }}</span></div>
        @else
          <form method="post" action="{{ $action }}" class="btns">
            @csrf
            <input type="hidden" name="do" value="feedback"><input type="hidden" name="anomaly" value="{{ $a->id }}">
            <button type="submit" name="verdict" value="real">👍 Real problem</button>
            <button type="submit" name="verdict" value="not_real">👎 Not real</button>
          </form>
        @endif
      </div>
    @empty
      <div class="muted">Nothing to check at your store right now.</div>
    @endforelse
  </div>

  <div class="card">
    <h2>To count ({{ $counts->count() }})</h2>
    @if($counts->isEmpty())
      <div class="muted">Nothing to count right now.</div>
    @else
      <div class="muted" style="margin-bottom:.5rem">Count what is physically there (shelf and back room) and enter it. Leave a line blank if you did not count it.</div>
      <form method="post" action="{{ $action }}">
        @csrf
        <input type="hidden" name="do" value="counts">
        <table>
          <thead><tr><th>SKU</th><th>Product</th><th>System</th><th>Counted</th></tr></thead>
          <tbody>
          @foreach($counts as $c)
            <tr>
              <td class="mono">{{ $c->sku }}</td>
              <td>{{ $names[$c->sku] ?? '' }}<div class="muted" style="font-size:.75rem">{{ $c->reasonLabel() }}@if($stores->count() > 1) · {{ $storeNames[$c->store_id] ?? '' }}@endif</div></td>
              <td>{{ rtrim(rtrim(number_format((float) $c->system_qty, 2), '0'), '.') ?: '0' }}</td>
              <td><input type="text" inputmode="decimal" name="counted[{{ $c->id }}]" aria-label="Counted quantity for {{ $c->sku }}"></td>
            </tr>
          @endforeach
          </tbody>
        </table>
        <div style="margin-top:.8rem"><button type="submit" class="primary">Save counts</button></div>
      </form>
    @endif
  </div>

  @if($counted->isNotEmpty())
  <div class="card">
    <h2>Counted this week</h2>
    <table>
      <thead><tr><th>SKU</th><th>System</th><th>Counted</th><th>Difference</th></tr></thead>
      <tbody>
      @foreach($counted as $c)
        <tr><td class="mono">{{ $c->sku }}</td>
          <td>{{ rtrim(rtrim(number_format((float) $c->system_qty, 2), '0'), '.') ?: '0' }}</td>
          <td>{{ rtrim(rtrim(number_format((float) $c->counted_qty, 2), '0'), '.') ?: '0' }}</td>
          <td class="{{ $c->variance_qty < 0 ? 'no' : '' }}">{{ $c->variance_qty > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format((float) $c->variance_qty, 2), '0'), '.') ?: '0' }} · {{ $money(abs((float) $c->variance_value)) }}</td></tr>
      @endforeach
      </tbody>
    </table>
  </div>
  @endif
  <div class="muted" style="text-align:center;padding:1rem 0">Autnyx · this link works for 7 days from your digest.</div>
</div>
</body>
</html>
