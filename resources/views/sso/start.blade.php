<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Single sign-on — Autnyx</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: #f5f5f7; color: #1c1c1e; padding: 1.5rem;
        }
        @media (prefers-color-scheme: dark) { body { background: #0b0b0f; color: #f2f2f7; } }
        .card {
            width: 100%; max-width: 400px; background: #fff; border-radius: 16px;
            box-shadow: 0 10px 40px rgba(0,0,0,.08); padding: 2rem;
        }
        @media (prefers-color-scheme: dark) { .card { background: #17171c; box-shadow: 0 10px 40px rgba(0,0,0,.4); } }
        .brand { font-weight: 800; font-size: 1.25rem; color: #6d28d9; margin-bottom: .25rem; }
        h1 { font-size: 1.05rem; margin: 0 0 1.25rem; font-weight: 600; }
        label { display: block; font-size: .8rem; font-weight: 600; margin-bottom: .4rem; }
        input[type=email] {
            width: 100%; padding: .7rem .85rem; border-radius: 10px; border: 1px solid #d1d1d6;
            font-size: .95rem; background: transparent; color: inherit;
        }
        input[type=email]:focus { outline: 2px solid #8b5cf6; border-color: transparent; }
        button {
            width: 100%; margin-top: 1rem; padding: .75rem; border: 0; border-radius: 10px;
            background: #6d28d9; color: #fff; font-weight: 600; font-size: .95rem; cursor: pointer;
        }
        button:hover { background: #5b21b6; }
        .error { background: #fdecec; color: #b42318; border-radius: 10px; padding: .65rem .8rem; font-size: .82rem; margin-bottom: 1rem; }
        @media (prefers-color-scheme: dark) { .error { background: #3a1613; color: #f7b4ab; } }
        .alt { margin-top: 1.25rem; font-size: .82rem; text-align: center; }
        .alt a { color: #6d28d9; text-decoration: none; }
        .hint { margin-top: .5rem; font-size: .75rem; color: #8e8e93; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">Autnyx</div>
        <h1>Sign in with your organisation</h1>

        @php $err = $error ?? session('error'); @endphp
        @if ($err)
            <div class="error">{{ $err }}</div>
        @endif

        <form method="POST" action="{{ route('sso.discover') }}">
            @csrf
            <label for="email">Work email</label>
            <input id="email" type="email" name="email" placeholder="you@company.com" required autofocus>
            <button type="submit">Continue</button>
            <div class="hint">We’ll route you to your organisation’s identity provider.</div>
        </form>

        <div class="alt">
            <a href="/admin/login">Sign in with a password instead</a>
        </div>
    </div>
</body>
</html>
