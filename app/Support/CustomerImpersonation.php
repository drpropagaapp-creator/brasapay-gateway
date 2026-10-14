<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Admin da plataforma abre o painel do aluno em outra aba, sem trocar o login do admin.
 */
final class CustomerImpersonation
{
    public const ADMIN_ID = 'customer_impersonator_admin_id';

    public const CUSTOMER_ID = 'customer_impersonator_customer_id';

    public const RETURN_URL = 'customer_impersonator_return_url';

    public const EXPIRES_AT = 'customer_impersonator_expires_at';

    public static function start(Request $request, User $admin, User $customer): void
    {
        $request->session()->put(self::ADMIN_ID, (int) $admin->id);
        $request->session()->put(self::CUSTOMER_ID, (int) $customer->id);
        $request->session()->put(self::RETURN_URL, route('plataforma.clientes.show', $customer));
        $request->session()->put(self::EXPIRES_AT, now()->addMinutes(30)->getTimestamp());
    }

    public static function forget(Request $request): void
    {
        $request->session()->forget([
            self::ADMIN_ID,
            self::CUSTOMER_ID,
            self::RETURN_URL,
            self::EXPIRES_AT,
        ]);
    }

    public static function sessionPending(Request $request): bool
    {
        $adminId = (int) $request->session()->get(self::ADMIN_ID, 0);
        $customerId = (int) $request->session()->get(self::CUSTOMER_ID, 0);

        return $adminId > 0 && $customerId > 0 && ! self::isExpired($request);
    }

    public static function isActive(Request $request): bool
    {
        if (! self::sessionPending($request)) {
            return false;
        }

        $user = $request->user();
        if (! $user) {
            return false;
        }

        $adminId = (int) $request->session()->get(self::ADMIN_ID, 0);
        $customerId = (int) $request->session()->get(self::CUSTOMER_ID, 0);

        return (int) $user->id === $adminId || (int) $user->id === $customerId;
    }

    public static function isExpired(Request $request): bool
    {
        $adminId = (int) $request->session()->get(self::ADMIN_ID, 0);
        $expiresAt = (int) $request->session()->get(self::EXPIRES_AT, 0);

        return $adminId > 0 && $expiresAt > 0 && $expiresAt < now()->getTimestamp();
    }

    public static function keepsAdminIdentity(Request $request): bool
    {
        if ($request->routeIs('customer-impersonation.leave')) {
            return true;
        }

        return $request->is('plataforma', 'plataforma/*');
    }

    public static function adminId(Request $request): ?int
    {
        $adminId = (int) $request->session()->get(self::ADMIN_ID, 0);

        return $adminId > 0 ? $adminId : null;
    }

    public static function customerId(Request $request): ?int
    {
        $customerId = (int) $request->session()->get(self::CUSTOMER_ID, 0);

        return $customerId > 0 ? $customerId : null;
    }

    public static function customer(Request $request): ?User
    {
        $customerId = self::customerId($request);
        if ($customerId === null) {
            return null;
        }

        return User::query()->find($customerId);
    }

    public static function returnUrl(Request $request): string
    {
        $fallback = route('plataforma.clientes.index');
        $url = $request->session()->get(self::RETURN_URL);
        if (! is_string($url) || $url === '') {
            return $fallback;
        }

        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
        if (! str_starts_with($path, '/plataforma/clientes')) {
            return $fallback;
        }

        return $url;
    }

    /**
     * @return array{active: true, name: ?string, email: ?string, leave_url: string}|null
     */
    public static function inertiaPayload(Request $request): ?array
    {
        if (! self::isActive($request) || self::keepsAdminIdentity($request)) {
            return null;
        }

        $customer = self::customer($request);

        return [
            'active' => true,
            'name' => $customer?->name,
            'email' => $customer?->email,
            'leave_url' => route('customer-impersonation.leave'),
        ];
    }
}
