<?php

namespace App\Console\Commands;

use App\Models\McpPlatformKey;
use App\Models\User;
use App\Services\PlatformAuditService;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

/**
 * Chaves de integração do MCP da plataforma (ex.: MetaTrack).
 *
 *   php artisan mcp:platform-key create --name=MetaTrack --admin=admin@exemplo.com
 *   php artisan mcp:platform-key list
 *   php artisan mcp:platform-key revoke --id=3
 *
 * A chave só aparece uma vez, na criação.
 */
class McpPlatformKeyCommand extends Command
{
    protected $signature = 'mcp:platform-key {action : create, list ou revoke} {--name=} {--admin= : e-mail do admin da plataforma} {--id=}';

    protected $description = 'Cria, lista ou revoga chaves de integração do MCP da plataforma (escopo agência).';

    public function handle(): int
    {
        return match ((string) $this->argument('action')) {
            'create' => $this->create(),
            'list' => $this->listKeys(),
            'revoke' => $this->revoke(),
            default => $this->failWith('Ação inválida. Use create, list ou revoke.'),
        };
    }

    private function create(): int
    {
        $name = trim((string) $this->option('name'));
        $email = trim((string) $this->option('admin'));
        if ($name === '' || $email === '') {
            return $this->failWith('Informe --name e --admin.');
        }

        $admin = User::query()->where('email', $email)->first();
        if ($admin === null || ! $admin->isPlatformAdmin()) {
            return $this->failWith('--admin precisa ser o e-mail de um administrador da plataforma.');
        }

        $issued = McpPlatformKey::issue($name, $admin);
        $this->audit('platform.mcp.key_created', $admin, ['key_id' => $issued['model']->id, 'name' => $name]);

        $this->line('Chave criada (id '.$issued['model']->id.'). Guarde agora; ela não aparece de novo:');
        $this->line($issued['plain']);

        return self::SUCCESS;
    }

    private function listKeys(): int
    {
        $rows = McpPlatformKey::query()->orderBy('id')->get()->map(fn (McpPlatformKey $k) => [
            $k->id,
            $k->name,
            $k->maskedToken(),
            $k->scope,
            $k->created_by_user_id,
            $k->last_used_at?->toDateTimeString() ?? '-',
            $k->revoked_at?->toDateTimeString() ?? 'ativa',
        ])->all();

        $this->table(['id', 'nome', 'chave', 'escopo', 'admin', 'último uso', 'revogada'], $rows);

        return self::SUCCESS;
    }

    private function revoke(): int
    {
        $key = McpPlatformKey::query()->find((int) $this->option('id'));
        if ($key === null) {
            return $this->failWith('Chave não encontrada (--id).');
        }
        $key->forceFill(['revoked_at' => now()])->save();
        $admin = User::query()->find($key->created_by_user_id);
        if ($admin !== null) {
            $this->audit('platform.mcp.key_revoked', $admin, ['key_id' => $key->id]);
        }
        $this->info('Chave '.$key->id.' revogada.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function audit(string $action, User $admin, array $meta): void
    {
        $r = Request::create('/artisan', 'POST', [], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'artisan']);
        $r->setUserResolver(fn () => $admin);
        PlatformAuditService::log($action, $meta, $r);
    }

    private function failWith(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
