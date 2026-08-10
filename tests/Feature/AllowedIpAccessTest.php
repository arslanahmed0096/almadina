<?php

namespace Tests\Feature;

use App\Http\Middleware\EnforceAllowedIps;
use App\Http\Middleware\TrustProxies;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AllowedIpAccessTest extends TestCase
{
    private const ALLOWED_IPS = [
        '182.190.38.179',
        '182.177.51.115',
        '202.165.228.7',
        '119.159.177.143',
        '202.165.228.1',
        '39.58.209.53',
        '202.163.81.154',
        '119.158.115.221',
        '39.43.176.7',
        '39.49.250.194',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table) {
            $table->increments('id');
            $table->boolean('allowed_ips_enabled')->default(false);
            $table->text('allowed_ips')->nullable();
            $table->text('allowed_ip_role_ids')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('username')->nullable();
            $table->string('password')->nullable();
            $table->integer('role_id')->nullable();
            $table->timestamps();
        });
        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('role_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('role_id');
            $table->integer('user_id');
        });
        Schema::create('permissions', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('permission_user', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('permission_id');
            $table->integer('user_id');
            $table->string('type')->default('allow');
            $table->timestamps();
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Setting::create([
            'allowed_ips_enabled' => true,
            // Matches the supported UI input: commas, optional spaces and new lines.
            'allowed_ips' => implode(",\n ", self::ALLOWED_IPS),
            'allowed_ip_role_ids' => [],
        ]);
    }

    protected function tearDown(): void
    {
        Request::setTrustedProxies([], -1);
        Schema::dropIfExists('categories');
        Schema::dropIfExists('permission_user');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('role_user');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('settings');

        parent::tearDown();
    }

    public function test_forwarded_visitor_ip_is_allowed_behind_hosting_proxy(): void
    {
        foreach (self::ALLOWED_IPS as $allowedIp) {
            $request = $this->requestFrom($allowedIp);

            $response = (new TrustProxies)->handle($request, function ($trustedRequest) {
                return (new EnforceAllowedIps)->handle(
                    $trustedRequest,
                    fn () => response('allowed', 200)
                );
            });

            $this->assertSame($allowedIp, $request->ip());
            $this->assertSame(200, $response->getStatusCode(), $allowedIp.' should be allowed.');
            $this->assertSame('allowed', $response->getContent());
        }
    }

    public function test_denied_web_request_returns_not_authorized_without_login_redirect(): void
    {
        $request = $this->requestFrom('203.0.113.25');

        $response = (new TrustProxies)->handle($request, function ($trustedRequest) {
            return (new EnforceAllowedIps)->handle(
                $trustedRequest,
                fn () => response('should not run', 200)
            );
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertFalse($response->isRedirect());
        $this->assertStringContainsString('Not Authorized', $response->getContent());
        $this->assertStringContainsString('203.0.113.25', $response->getContent());
    }

    public function test_denied_api_request_returns_403_with_detected_ip(): void
    {
        $request = $this->requestFrom('203.0.113.25', '/api/sales');
        $request->headers->set('Accept', 'application/json');

        $response = (new TrustProxies)->handle($request, function ($trustedRequest) {
            return (new EnforceAllowedIps)->handle(
                $trustedRequest,
                fn () => response()->json(['success' => true])
            );
        });

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([
            'message' => 'You are not authorized from this IP address.',
            'ip_address' => '203.0.113.25',
        ], $response->getData(true));
    }

    private function requestFrom(string $forwardedIp, string $uri = '/app/dashboard'): Request
    {
        $request = Request::create($uri, 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.10.0.5',
            'HTTP_X_FORWARDED_FOR' => $forwardedIp,
        ]);
        $user = User::create(['username' => 'restricted-user']);
        $request->setUserResolver(fn () => $user);

        return $request;
    }
}
