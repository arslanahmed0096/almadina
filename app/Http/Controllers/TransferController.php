<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProcessStockTransferRequest;
use App\Http\Requests\ReceiveStockTransferRequest;
use App\Http\Requests\StoreStockTransferRequest;
use App\Http\Requests\TransferActionRequest;
use App\Models\Product;
use App\Models\product_warehouse;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Models\Warehouse;
use App\Services\BatchService;
use App\Services\StockTransferWorkflowService;
use App\utils\helpers;
use ArPHP\I18N\Arabic;
use Carbon\Carbon;
use DB;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use PDF;

class TransferController extends BaseController
{
    // ------------ Show All Transfers  -----------\\

    public function index(request $request)
    {
        $this->authorizeForUser($request->user('api'), 'view', Transfer::class);
        $canViewTransferPrice = $this->canViewTransferPrice($request);
        $user = Auth::user();
        $is_all_warehouses = $user->is_all_warehouses;

        $warehouse_ids = [];
        if (! $is_all_warehouses) {
            $warehouse_ids = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id')
                ->toArray();
        }

        // How many items do you want to display.
        $perPage = $request->limit;
        $pageStart = \Request::get('page', 1);
        // Start displaying items from this number;
        $offSet = ($pageStart * $perPage) - $perPage;
        $order = $request->SortField;
        $dir = $request->SortType;
        $helpers = new helpers;
        // Filter fields With Params to retrieve
        $columns = [0 => 'Ref', 1 => 'from_warehouse_id', 2 => 'to_warehouse_id', 3 => 'statut', 4 => 'workflow_status'];
        $param = [0 => 'like', 1 => '=', 2 => '=', 3 => 'like', 4 => '='];
        $data = [];

        // Transfer visibility is warehouse-scoped. Source approvers must be able to
        // see incoming requests even when they did not create them.
        $transfers = Transfer::with('from_warehouse', 'to_warehouse')
            ->where('deleted_at', '=', null);

            // ✅ Restrict by warehouses (from OR to)
        if (! $is_all_warehouses) {
            $transfers->where(function ($q) use ($warehouse_ids) {
                $q->whereIn('from_warehouse_id', $warehouse_ids)
                ->orWhereIn('to_warehouse_id', $warehouse_ids);
            });
        }

        // Multiple Filter
        $Filtred = $helpers->filter($transfers, $columns, $param, $request)
        // Search With Multiple Param
            ->where(function ($query) use ($request) {
                return $query->when($request->filled('search'), function ($query) use ($request) {
                    return $query->where('Ref', 'LIKE', "%{$request->search}%")
                        ->orWhere('statut', 'LIKE', "%{$request->search}%")
                        ->orWhere('workflow_status', 'LIKE', "%{$request->search}%")
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('from_warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        })
                        ->orWhere(function ($query) use ($request) {
                            return $query->whereHas('to_warehouse', function ($q) use ($request) {
                                $q->where('name', 'LIKE', "%{$request->search}%");
                            });
                        });
                });
            });

        if ($request->filled('created_at')) {
            $Filtred->whereDate('created_at', $request->created_at);
        }

        $totalRows = $Filtred->count();
        if ($perPage == '-1') {
            $perPage = $totalRows;
        }
        $transfers = $Filtred->offset($offSet)
            ->limit($perPage)
            ->orderBy($order, $dir)
            ->get();

        foreach ($transfers as $transfer) {
            $item['id'] = $transfer->id;
            $item['date'] = $transfer['date'].' '.$transfer['time'];
            $item['Ref'] = $transfer->Ref;
            $item['from_warehouse'] = $transfer['from_warehouse']->name;
            $item['to_warehouse'] = $transfer['to_warehouse']->name;
            if ($canViewTransferPrice) {
                $item['GrandTotal'] = $transfer->GrandTotal;
            }
            $item['items'] = $transfer->items;
            $item['statut'] = $transfer->statut;
            $item['approval_status'] = $transfer->approval_status;
            $item['workflow_status'] = $transfer->workflow_status ?: $transfer->statut;
            $item['response_note'] = $transfer->response_note;
            $item['requested_by'] = $transfer->user_id;
            $data[] = $item;
        }

        // The records remain warehouse-scoped above, while filter options include
        // both sides of a request (central source and requesting destination).
        $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'totalRows' => $totalRows,
            'warehouses' => $warehouses,
            'transfers' => $data,
            'can_view_transfer_price' => $canViewTransferPrice,
        ]);
    }

    // ------------ Store New Transfer -----------\\

    public function store(StoreStockTransferRequest $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Transfer::class);

        $validated = $request->validated();

        $this->assertProductsSelectable($request->user('api'), $request->input('details', []));
        $workflow = app(StockTransferWorkflowService::class);
        $workflow->assertWarehouseAccess($request->user('api'), (int) $validated['transfer']['from_warehouse'], 'request');

        $createdTransfer = \DB::transaction(function () use ($request, $validated) {
            $order = new Transfer;

            $order->date = $validated['transfer']['date'];
            $order->time = now()->format('H:i:s');
            $order->Ref = $this->getNumberOrder();
            $order->from_warehouse_id = $validated['transfer']['from_warehouse'];
            $order->to_warehouse_id = $validated['transfer']['to_warehouse'];
            $order->required_date = $validated['transfer']['required_date'] ?? null;
            $order->items = count($validated['details']);
            $order->tax_rate = (float) $request->input('transfer.tax_rate', 0);
            $order->TaxNet = (float) $request->input('transfer.TaxNet', 0);
            $order->discount = (float) $request->input('transfer.discount', 0);
            $order->shipping = (float) $request->input('transfer.shipping', 0);
            $order->statut = 'pending';
            $order->notes = $validated['transfer']['notes'];
            $order->request_note = $validated['transfer']['notes'];
            $order->GrandTotal = (float) ($validated['GrandTotal'] ?? 0);
            $order->user_id = Auth::user()->id;
            $order->approval_status = 'pending';
            $order->workflow_status = Transfer::WORKFLOW_PENDING_APPROVAL;
            $order->requested_at = now();
            $order->save();

            foreach ($validated['details'] as $value) {
                TransferDetail::create([
                    'transfer_id' => $order->id,
                    'quantity' => $value['quantity'],
                    'requested_quantity' => $value['quantity'],
                    'approved_quantity' => 0,
                    'dispatched_quantity' => 0,
                    'received_quantity' => 0,
                    'purchase_unit_id' => $value['purchase_unit_id'] ?? null,
                    'product_id' => $value['product_id'],
                    'product_variant_id' => $value['product_variant_id'] ?? null,
                    'cost' => (float) ($value['Unit_cost'] ?? 0),
                    'TaxNet' => (float) ($value['tax_percent'] ?? 0),
                    'tax_method' => $value['tax_method'] ?? '1',
                    'discount' => (float) ($value['discount'] ?? 0),
                    'discount_method' => $value['discount_Method'] ?? '1',
                    'total' => (float) ($value['subtotal'] ?? 0),
                    'requested_batches' => $value['batches'] ?? null,
                ]);
            }

            return $order;
        }, 10);

        $workflow->record($createdTransfer, $request->user('api'), null, Transfer::WORKFLOW_PENDING_APPROVAL, 'request_submitted', $createdTransfer->request_note, [], (int) $createdTransfer->to_warehouse_id);
        $workflow->notifyNewRequest($createdTransfer);

        return response()->json(['success' => true, 'id' => $createdTransfer->id]);
    }

    // ------------- Update Transfer -----------\\

    public function update(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Transfer::class);

        request()->validate([
            'transfer.to_warehouse' => 'required',
            'transfer.from_warehouse' => 'required',
        ]);

        \DB::transaction(function () use ($request, $id) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Transfer = Transfer::findOrFail($id);
            if ($current_Transfer->histories()->where('action', 'request_submitted')->exists()) {
                abort(422, 'Submitted stock requests cannot be edited. Create a new request if quantities must change.');
            }

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === Transfer->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Transfer);
            }

            $Old_Details = TransferDetail::where('transfer_id', $id)->get();
            $data = $request['details'];
            $Trans = $request->transfer;
            $length = count($data);

            // Only already-approved transfers affect stock at update time.
            // Pending or rejected transfers never touch stock here.
            $isApproved = $current_Transfer->isApproved();

            // Get Ids details
            $new_products_id = [];
            foreach ($data as $new_detail) {
                $new_products_id[] = $new_detail['id'];
            }

            // Pharmacy: reverse old batch movements before the warehouse-stock reversal
            // below so the per-batch ledger ends up consistent. Only matters when the
            // current transfer is one that actually touched stock (approved + not pending).
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()
                && $isApproved
                && in_array($current_Transfer->statut, ['completed', 'sent'], true)) {
                $batchService->reverseForTransferDetails($Old_Details);
            }

            // Init Data with old Parametre
            $old_products_id = [];
            foreach ($Old_Details as $key => $value) {
                // check if detail has purchase_unit_id Or Null
                if ($value['purchase_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $value['product_id'])
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                $old_products_id[] = $value->id;

                if ($value['purchase_unit_id'] !== null) {

                    if ($isApproved && $current_Transfer->statut == 'completed') {
                        if ($value['product_variant_id'] !== null) {

                            $warehouse_from_variant = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $warehouse_from_variant) {
                                if ($unit->operator == '/') {
                                    $warehouse_from_variant->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_from_variant->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_from_variant->save();
                            }

                            $warehouse_To_variant = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $warehouse_To_variant) {
                                if ($unit->operator == '/') {
                                    $warehouse_To_variant->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_To_variant->qte -= $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_To_variant->save();
                            }

                        } else {
                            $warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $warehouse_from) {
                                if ($unit->operator == '/') {
                                    $warehouse_from->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_from->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_from->save();
                            }

                            $warehouse_To = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $warehouse_To) {
                                if ($unit->operator == '/') {
                                    $warehouse_To->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_To->qte -= $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_To->save();
                            }
                        }

                    } elseif ($isApproved && $current_Transfer->statut == 'sent') {
                        if ($value['product_variant_id'] !== null) {

                            $Sent_variant_To = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $Sent_variant_To) {
                                if ($unit->operator == '/') {
                                    $Sent_variant_To->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $Sent_variant_To->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $Sent_variant_To->save();
                            }
                        } else {
                            $Sent_variant_From = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $Sent_variant_From) {
                                if ($unit->operator == '/') {
                                    $Sent_variant_From->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $Sent_variant_From->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $Sent_variant_From->save();
                            }
                        }
                    }

                    // Delete Detail
                    if (! in_array($old_products_id[$key], $new_products_id)) {
                        $TransferDetail = TransferDetail::findOrFail($value->id);
                        $TransferDetail->delete();
                    }
                }

            }

            // Update Data with New request
            $newPersistedDetails = [];
            foreach ($data as $key => $product_detail) {

                if ($product_detail['no_unit'] !== 0) {
                    $unit = Unit::where('id', $product_detail['purchase_unit_id'])->first();
                    if ($isApproved && $Trans['statut'] == 'completed') {
                        if ($product_detail['product_variant_id'] !== null) {

                            // --------- eliminate the quantity ''from_warehouse''--------------\\
                            $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['from_warehouse'])
                                ->where('product_id', $product_detail['product_id'])
                                ->where('product_variant_id', $product_detail['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse_from) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_from->save();
                            }

                            // --------- ADD the quantity ''TO_warehouse''------------------\\
                            $product_warehouse_to = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['to_warehouse'])
                                ->where('product_id', $product_detail['product_id'])
                                ->where('product_variant_id', $product_detail['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse_to) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_to->qte += $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_to->qte += $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_to->save();
                            }

                        } else {

                            // --------- eliminate the quantity ''from_warehouse''--------------\\
                            $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['from_warehouse'])
                                ->where('product_id', $product_detail['product_id'])->first();

                            if ($unit && $product_warehouse_from) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_from->save();
                            }

                            // --------- ADD the quantity ''TO_warehouse''------------------\\
                            $product_warehouse_to = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['to_warehouse'])
                                ->where('product_id', $product_detail['product_id'])->first();

                            if ($unit && $product_warehouse_to) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_to->qte += $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_to->qte += $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_to->save();
                            }
                        }

                    } elseif ($isApproved && $Trans['statut'] == 'sent') {

                        if ($product_detail['product_variant_id'] !== null) {

                            $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['from_warehouse'])
                                ->where('product_id', $product_detail['product_id'])
                                ->where('product_variant_id', $product_detail['product_variant_id'])
                                ->first();

                            if ($unit && $product_warehouse_from) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_from->save();
                            }

                        } else {

                            $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $Trans['from_warehouse'])
                                ->where('product_id', $product_detail['product_id'])->first();

                            if ($unit && $product_warehouse_from) {
                                if ($unit->operator == '/') {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] / $unit->operator_value;
                                } else {
                                    $product_warehouse_from->qte -= $product_detail['quantity'] * $unit->operator_value;
                                }
                                $product_warehouse_from->save();
                            }
                        }
                    }

                    $TransDetail['transfer_id'] = $id;
                    $TransDetail['quantity'] = $product_detail['quantity'];
                    $TransDetail['purchase_unit_id'] = $product_detail['purchase_unit_id'];
                    $TransDetail['product_id'] = $product_detail['product_id'];
                    $TransDetail['product_variant_id'] = $product_detail['product_variant_id'];
                    $TransDetail['cost'] = $product_detail['Unit_cost'];
                    $TransDetail['TaxNet'] = $product_detail['tax_percent'];
                    $TransDetail['tax_method'] = $product_detail['tax_method'];
                    $TransDetail['discount'] = $product_detail['discount'];
                    $TransDetail['discount_method'] = $product_detail['discount_Method'];
                    $TransDetail['total'] = $product_detail['subtotal'];

                    if (! in_array($product_detail['id'], $old_products_id)) {
                        $persistedDetail = TransferDetail::Create($TransDetail);
                    } else {
                        TransferDetail::where('id', $product_detail['id'])->update($TransDetail);
                        $persistedDetail = TransferDetail::find($product_detail['id']);
                    }
                    $newPersistedDetails[$key] = $persistedDetail;
                }
            }

            $current_Transfer->update([
                'to_warehouse_id' => $Trans['to_warehouse'],
                'from_warehouse_id' => $Trans['from_warehouse'],
                'date' => $Trans['date'],
                'notes' => $Trans['notes'],
                'statut' => $Trans['statut'],
                'items' => count($request['details']),
                'tax_rate' => $Trans['tax_rate'] ? $Trans['tax_rate'] : 0,
                'TaxNet' => $Trans['TaxNet'] ? $Trans['TaxNet'] : 0,
                'discount' => $Trans['discount'] ? $Trans['discount'] : 0,
                'shipping' => $Trans['shipping'] ? $Trans['shipping'] : 0,
                'GrandTotal' => $request['GrandTotal'],
            ]);

            // Pharmacy: re-apply per-batch movements now that TransferDetail rows exist.
            // Only when the new state actually touches stock (approved + completed/sent).
            // Keep input + persisted in lockstep so no_unit==0 rows don't misalign indices.
            if ($batchService->isSupported()
                && $isApproved
                && in_array($Trans['statut'], ['completed', 'sent'], true)) {
                $alignedInput = [];
                $alignedPersisted = [];
                foreach ($data as $key => $product_detail) {
                    if (isset($newPersistedDetails[$key])) {
                        $alignedInput[] = $product_detail;
                        $alignedPersisted[] = $newPersistedDetails[$key];
                    }
                }
                $batchService->applyForTransferWithAutoFallback(
                    $current_Transfer->fresh(),
                    $alignedInput,
                    $alignedPersisted
                );
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Delete Transfer -----------\\

    public function destroy(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'delete', Transfer::class);

        \DB::transaction(function () use ($id, $request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $current_Transfer = Transfer::findOrFail($id);
            if ($current_Transfer->histories()->where('action', 'request_submitted')->exists()) {
                if ($current_Transfer->workflow_status !== Transfer::WORKFLOW_PENDING_APPROVAL) {
                    abort(422, 'A processed stock request cannot be deleted.');
                }
                if ((int) $current_Transfer->user_id !== (int) $user->id && ! $user->isSuperAdmin()) {
                    abort(403, 'Only the requesting user can delete an unprocessed stock request.');
                }
            }
            $Old_Details = TransferDetail::where('transfer_id', $id)->get();

            // Check If User Has Permission view All Records
            if (! $view_records) {
                // Check If User->id === current_Transfer->id
                $this->authorizeForUser($request->user('api'), 'check_record', $current_Transfer);
            }

            // Only already-approved transfers affect stock when being deleted.
            $isApproved = $current_Transfer->isApproved();

            // Pharmacy: reverse batch movements before warehouse-stock reversal so the
            // per-batch ledger mirrors the warehouse change.
            $batchService = app(BatchService::class);
            if ($batchService->isSupported()
                && $isApproved
                && in_array($current_Transfer->statut, ['completed', 'sent'], true)) {
                $batchService->reverseForTransferDetails($Old_Details);
            }

            // Init Data with old Parametre
            foreach ($Old_Details as $key => $value) {
                // check if detail has purchase_unit_id Or Null
                if ($value['purchase_unit_id'] !== null) {
                    $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                } else {
                    $product_unit_purchase_id = Product::with('unitPurchase')
                        ->where('id', $value['product_id'])
                        ->first();
                    $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                }

                if ($isApproved && $current_Transfer->statut == 'completed') {
                    if ($value['product_variant_id'] !== null) {

                        $warehouse_from_variant = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $warehouse_from_variant) {
                            if ($unit->operator == '/') {
                                $warehouse_from_variant->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $warehouse_from_variant->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $warehouse_from_variant->save();
                        }

                        $warehouse_To_variant = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $warehouse_To_variant) {
                            if ($unit->operator == '/') {
                                $warehouse_To_variant->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $warehouse_To_variant->qte -= $value['quantity'] * $unit->operator_value;
                            }
                            $warehouse_To_variant->save();
                        }

                    } else {
                        $warehouse_from = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                            ->where('product_id', $value['product_id'])->first();

                        if ($unit && $warehouse_from) {
                            if ($unit->operator == '/') {
                                $warehouse_from->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $warehouse_from->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $warehouse_from->save();
                        }

                        $warehouse_To = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                            ->where('product_id', $value['product_id'])->first();

                        if ($unit && $warehouse_To) {
                            if ($unit->operator == '/') {
                                $warehouse_To->qte -= $value['quantity'] / $unit->operator_value;
                            } else {
                                $warehouse_To->qte -= $value['quantity'] * $unit->operator_value;
                            }
                            $warehouse_To->save();
                        }
                    }

                } elseif ($isApproved && $current_Transfer->statut == 'sent') {
                    if ($value['product_variant_id'] !== null) {

                        $Sent_variant_To = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                            ->where('product_id', $value['product_id'])
                            ->where('product_variant_id', $value['product_variant_id'])
                            ->first();

                        if ($unit && $Sent_variant_To) {
                            if ($unit->operator == '/') {
                                $Sent_variant_To->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $Sent_variant_To->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $Sent_variant_To->save();
                        }
                    } else {
                        $Sent_variant_From = product_warehouse::where('deleted_at', '=', null)
                            ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                            ->where('product_id', $value['product_id'])->first();

                        if ($unit && $Sent_variant_From) {
                            if ($unit->operator == '/') {
                                $Sent_variant_From->qte += $value['quantity'] / $unit->operator_value;
                            } else {
                                $Sent_variant_From->qte += $value['quantity'] * $unit->operator_value;
                            }
                            $Sent_variant_From->save();
                        }
                    }
                }

            }

            $current_Transfer->details()->delete();
            $current_Transfer->update([
                'deleted_at' => Carbon::now(),
            ]);

        }, 10);

        return response()->json(['success' => true]);
    }

    // -------------- Delete by selection  ---------------\\

    public function delete_by_selection(Request $request)
    {

        $this->authorizeForUser($request->user('api'), 'delete', Transfer::class);

        \DB::transaction(function () use ($request) {
            $user = Auth::user();
            // New way: Check user's record_view field (user-level boolean)
            // Backward compatibility: If record_view is null, fall back to role permission check
            $view_records = $user->hasRecordView();
            $selectedIds = $request->selectedIds;
            foreach ($selectedIds as $Transfer_id) {
                $current_Transfer = Transfer::findOrFail($Transfer_id);
                if ($current_Transfer->histories()->where('action', 'request_submitted')->exists()
                    && $current_Transfer->workflow_status !== Transfer::WORKFLOW_PENDING_APPROVAL) {
                    abort(422, 'One or more selected stock requests have already been processed and cannot be deleted.');
                }
                $Old_Details = TransferDetail::where('transfer_id', $Transfer_id)->get();

                // Check If User Has Permission view All Records
                if (! $view_records) {
                    // Check If User->id === Transfer->id
                    $this->authorizeForUser($request->user('api'), 'check_record', $current_Transfer);
                }

                // Only already-approved transfers affect stock when being deleted.
                $isApproved = $current_Transfer->isApproved();

                // Pharmacy: reverse batch movements before warehouse-stock reversal so
                // the per-batch ledger mirrors the warehouse change. Without this the
                // bulk-delete path would orphan transfer_detail_batches rows and drift
                // ProductBatch.qty.
                $batchService = app(BatchService::class);
                if ($batchService->isSupported()
                    && $isApproved
                    && in_array($current_Transfer->statut, ['completed', 'sent'], true)) {
                    $batchService->reverseForTransferDetails($Old_Details);
                }

                // Init Data with old Parametre
                foreach ($Old_Details as $key => $value) {
                    // check if detail has purchase_unit_id Or Null
                    if ($value['purchase_unit_id'] !== null) {
                        $unit = Unit::where('id', $value['purchase_unit_id'])->first();
                    } else {
                        $product_unit_purchase_id = Product::with('unitPurchase')
                            ->where('id', $value['product_id'])
                            ->first();
                        $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                    }

                    if ($isApproved && $current_Transfer->statut == 'completed') {
                        if ($value['product_variant_id'] !== null) {

                            $warehouse_from_variant = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $warehouse_from_variant) {
                                if ($unit->operator == '/') {
                                    $warehouse_from_variant->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_from_variant->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_from_variant->save();
                            }

                            $warehouse_To_variant = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $warehouse_To_variant) {
                                if ($unit->operator == '/') {
                                    $warehouse_To_variant->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_To_variant->qte -= $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_To_variant->save();
                            }

                        } else {
                            $warehouse_from = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $warehouse_from) {
                                if ($unit->operator == '/') {
                                    $warehouse_from->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_from->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_from->save();
                            }

                            $warehouse_To = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->to_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $warehouse_To) {
                                if ($unit->operator == '/') {
                                    $warehouse_To->qte -= $value['quantity'] / $unit->operator_value;
                                } else {
                                    $warehouse_To->qte -= $value['quantity'] * $unit->operator_value;
                                }
                                $warehouse_To->save();
                            }
                        }

                    } elseif ($isApproved && $current_Transfer->statut == 'sent') {
                        if ($value['product_variant_id'] !== null) {

                            $Sent_variant_To = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])
                                ->where('product_variant_id', $value['product_variant_id'])
                                ->first();

                            if ($unit && $Sent_variant_To) {
                                if ($unit->operator == '/') {
                                    $Sent_variant_To->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $Sent_variant_To->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $Sent_variant_To->save();
                            }
                        } else {
                            $Sent_variant_From = product_warehouse::where('deleted_at', '=', null)
                                ->where('warehouse_id', $current_Transfer->from_warehouse_id)
                                ->where('product_id', $value['product_id'])->first();

                            if ($unit && $Sent_variant_From) {
                                if ($unit->operator == '/') {
                                    $Sent_variant_From->qte += $value['quantity'] / $unit->operator_value;
                                } else {
                                    $Sent_variant_From->qte += $value['quantity'] * $unit->operator_value;
                                }
                                $Sent_variant_From->save();
                            }
                        }
                    }

                }

                $current_Transfer->details()->delete();
                $current_Transfer->update([
                    'deleted_at' => Carbon::now(),
                ]);
            }

        }, 10);

        return response()->json(['success' => true]);
    }

    // ------------ Reference Number of transfers  -----------\\

    // ------ batches_for_transfer ---------------\\
    //
    // Returns FEFO-ordered active batches at the SOURCE warehouse for batch-tracked
    // products. Mirrors batches_for_sale but authorized via the Transfer policy.

    public function batches_for_transfer(Request $request, $product_id, $warehouse_id, $variant_id = null)
    {
        $this->authorizeForUser($request->user('api'), 'create', Transfer::class);

        $productId = (int) $product_id;
        $warehouseId = (int) $warehouse_id;
        $variantId = ($variant_id !== null && $variant_id !== '' && $variant_id !== 'null' && (int) $variant_id > 0)
            ? (int) $variant_id
            : null;

        $batchService = app(BatchService::class);

        return response()->json([
            'supported' => $batchService->isSupported(),
            'batches' => $batchService->availableBatchesForSale($productId, $variantId, $warehouseId),
        ]);
    }

    public function getNumberOrder()
    {
        // Get prefix from settings, fallback to 'TR' if not set
        $setting = \App\Models\Setting::where('deleted_at', '=', null)->first();
        $prefix = !empty($setting->transfer_prefix) ? $setting->transfer_prefix : 'TR';
        
        // Get the last transfer with a reference that starts with the prefix
        $last = DB::table('transfers')
            ->where('Ref', 'like', $prefix.'_%')
            ->latest('id')
            ->first();

        if ($last) {
            $item = $last->Ref;
            $nwMsg = explode('_', $item);
            
            // Ensure valid structure before processing
            if (isset($nwMsg[1]) && is_numeric($nwMsg[1])) {
                $inMsg = $nwMsg[1] + 1;
                $code = $nwMsg[0].'_'.str_pad($inMsg, 4, '0', STR_PAD_LEFT);
            } else {
                $code = $prefix.'_0001'; // Fallback if reference is corrupted
            }
        } else {
            $code = $prefix.'_0001';
        }

        return $code;

    }

    // ------------- Show Form Edit Transfer-----------\\

    public function edit(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'update', Transfer::class);
        $user = Auth::user();
        // New way: Check user's record_view field (user-level boolean)
        // Backward compatibility: If record_view is null, fall back to role permission check
        $view_records = $user->hasRecordView();
        $Transfer_data = Transfer::with('details.product.unit')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        /**
         * Warehouses restriction
         * Allow if:
         * - user has access to all warehouses
         * - OR at least one of (from_warehouse_id OR to_warehouse_id)
         *   belongs to user assigned warehouses
         */
        $user_auth = auth()->user();

        if (! $user_auth->is_all_warehouses) {

            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                ->pluck('warehouse_id')
                ->toArray();

            $fromAllowed = !empty($Transfer_data->from_warehouse_id) 
                && in_array($Transfer_data->from_warehouse_id, $warehouses_id);

            $toAllowed = !empty($Transfer_data->to_warehouse_id) 
                && in_array($Transfer_data->to_warehouse_id, $warehouses_id);

            // Allow if at least one warehouse matches
            if (! $fromAllowed && ! $toAllowed) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not allowed to access this transfer (warehouse restriction).',
                ], 403);
            }
        }

        $details = [];
        // Check If User Has Permission view All Records
        if (! $view_records) {
            // Check If User->id === Transfer->id
            $this->authorizeForUser($request->user('api'), 'check_record', $Transfer_data);
        }

        if ($Transfer_data->from_warehouse_id) {
            if (Warehouse::where('id', $Transfer_data->from_warehouse_id)
                ->where('deleted_at', '=', null)
                ->first()) {
                $transfer['from_warehouse'] = $Transfer_data->from_warehouse_id;
            } else {
                $transfer['from_warehouse'] = '';
            }
        } else {
            $transfer['from_warehouse'] = '';
        }

        if ($Transfer_data->to_warehouse_id) {
            if (Warehouse::where('id', $Transfer_data->to_warehouse_id)->where('deleted_at', '=', null)->first()) {
                $transfer['to_warehouse'] = $Transfer_data->to_warehouse_id;
            } else {
                $transfer['to_warehouse'] = '';
            }
        } else {
            $transfer['to_warehouse'] = '';
        }

        $transfer['statut'] = $Transfer_data->statut;
        $transfer['notes'] = $Transfer_data->notes;
        $transfer['date'] = $Transfer_data->date;
        $transfer['tax_rate'] = $Transfer_data->tax_rate;
        $transfer['TaxNet'] = $Transfer_data->TaxNet;
        $transfer['discount'] = $Transfer_data->discount;
        $transfer['shipping'] = $Transfer_data->shipping;

        $batchesByDetail = app(BatchService::class)->batchesForTransferDetails($Transfer_data['details']);

        $detail_id = 0;
        foreach ($Transfer_data['details'] as $detail) {
            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
                $data['no_unit'] = 1;
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
                $data['no_unit'] = 0;
            }

            if ($detail->product_variant_id) {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)
                    ->where('product_variant_id', $detail->product_variant_id)
                    ->where('warehouse_id', $Transfer_data->from_warehouse_id)
                    ->first();

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
                $data['code'] = $productsVariants->code;

                $data['product_variant_id'] = $detail->product_variant_id;

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }
                $data['unitPurchase'] = $detail['product']['unitPurchase']->ShortName;

            } else {
                $item_product = product_warehouse::where('product_id', $detail->product_id)
                    ->where('deleted_at', '=', null)->where('warehouse_id', $Transfer_data->from_warehouse_id)
                    ->where('product_variant_id', '=', null)->first();

                $item_product ? $data['del'] = 0 : $data['del'] = 1;
                $data['product_variant_id'] = null;
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];

                if ($unit && $unit->operator == '/') {
                    $data['stock'] = $item_product ? $item_product->qte * $unit->operator_value : 0;
                } elseif ($unit && $unit->operator == '*') {
                    $data['stock'] = $item_product ? $item_product->qte / $unit->operator_value : 0;
                } else {
                    $data['stock'] = 0;
                }
            }

            $data['id'] = $detail->id;
            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = $detail->quantity;
            $data['product_id'] = $detail->product_id;
            $data['etat'] = 'current';
            $data['qte_copy'] = $detail->quantity;
            $data['unitPurchase'] = $unit->ShortName;
            $data['purchase_unit_id'] = $unit->id;

            if ($detail->discount_method == '2') {
                $data['DiscountNet'] = $detail->discount;
            } else {
                $data['DiscountNet'] = $detail->cost * $detail->discount / 100;
            }
            $tax_cost = $detail->TaxNet * (($detail->cost - $data['DiscountNet']) / 100);
            $data['Unit_cost'] = $detail->cost;
            $data['tax_percent'] = $detail->TaxNet;
            $data['tax_method'] = $detail->tax_method;
            $data['discount'] = $detail->discount;
            $data['discount_Method'] = $detail->discount_method;

            if ($detail->tax_method == '1') {
                $data['Net_cost'] = $detail->cost - $data['DiscountNet'];
                $data['taxe'] = $tax_cost;
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            } else {
                $data['Net_cost'] = ($detail->cost - $data['DiscountNet'] - $tax_cost);
                $data['taxe'] = $detail->cost - $data['Net_cost'] - $data['DiscountNet'];
                $data['subtotal'] = ($data['Net_cost'] * $data['quantity']) + ($tax_cost * $data['quantity']);
            }

            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            // The picker stores selected source batches under product_batch_id; we map
            // the saved pivot rows to that shape so the picker UI re-hydrates correctly.
            $existingBatches = $batchesByDetail[(int) $detail->id] ?? [];
            $data['batches'] = array_map(function ($b) {
                return [
                    'product_batch_id' => $b['source_batch_id'],
                    'batch_no' => $b['batch_no'],
                    'expiry_date' => $b['expiry_date'],
                    'qty_available' => 0, // hydrated by fetch_batches_for_detail on edit page
                    'qty' => $b['qty'],
                ];
            }, $existingBatches);

            $details[] = $data;
        }

        // get warehouses assigned to user
        $user_auth = auth()->user();

        if ($user_auth->is_all_warehouses) {

            $warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        } else {

            $warehouses_id = UserWarehouse::where('user_id', $user_auth->id)
                ->pluck('warehouse_id')
                ->toArray();

            $warehouses = Warehouse::where('deleted_at', '=', null)
                ->whereIn('id', $warehouses_id)
                ->get(['id', 'name']);

            // ✅ Append current transfer warehouses (from/to) if not assigned
            $appendIds = array_filter([
                $Transfer_data->from_warehouse_id ?? null
            ]);

            $missingIds = array_diff($appendIds, $warehouses_id);

            if (! empty($missingIds)) {
                $missingWarehouses = Warehouse::where('deleted_at', '=', null)
                    ->whereIn('id', $missingIds)
                    ->get(['id', 'name']);

                $warehouses = $warehouses->merge($missingWarehouses)->unique('id')->values();
            }
        }

        $to_warehouses = Warehouse::where('deleted_at', '=', null)->get(['id', 'name']);

        return response()->json([
            'details' => $details,
            'transfer' => $transfer,
            'warehouses' => $warehouses,
            'to_warehouses' => $to_warehouses,
        ]);
    }

    // ---------------- Get Details Transfer -----------------\\

    public function show(Request $request, $id)
    {

        $this->authorizeForUser($request->user('api'), 'view', Transfer::class);
        $canViewTransferPrice = $this->canViewTransferPrice($request);
        $user = Auth::user();
        $Transfer_data = Transfer::with([
            'details.product.unit', 'from_warehouse', 'to_warehouse', 'requester', 'processor',
            'acknowledger', 'histories.performer',
        ])
            ->where('deleted_at', '=', null)
            ->findOrFail($id);

        $details = [];
        $this->assertTransferAccessible($user, $Transfer_data);
        $workflow = app(StockTransferWorkflowService::class);
        $availability = $workflow->availabilityFor($Transfer_data);

        $transfer['date'] = $Transfer_data->date.' '.$Transfer_data->time;
        $transfer['note'] = $Transfer_data->notes;
        $transfer['Ref'] = $Transfer_data->Ref;
        $transfer['from_warehouse'] = $Transfer_data['from_warehouse']->name;
        $transfer['to_warehouse'] = $Transfer_data['to_warehouse']->name;
        $transfer['items'] = $Transfer_data->items;
        $transfer['statut'] = $Transfer_data->statut;
        $transfer['approval_status'] = $Transfer_data->approval_status;
        $transfer['workflow_status'] = $Transfer_data->workflow_status ?: $Transfer_data->statut;
        $transfer['request_note'] = $Transfer_data->request_note ?: $Transfer_data->notes;
        $transfer['response_note'] = $Transfer_data->response_note;
        $transfer['acknowledgement_note'] = $Transfer_data->acknowledgement_note;
        $transfer['required_date'] = optional($Transfer_data->required_date)->format('Y-m-d');
        $transfer['requested_by'] = optional($Transfer_data->requester)->username;
        $transfer['processed_by'] = optional($Transfer_data->processor)->username;
        $transfer['processed_at'] = optional($Transfer_data->processed_at)->toDateTimeString();
        $transfer['acknowledged_by'] = optional($Transfer_data->acknowledger)->username;
        $transfer['acknowledged_at'] = optional($Transfer_data->acknowledged_at)->toDateTimeString();
        $transfer['dispatched_at'] = optional($Transfer_data->dispatched_at)->toDateTimeString();
        $transfer['received_at'] = optional($Transfer_data->received_at)->toDateTimeString();
        if ($canViewTransferPrice) {
            $transfer['GrandTotal'] = $Transfer_data->GrandTotal;
        }

        $batchesByDetail = app(BatchService::class)->batchesForTransferDetails($Transfer_data['details']);

        foreach ($Transfer_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];

            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['quantity'] = $detail->quantity;
            $data['detail_id'] = (int) $detail->id;
            $data['requested_quantity'] = (float) ($detail->requested_quantity ?? $detail->quantity);
            $data['approved_quantity'] = (float) $detail->approved_quantity;
            $data['unapproved_quantity'] = max(0, $data['requested_quantity'] - $data['approved_quantity']);
            $data['dispatched_quantity'] = (float) $detail->dispatched_quantity;
            $data['received_quantity'] = (float) $detail->received_quantity;
            $data['decision_status'] = $detail->decision_status;
            $data['response_reason'] = $detail->response_reason;
            $stock = $availability[(int) $detail->id] ?? ['on_hand' => 0, 'reserved' => 0, 'transferable' => 0];
            $data['on_hand'] = $stock['on_hand'];
            $data['reserved'] = $stock['reserved'];
            $data['transferable'] = $stock['transferable'];
            $data['unit'] = $unit->ShortName;
            if ($canViewTransferPrice) {
                $data['total'] = $detail->total;
            }
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $detailBatches = $batchesByDetail[(int) $detail->id] ?? [];
            if (! $canViewTransferPrice) {
                $detailBatches = array_map(function ($batch) {
                    unset($batch['unit_cost'], $batch['cost'], $batch['total']);

                    return $batch;
                }, $detailBatches);
            }
            $data['batches'] = $detailBatches;

            $details[] = $data;
        }

        return response()->json([
            'details' => $details,
            'transfer' => $transfer,
            'history' => $Transfer_data->histories->map(function ($history) {
                return [
                    'id' => $history->id,
                    'action' => $history->action,
                    'previous_status' => $history->previous_status,
                    'new_status' => $history->new_status,
                    'note' => $history->note,
                    'metadata' => $history->metadata,
                    'performed_by' => optional($history->performer)->username,
                    'created_at' => optional($history->created_at)->toDateTimeString(),
                ];
            })->values(),
            'actions' => [
                'can_process' => $this->userHasAnyPermission($user, ['transfer_approve', 'transfer_partial_approve', 'transfer_decline'])
                    && $Transfer_data->workflow_status === Transfer::WORKFLOW_PENDING_APPROVAL
                    && $this->userHasWarehouse($user, (int) $Transfer_data->from_warehouse_id)
                    && ((int) $Transfer_data->user_id !== (int) $user->id || $user->isSuperAdmin()),
                'can_acknowledge' => $this->userHasAnyPermission($user, ['transfer_acknowledge'])
                    && $Transfer_data->workflow_status === Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT
                    && $this->userHasWarehouse($user, (int) $Transfer_data->to_warehouse_id),
                'can_dispatch' => $this->userHasAnyPermission($user, ['transfer_dispatch'])
                    && in_array($Transfer_data->workflow_status, [Transfer::WORKFLOW_ACKNOWLEDGED, Transfer::WORKFLOW_READY_FOR_DISPATCH], true)
                    && in_array($Transfer_data->approval_status, ['approved', 'partially_approved'], true)
                    && $this->userHasWarehouse($user, (int) $Transfer_data->from_warehouse_id),
                'can_receive' => $this->userHasAnyPermission($user, ['transfer_receive'])
                    && in_array($Transfer_data->workflow_status, [Transfer::WORKFLOW_DISPATCHED, Transfer::WORKFLOW_PARTIALLY_RECEIVED], true)
                    && $this->userHasWarehouse($user, (int) $Transfer_data->to_warehouse_id),
            ],
            'can_view_transfer_price' => $canViewTransferPrice,
        ]);
    }

    private function canViewTransferPrice(Request $request): bool
    {
        $user = $request->user('api');

        return $user
            && $user->effectivePermissionNames()->contains('transfer_price_view');
    }

    private function assertTransferAccessible(User $user, Transfer $transfer): void
    {
        if ($this->userHasWarehouse($user, (int) $transfer->from_warehouse_id)
            || $this->userHasWarehouse($user, (int) $transfer->to_warehouse_id)) {
            return;
        }

        abort(403, 'You are not allowed to access this stock transfer request.');
    }

    private function userHasWarehouse(User $user, int $warehouseId): bool
    {
        return $user->isSuperAdmin()
            || (bool) $user->is_all_warehouses
            || UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists();
    }

    private function userHasAnyPermission(User $user, array $permissions): bool
    {
        return $user->isSuperAdmin()
            || $user->effectivePermissionNames()->intersect($permissions)->isNotEmpty();
    }

    // ---------------- Show Form Create Transfer ---------------\\

    public function create(Request $request)
    {
        $this->authorizeForUser($request->user('api'), 'create', Transfer::class);

        $user = $request->user('api');
        $sourceLocked = ! ((bool) $user->is_all_warehouses || $user->isSuperAdmin());

        if ($sourceLocked) {
            $assignedWarehouseIds = UserWarehouse::where('user_id', $user->id)
                ->pluck('warehouse_id');
            $warehouses = Warehouse::whereNull('deleted_at')
                ->whereIn('id', $assignedWarehouseIds)
                ->orderBy('name')
                ->get(['id', 'name']);
            $assignedSourceWarehouseId = optional($warehouses->first())->id;
        } else {
            $warehouses = Warehouse::whereNull('deleted_at')
                ->orderBy('name')
                ->get(['id', 'name']);
            $assignedSourceWarehouseId = null;
        }

        $to_warehouses = Warehouse::whereNull('deleted_at')
            ->when($assignedSourceWarehouseId, fn ($query) => $query->where('id', '!=', $assignedSourceWarehouseId))
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'warehouses' => $warehouses,
            'to_warehouses' => $to_warehouses,
            'assigned_source_warehouse_id' => $assignedSourceWarehouseId,
            'source_locked' => $sourceLocked,
        ]);
    }

    // -------------- transfer_pdf -----------\\

    public function transfer_pdf(Request $request, $id)
    {
        $this->authorizeForUser($request->user('api'), 'view', Transfer::class);
        $details = [];
        $helpers = new helpers;
        $transfer_data = Transfer::with('details.product.unitPurchase')
            ->where('deleted_at', '=', null)
            ->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer_data);

        $batchesByDetail = app(BatchService::class)->batchesForTransferDetails($transfer_data['details']);

        $transfer['from_warehouse'] = $transfer_data['from_warehouse']->name;
        $transfer['to_warehouse'] = $transfer_data['to_warehouse']->name;

        $transfer['statut'] = $transfer_data->statut;
        // Expose approval_status in case PDFs need it in the future.
        $transfer['approval_status'] = $transfer_data->approval_status;
        $transfer['Ref'] = $transfer_data->Ref;
        $transfer['date'] = $transfer_data->date.' '.$transfer_data->time;

        $detail_id = 0;
        foreach ($transfer_data['details'] as $detail) {

            // -------check if detail has purchase_unit_id Or Null
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first();
            }

            if ($detail->product_variant_id) {

                $productsVariants = ProductVariant::where('product_id', $detail->product_id)
                    ->where('id', $detail->product_variant_id)->first();

                $data['code'] = $productsVariants->code;
                $data['name'] = '['.$productsVariants->name.']'.$detail['product']['name'];
            } else {
                $data['code'] = $detail['product']['code'];
                $data['name'] = $detail['product']['name'];
            }

            $data['detail_id'] = $detail_id += 1;
            $data['quantity'] = number_format($detail->quantity, 2, '.', '');
            $data['unit_purchase'] = $unit->ShortName;
            $data['is_batch_tracked'] = (bool) ($detail['product']['is_batch_tracked'] ?? false);
            $data['batches'] = $batchesByDetail[(int) $detail->id] ?? [];

            $details[] = $data;
        }

        $settings = Setting::where('deleted_at', '=', null)->first();
        $Html = view('pdf.transfer_pdf', [
            'setting' => $settings,
            'transfer' => $transfer,
            'details' => $details,
        ])->render();

        $arabic = new Arabic;
        $p = $arabic->arIdentify($Html);

        for ($i = count($p) - 1; $i >= 0; $i -= 2) {
            $utf8ar = $arabic->utf8Glyphs(substr($Html, $p[$i - 1], $p[$i] - $p[$i - 1]));
            $Html = substr_replace($Html, $utf8ar, $p[$i - 1], $p[$i] - $p[$i - 1]);
        }

        $pdf = PDF::loadHTML($Html);

        return $pdf->download('transfer.pdf');

    }

    // -------------- Stock request workflow -----------\\

    public function review(ProcessStockTransferRequest $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'process', Transfer::class);
        $validated = $request->validated();

        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $result = $workflow->process($transfer, $request->user('api'), $validated['items'], $validated['response_note']);

        return response()->json(['success' => true, 'transfer' => $result]);
    }

    public function acknowledge(TransferActionRequest $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'acknowledge', Transfer::class);
        $validated = $request->validated();
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $result = $workflow->acknowledge($transfer, $request->user('api'), $validated['note'] ?? null);

        return response()->json(['success' => true, 'transfer' => $result]);
    }

    public function dispatch(TransferActionRequest $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'dispatch', Transfer::class);
        $validated = $request->validated();
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $result = $workflow->dispatch($transfer, $request->user('api'), $validated['note'] ?? null);

        return response()->json(['success' => true, 'transfer' => $result]);
    }

    public function receive(ReceiveStockTransferRequest $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'receive', Transfer::class);
        $validated = $request->validated();
        $transfer = Transfer::whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $result = $workflow->receive($transfer, $request->user('api'), $validated['items'], $validated['note'] ?? null);

        return response()->json(['success' => true, 'transfer' => $result]);
    }

    /**
     * Backward-compatible full approval endpoint. New screens use review().
     */
    public function approve(Request $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'process', Transfer::class);
        $validated = $request->validate(['response_note' => 'required|string|max:5000']);
        $transfer = Transfer::with('details')->whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $items = $transfer->details->map(fn ($detail) => [
            'detail_id' => $detail->id,
            'approved_quantity' => $detail->requested_quantity ?? $detail->quantity,
            'response_reason' => null,
        ])->all();
        $workflow->process($transfer, $request->user('api'), $items, $validated['response_note']);

        return response()->json(['success' => true]);
    }

    /**
     * Mark a pending transfer as rejected – no stock movement is ever applied.
     */
    public function reject(Request $request, $id, StockTransferWorkflowService $workflow)
    {
        $this->authorizeForUser($request->user('api'), 'process', Transfer::class);
        $validated = $request->validate([
            'response_note' => 'required|string|max:5000',
            'reason' => 'required|string|max:2000',
        ]);
        $transfer = Transfer::with('details')->whereNull('deleted_at')->findOrFail($id);
        $this->assertTransferAccessible($request->user('api'), $transfer);
        $items = $transfer->details->map(fn ($detail) => [
            'detail_id' => $detail->id,
            'approved_quantity' => 0,
            'response_reason' => $validated['reason'],
        ])->all();
        $workflow->process($transfer, $request->user('api'), $items, $validated['response_note']);

        return response()->json(['success' => true]);
    }

    /**
     * Apply stock movement for an unapproved transfer using its saved details.
     * This mirrors the existing logic in store(), but is executed only once,
     * at approval time, and only for transfers that weren't touching stock yet.
     */
    protected function applyInitialStockMovement(Transfer $transfer)
    {
        $details = TransferDetail::where('transfer_id', $transfer->id)->get();

        foreach ($details as $detail) {
            // Resolve unit exactly like other transfer routines do.
            if ($detail->purchase_unit_id !== null) {
                $unit = Unit::where('id', $detail->purchase_unit_id)->first();
            } else {
                $product_unit_purchase_id = Product::with('unitPurchase')
                    ->where('id', $detail->product_id)
                    ->first();
                $unit = $product_unit_purchase_id
                    ? Unit::where('id', $product_unit_purchase_id['unitPurchase']->id)->first()
                    : null;
            }

            // No movement if unit is missing (keeps behaviour safe).
            if (! $unit) {
                continue;
            }

            // Mirror "completed" behaviour from store(): move stock from -> to.
            if ($transfer->statut == 'completed') {
                if ($detail->product_variant_id !== null) {
                    // FROM warehouse (variant)
                    $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->from_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->first();

                    if ($product_warehouse_from) {
                        if ($unit->operator == '/') {
                            $product_warehouse_from->qte -= $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_from->qte -= $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_from->save();
                    }

                    // TO warehouse (variant)
                    $product_warehouse_to = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->to_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->first();

                    if ($product_warehouse_to) {
                        if ($unit->operator == '/') {
                            $product_warehouse_to->qte += $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_to->qte += $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_to->save();
                    }
                } else {
                    // FROM warehouse (simple product)
                    $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->from_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->first();

                    if ($product_warehouse_from) {
                        if ($unit->operator == '/') {
                            $product_warehouse_from->qte -= $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_from->qte -= $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_from->save();
                    }

                    // TO warehouse (simple product)
                    $product_warehouse_to = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->to_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->first();

                    if ($product_warehouse_to) {
                        if ($unit->operator == '/') {
                            $product_warehouse_to->qte += $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_to->qte += $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_to->save();
                    }
                }
            } elseif ($transfer->statut == 'sent') {
                // Mirror "sent" behaviour from store(): move stock out of FROM only.
                if ($detail->product_variant_id !== null) {
                    $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->from_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->where('product_variant_id', $detail->product_variant_id)
                        ->first();

                    if ($product_warehouse_from) {
                        if ($unit->operator == '/') {
                            $product_warehouse_from->qte -= $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_from->qte -= $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_from->save();
                    }
                } else {
                    $product_warehouse_from = product_warehouse::where('deleted_at', '=', null)
                        ->where('warehouse_id', $transfer->from_warehouse_id)
                        ->where('product_id', $detail->product_id)
                        ->first();

                    if ($product_warehouse_from) {
                        if ($unit->operator == '/') {
                            $product_warehouse_from->qte -= $detail->quantity / $unit->operator_value;
                        } else {
                            $product_warehouse_from->qte -= $detail->quantity * $unit->operator_value;
                        }
                        $product_warehouse_from->save();
                    }
                }
            }
        }

        // Pharmacy: per-batch movements are NOT auto-FEFO'd at approval time. The
        // strict picker is the source of truth — for a pending transfer to update
        // the per-batch ledger on approval, the user must edit it after approval
        // and pick batches explicitly (the same flow they'd use to fix any line).
        // Warehouse stock above is still moved either way; only the per-batch
        // ledger is left untouched when no batches were saved.
    }
}
