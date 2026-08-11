<?php

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * Trust the hosting proxy that directly connects to the application.
     * Laravel resolves the original visitor from the forwarded IP chain.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * Only trust the forwarding header needed to resolve the visitor IP.
     *
     * @var int
     */
    protected $headers = Request::HEADER_X_FORWARDED_FOR;
}
