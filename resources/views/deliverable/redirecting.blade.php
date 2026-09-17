<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta http-equiv="refresh" content="0;url={{ $continueUrl }}">
    <title>Abrindo conteúdo</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #f8fafc; color: #0f172a; display: flex; min-height: 100vh; align-items: center; justify-content: center; margin: 0; }
        .box { text-align: center; padding: 24px; }
        a { color: #0369a1; }
        p { line-height: 1.5; }
    </style>
</head>
<body>
    <div class="box">
        <p>Abrindo o conteúdo de <strong>{{ $productName }}</strong>…</p>
        <p><a href="{{ $continueUrl }}">Continuar</a></p>
    </div>
    <script>window.location.replace(@json($continueUrl));</script>
</body>
</html>
