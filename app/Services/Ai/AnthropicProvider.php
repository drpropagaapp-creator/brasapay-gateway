<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Beta\Messages\BetaToolUseBlock;
use App\Support\AiAssistantSettings;
use GuzzleHttp\Client as GuzzleClient;

/**
 * Provedor Anthropic (Claude) pelo SDK oficial. Laço manual de ferramentas: só as ferramentas tipadas
 * do Brasa AI, com schema estrito; o tenant nunca é parâmetro de ferramenta (vem do backend).
 */
class AnthropicProvider implements AiProvider
{
    /** Teto de chamadas ao modelo por pergunta (cada uma pode pedir várias ferramentas). */
    private const MAX_ROUNDS = 6;

    public function run(string $systemPrompt, array $history, string $userText, array $tools, callable $runTool, array $options): AiRunResult
    {
        $client = new Client(
            apiKey: (string) AiAssistantSettings::apiKey(),
            requestOptions: [
                // O SDK não aplica timeout sozinho: quem aplica é o transporte HTTP.
                'transporter' => new GuzzleClient(['timeout' => (float) ($options['timeout'] ?? 45), 'connect_timeout' => 10]),
                'maxRetries' => 1,
            ],
        );

        $messages = [];
        foreach ($history as $turn) {
            $messages[] = ['role' => $turn['role'], 'content' => $turn['text']];
        }
        $messages[] = ['role' => 'user', 'content' => $userText];

        $toolDefs = array_map(fn ($t) => [
            'name' => $t['name'],
            'description' => $t['description'],
            'inputSchema' => $t['schema'],
            'strict' => true,
        ], $tools);

        $used = [];
        $in = 0;
        $out = 0;
        $model = (string) $options['model'];
        $deadline = microtime(true) + (float) ($options['budget_seconds'] ?? 90);

        for ($round = 0; $round < self::MAX_ROUNDS; $round++) {
            $response = $client->beta->messages->create(
                maxTokens: 4000,
                messages: $messages,
                model: (string) $options['model'],
                system: [['type' => 'text', 'text' => $systemPrompt, 'cacheControl' => ['type' => 'ephemeral']]],
                tools: $toolDefs,
                outputConfig: ['effort' => (string) ($options['effort'] ?? 'medium')],
                // Se o modelo recusar por política, a API tenta o modelo de reserva padrão na mesma chamada.
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
            $in += (int) $response->usage->inputTokens;
            $out += (int) $response->usage->outputTokens;
            $model = (string) ($response->model ?? $model);

            if ($response->stopReason === 'refusal') {
                return new AiRunResult('Não posso ajudar com esse pedido. Tente perguntar sobre suas vendas, produtos ou campanhas.', $used, $model, $in, $out, 'refused');
            }
            if ($response->stopReason !== 'tool_use') {
                return new AiRunResult($this->text($response->content), $used, $model, $in, $out, $response->stopReason === 'max_tokens' ? 'incomplete' : 'ok');
            }

            $results = [];
            foreach ($response->content as $block) {
                if (! $block instanceof BetaToolUseBlock) {
                    continue;
                }
                $used[] = $block->name;
                try {
                    $data = $runTool($block->name, is_array($block->input) ? $block->input : []);
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => self::wrap($block->name, $data)];
                } catch (\Throwable $e) {
                    $results[] = ['type' => 'tool_result', 'toolUseID' => $block->id, 'content' => 'Erro ao consultar: '.mb_substr($e->getMessage(), 0, 200), 'isError' => true];
                }
            }
            // Histórico só cresce no fim (append-only): a resposta do modelo como veio, depois os resultados.
            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $results];

            if (microtime(true) > $deadline) {
                break;
            }
        }

        return new AiRunResult('Não consegui concluir a análise a tempo. Tente uma pergunta mais específica.', $used, $model, $in, $out, 'incomplete');
    }

    /**
     * Resultado de ferramenta vai como dado delimitado. Nomes de campanha e de produto podem vir de fora
     * (UTMs são digitadas por qualquer visitante), então nunca são tratados como instrução.
     */
    public static function wrap(string $tool, array $data): string
    {
        // JSON_HEX_TAG: um "</dados_da_plataforma>" dentro de um nome não fecha o bloco.
        return "<dados_da_plataforma ferramenta=\"{$tool}\">\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG)."\n</dados_da_plataforma>";
    }

    private function text(array $content): string
    {
        $parts = [];
        foreach ($content as $block) {
            if (($block->type ?? null) === 'text') {
                $parts[] = $block->text;
            }
        }

        return trim(implode("\n\n", $parts)) ?: 'Sem resposta.';
    }
}
