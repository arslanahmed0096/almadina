<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Support\Facades\Auth;

class EnforceAllowedIps
{
    public function handle($request, Closure $next)
    {
        $user = $request->user('api') ?: Auth::user();

        if (! $user) {
            return $next($request);
        }

        $settings = Setting::query()->first();
        if (! $settings || ! $settings->allowed_ips_enabled) {
            return $next($request);
        }

        $user->loadMissing('roles');

        if ((int) $user->role_id === 1 || $user->hasRole('Super Admin')) {
            return $next($request);
        }

        $bypassRoleIds = $this->decodeRoleIds($settings->allowed_ip_role_ids);
        $userRoleIds = $user->roles->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($user->role_id) {
            $userRoleIds[] = (int) $user->role_id;
            $userRoleIds = array_values(array_unique($userRoleIds));
        }

        if (! empty(array_intersect($userRoleIds, $bypassRoleIds))) {
            return $next($request);
        }

        if ($this->ipIsAllowed($request->ip(), (string) $settings->allowed_ips)) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json([
                'message' => 'Access denied from this IP address.',
            ], 403);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/login')->with('erro_login', 'Access denied from this IP address.');
    }

    private function decodeRoleIds($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('intval', $value)));
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return array_values(array_filter(array_map('intval', $decoded)));
            }
        }

        return [];
    }

    private function ipIsAllowed(?string $ip, string $allowedIps): bool
    {
        if (! $ip) {
            return false;
        }

        $entries = preg_split('/[\r\n,]+/', $allowedIps) ?: [];

        foreach ($entries as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if ($entry === $ip || $this->cidrMatches($ip, $entry)) {
                return true;
            }
        }

        return false;
    }

    private function cidrMatches(string $ip, string $cidr): bool
    {
        if (strpos($cidr, '/') === false) {
            return false;
        }

        [$subnet, $bits] = explode('/', $cidr, 2);
        $bits = (int) $bits;
        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);

        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $maxBits = strlen($ipBinary) * 8;
        if ($bits < 0 || $bits > $maxBits) {
            return false;
        }

        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes && substr($ipBinary, 0, $bytes) !== substr($subnetBinary, 0, $bytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainder)) & 0xff;

        return (ord($ipBinary[$bytes]) & $mask) === (ord($subnetBinary[$bytes]) & $mask);
    }
}
