<?php

namespace App\Http\Controllers;

use App\Models\Product;
use Config;
use DB;
use Illuminate\Validation\ValidationException;

class BaseController extends Controller
{
    /**
     * Reject inactive catalogue products submitted outside the filtered UI. Existing
     * transaction reads are unaffected; this protects creation endpoints only.
     */
    protected function assertProductsSelectable($user, $details): void
    {
        if (! $user || $user->isSuperAdmin()) {
            return;
        }

        $ids = collect(is_array($details) ? $details : [])
            ->map(fn ($detail) => (int) ($detail['product_id'] ?? 0))
            ->filter()
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $visibleCount = Product::query()
            ->visibleTo($user)
            ->whereNull('deleted_at')
            ->whereIn('id', $ids)
            ->count();
        if ($visibleCount !== $ids->count()) {
            throw ValidationException::withMessages([
                'details' => ['One or more selected products are inactive or unavailable. Refresh the product list and try again.'],
            ]);
        }
    }

    public function sendResponse($result, $msg)
    {
        $response = [
            'success' => true,
            'message' => $msg,
        ];
        if (! empty($result)) {
            $response['data'] = $result;
        }

        return response()->json($response, 200);
    }

    public function sendError($error_msg, $error = null)
    {
        $response = [
            'success' => false,
            'message' => $error_msg,
        ];
        if (isset($error)) {
            $response['errors'] = $error;
        }

        return response()->json($response, 400);
    }

    //    Set cookie
    public function setCookie($cookie_name, $cookie_value)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        $this->destroyCookie($cookie_name);
        setcookie($cookie_name, $cookie_value, time() + 2147483647, '/', $domain);
    }

    // Get cookie
    public function getCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return $_COOKIE[$cookie_name];
        } else {
            return false;
        }
    }

    // Has cookie
    public function hasCookie($cookie_name)
    {
        if (isset($_COOKIE[$cookie_name])) {
            return true;
        } else {
            return false;
        }
    }

    // Destroy cookie
    public function destroyCookie($cookie_name)
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE[$cookie_name])) {
            unset($_COOKIE[$cookie_name]);
            setcookie($cookie_name, '', time() - 2147483647, '/', $domain);

        }
    }

    // Clear cookie
    public function clearCookie()
    {
        $domain = ($_SERVER['SERVER_NAME'] != 'localhost') ? $_SERVER['SERVER_NAME'] : '.'.$_SERVER['SERVER_NAME'];
        if (isset($_COOKIE['Stocky_token'])) {
            unset($_COOKIE['Stocky_token']);
            setcookie('Stocky_token', '', time() - 2147483647, '/', $domain); // empty value and old timestamp
        }
    }

    // Set config mail
    public function Set_config_mail()
    {

        $server = DB::table('servers')->where('deleted_at', '=', null)->first();
        $settings = DB::table('settings')->where('deleted_at', '=', null)->first();
        if ($server && $settings) { // checking if table is not empty
            // Prefer sender_email from server, fallback to settings email
            $fromEmail = ($server->sender_email ?? null) ?: $settings->email;
            
            $config = [
                    'driver' => $server->mail_mailer,
                    'host' => $server->host,
                    'port' => $server->port,
                    'from' => ['address' => $fromEmail, 'name' => $server->sender_name],
                    'encryption' => $server->encryption,
                    'username' => $server->username,
                    'password' => $server->password,
                    'sendmail' => '/usr/sbin/sendmail -bs',
                    'pretend' => false,
                    'stream' => [
                        'ssl' => [
                            'allow_self_signed' => true,
                            'verify_peer' => false,
                            'verify_peer_name' => false,
                        ],
                    ],
                ];
            Config::set('mail', $config);
        }
    }
}
