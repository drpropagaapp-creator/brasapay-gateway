<?php
/**
 * Lê o override da VPS (JSON e/ou YAML Compose) no formato
 * services.app.environment / services.queue.environment.
 * Decodifica $$ do Compose para o valor literal. Não imprime segredos.
 *
 * Uso: php parse-vps-db-override.php [json] [yml] [outdir]
 * Exit 0: escreve username, password, database, restore.yml em outdir.
 * Exit 2: app e queue divergem.
 * Exit 1: formato inválido.
 */
declare(strict_types=1);

$jsonPath = $argv[1] ?? '';
$ymlPath = $argv[2] ?? '';
$outDir = $argv[3] ?? '';
if ($outDir === '' || !is_dir($outDir)) {
    fwrite(STDERR, "outdir ausente\n");
    exit(1);
}

function decode_compose_dollars(string $v): string
{
    return str_replace('$$', '$', $v);
}

function normalize_env($raw): array
{
    $out = [];
    if (!is_array($raw)) {
        return $out;
    }
    $isList = array_keys($raw) === range(0, count($raw) - 1);
    if ($isList) {
        foreach ($raw as $item) {
            if (!is_string($item) || !str_contains($item, '=')) {
                continue;
            }
            [$k, $v] = explode('=', $item, 2);
            $k = trim($k);
            if ($k !== '') {
                $out[$k] = decode_compose_dollars(trim($v, " \t\"'"));
            }
        }
        return $out;
    }
    foreach ($raw as $k => $v) {
        if (!is_string($k) || !is_scalar($v)) {
            continue;
        }
        $out[$k] = decode_compose_dollars(trim((string) $v, " \t\"'"));
    }
    return $out;
}

function db_fields(array $env): array
{
    $u = $env['GETFY_DB_USERNAME'] ?? $env['DB_USERNAME'] ?? '';
    $p = $env['GETFY_DB_PASSWORD'] ?? $env['DB_PASSWORD'] ?? '';
    $d = $env['GETFY_DB_DATABASE'] ?? $env['DB_DATABASE'] ?? '';
    $h = $env['GETFY_DB_HOST'] ?? $env['DB_HOST'] ?? '';
    return [
        'username' => is_string($u) ? $u : '',
        'password' => is_string($p) ? $p : '',
        'database' => is_string($d) ? $d : '',
        'host' => is_string($h) ? $h : '',
    ];
}

function parse_yaml_service_env(string $yaml): array
{
    $result = ['app' => [], 'queue' => []];
    $svc = null;
    $inEnv = false;
    $envIndent = 0;
    foreach (preg_split('/\R/', $yaml) as $line) {
        if (preg_match('/^(\s*)([A-Za-z0-9_-]+):\s*(.*)$/', $line, $m)) {
            $indent = strlen(str_replace("\t", '  ', $m[1]));
            $key = $m[2];
            $rest = trim($m[3]);
            if ($indent <= 4 && in_array($key, ['app', 'queue'], true) && ($rest === '' || $rest === '|')) {
                $svc = $key;
                $inEnv = false;
                continue;
            }
            if ($svc !== null && $key === 'environment' && $rest === '') {
                $inEnv = true;
                $envIndent = $indent;
                continue;
            }
            if ($inEnv && $indent <= $envIndent) {
                $inEnv = false;
                if (in_array($key, ['app', 'queue'], true) && $rest === '') {
                    $svc = $key;
                }
            }
            if ($inEnv && $svc !== null && $indent > $envIndent && $rest !== '') {
                $result[$svc][$key] = decode_compose_dollars(trim($rest, " \t\"'"));
            }
            continue;
        }
        if ($inEnv && $svc !== null && preg_match('/^\s+-\s*([A-Z0-9_]+)=(.*)$/', $line, $m)) {
            $result[$svc][$m[1]] = decode_compose_dollars(trim($m[2], " \t\"'"));
        }
    }
    return $result;
}

function env_from_document(array $doc): array
{
    $services = $doc['services'] ?? null;
    if (!is_array($services)) {
        return [
            'app' => db_fields(normalize_env($doc)),
            'queue' => ['username' => '', 'password' => '', 'database' => '', 'host' => ''],
        ];
    }
    $appRaw = $services['app']['environment'] ?? [];
    $queueRaw = $services['queue']['environment'] ?? [];
    return [
        'app' => db_fields(normalize_env(is_array($appRaw) ? $appRaw : [])),
        'queue' => db_fields(normalize_env(is_array($queueRaw) ? $queueRaw : [])),
    ];
}

function yaml_quote(string $v): string
{
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    $escaped = str_replace('$', '$$', $escaped);
    return '"'.$escaped.'"';
}

$fromJson = ['app' => ['username' => '', 'password' => '', 'database' => '', 'host' => ''], 'queue' => ['username' => '', 'password' => '', 'database' => '', 'host' => '']];
$fromYml = $fromJson;

if ($jsonPath !== '' && is_file($jsonPath) && filesize($jsonPath) > 0) {
    $j = json_decode((string) file_get_contents($jsonPath), true);
    if (!is_array($j)) {
        fwrite(STDERR, "json invalido\n");
        exit(1);
    }
    $fromJson = env_from_document($j);
}

if ($ymlPath !== '' && is_file($ymlPath) && filesize($ymlPath) > 0) {
    $fromYml = parse_yaml_service_env((string) file_get_contents($ymlPath));
    $fromYml = [
        'app' => db_fields($fromYml['app'] ?? []),
        'queue' => db_fields($fromYml['queue'] ?? []),
    ];
}

function merge_svc(array $a, array $b): array
{
    $out = $a;
    foreach (['username', 'password', 'database', 'host'] as $k) {
        if ($out[$k] === '' && $b[$k] !== '') {
            $out[$k] = $b[$k];
        }
    }
    return $out;
}

$app = merge_svc($fromJson['app'], $fromYml['app']);
$queue = merge_svc($fromJson['queue'], $fromYml['queue']);

if ($app['username'] === '' || $app['password'] === '') {
    if ($queue['username'] !== '' && $queue['password'] !== '') {
        $app = $queue;
    }
}
if ($queue['username'] === '' && $queue['password'] === '') {
    $queue = $app;
}

if ($app['username'] === '' || $app['password'] === '') {
    fwrite(STDERR, "override sem user/senha em services.app|queue.environment\n");
    exit(1);
}

foreach (['username', 'password', 'database'] as $k) {
    if ($queue[$k] !== '' && $app[$k] !== '' && $queue[$k] !== $app[$k]) {
        fwrite(STDERR, "app e queue divergem em {$k}\n");
        exit(2);
    }
}

file_put_contents($outDir.'/username', $app['username']);
file_put_contents($outDir.'/password', $app['password']);
file_put_contents($outDir.'/database', $app['database']);
file_put_contents($outDir.'/host', $app['host']);

$dbName = $app['database'] !== '' ? $app['database'] : 'getfy';
$dbHost = $app['host'] !== '' ? $app['host'] : 'postgres';
$restore = "services:\n  app:\n    environment:\n"
    ."      GETFY_RUN_SETUP: \"false\"\n"
    ."      GETFY_SKIP_MIGRATE: \"1\"\n"
    ."      DB_HOST: ".yaml_quote($dbHost)."\n"
    ."      DB_USERNAME: ".yaml_quote($app['username'])."\n"
    ."      DB_PASSWORD: ".yaml_quote($app['password'])."\n"
    ."      DB_DATABASE: ".yaml_quote($dbName)."\n"
    ."  queue:\n    environment:\n"
    ."      GETFY_RUN_SETUP: \"false\"\n"
    ."      GETFY_SKIP_MIGRATE: \"1\"\n"
    ."      DB_HOST: ".yaml_quote($dbHost)."\n"
    ."      DB_USERNAME: ".yaml_quote($app['username'])."\n"
    ."      DB_PASSWORD: ".yaml_quote($app['password'])."\n"
    ."      DB_DATABASE: ".yaml_quote($dbName)."\n";
file_put_contents($outDir.'/restore.yml', $restore);
file_put_contents($outDir.'/ok', "1\n");
exit(0);
