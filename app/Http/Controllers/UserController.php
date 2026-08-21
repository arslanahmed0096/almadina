<?php

namespace App\Http\Controllers;

use App\Models\product_warehouse;
use App\Models\Permission;
use App\Models\Role;
use App\Models\role_user;
use App\Models\Setting;
use App\Models\Transfer;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Notifications\TransferWorkflowNotification;
use App\Notifications\OverdueCreditInvoiceNotification;
use App\utils\helpers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Intervention\Image\ImageManagerStatic as Image;

class UserController extends BaseController
{
    // ------------- GET ALL USERS---------\\

    public function index(request $request)
    {

        $this->authorizeForUser($request->user('api'), 'view', User::class);
        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'username', 1 => 'statut', 2 => 'phone', 3 => 'email'];
        $param = [0 => 'like', 1 => '=', 2 => 'like', 3 => 'like'];
        $data = [];

        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $ShowRecord = $user->hasRecordView();

        $users = User::where('deleted_at', '=', null)
            ->where(function ($query) use ($ShowRecord) {
            if (! $ShowRecord) {
                return $query->where('id', '=', Auth::user()->id);
            }
        });

        // Multiple Filter
        $Filtred = $helpers->filter($users, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('username', 'LIKE', "%{$request->search}%")
                        ->orWhere('firstname', 'LIKE', "%{$request->search}%")
                        ->orWhere('lastname', 'LIKE', "%{$request->search}%")
                        ->orWhere('email', 'LIKE', "%{$request->search}%")
                        ->orWhere('phone', 'LIKE', "%{$request->search}%");
                });
            });
        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $users = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        $roleNames = $roles->pluck('name', 'id');
        foreach ($users as $listedUser) {
            $listedUser->setAttribute('role_name', $roleNames->get($listedUser->role_id, ''));
        }

        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $permissions = Permission::where('deleted_at', null)
            ->orderBy('name')
            ->get(['id', 'name', 'label']);

        return response()->json([
            'users' => $users,
            'roles' => $roles,
            'warehouses' => $warehouses,
            'permissions' => $permissions,
            'totalRows' => $totalRows,
        ]);
    }

    // ------------- GET USER Auth ---------\\

    public function GetUserAuth(Request $request)
    {
        $helpers = new Helpers;
        $user = Auth::user();
        $settings = Setting::first();

        $userData = [
            'id' => $user->id,
            'avatar' => empty($user->avatar) || $user->avatar === 'no_avatar.png'
                ? 'default_avatar.png'
                : $user->avatar,
            'username' => $user->username,
            'currency' => $helpers->Get_Currency(),
            'logo' => $settings->logo ?? null,
            'default_language' => $settings->default_language ?? 'en',
            'show_language' => $settings->show_language ?? false,
            'footer' => $settings->footer ?? '',
            'developed_by' => $settings->developed_by ?? '',
            'app_name' => $settings->app_name ?? config('app.name'),
            'page_title_suffix' => $settings->page_title_suffix ?? '',
            'customize_button_visible' => (bool) ($settings->customize_button_visible ?? true),
            'hide_site_name' => (bool) ($settings->hide_site_name ?? false),
            'company' => $settings->CompanyName ?? '',
            'date_format' => $settings->date_format ?? 'YYYY-MM-DD',
            'price_format' => $settings->price_format ?? null,
            'dark_mode' => (bool) ($settings->dark_mode ?? false),
            'timezone' => $this->getEnvValue('APP_TIMEZONE', 'UTC'),
        ];

        $permissions = $user->effectivePermissionNames();

        $productsAlerts = product_warehouse::join('products', 'product_warehouse.product_id', '=', 'products.id')
            ->whereRaw('qte <= stock_alert')
            ->whereNull('product_warehouse.deleted_at')
            ->count();

        return response()->json([
            'success' => true,
            'user' => $userData,
            'notifs' => $productsAlerts,
            'permissions' => $permissions,
        ]);
    }

    // ------------- GET USER ROLES ---------\\

    public function GetUserRole(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => Auth::user()->effectivePermissionNames(),
        ]);

    }

    // ------------- GET UNREAD STOCK TRANSFER NOTIFICATIONS ---------\\

    public function GetTransferNotifications(Request $request)
    {
        $user = $request->user('api');
        $query = $user->unreadNotifications()
            ->whereIn('type', [TransferWorkflowNotification::class, OverdueCreditInvoiceNotification::class]);

        $notifications = (clone $query)
            ->latest()
            ->limit(25)
            ->get()
            ->map(function ($notification) {
                return [
                    'id' => $notification->id,
                    'transfer_id' => (int) ($notification->data['transfer_id'] ?? 0),
                    'sale_id' => isset($notification->data['sale_id']) ? (int) $notification->data['sale_id'] : null,
                    'title' => $notification->data['title'] ?? null,
                    'persisted' => true,
                    'message' => $notification->data['message'] ?? 'Stock transfer update',
                    'url' => $notification->data['url'] ?? '/app/transfers/list',
                    'reference' => $notification->data['reference'] ?? null,
                    'action' => $notification->data['action'] ?? null,
                    'created_at' => optional($notification->created_at)->toIso8601String(),
                ];
            })
            ->values();

        // Incoming transfers are actionable until receipt. Including them here also
        // recovers transfers dispatched before a user's database notification existed.
        $incomingQuery = Transfer::with('from_warehouse:id,name')
            ->whereNull('deleted_at')
            ->whereIn('workflow_status', [
                Transfer::WORKFLOW_DISPATCHED,
                Transfer::WORKFLOW_IN_TRANSIT,
                Transfer::WORKFLOW_PARTIALLY_RECEIVED,
            ]);

        if (! $user->isSuperAdmin() && ! (bool) $user->is_all_warehouses) {
            $warehouseIds = UserWarehouse::where('user_id', $user->id)->pluck('warehouse_id');
            $incomingQuery->whereIn('to_warehouse_id', $warehouseIds);
        }

        if (! $user->isSuperAdmin() && ! $user->effectivePermissionNames()->contains('transfer_view')) {
            $incomingQuery->whereRaw('1 = 0');
        }

        $notifiedTransferIds = $notifications->pluck('transfer_id')->filter();
        $incoming = $incomingQuery
            ->whereNotIn('id', $notifiedTransferIds)
            ->latest('dispatched_at')
            ->limit(25)
            ->get()
            ->map(function (Transfer $transfer) {
                $source = optional($transfer->from_warehouse)->name ?: 'the source warehouse';

                return [
                    'id' => 'incoming-'.$transfer->id,
                    'transfer_id' => (int) $transfer->id,
                    'persisted' => false,
                    'message' => "Incoming stock transfer {$transfer->Ref} from {$source} is awaiting receipt.",
                    'url' => '/app/transfers/detail/'.$transfer->id,
                    'reference' => $transfer->Ref,
                    'action' => 'incoming',
                    'created_at' => optional($transfer->dispatched_at ?: $transfer->updated_at)->toIso8601String(),
                ];
            });

        $items = $notifications
            ->concat($incoming)
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'notifications' => $items->take(10)->values(),
            'unread_count' => $items->count(),
        ]);
    }

    // ------------- MARK STOCK TRANSFER NOTIFICATION AS READ ---------\\

    public function MarkTransferNotificationRead(Request $request, $id)
    {
        $notification = $request->user('api')
            ->notifications()
            ->whereIn('type', [TransferWorkflowNotification::class, OverdueCreditInvoiceNotification::class])
            ->findOrFail($id);

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    // ------------- STORE NEW USER ---------\\

    public function store(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', User::class);
        $this->validate($request, [
            'email' => 'required|unique:users',
        ], [
            'email.unique' => 'This Email already taken.',
        ]);
        \DB::transaction(function () use ($request) {
            $image = $request->file('avatar');
            if ($image && is_object($image) && method_exists($image, 'getClientOriginalName')) {
                $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path('/images/avatar/' . $filename));
            } else {
                $filename = 'default_avatar.png';
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            $User = new User;
            $User->firstname = $request['firstname'];
            $User->lastname = $request['lastname'];
            $User->username = $request['username'];
            $User->email = $request['email'];
            $User->phone = $request['phone'];
            $User->password = Hash::make($request['password']);
            $User->avatar = $filename;
            $User->role_id = $request['role'];
            $User->is_all_warehouses = $is_all_warehouses;
            
            // Set record_view from request (default to false if not provided)
            if (isset($request['record_view'])) {
                $User->record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            } else {
                $User->record_view = 0;
            }
            
            $User->save();

            $role_user = new role_user;
            $role_user->user_id = $User->id;
            $role_user->role_id = $request['role'];
            $role_user->save();

            if (! $User->is_all_warehouses) {
                $User->assignedWarehouses()->sync($request['assigned_to']);
            }

            $this->syncUserPermissionOverrides(
                $User,
                $request->input('allowed_permission_ids', []),
                $request->input('denied_permission_ids', [])
            );

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ function show -----------\\

    public function show($id)
    {
        //

    }

    public function edit(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $user = User::where('deleted_at', '=', null)->findOrFail($id);
        $assigned_warehouses = UserWarehouse::where('user_id', $id)->pluck('warehouse_id')->toArray();
        $warehouses = Warehouse::where('deleted_at', '=', null)->whereIn('id', $assigned_warehouses)->pluck('id')->toArray();
        $roles = Role::where('deleted_at', null)->get(['id', 'name']);
        $all_warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);
        $permissions = Permission::where('deleted_at', null)
            ->orderBy('name')
            ->get(['id', 'name', 'label']);
        $permission_overrides = $this->userPermissionOverrideIds($user);

        return response()->json([
            'user' => $user,
            'assigned_warehouses' => $warehouses,
            'roles' => $roles,
            'warehouses' => $all_warehouses,
            'permissions' => $permissions,
            'permission_overrides' => $permission_overrides,
        ]);
    }

    // ------------- UPDATE  USER ---------\\

    public function update(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $this->validate($request, [
            'email' => 'required|email|unique:users',
            'email' => Rule::unique('users')->ignore($id),
        ], [
            'email.unique' => 'This Email already taken.',
        ]);

        \DB::transaction(function () use ($id, $request) {
            $user = User::findOrFail($id);
            $current = $user->password;

            if ($request->NewPassword != 'null') {
                if ($request->NewPassword != $current) {
                    $pass = Hash::make($request->NewPassword);
                } else {
                    $pass = $user->password;
                }

            } else {
                $pass = $user->password;
            }

            $currentAvatar = $user->avatar;
            if ($request->avatar != $currentAvatar) {
                $image = $request->file('avatar');
                $path = public_path() . '/images/avatar';
                if ($image && is_object($image) && method_exists($image, 'getClientOriginalName')) {
                    $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

                    $image_resize = Image::make($image->getRealPath());
                    $image_resize->resize(128, 128);
                    $image_resize->save(public_path('/images/avatar/' . $filename));

                    $userPhoto = $path . '/' . $currentAvatar;
                    if (file_exists($userPhoto)) {
                        if (! in_array($user->avatar, ['no_avatar.png', 'default_avatar.png'], true)) {
                            @unlink($userPhoto);
                        }
                    }
                } else {
                    // No uploaded file present — keep current avatar filename
                    $filename = $currentAvatar;
                }
            } else {
                $filename = $currentAvatar;
            }

            if ($request['is_all_warehouses'] == '1' || $request['is_all_warehouses'] == 'true') {
                $is_all_warehouses = 1;
            } else {
                $is_all_warehouses = 0;
            }

            // Set record_view from request (default to false if not provided)
            $record_view = 0;
            if (isset($request['record_view'])) {
                $record_view = ($request['record_view'] == '1' || $request['record_view'] == 'true' || $request['record_view'] == 1) ? 1 : 0;
            }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'],
                'password' => $pass,
                'record_view' => $record_view,
                'avatar' => $filename,
                'statut' => $request['statut'],
                'is_all_warehouses' => $is_all_warehouses,
                'role_id' => $request['role'],

            ]);

            role_user::where('user_id', $id)->update([
                'user_id' => $id,
                'role_id' => $request['role'],
            ]);

            $user_saved = User::where('deleted_at', '=', null)->findOrFail($id);
            $user_saved->assignedWarehouses()->sync($request['assigned_to']);
            $this->syncUserPermissionOverrides(
                $user_saved,
                $request->input('allowed_permission_ids', []),
                $request->input('denied_permission_ids', [])
            );

        }, 10);

        return response()->json(['success' => true]);

    }

    private function userPermissionOverrideIds(User $user): array
    {
        $user->loadMissing('permissionOverrides');

        return [
            'allow' => $user->permissionOverrides
                ->where('pivot.type', 'allow')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
            'deny' => $user->permissionOverrides
                ->where('pivot.type', 'deny')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all(),
        ];
    }

    private function syncUserPermissionOverrides(User $user, $allowedPermissionIds, $deniedPermissionIds): void
    {
        $allowedPermissionIds = $this->normalizePermissionIds($allowedPermissionIds);
        $deniedPermissionIds = $this->normalizePermissionIds($deniedPermissionIds);

        // Deny wins if the same permission is accidentally selected in both lists.
        $allowedPermissionIds = array_values(array_diff($allowedPermissionIds, $deniedPermissionIds));

        $sync = [];
        foreach ($allowedPermissionIds as $permissionId) {
            $sync[$permissionId] = ['type' => 'allow'];
        }
        foreach ($deniedPermissionIds as $permissionId) {
            $sync[$permissionId] = ['type' => 'deny'];
        }

        $user->permissionOverrides()->sync($sync);
    }

    private function normalizePermissionIds($value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', $value))));
    }

    // ------------- UPDATE PROFILE ---------\\

    public function updateProfile(Request $request, $id)
    {

        $this->validate($request, [
            'firstname' => 'required',
            'lastname' => 'required',
            'username' => 'required',
            'email' => 'required|email|unique:users',
            'email' => Rule::unique('users')->ignore($id),
            'phone' => 'required',
        ]
        );

        $id = Auth::user()->id;
        $user = User::findOrFail($id);

        $currentAvatar = $user->avatar;
        if ($request->avatar != $currentAvatar) {
            $image = $request->file('avatar');
            $path = public_path() . '/images/avatar';
            if ($image && is_object($image) && method_exists($image, 'getClientOriginalName')) {
                $filename = rand(11111111, 99999999) . $image->getClientOriginalName();

                $image_resize = Image::make($image->getRealPath());
                $image_resize->resize(128, 128);
                $image_resize->save(public_path('/images/avatar/' . $filename));

                $userPhoto = $path . '/' . $currentAvatar;

                if (file_exists($userPhoto)) {
                    if (! in_array($user->avatar, ['no_avatar.png', 'default_avatar.png'], true)) {
                        @unlink($userPhoto);
                    }
                }
            } else {
                // No uploaded file present — keep current avatar filename
                $filename = $currentAvatar;
            }
        } else {
            $filename = $currentAvatar;
        }

            User::whereId($id)->update([
                'firstname' => $request['firstname'],
                'lastname' => $request['lastname'],
                'username' => $request['username'],
                'email' => $request['email'],
                'phone' => $request['phone'],
                // Password is updated via dedicated endpoint
                'avatar' => $filename,

            ]);

        return response()->json(['avatar' => $filename, 'user' => $request['username']]);

    }

    // ------------- UPDATE PASSWORD (Profile) ---------\\

    public function updatePassword(Request $request)
    {
        $user = Auth::user();

        $this->validate($request, [
            'current_password' => ['required'],
            'new_password' => ['required', 'min:6', 'max:14', 'confirmed'],
        ]);

        if (! Hash::check($request->input('current_password'), $user->password)) {
            return response()->json([
                'success' => false,
                'message' => trans('translate.CurrentPasswordIncorrect'),
            ], 422);
        }

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        return response()->json([
            'success' => true,
            'message' => trans('translate.PasswordUpdated'),
        ]);
    }

    // ----------- IsActivated (Update Statut User) -------\\

    public function IsActivated(request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', User::class);

        $user = Auth::user();
        if ($request['id'] !== $user->id) {
            User::whereId($id)->update([
                'statut' => $request['statut'],
            ]);

            return response()->json([
                'success' => true,
            ]);
        } else {
            return response()->json([
                'success' => false,
            ]);
        }
    }

    // ------------- DELETE USER ---------\\
    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', User::class);

        $user = Auth::user();
        
        // Prevent user from deleting their own account
        if ($id == $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot delete your own account.',
            ], 403);
        }

        $userToDelete = User::where('deleted_at', '=', null)->findOrFail($id);

        // Soft delete the user (bypass mass-assignment on deleted_at)
        // and mark them as inactive (statut = 0) so any legacy checks
        // that still rely on statut will treat deleted users as disabled.
        $userToDelete->deleted_at = \Carbon\Carbon::now();
        $userToDelete->statut = 0;
        $userToDelete->save();

        return response()->json(['success' => true]);
    }

    /**
     * Lightweight users list for select dropdowns (e.g. linking a sales agent to a user).
     * Only requires authentication — no `view User` permission check, since the call
     * site only needs id + display name to render a picker.
     */
    public function listForSelect(Request $request)
    {
        $users = User::where('deleted_at', null)
            ->where('statut', 1)
            ->orderBy('firstname')
            ->orderBy('lastname')
            ->get(['id', 'firstname', 'lastname', 'username', 'email']);

        return response()->json(['users' => $users]);
    }

    public function GetPermissions()
    {
        $roles = Auth::user()->roles()->with('permissions')->first();
        $data = [];
        if ($roles) {
            foreach ($roles->permissions as $permission) {
                $item[$permission->name]['slug'] = $permission->name;
                $item[$permission->name]['id'] = $permission->id;

            }
            $data[] = $item;
        }

        return $data[0];

    }

    // ------------- GET USER Auth ---------\\

    public function GetInfoProfile(Request $request)
    {
        $data = Auth::user();

        return response()->json(['success' => true, 'user' => $data]);
    }

    // -------------- Get Environment Value Directly from .env File ---------------\\

    private function getEnvValue($key, $default = null)
    {
        $envFile = app()->environmentFilePath();
        if (!file_exists($envFile)) {
            return $default;
        }
        
        $content = file_get_contents($envFile);
        $lines = preg_split('/\r\n|\r|\n/', $content);
        
        foreach ($lines as $line) {
            // Skip comments and empty lines
            if (empty(trim($line)) || strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Check if line contains the key
            if (strpos($line, $key . '=') === 0) {
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $value = trim($parts[1]);
                    // Remove quotes if present
                    $value = trim($value, '"\'');
                    return $value;
                }
            }
        }
        
        return $default;
    }
}
