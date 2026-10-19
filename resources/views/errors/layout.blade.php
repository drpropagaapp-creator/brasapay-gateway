@php
    $appName = (string) config('getfy.app_name', config('app.name', 'Paynext'));
    $nameParts = preg_match('/^(.+?[a-zà-ú])([A-ZÀ-Ú].*)$/u', $appName, $m) ? [$m[1], $m[2]] : [$appName, ''];
    $favicon = config('getfy.favicon_url') ?: '/images/favicon.png';
    $logo = config('getfy.app_logo');
    $logoDark = config('getfy.app_logo_dark') ?: $logo;
@endphp
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.panel-theme-init')
    <title>@yield('code') · {{ $appName }}</title>
    <link rel="icon" href="{{ $favicon }}">
    @vite(['resources/css/app.css'])
    <style>
        .pn-error { min-height: 100vh; display: grid; place-items: center; padding: 24px; }
        .pn-error__card { width: min(560px, 100%); padding: 44px 40px; border-radius: 40px; display: flex; flex-direction: column; gap: 24px; }
        .pn-error__code { font: 400 96px/1 var(--font-display); letter-spacing: -0.05em; color: var(--text-primary); }
        .pn-error__title { font: var(--text-h3); letter-spacing: var(--tracking-heading); }
        .pn-error__text { margin-top: 10px; font: var(--text-body-m); color: var(--text-secondary); }
        .pn-error__actions { display: flex; flex-wrap: wrap; gap: 12px; }
        .pn-orb { position: fixed; border-radius: 50%; filter: blur(70px); opacity: .5; pointer-events: none; z-index: -1; }
    </style>
</head>
<body>
    <span class="pn-orb" style="width:46vw;height:46vw;left:-12vw;top:-18vw;background:var(--orb-1)"></span>
    <span class="pn-orb" style="width:40vw;height:40vw;right:-14vw;top:-8vw;background:var(--orb-2);animation-delay:-7s"></span>
    <main class="pn-error">
        <div class="pn-glass pn-glass--strong pn-anim pn-error__card">
            @if($logo)
                <span>
                    <img src="{{ $logo }}" alt="{{ $appName }}" class="h-9 w-auto object-contain dark:hidden">
                    <img src="{{ $logoDark }}" alt="{{ $appName }}" class="hidden h-9 w-auto object-contain dark:block">
                </span>
            @else
                <span class="pn-wordmark" style="font-size:22px">{{ $nameParts[0] }}@if($nameParts[1] !== '')<b>{{ $nameParts[1] }}</b>@endif<i></i></span>
            @endif
            <div>
                <div class="pn-error__code">@yield('code')<span class="pn-dot">.</span></div>
            </div>
            <div>
                <h1 class="pn-error__title">@yield('title')</h1>
                <p class="pn-error__text">@yield('message')</p>
            </div>
            <div class="pn-error__actions">
                <a href="/" class="pn-btn pn-btn--primary" style="text-decoration:none">Ir para o início</a>
                <a href="javascript:history.back()" class="pn-btn pn-btn--secondary" style="text-decoration:none">Voltar</a>
            </div>
        </div>
    </main>
</body>
</html>
