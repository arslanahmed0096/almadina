<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\Shipment;
use App\Models\UserWarehouse;
use App\Services\ShipmentEligibilityService;
use App\utils\helpers;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ShipmentController extends BaseController
{
    // ----------- Get ALL Shipments-------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Shipment::class);

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        $data = [];

        $shipments = Shipment::with('sale', 'sale.client', 'sale.warehouse')

        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('status', 'LIKE', "%{$request->search}%")
                        ->orWhere('delivered_to', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale', function ($q) use ($request) {
                                $q->where('Ref', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('sale.client', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });

                });
            });
        $totalRows = $shipments->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $shipments_data = $shipments->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($shipments_data as $shipment) {

            $item['id'] = $shipment['id'];
            $item['date'] = $shipment['date'];
            $item['shipment_ref'] = $shipment['Ref'];
            $item['status'] = $shipment['status'];
            $item['delivered_to'] = $shipment['delivered_to'];
            $item['shipping_address'] = $shipment['shipping_address'];
            $item['shipping_details'] = $shipment['shipping_details'];
            $item['sale_ref'] = $shipment['sale']['Ref'];
            $item['sale_id'] = $shipment['sale']['id'];
            $item['warehouse_name'] = $shipment['sale']['warehouse']->name;
            $item['customer_name'] = $shipment['sale']['client']->name;

            $data[] = $item;
        }

        return response()->json([
            'shipments' => $data,
            'totalRows' => $totalRows,
        ]);
    }

    // ----------- Store new Shipment -------\\

    public function store(Request $request, ShipmentEligibilityService $eligibilityService)
    {
        $this->authorizeForUser($request->user('api'), 'create', Shipment::class);

        $validated = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'sale_detail_ids' => ['required', 'array', 'min:1'],
            'sale_detail_ids.*' => ['required', 'integer', 'distinct', 'exists:sale_details,id'],
            'Ref' => ['nullable', 'string', 'max:192'],
            'delivered_to' => ['nullable', 'string', 'max:192'],
            'shipping_address' => ['nullable', 'string'],
            'shipping_details' => ['nullable', 'string'],
            'delivery_method' => ['required', Rule::in(['self_delivery', 'almadina_driver'])],
            'driver_name' => ['nullable', 'required_if:delivery_method,almadina_driver', 'string', 'max:192'],
        ]);

        $sale = Sale::findOrFail($validated['sale_id']);
        $this->assertSaleAccess($request, $sale);
        $result = $eligibilityService->shipSelectedItems(
            $sale,
            $validated['sale_detail_ids'],
            $validated,
            (int) Auth::id()
        );

        return response()->json([
            'success' => true,
            'message' => $result['all_shipped']
                ? 'All items were shipped and the sale is now Completed.'
                : 'Selected items were shipped. The sale remains Ordered.',
            'sale_status' => $result['sale']->statut,
            'shipment_status' => $result['shipment']->status,
        ]);

    }

    public function show(Request $request, $id, ShipmentEligibilityService $eligibilityService)
    {
        $this->authorizeForUser($request->user('api'), 'view', Shipment::class);

        $sale = Sale::findOrFail($id);
        $this->assertSaleAccess($request, $sale);

        $get_shipment = Shipment::where('sale_id', $id)->whereNull('deleted_at')->first();

        if ($get_shipment) {

            $shipment_data['Ref'] = $get_shipment->Ref;
            $shipment_data['sale_id'] = $get_shipment->sale_id;
            $shipment_data['delivered_to'] = $get_shipment->delivered_to ?: optional($sale->client)->name;
            $shipment_data['shipping_address'] = $get_shipment->shipping_address;
            $shipment_data['status'] = $get_shipment->status;
            $shipment_data['shipping_details'] = $get_shipment->shipping_details;
            $shipment_data['delivery_method'] = $get_shipment->delivery_method ?: 'self_delivery';
            $shipment_data['driver_name'] = $get_shipment->driver_name ?: '';

        } else {

            $shipment_data['Ref'] = $this->getNumberOrder();
            $shipment_data['sale_id'] = $id;
            $shipment_data['delivered_to'] = optional($sale->client)->name ?: '';
            $shipment_data['shipping_address'] = '';
            $shipment_data['status'] = '';
            $shipment_data['shipping_details'] = '';
            $shipment_data['delivery_method'] = 'self_delivery';
            $shipment_data['driver_name'] = '';
        }

        return response()->json([
            'shipment' => $shipment_data,
            'eligibility' => $eligibilityService->forSale($sale),
        ]);

    }

    // ----------- Update Shipment-------\\

    public function update(Request $request, $id, ShipmentEligibilityService $eligibilityService)
    {
        $this->authorizeForUser($request->user('api'), 'update', Shipment::class);

        $validated = $request->validate([
            'sale_id' => ['required', 'integer', 'exists:sales,id'],
            'sale_detail_ids' => ['required', 'array', 'min:1'],
            'sale_detail_ids.*' => ['required', 'integer', 'distinct', 'exists:sale_details,id'],
            'delivered_to' => ['nullable', 'string', 'max:192'],
            'shipping_address' => ['nullable', 'string'],
            'shipping_details' => ['nullable', 'string'],
            'delivery_method' => ['required', Rule::in(['self_delivery', 'almadina_driver'])],
            'driver_name' => ['nullable', 'required_if:delivery_method,almadina_driver', 'string', 'max:192'],
        ]);

        $shipment = Shipment::findOrFail($id);
        if ((int) $shipment->sale_id !== (int) $validated['sale_id']) {
            throw ValidationException::withMessages([
                'sale_id' => ['The shipment does not belong to the selected sale.'],
            ]);
        }
        $sale = Sale::findOrFail($validated['sale_id']);
        $this->assertSaleAccess($request, $sale);
        $result = $eligibilityService->shipSelectedItems(
            $sale,
            $validated['sale_detail_ids'],
            $validated,
            (int) Auth::id()
        );

        return response()->json([
            'success' => true,
            'message' => $result['all_shipped']
                ? 'All items were shipped and the sale is now Completed.'
                : 'Selected items were shipped. The sale remains Ordered.',
            'sale_status' => $result['sale']->statut,
            'shipment_status' => $result['shipment']->status,
        ]);

    }

    // ----------- delete Shipment-------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Shipment::class);

        \DB::transaction(function () use ($request, $id) {

            $shipment = Shipment::findOrFail($id);
            if ($shipment && $shipment->items()->exists()) {
                abort(422, 'A shipment with shipped items cannot be deleted.');
            }
            $shipment->delete();

            $sale = Sale::findOrFail($shipment->sale_id);
            $sale->update([
                'shipping_status' => $request['status'],
            ]);

        }, 10);

        return response()->json(['success' => true]);

    }

    // ------------- Reference Number Order SALE -----------\\

    public function getNumberOrder()
    {

        $last = DB::table('shipments')->latest('id')->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            $inMsg = $nwMsg[1] + 1;
            $code = $nwMsg[0].'_'.$inMsg;
        } else {
            $code = 'SM_1111';
        }

        return $code;
    }

    private function assertSaleAccess(Request $request, Sale $sale): void
    {
        $user = $request->user('api');
        if (! $user->hasRecordView()) {
            $this->authorizeForUser($user, 'check_record', $sale);
        }

        if (! $user->is_all_warehouses) {
            $allowed = UserWarehouse::where('user_id', $user->id)
                ->where('warehouse_id', $sale->warehouse_id)
                ->exists();
            abort_unless($allowed, 403, 'You are not allowed to access this sale warehouse.');
        }
    }
}
