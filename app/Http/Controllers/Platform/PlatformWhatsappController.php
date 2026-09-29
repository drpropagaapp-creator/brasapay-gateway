<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Concerns\RequiresPlatformStepUp;
use App\Http\Controllers\Controller;
use App\Models\PlatformWhatsappCampaign;
use App\Models\PlatformWhatsappChannel;
use App\Models\PlatformWhatsappDispatch;
use App\Models\PlatformWhatsappTemplate;
use App\Models\User;
use App\Services\PlatformWhatsapp\PlatformWhatsappCampaignService;
use App\Services\PlatformWhatsapp\PlatformWhatsappChannelService;
use App\Services\PlatformWhatsapp\PlatformWhatsappMessageVars;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PlatformWhatsappController extends Controller
{
    use RequiresPlatformStepUp;

    public function updateChannel(Request $request, PlatformWhatsappChannelService $channelService): JsonResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string', 'in:uazapi,evolution'],
            'server_url' => ['required', 'url', 'max:255'],
            'instance_name' => ['nullable', 'string', 'max:120'],
            'instance_token' => ['nullable', 'string', 'max:2048'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $channel = PlatformWhatsappChannel::current();

        try {
            $channel = $channelService->saveCredentials(
                $channel,
                $validated['provider'],
                $validated['server_url'],
                $validated['instance_name'] ?? null,
                $validated['instance_token'] ?? null
            );
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'channel' => $channel->fresh()->toPublicArray(),
            ], 422);
        }

        if ($request->has('is_active')) {
            $channel->is_active = $request->boolean('is_active');
            $channel->save();
        }

        return response()->json([
            'channel' => $channel->fresh()->toPublicArray(),
            'templates' => PlatformWhatsappTemplate::toPublicList(),
        ]);
    }

    public function connect(PlatformWhatsappChannelService $channelService): JsonResponse
    {
        $channel = PlatformWhatsappChannel::current();

        try {
            $channel = $channelService->connect($channel);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'channel' => $channel->fresh()->toPublicArray(),
            ], 422);
        }

        return response()->json(['channel' => $channel->toPublicArray()]);
    }

    public function disconnect(PlatformWhatsappChannelService $channelService): JsonResponse
    {
        $channel = $channelService->disconnect(PlatformWhatsappChannel::current());

        return response()->json(['channel' => $channel->toPublicArray()]);
    }

    public function status(PlatformWhatsappChannelService $channelService): JsonResponse
    {
        $channel = $channelService->refreshStatus(PlatformWhatsappChannel::current());

        return response()->json(['channel' => $channel->toPublicArray()]);
    }

    public function updateTemplates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'templates' => ['required', 'array'],
            'templates.*.event_key' => ['required', 'string'],
            'templates.*.enabled' => ['nullable', 'boolean'],
            'templates.*.message' => ['required', 'string', 'max:'.(int) config('platform_whatsapp.max_message_length', 1000)],
        ]);

        foreach ($validated['templates'] as $row) {
            $key = (string) $row['event_key'];
            if (! in_array($key, PlatformWhatsappTemplate::eventKeys(), true)) {
                continue;
            }
            PlatformWhatsappTemplate::query()->updateOrCreate(
                ['event_key' => $key],
                [
                    'enabled' => (bool) ($row['enabled'] ?? true),
                    'message' => (string) $row['message'],
                ]
            );
        }

        return response()->json(['templates' => PlatformWhatsappTemplate::toPublicList()]);
    }

    public function broadcastPreview(Request $request, PlatformWhatsappCampaignService $campaigns): JsonResponse
    {
        $filters = $this->broadcastFilters($request);

        return response()->json([
            'count' => $campaigns->previewCount($filters),
        ]);
    }

    public function broadcast(
        Request $request,
        PlatformWhatsappCampaignService $campaigns
    ): JsonResponse {
        $this->validateRequiredSecurityStepUp(
            $request,
            'Cadastre o 2FA em Meu perfil ou o PIN de operação em Financeiro > Saques para disparar campanhas em massa.'
        );

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:'.(int) config('platform_whatsapp.max_message_length', 1000)],
            'delay_seconds' => ['nullable', 'integer', 'min:5', 'max:120'],
            'account_status' => ['nullable', 'string', 'max:32'],
            'kyc_status' => ['nullable', 'string', 'max:32'],
            'totp_code' => ['nullable', 'string', 'max:16'],
            'manual_approval_pin' => ['nullable', 'string', 'max:16'],
        ]);

        $filters = $this->broadcastFilters($request);

        try {
            $campaign = $campaigns->launch(
                $request->user(),
                $validated['message'],
                (int) ($validated['delay_seconds'] ?? config('platform_whatsapp.campaign.delay_seconds', 10)),
                $filters
            );
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['message' => $e->getMessage()]);
        }

        return response()->json([
            'campaign' => $campaign->toPublicArray(),
            'recent_dispatches' => $this->recentDispatches(),
        ]);
    }

    public function test(
        Request $request,
        PlatformWhatsappChannelService $channelService,
        PlatformWhatsappMessageVars $vars
    ): JsonResponse {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'message' => ['nullable', 'string', 'max:'.(int) config('platform_whatsapp.max_message_length', 1000)],
        ]);

        $phone = $vars->phoneOf(new User(['phone' => $validated['phone']]));
        if ($phone === null) {
            return response()->json(['success' => false, 'message' => 'Telefone inválido.'], 422);
        }

        $message = trim((string) ($validated['message'] ?? ''));
        if ($message === '') {
            $message = 'Teste Stacker — canal WhatsApp da plataforma.';
        }

        try {
            $channelService->sendText(PlatformWhatsappChannel::current(), $phone, $message);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * @return array{account_status?: string, kyc_status?: string}
     */
    private function broadcastFilters(Request $request): array
    {
        $filters = [];
        $account = trim((string) $request->input('account_status', ''));
        $kyc = trim((string) $request->input('kyc_status', ''));
        if ($account !== '') {
            $filters['account_status'] = $account;
        }
        if ($kyc !== '') {
            $filters['kyc_status'] = $kyc;
        }

        return $filters;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentDispatches(): array
    {
        return PlatformWhatsappDispatch::query()
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn (PlatformWhatsappDispatch $d) => $d->toPublicArray())
            ->values()
            ->all();
    }
}
