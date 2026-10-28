<?php

namespace App\Services\Ai;

/**
 * Camada de provedor do Brasa AI. Cada provedor roda o próprio laço de ferramentas no formato da sua API;
 * o resto do sistema só vê o resultado. Hoje existe a implementação Anthropic.
 */
interface AiProvider
{
    /**
     * @param  list<array{role: 'user'|'assistant', text: string}>  $history  turnos anteriores (só texto)
     * @param  list<array{name: string, description: string, schema: array}>  $tools
     * @param  callable(string $name, array $input): array  $runTool  executa a ferramenta no backend
     */
    public function run(string $systemPrompt, array $history, string $userText, array $tools, callable $runTool, array $options): AiRunResult;
}
