<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subjectLine }}</title>
<style>
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif; background: #f3f4f6; color: #111827; }
  .wrapper { max-width: 560px; margin: 32px auto; background: #fff; border-radius: 12px; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.1); }
  .header { background: linear-gradient(135deg, #14808c 0%, #0f2740 100%); padding: 28px 40px; }
  .header .brand { color: rgba(255,255,255,.7); font-size: 12px; font-weight: 700; letter-spacing: 3px; text-transform: uppercase; }
  .header h1 { color: #fff; font-size: 20px; font-weight: 700; letter-spacing: -0.2px; margin-top: 6px; }
  .content { padding: 28px 40px; }
  .content .greeting { font-size: 15px; color: #374151; margin-bottom: 14px; }
  .content .body { font-size: 15px; line-height: 1.6; color: #374151; white-space: pre-line; }
  .btn-wrap { padding: 8px 40px 32px; }
  .btn { display: inline-block; background: #14808c; color: #fff !important; text-decoration: none; padding: 12px 26px; border-radius: 8px; font-weight: 600; font-size: 14px; }
  .footer { padding: 20px 40px 32px; border-top: 1px solid #f3f4f6; }
  .footer p { font-size: 12px; color: #9ca3af; line-height: 1.5; }
</style>
</head>
<body>
  <div class="wrapper">
    <div class="header">
      <div class="brand">Autnyx</div>
      <h1>{{ $subjectLine }}</h1>
    </div>

    <div class="content">
      @if($greetingName)
        <p class="greeting">Hi {{ $greetingName }},</p>
      @endif
      @if($bodyText)
        <p class="body">{{ $bodyText }}</p>
      @endif
    </div>

    @if($actionUrl)
      <div class="btn-wrap">
        <a class="btn" href="{{ $actionUrl }}">Open in Autnyx →</a>
      </div>
    @endif

    <div class="footer">
      <p>You're receiving this because you're a member of an Autnyx workspace. Manage what reaches you from your notification settings in the console.</p>
    </div>
  </div>
</body>
</html>
