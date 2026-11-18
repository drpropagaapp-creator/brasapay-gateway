<?php

namespace App\Services\Mcp;

use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Facade;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Executa uma ação do painel da plataforma (método de controller) em nome do admin do MCP.
 *
 * Motivo: saque, KYC, produto, estorno e MED têm regras, step-up (2FA/PIN), envio ao gateway e
 * auditoria escritos no controller do painel. Chamar o mesmo método garante que o MCP segue
 * exatamente as mesmas travas, sem duplicar a lógica de dinheiro.
 *
 * O controller recebe uma Request sintética com o admin autenticado; o resultado vem do flash
 * "success"/"error" do redirect, de ValidationException ou de abort().
 */
class McpPanelActionRunner
{
    /**
     * @param  array<string, mixed>  $input
     * @param  callable(Request): mixed  $action
     * @return array{ok: bool, message: string, errors?: array<string, list<string>>}
     */
    public function run(User $admin, string $uri, array $input, callable $action): array
    {
        $original = app('request');
        $sub = Request::create($uri, 'POST', $input, [], [], [
            'REMOTE_ADDR' => $original->ip(),
            'HTTP_USER_AGENT' => 'MCP: '.(string) $original->userAgent(),
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $sub->setUserResolver(fn () => $admin);
        $session = app('session.store');
        $sub->setLaravelSession($session);
        $session->forget(['success', 'error']);

        app()->instance('request', $sub);
        Facade::clearResolvedInstance('request');
        Auth::setUser($admin);

        try {
            $response = $action($sub);
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $first = collect($errors)->flatten()->first();

            return [
                'ok' => false,
                'message' => is_string($first) && $first !== '' ? $first : 'Dados inválidos para esta ação.',
                'errors' => $errors,
            ];
        } catch (HttpResponseException $e) {
            $response = $e->getResponse();
        } catch (HttpExceptionInterface $e) {
            $message = trim($e->getMessage());

            return [
                'ok' => false,
                'message' => $message !== '' ? $message : 'Ação recusada pelo painel (HTTP '.$e->getStatusCode().').',
            ];
        } finally {
            app()->instance('request', $original);
            Facade::clearResolvedInstance('request');
            Auth::forgetUser();
        }

        if ($response instanceof JsonResponse) {
            $session->forget(['success', 'error']);
            $data = $response->getData(true);
            $data = is_array($data) ? $data : [];
            $message = is_string($data['message'] ?? null) ? $data['message'] : '';

            return [
                'ok' => ($data['success'] ?? false) === true && $response->getStatusCode() < 400,
                'message' => $message !== '' ? $message : 'Resposta HTTP '.$response->getStatusCode().'.',
            ];
        }

        $error = $session->pull('error');
        $success = $session->pull('success');

        if (is_string($error) && $error !== '') {
            return ['ok' => false, 'message' => $error];
        }

        if (is_string($success) && $success !== '') {
            return ['ok' => true, 'message' => $success];
        }

        // Sem flash: o painel não confirmou. Não declarar sucesso sem prova.
        $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 0;

        return [
            'ok' => false,
            'message' => 'O painel não confirmou a ação (resposta HTTP '.$status.'). Confira o estado antes de tentar de novo.',
        ];
    }
}
