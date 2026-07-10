<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\UserLoginSession;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Login Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles authenticating users for the application and
    | redirecting them to your home screen. The controller uses a trait
    | to conveniently provide its functionality to your applications.
    |
    */

    /**
     * Where to redirect users after login.
     *
     * @var string
     */
    protected $redirectTo = RouteServiceProvider::HOME;

    /**
     * Create a new controller instance.
     *
     * @return void
     */


    
    /**
     * Get the needed authorization credentials from the request.
     *
     * @return array
     */
    protected function credentials(\Illuminate\Http\Request $request)
    {
        return ['email' => $request->{$this->username()}, 'password' => $request->password, 'statut' => 1];
    }

    public function __construct()
    {
        $this->middleware('guest')->except('logout');
    }

    public function showLoginForm()
    {
        return response()
            ->view('auth.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache')
            ->header('Expires', '0');
    }

    /**
     * Handle a login request to the application.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        $remember = (bool) $request->input('remember', false);

        if (Auth::attempt($this->credentials($request), $remember)) {
            // Regenerate the web session ID to prevent fixation
            $request->session()->regenerate();

            // Persist the current web session ID in user_login_sessions
            try {
                $user = Auth::guard('web')->user();
                if ($user) {
                    $sessionId = $request->session()->getId();

                    UserLoginSession::query()->updateOrCreate(
                        [
                            'user_id' => (int) $user->id,
                            'session_id' => $sessionId,
                        ],
                        [
                            'access_token_id' => $sessionId, // marker for web sessions
                            'ip_address' => $request->ip(),
                            'user_agent' => (string) ($request->userAgent() ?? ''),
                            'logged_in_at' => now(),
                            'last_activity_at' => now(),
                            'revoked_at' => null,
                        ]
                    );
                }
            } catch (\Throwable $e) {
                // Never break login if tracking fails
            }

            $intended = $request->session()->pull('url.intended');

            if ($this->isValidPostLoginRedirect($request, $intended)) {
                return redirect()->to($intended);
            }

            return redirect($this->redirectTo);
        }

        // Failed login
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 422);
        }

        return back()->withErrors([
            'email' => 'These credentials do not match our records.',
        ])->withInput($request->only('email', 'remember'));
    }

    /**
     * Log the user out of the application.
     */
    public function logout(Request $request)
    {
        // 1) Explicitly log out the web guard
        $user = Auth::guard('web')->user();

        if ($user) {
            // Clear remember token to avoid automatic re-authentication
            $user->setRememberToken(null);
            $user->save();
        }

        Auth::guard('web')->logout();

        // 2) Fully invalidate the web session
        //    - clears all session data
        //    - regenerates the session ID
        $request->session()->invalidate();

        // 3) Regenerate CSRF token for the new empty session
        $request->session()->regenerateToken();

        // 4) For SPA (AJAX) logout calls, return JSON and let the frontend
        //    perform a full-page navigation with window.location.replace('/login')
        if ($request->expectsJson() || $request->ajax()) {
            return response()->json(['success' => true]);
        }

        // 5) For classic form logouts, redirect to /login
        return redirect()->route('login');
    }
    
    
    

    /**
     * Get the login username to be used by the controller.
     */
    public function username()
    {
        return 'email';
    }

    private function isValidPostLoginRedirect(Request $request, $url): bool
    {
        if (! is_string($url) || trim($url) === '') {
            return false;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return false;
        }

        if (isset($parts['host']) && strcasecmp($parts['host'], $request->getHost()) !== 0) {
            return false;
        }

        $path = $parts['path'] ?? '/';
        $path = '/' . ltrim($path, '/');
        $lowerPath = strtolower($path);

        $blockedPrefixes = [
            '/images/',
            '/css/',
            '/js/',
            '/assets/',
            '/fonts/',
            '/vendor/',
            '/storage/',
        ];

        foreach ($blockedPrefixes as $prefix) {
            if (strpos($lowerPath, $prefix) === 0) {
                return false;
            }
        }

        if (in_array($lowerPath, ['/login', '/logout', '/csrf-token', '/favicon.ico', '/sw.js'], true)) {
            return false;
        }

        return ! preg_match('/\.(?:jpg|jpeg|png|gif|webp|svg|ico|css|js|map|woff2?|ttf|eot|pdf|xlsx?|csv)$/i', $lowerPath);
    }
}
