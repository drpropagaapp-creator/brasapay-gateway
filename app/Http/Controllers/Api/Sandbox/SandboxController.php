<?php

namespace App\Http\Controllers\Api\Sandbox;

use App\Http\Controllers\Controller;
use App\Models\ApiApplication;
use App\Models\ApiKey;
use App\Models\SandboxTransaction;
use App\Services\Api\SandboxService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Endpoints de sandbox (/api/sandbox/v1). Só aceitam chave de sandbox (middleware api.application:sandbox)
 * e nunca produzem efeito financeiro real.
 */
class SandboxController extends Controller
{
    public function __construct(private SandboxService $sandbox) {}

    public function createPix(Request $request): JsonResponse
    {
        return $this->create($request, 'pix');
    }

    public function createCard(Request $request): JsonResponse
    {
        return $this->create($request, 'card', ['card' => ['required', 'array'], 'card.payment_token' => ['required', 'string', 'max:10000']]);
    }

    public function createBoleto(Request $request): JsonResponse
    {
        return $this->create($request, 'boleto');
    }

    public function index(Request $request): JsonResponse
    {
        $rows = $this->query($request, SandboxTransaction::KIND_PAYMENT)->latest()->limit(min(100, max(1, (int) $request->query('limit', 20))))->get();

        return response()->json(['data' => $rows->map(fn ($t) => $this->sandbox->statusResponse($t))->values(), 'livemode' => false]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        return response()->json($this->sandbox->statusResponse($this->find($request, SandboxTransaction::KIND_PAYMENT, $id)));
    }

    public function simulatePayment(Request $request, string $id, string $action): JsonResponse
    {
        $tx = $this->sandbox->simulatePayment($this->find($request, SandboxTransaction::KIND_PAYMENT, $id), $action, $this->app($request), $this->key($request));

        return response()->json($this->sandbox->statusResponse($tx));
    }

    public function balance(Request $request): JsonResponse
    {
        $cents = $this->sandbox->availableCents($this->app($request));
        $amount = (float) bcdiv((string) $cents, '100', 2);

        return response()->json(['available_pix' => $amount, 'available_card' => 0.0, 'available_boleto' => 0.0,
            'available_balance' => $amount, 'pending_balance' => 0.0, 'livemode' => false]);
    }

    public function createWithdrawal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'pix_key' => ['required', 'string', 'max:255'],
            'pix_key_type' => ['required', 'string', 'in:cpf,cnpj,email,phone,evp,random'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->sandbox->withdrawalResponse($this->sandbox->createWithdrawal($this->app($request), $this->key($request), $data)), 201);
    }

    public function showWithdrawal(Request $request, string $id): JsonResponse
    {
        return response()->json($this->sandbox->withdrawalResponse($this->find($request, SandboxTransaction::KIND_WITHDRAWAL, $id)));
    }

    public function simulateWithdrawal(Request $request, string $id, string $action): JsonResponse
    {
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;
        $tx = $this->sandbox->simulateWithdrawal($this->find($request, SandboxTransaction::KIND_WITHDRAWAL, $id), $action, $this->app($request), $this->key($request), $reason);

        return response()->json($this->sandbox->withdrawalResponse($tx));
    }

    public function testWebhook(Request $request): JsonResponse
    {
        $event = $request->validate(['event' => ['required', Rule::in(self::testEvents())]])['event'];
        $sent = $this->sandbox->sendTestEvent($this->app($request), $event);

        return response()->json(['event' => $event, 'deliveries_created' => $sent, 'livemode' => false], $sent > 0 ? 202 : 422);
    }

    /** @return list<string> */
    public static function testEvents(): array
    {
        return ['order.pending', 'pix.generated', 'order.completed', 'order.cancelled', 'order.refunded',
            'withdrawal.created', 'withdrawal.processing', 'withdrawal.completed', 'withdrawal.failed'];
    }

    private function create(Request $request, string $method, array $extra = []): JsonResponse
    {
        $data = $request->validate([
            'customer' => ['required', 'array'],
            'customer.email' => ['required', 'email'],
            'customer.name' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:1000000'],
            'currency' => ['nullable', 'string', 'in:BRL,USD,EUR'],
            'metadata' => ['nullable', 'array'],
        ] + $extra);
        $tx = $this->sandbox->createPayment($this->app($request), $this->key($request), $method, $data);

        if ($tx->status === 'refused') {
            return response()->json(['message' => 'Cartão recusado (sandbox).', 'order_id' => $tx->id, 'status' => 'refused', 'livemode' => false], 422);
        }

        return response()->json($this->sandbox->paymentResponse($tx), 201);
    }

    private function query(Request $request, string $kind)
    {
        return SandboxTransaction::query()->where('api_application_id', $this->app($request)->id)->where('kind', $kind);
    }

    private function find(Request $request, string $kind, string $id): SandboxTransaction
    {
        $tx = $this->query($request, $kind)->whereKey($id)->first();
        abort_if($tx === null, 404, 'Not found.');

        return $tx;
    }

    private function app(Request $request): ApiApplication
    {
        return $request->attributes->get('api_application');
    }

    private function key(Request $request): ?ApiKey
    {
        $key = $request->attributes->get('api_key');

        return $key instanceof ApiKey ? $key : null;
    }
}
