<?php

namespace App\Http\Controllers;

use App\Models\PanelNotification;
use App\Models\PanelPushSubscription;
use App\Services\PanelPushService;
use App\Support\PanelPushSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelNotificationsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $perPage = min((int) $request->input('per_page', 20), 50);
        $notifications = PanelNotification::forUser($user->id)
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $unreadCount = PanelNotification::forUser($user->id)->unread()->count();
        $pushStatus = PanelPushSubscription::pushStatusForUser(
            (int) $user->id,
            $user->tenant_id !== null ? (int) $user->tenant_id : null
        );

        return response()->json([
            'data' => $notifications->items(),
            'meta' => [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
            ],
            'unread_count' => $unreadCount,
            'push_subscribed' => $pushStatus['push_subscribed'],
            'push_needs_resubscribe' => $pushStatus['push_needs_resubscribe'],
        ]);
    }

    public function markRead(Request $request, PanelNotification $notification): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel() || $notification->user_id !== $user->id) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $notification->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markReadBatch(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $ids = $request->input('ids', []);
        if (! is_array($ids)) {
            return response()->json(['message' => 'ids deve ser um array.'], 422);
        }

        PanelNotification::forUser($user->id)
            ->whereIn('id', $ids)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        PanelNotification::forUser($user->id)->unread()->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function clearAll(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        $deleted = PanelNotification::forUser($user->id)->delete();

        return response()->json(['success' => true, 'deleted' => $deleted]);
    }

    public function testPush(Request $request, PanelPushService $panelPushService): JsonResponse
    {
        $user = $request->user();
        if (! $user->canAccessPanel()) {
            return response()->json(['message' => 'Acesso negado.'], 403);
        }

        if (! PanelPushSettings::isPushEnabled()) {
            return response()->json([
                'ok' => false,
                'message' => 'Notificações push não estão configuradas na plataforma.',
            ], 422);
        }

        $subs = PanelPushSubscription::query()->where('user_id', $user->id)->get();
        if ($subs->isEmpty()) {
            return response()->json([
                'ok' => false,
                'message' => 'Nenhuma inscrição neste dispositivo. Ative as notificações primeiro.',
            ], 422);
        }

        $appName = trim((string) config('getfy.app_name', config('app.name'))) ?: 'Painel';
        $result = $panelPushService->sendToSubscriptions(
            $subs,
            'Teste '.$appName,
            'Notificação de teste enviada pelo painel.',
            '/dashboard'
        );

        $sent = (int) ($result['sent'] ?? 0);

        return response()->json([
            'ok' => $sent > 0,
            'message' => $sent > 0
                ? 'Notificação de teste enviada. Se chegou neste dispositivo, está tudo certo.'
                : $this->formatPushFailureMessage($result),
            'result' => $result,
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatPushFailureMessage(array $result): string
    {
        $failed = (int) ($result['failed'] ?? 0);
        $expired = (int) ($result['expired'] ?? 0);
        $invalid = (int) ($result['invalid'] ?? 0);
        $total = (int) ($result['total'] ?? 0);

        if ($total === 0) {
            return 'Nenhuma inscrição compatível com o provedor ativo. Reative as notificações neste dispositivo.';
        }
        if ($expired > 0) {
            return 'Inscrição expirada. Reative as notificações neste dispositivo.';
        }
        if ($invalid > 0) {
            return 'Inscrição inválida. Reative as notificações neste dispositivo.';
        }
        if ($failed > 0) {
            return 'Falha ao enviar o teste. Tente novamente em alguns segundos.';
        }

        return 'Nenhum push entregue. Reative as notificações e tente de novo.';
    }
}
