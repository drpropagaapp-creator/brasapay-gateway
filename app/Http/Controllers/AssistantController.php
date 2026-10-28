<?php

namespace App\Http\Controllers;

use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Services\Ai\AiAssistantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Brasa AI (seller): conversas com o assistente. Cada usuário vê só as próprias conversas. */
class AssistantController extends Controller
{
    public function __construct(private AiAssistantService $assistant) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $conversations = AiConversation::query()->where('tenant_id', (int) $user->tenant_id)->where('user_id', $user->id)
            ->latest('updated_at')->limit(30)->get(['id', 'title', 'updated_at']);
        $current = $request->integer('c') ? $conversations->firstWhere('id', $request->integer('c')) : null;

        return Inertia::render('Assistant/Index', [
            'usage' => $this->assistant->usage($user),
            'conversations' => $conversations->map(fn ($c) => ['id' => $c->id, 'title' => $c->title, 'at' => $c->updated_at?->toIso8601String()])->values(),
            'current' => $current ? ['id' => $current->id, 'messages' => $this->messages($current)] : null,
        ]);
    }

    public function send(Request $request): JsonResponse
    {
        $v = $request->validate(['message' => ['required', 'string', 'min:2', 'max:2000'], 'conversation_id' => ['nullable', 'integer']]);
        $user = $request->user();
        $conversation = isset($v['conversation_id'])
            ? AiConversation::query()->whereKey($v['conversation_id'])->where('tenant_id', (int) $user->tenant_id)->where('user_id', $user->id)->firstOrFail()
            : null;
        $answer = $this->assistant->ask($user, $conversation, trim($v['message']));

        return response()->json([
            'conversation_id' => $answer->conversation_id,
            'answer' => $this->present($answer),
            'usage' => $this->assistant->usage($user),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function messages(AiConversation $c): array
    {
        return AiMessage::query()->where('conversation_id', $c->id)->orderBy('id')->limit(200)->get()->map(fn ($m) => $this->present($m))->all();
    }

    private function present(AiMessage $m): array
    {
        return ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'tools' => $m->tools_used ?? [], 'status' => $m->status, 'at' => $m->created_at?->toIso8601String()];
    }
}
