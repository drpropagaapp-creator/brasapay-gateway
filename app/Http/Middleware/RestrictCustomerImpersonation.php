<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Platform\CustomerImpersonationController;
use App\Models\User;
use App\Support\CustomerImpersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Na aba do aluno, o admin vê a conta dele. Na aba da plataforma, o login continua o do admin.
 */
class RestrictCustomerImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (CustomerImpersonation::isExpired($request)) {
            $returnUrl = CustomerImpersonation::returnUrl($request);
            CustomerImpersonation::forget($request);
            if (! CustomerImpersonation::keepsAdminIdentity($request)) {
                return redirect()->to($returnUrl);
            }
        }

        if (! CustomerImpersonation::sessionPending($request)) {
            return $next($request);
        }

        if ($request->is('logout')) {
            return app(CustomerImpersonationController::class)->leave($request);
        }

        if (CustomerImpersonation::keepsAdminIdentity($request)) {
            $this->useAdmin($request);

            return $next($request);
        }

        $customer = CustomerImpersonation::customer($request);
        if (! $customer || ! $customer->canAccessCustomerPanel()) {
            CustomerImpersonation::forget($request);

            return redirect()->to(CustomerImpersonation::returnUrl($request));
        }

        $this->useUser($request, $customer);

        if (! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            $message = 'Acesso como cliente é somente leitura.';
            if ($request->header('X-Inertia') || $request->expectsJson()) {
                return redirect()->back()->with('error', $message);
            }

            abort(403, $message);
        }

        return $next($request);
    }

    private function useAdmin(Request $request): void
    {
        $adminId = CustomerImpersonation::adminId($request);
        $admin = $adminId ? User::query()->find($adminId) : null;
        if ($admin) {
            $this->useUser($request, $admin);
        }
    }

    private function useUser(Request $request, User $user): void
    {
        Auth::setUser($user);
        $request->setUserResolver(static fn () => $user);
    }
}
