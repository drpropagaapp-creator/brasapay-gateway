<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PlatformAuditService;
use App\Support\CustomerImpersonation;
use App\Support\PlatformCustomerDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CustomerImpersonationController extends Controller
{
    public function start(Request $request, User $user): RedirectResponse
    {
        $admin = $request->user();
        if (! $admin || ! $admin->canAccessPlatformPanel()) {
            abort(403);
        }

        if (! PlatformCustomerDirectory::isViewableCustomer($user) || ! $user->canAccessCustomerPanel()) {
            abort(404);
        }

        PlatformAuditService::log('platform.customer.impersonation_started', [
            'customer_id' => $user->id,
            'customer_email' => $user->email,
        ], $request);

        CustomerImpersonation::start($request, $admin, $user);

        return redirect('/painel-cliente');
    }

    public function leave(Request $request): RedirectResponse
    {
        $adminId = CustomerImpersonation::adminId($request);
        $customerId = CustomerImpersonation::customerId($request);
        $returnUrl = CustomerImpersonation::returnUrl($request);
        $admin = $adminId ? User::query()->find($adminId) : null;

        if (! $admin || ! $admin->canAccessPlatformPanel()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('plataforma.login');
        }

        Auth::setUser($admin);
        CustomerImpersonation::forget($request);

        PlatformAuditService::log('platform.customer.impersonation_ended', [
            'customer_id' => $customerId,
        ], $request);

        return redirect()->to($returnUrl);
    }
}
