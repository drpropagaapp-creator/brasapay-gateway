<?php

namespace App\Services\Ai;

final class AiRunResult
{
    /**
     * @param  list<string>  $toolsUsed
     */
    public function __construct(
        public string $text,
        public array $toolsUsed,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public string $status = 'ok', // ok | refused | incomplete
    ) {}
}
