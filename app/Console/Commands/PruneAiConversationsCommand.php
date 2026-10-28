<?php

namespace App\Console\Commands;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Console\Command;

/** Retenção do Brasa AI: conversas sem atividade há mais de N dias (padrão 180) são apagadas. */
class PruneAiConversationsCommand extends Command
{
    protected $signature = 'ai:prune {--days=180}';

    protected $description = 'Apaga conversas antigas do assistente';

    public function handle(): int
    {
        $ids = AiConversation::query()->where('updated_at', '<', now()->subDays(max(30, (int) $this->option('days'))))->pluck('id');
        foreach ($ids->chunk(500) as $chunk) {
            AiMessage::query()->whereIn('conversation_id', $chunk)->delete();
            AiConversation::query()->whereIn('id', $chunk)->delete();
        }
        $this->info('Conversas removidas: '.$ids->count().'.');

        return self::SUCCESS;
    }
}
