@php
    /** @var \Throwable|null $exception */
    $status = (int) ($status ?? 500);
    $original = isset($exception) ? ($exception->getPrevious() ?? $exception) : null;
    $reference = $status >= 500 && $original !== null
        ? app(\App\Exceptions\ErrorPresenter::class)->referenceFor($original)
        : null;
    $titleKey = "errors.titles.{$status}";
    $messageKey = "errors.http.{$status}";
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $status }} – {{ __(Lang::has($titleKey) ? $titleKey : 'errors.titles.default') }}</title>
    <style>
        :root { --bg: #f8fafc; --card: #ffffff; --text: #0f172a; --muted: #64748b; --accent: #4f46e5; --border: #e2e8f0; }
        @media (prefers-color-scheme: dark) {
            :root { --bg: #0b1120; --card: #111827; --text: #f1f5f9; --muted: #94a3b8; --accent: #818cf8; --border: #1f2937; }
        }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 16px;
               background: var(--bg); color: var(--text); font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
        main { width: 100%; max-width: 480px; background: var(--card); border: 1px solid var(--border);
               border-radius: 12px; padding: 32px; text-align: center; }
        .code { font-size: 14px; font-weight: 600; letter-spacing: .08em; color: var(--accent); }
        h1 { margin: 8px 0 12px; font-size: 22px; }
        p { margin: 0 0 24px; color: var(--muted); }
        .ref { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 13px; color: var(--muted); margin-bottom: 24px; }
        a { display: inline-block; padding: 10px 18px; border-radius: 8px; background: var(--accent); color: #fff; text-decoration: none; font-weight: 500; }
    </style>
</head>
<body>
<main>
    <div class="code">{{ $status }}</div>
    <h1>{{ __(Lang::has($titleKey) ? $titleKey : 'errors.titles.default') }}</h1>
    <p>{{ __(Lang::has($messageKey) ? $messageKey : ($status >= 500 ? 'errors.http.500' : 'errors.http.400')) }}</p>
    @if ($reference)
        <div class="ref">{{ __('errors.reference', ['reference' => $reference]) }}</div>
    @endif
    <a href="{{ $status === 419 ? url()->current() : url('/admin') }}">{{ $status === 419 ? __('errors.links.reload') : __('errors.links.home') }}</a>
</main>
</body>
</html>
