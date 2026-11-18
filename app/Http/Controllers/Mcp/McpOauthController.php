<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Models\McpOauthAccessToken;
use App\Models\User;
use App\Services\Mcp\McpOauthException;
use App\Services\Mcp\McpOauthService;
use App\Services\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class McpOauthController extends Controller
{
    public function __construct(
        private readonly McpOauthService $oauth,
    ) {}

    public function protectedResource(Request $request, ?string $path = null): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCors(response('', HttpResponse::HTTP_NO_CONTENT));
        }

        return $this->withCors(response()->json($this->oauth->protectedResourceMetadata()));
    }

    public function authorizationServer(Request $request, ?string $path = null): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCors(response('', HttpResponse::HTTP_NO_CONTENT));
        }

        return $this->withCors(response()->json($this->oauth->authorizationServerMetadata()));
    }

    public function register(Request $request): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCors(response('', HttpResponse::HTTP_NO_CONTENT));
        }

        try {
            $client = $this->oauth->registerClient($request->json()->all() ?: $request->all());
        } catch (McpOauthException $e) {
            return $this->withCors(response()->json($e->toPayload(), $e->status));
        }

        return $this->withCors(response()->json($client, HttpResponse::HTTP_CREATED));
    }

    public function showAuthorize(Request $request): View|RedirectResponse|Response
    {
        $validated = $this->oauth->validateAuthorizeRequest($request->query());
        if ($validated['ok'] !== true) {
            if (($validated['redirect'] ?? false) === true && isset($validated['params']['redirect_uri'])) {
                return $this->redirectWithOauthError(
                    $validated['params']['redirect_uri'],
                    $validated['error'],
                    $validated['description'],
                    $validated['params']['state'] ?? '',
                );
            }

            return response()->view('mcp.oauth-error', [
                'message' => $validated['description'],
            ], HttpResponse::HTTP_BAD_REQUEST);
        }

        $params = $validated['params'];
        $request->session()->put('mcp_oauth.pending', $params);

        $user = $request->user();
        if (! $user instanceof User) {
            // Infoprodutor entra em /login; admin da plataforma em /plataforma/login (com 2FA).
            // Os dois logins devolvem para cá pelo url.intended.
            $request->session()->put('url.intended', $request->fullUrl());

            return view('mcp.oauth-login', [
                'clientName' => $params['client_name'],
            ]);
        }

        $access = $this->oauth->audienceFor($user);
        if ($access['blocked'] !== null) {
            return response()->view('mcp.oauth-error', [
                'message' => $access['blocked'],
            ], HttpResponse::HTTP_FORBIDDEN);
        }

        return view('mcp.oauth-consent', [
            'clientName' => $params['client_name'],
            'resource' => $this->oauth->resourceUrl(),
            'platform' => $access['audience'] === McpOauthAccessToken::AUDIENCE_PLATFORM,
        ]);
    }

    public function approve(Request $request): RedirectResponse|View|Response
    {
        $pending = $request->session()->get('mcp_oauth.pending');
        if (! is_array($pending) || ! isset($pending['client_id'], $pending['redirect_uri'], $pending['code_challenge'])) {
            return response()->view('mcp.oauth-error', [
                'message' => 'Pedido de autorização expirado. Volte ao ChatGPT e tente de novo.',
            ], HttpResponse::HTTP_BAD_REQUEST);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            return redirect()->guest(url('/login'));
        }

        $access = $this->oauth->audienceFor($user);
        if ($access['blocked'] !== null) {
            return response()->view('mcp.oauth-error', [
                'message' => $access['blocked'],
            ], HttpResponse::HTTP_FORBIDDEN);
        }
        $platform = $access['audience'] === McpOauthAccessToken::AUDIENCE_PLATFORM;

        $decision = (string) $request->input('decision', '');
        $state = is_string($pending['state'] ?? null) ? $pending['state'] : '';
        $redirectUri = (string) $pending['redirect_uri'];

        if ($decision !== 'approve') {
            $request->session()->forget('mcp_oauth.pending');

            return $this->redirectWithOauthError($redirectUri, 'access_denied', 'O vendedor recusou o acesso.', $state);
        }

        $code = $this->oauth->issueAuthorizationCode(
            $platform ? 0 : (int) $user->tenant_id,
            (int) $user->id,
            $pending,
            $access['audience'],
        );
        $request->session()->forget('mcp_oauth.pending');

        if ($platform) {
            PlatformAuditService::log('platform.mcp.authorized', [
                'client_id' => (string) $pending['client_id'],
                'client_name' => (string) ($pending['client_name'] ?? ''),
            ], $request);
        }

        return redirect()->away($this->appendQuery($redirectUri, [
            'code' => $code,
            'state' => $state,
        ]));
    }

    public function token(Request $request): JsonResponse|Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->withCors(response('', HttpResponse::HTTP_NO_CONTENT));
        }

        $grant = (string) $request->input('grant_type', '');
        $clientId = (string) $request->input('client_id', '');

        if ($this->oauth->findClient($clientId) === null) {
            return $this->withCors(response()->json([
                'error' => 'invalid_client',
                'error_description' => 'client_id desconhecido.',
            ], HttpResponse::HTTP_UNAUTHORIZED));
        }

        try {
            $tokens = match ($grant) {
                'authorization_code' => $this->oauth->exchangeAuthorizationCode(
                    $clientId,
                    (string) $request->input('code', ''),
                    (string) $request->input('redirect_uri', ''),
                    (string) $request->input('code_verifier', ''),
                    $request->input('resource'),
                ),
                'refresh_token' => $this->oauth->refreshAccessToken(
                    $clientId,
                    (string) $request->input('refresh_token', ''),
                ),
                default => throw new McpOauthException('unsupported_grant_type', 'Use authorization_code ou refresh_token.'),
            };
        } catch (McpOauthException $e) {
            return $this->withCors(response()->json($e->toPayload(), $e->status));
        }

        return $this->withCors(response()->json($tokens));
    }

    private function redirectWithOauthError(string $redirectUri, string $error, string $description, string $state): RedirectResponse
    {
        return redirect()->away($this->appendQuery($redirectUri, [
            'error' => $error,
            'error_description' => $description,
            'state' => $state,
        ]));
    }

    /**
     * @param  array<string, string>  $query
     */
    private function appendQuery(string $uri, array $query): string
    {
        $filtered = [];
        foreach ($query as $key => $value) {
            if ($value !== '') {
                $filtered[$key] = $value;
            }
        }

        $separator = str_contains($uri, '?') ? '&' : '?';

        return $uri.$separator.http_build_query($filtered);
    }

    private function withCors(JsonResponse|Response $response): JsonResponse|Response
    {
        return $response
            ->header('Access-Control-Allow-Origin', '*')
            ->header('Access-Control-Allow-Headers', 'Authorization, Content-Type, MCP-Protocol-Version')
            ->header('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->header('Access-Control-Expose-Headers', 'WWW-Authenticate')
            ->header('Access-Control-Max-Age', '86400')
            ->header('Cache-Control', 'no-store');
    }
}
