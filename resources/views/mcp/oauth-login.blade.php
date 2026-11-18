<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Entrar para autorizar o MCP</title>
    <style>
        body { font-family: ui-sans-serif, system-ui, sans-serif; background: #f4f4f5; color: #18181b; margin: 0; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px; }
        .card { width: 100%; max-width: 440px; background: #fff; border: 1px solid #e4e4e7; border-radius: 16px; padding: 28px; }
        h1 { font-size: 1.25rem; margin: 0 0 8px; }
        p { font-size: 0.95rem; line-height: 1.5; color: #52525b; margin: 0 0 12px; }
        .actions { display: flex; flex-direction: column; gap: 8px; margin-top: 20px; }
        a.btn { display: block; text-align: center; text-decoration: none; border-radius: 10px; padding: 11px 16px; font-weight: 600; }
        .primary { background: #0050fc; color: #fff; }
        .secondary { background: #f4f4f5; color: #3f3f46; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card">
            <h1>Entre para conectar o MCP</h1>
            <p><strong>{{ $clientName }}</strong> quer se conectar à sua conta. Entre com a conta que vai usar; depois do login você volta para esta autorização.</p>
            <div class="actions">
                <a class="btn primary" href="{{ url('/login') }}">Sou infoprodutor</a>
                <a class="btn secondary" href="{{ url('/plataforma/login') }}">Sou administrador da plataforma</a>
            </div>
        </div>
    </div>
</body>
</html>
