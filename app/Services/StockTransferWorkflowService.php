<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Damage;
use App\Models\DamageDetail;
use App\Models\Employee;
use App\Models\product_warehouse;
use App\Models\Transfer;
use App\Models\TransferDetail;
use App\Models\TransferDetailBatch;
use App\Models\TransferStatusHistory;
use App\Models\TransferReturn;
use App\Models\TransferReturnDetail;
use App\Models\TransferStockMovement;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserWarehouse;
use App\Notifications\TransferWorkflowNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class StockTransferWorkflowService
{
    private const RESERVED_STATUSES = [
        Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT,
        Transfer::WORKFLOW_ACKNOWLEDGED,
        Transfer::WORKFLOW_READY_FOR_DISPATCH,
    ];

    public function availabilityFor(Transfer $transfer): array
    {
        $transfer->loadMissing('details.product', 'details');

        return $transfer->details->map(function (TransferDetail $detail) use ($transfer) {
            $unit = $this->unitFor($detail);
            $onHandBase = (float) ($this->stockRow($transfer, $detail)?->qte ?? 0);
            $reservedBase = $this->reservedBaseFor($transfer, $detail);

            return [
                'detail_id' => (int) $detail->id,
                'on_hand' => $this->fromBase($onHandBase, $unit),
                'reserved' => $this->fromBase($reservedBase, $unit),
                'transferable' => max(0, $this->fromBase($onHandBase - $reservedBase, $unit)),
            ];
        })->keyBy('detail_id')->all();
    }

    public function process(Transfer $transfer, User $user, array $items, string $responseNote): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $items, $responseNote) {
            $locked = Transfer::with('details.product')
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->findOrFail($transfer->id);

            if ($locked->workflow_status !== Transfer::WORKFLOW_PENDING_APPROVAL || $locked->approval_status !== 'pending') {
                throw ValidationException::withMessages(['transfer' => ['This request has already been processed. Reload to see its current status.']]);
            }
            if ((int) $locked->user_id === (int) $user->id && ! $user->isSuperAdmin()) {
                throw ValidationException::withMessages(['transfer' => ['The requesting user cannot approve their own stock request.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->from_warehouse_id, 'process');

            $submitted = collect($items)->keyBy(fn ($item) => (int) ($item['detail_id'] ?? 0));
            $approvedAny = false;
            $allFullyApproved = true;
            $historyItems = [];

            foreach ($locked->details as $detail) {
                $row = $submitted->get((int) $detail->id, []);
                $requested = (float) ($detail->requested_quantity ?? $detail->quantity);
                $approved = round((float) ($row['approved_quantity'] ?? 0), 6);
                $reason = trim((string) ($row['response_reason'] ?? ''));

                if ($approved < 0 || $approved > $requested + 0.000001) {
                    throw ValidationException::withMessages([
                        "items.{$detail->id}.approved_quantity" => ['Approved quantity must be between zero and the requested quantity.'],
                    ]);
                }

                $stock = $this->lockedStockRow($locked, $detail);
                $unit = $this->unitFor($detail);
                $transferableBase = max(0, (float) ($stock?->qte ?? 0) - $this->reservedBaseFor($locked, $detail));
                if ($this->toBase($approved, $unit) > $transferableBase + 0.000001) {
                    throw ValidationException::withMessages([
                        "items.{$detail->id}.approved_quantity" => ['Approved quantity exceeds currently transferable stock. Reload and review availability.'],
                    ]);
                }

                $decision = $approved <= 0
                    ? 'declined'
                    : ($approved + 0.000001 >= $requested ? 'approved' : 'partially_approved');
                if ($decision !== 'approved' && $reason === '') {
                    throw ValidationException::withMessages([
                        "items.{$detail->id}.response_reason" => ['A reason is required for every partially approved or declined item.'],
                    ]);
                }

                $detail->update([
                    'approved_quantity' => $approved,
                    'decision_status' => $decision,
                    'response_reason' => $reason !== '' ? $reason : null,
                ]);

                $approvedAny = $approvedAny || $approved > 0;
                $allFullyApproved = $allFullyApproved && $decision === 'approved';
                $historyItems[] = [
                    'detail_id' => (int) $detail->id,
                    'product_id' => (int) $detail->product_id,
                    'requested_quantity' => $requested,
                    'approved_quantity' => $approved,
                    'decision_status' => $decision,
                    'response_reason' => $reason,
                ];
            }

            $decision = ! $approvedAny ? 'declined' : ($allFullyApproved ? 'approved' : 'partially_approved');
            $requiredPermission = match ($decision) {
                'approved' => 'transfer_approve',
                'partially_approved' => 'transfer_partial_approve',
                default => 'transfer_decline',
            };
            if (! $user->isSuperAdmin() && ! $user->effectivePermissionNames()->contains($requiredPermission)) {
                throw ValidationException::withMessages(['transfer' => ['You do not have permission for this transfer decision.']]);
            }
            $previous = $locked->workflow_status;
            $locked->update([
                'approval_status' => $decision,
                'workflow_status' => Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT,
                'response_note' => trim($responseNote),
                'processed_by' => $user->id,
                'processed_at' => now(),
            ]);

            $this->record($locked, $user, $previous, Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT, $decision, $responseNote, [
                'decision' => $decision,
                'items' => $historyItems,
            ], (int) $locked->from_warehouse_id);
            $this->notifyWarehouse($locked, (int) $locked->to_warehouse_id, 'transfer_view', 'processed',
                "Stock request {$locked->Ref} was " . str_replace('_', ' ', $decision) . '.');

            return $locked->fresh('details');
        }, 10);
    }

    public function acknowledge(Transfer $transfer, User $user, ?string $note): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $note) {
            $locked = Transfer::whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->workflow_status !== Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT) {
                throw ValidationException::withMessages(['transfer' => ['This response has already been acknowledged or is not ready for acknowledgement.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->to_warehouse_id, 'acknowledge');

            $previous = $locked->workflow_status;
            $locked->update([
                'workflow_status' => Transfer::WORKFLOW_ACKNOWLEDGED,
                'acknowledgement_note' => $note !== null ? trim($note) : null,
                'acknowledged_by' => $user->id,
                'acknowledged_at' => now(),
            ]);
            $this->record($locked, $user, $previous, Transfer::WORKFLOW_ACKNOWLEDGED, 'acknowledge', $note, [], (int) $locked->to_warehouse_id);
            if (in_array($locked->approval_status, ['approved', 'partially_approved'], true)) {
                $locked->workflow_status = Transfer::WORKFLOW_READY_FOR_DISPATCH;
                $locked->save();
                $this->record($locked, null, Transfer::WORKFLOW_ACKNOWLEDGED, Transfer::WORKFLOW_READY_FOR_DISPATCH, 'ready_for_dispatch', null, [], (int) $locked->from_warehouse_id);
            }
            $this->notifyWarehouse($locked, (int) $locked->from_warehouse_id, 'transfer_approve', 'acknowledged',
                "Stock request {$locked->Ref} was acknowledged by the requesting branch.");

            return $locked->fresh();
        }, 10);
    }

    public function dispatch(Transfer $transfer, User $user, ?string $note): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $note) {
            $locked = Transfer::with('details.product')->whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($locked->workflow_status, [Transfer::WORKFLOW_ACKNOWLEDGED, Transfer::WORKFLOW_READY_FOR_DISPATCH], true)) {
                throw ValidationException::withMessages(['transfer' => ['Only an acknowledged request can be dispatched.']]);
            }
            if (! in_array($locked->approval_status, ['approved', 'partially_approved'], true)) {
                throw ValidationException::withMessages(['transfer' => ['A declined request cannot be dispatched.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->from_warehouse_id, 'dispatch');

            foreach ($locked->details as $detail) {
                $approved = (float) $detail->approved_quantity;
                if ($approved <= 0) {
                    continue;
                }
                $stock = $this->lockedStockRow($locked, $detail);
                $unit = $this->unitFor($detail);
                $neededBase = $this->toBase($approved, $unit);
                $otherReservedBase = $this->reservedBaseFor($locked, $detail);
                if (! $stock || ((float) $stock->qte - $otherReservedBase) + 0.000001 < $neededBase) {
                    throw ValidationException::withMessages([
                        "items.{$detail->id}" => ['Stock changed after approval and is no longer sufficient to dispatch this item.'],
                    ]);
                }
                $stock->qte = (float) $stock->qte - $neededBase;
                $stock->save();
                $detail->update(['dispatched_quantity' => $approved]);
            }

            $previous = $locked->workflow_status;
            $locked->statut = 'sent';
            $locked->workflow_status = Transfer::WORKFLOW_DISPATCHED;
            $locked->dispatched_by = $user->id;
            $locked->dispatched_at = now();
            $locked->save();

            $this->dispatchBatches($locked);
            $this->record($locked, $user, $previous, Transfer::WORKFLOW_DISPATCHED, 'dispatch', $note, [], (int) $locked->from_warehouse_id);
            $this->notifyWarehouse($locked, (int) $locked->to_warehouse_id, 'transfer_view', 'dispatched',
                "Stock request {$locked->Ref} was dispatched.");

            return $locked->fresh('details');
        }, 10);
    }

    public function receive(Transfer $transfer, User $user, array $items, ?string $note): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $items, $note) {
            $locked = Transfer::with('details.product')->whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($locked->workflow_status, [Transfer::WORKFLOW_DISPATCHED, Transfer::WORKFLOW_PARTIALLY_RECEIVED], true)) {
                throw ValidationException::withMessages(['transfer' => ['This transfer is not awaiting receipt.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->to_warehouse_id, 'receive');

            $submitted = collect($items)->keyBy(fn ($item) => (int) ($item['detail_id'] ?? 0));
            $anyReceived = false;
            $allReceived = true;
            $receivedDeltas = [];

            foreach ($locked->details as $detail) {
                $dispatched = (float) $detail->dispatched_quantity;
                $current = (float) $detail->received_quantity;
                $target = round((float) ($submitted->get((int) $detail->id)['received_quantity'] ?? $current), 6);
                if ($target < $current - 0.000001 || $target > $dispatched + 0.000001) {
                    throw ValidationException::withMessages([
                        "items.{$detail->id}.received_quantity" => ['Received quantity cannot decrease or exceed the dispatched quantity.'],
                    ]);
                }

                $delta = $target - $current;
                if ($delta > 0) {
                    $unit = $this->unitFor($detail);
                    $stock = $this->lockedDestinationStockRow($locked, $detail);
                    $stock->qte = (float) $stock->qte + $this->toBase($delta, $unit);
                    $stock->save();
                    $detail->update(['received_quantity' => $target]);
                    $receivedDeltas[(int) $detail->id] = $delta;
                }

                $anyReceived = $anyReceived || $target > 0;
                $allReceived = $allReceived && ($dispatched <= 0 || $target + 0.000001 >= $dispatched);
            }

            if (empty($receivedDeltas)) {
                throw ValidationException::withMessages(['items' => ['Enter at least one newly received quantity.']]);
            }

            $this->receiveBatches($locked, $receivedDeltas);
            $previous = $locked->workflow_status;
            $next = $allReceived ? Transfer::WORKFLOW_COMPLETED : Transfer::WORKFLOW_PARTIALLY_RECEIVED;
            $locked->workflow_status = $next;
            $locked->statut = $allReceived ? 'completed' : 'sent';
            $locked->received_by = $user->id;
            $locked->received_at = $allReceived ? now() : $locked->received_at;
            $locked->save();
            $this->record($locked, $user, $previous, $next, $allReceived ? 'receive_complete' : 'receive_partial', $note, [
                'received_deltas' => $receivedDeltas,
            ], (int) $locked->to_warehouse_id);
            $this->notifyWarehouse($locked, (int) $locked->from_warehouse_id, 'transfer_dispatch', 'received',
                "Stock request {$locked->Ref} was " . ($allReceived ? 'fully received.' : 'partially received.'));

            return $locked->fresh('details');
        }, 10);
    }

    public function dispatchOutbound(Transfer $transfer, User $user, int $driverId, ?string $note = null, ?string $vehicle = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $driverId, $note, $vehicle) {
            $locked = Transfer::with('details.product')->whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($locked->workflow_status, [Transfer::WORKFLOW_DRAFT, Transfer::WORKFLOW_READY_FOR_DISPATCH, Transfer::WORKFLOW_ACKNOWLEDGED], true)) {
                throw ValidationException::withMessages(['transfer' => ['Only a draft or ready transfer can be dispatched.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->from_warehouse_id, 'dispatch');
            $driver = $this->activeDriver($driverId);

            foreach ($locked->details as $detail) {
                $quantity = (float) ($detail->requested_quantity ?? $detail->quantity);
                $stock = $this->lockedStockRow($locked, $detail);
                $unit = $this->unitFor($detail);
                $baseQuantity = $this->toBase($quantity, $unit);
                if (! $stock || (float) $stock->qte + 0.000001 < $baseQuantity) {
                    throw ValidationException::withMessages(["items.{$detail->id}" => ["Insufficient transferable stock for {$detail->product?->name}."]]);
                }
                $stock->qte = (float) $stock->qte - $baseQuantity;
                $stock->save();
                $detail->update([
                    'approved_quantity' => $quantity,
                    'dispatched_quantity' => $quantity,
                    'decision_status' => 'approved',
                ]);
                $this->movement($locked, $detail, 'dispatch_out', 'in_transit', $quantity, $user, "dispatch:{$detail->id}", (int) $locked->from_warehouse_id, [
                    'driver_id' => $driver->id,
                ]);
            }

            $previous = $locked->workflow_status;
            $locked->update([
                'driver_id' => $driver->id,
                'vehicle_details' => $vehicle ? trim($vehicle) : null,
                'dispatch_note' => $note ? trim($note) : null,
                'dispatched_by' => $user->id,
                'dispatched_at' => now(),
                'workflow_status' => Transfer::WORKFLOW_IN_TRANSIT,
                'approval_status' => 'approved',
                'statut' => 'sent',
            ]);
            $this->dispatchBatches($locked);
            $this->record($locked, $user, $previous, Transfer::WORKFLOW_IN_TRANSIT, 'transfer_dispatched', $note, ['driver_id' => $driver->id], (int) $locked->from_warehouse_id);
            $this->notifyWarehouse($locked, (int) $locked->to_warehouse_id, 'transfer_view', 'dispatched',
                "Incoming stock transfer {$locked->Ref} from {$locked->from_warehouse?->name} is on the way. Driver: {$driver->firstname} {$driver->lastname}.");

            return $locked->fresh(['details', 'driver']);
        }, 10);
    }

    public function receiveDelivery(Transfer $transfer, User $user, array $items, ?string $note = null, ?string $returnNote = null): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $items, $note, $returnNote) {
            $locked = Transfer::with(['details.product', 'from_warehouse', 'to_warehouse'])->whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if (! in_array($locked->workflow_status, [Transfer::WORKFLOW_IN_TRANSIT, Transfer::WORKFLOW_DISPATCHED], true)) {
                throw ValidationException::withMessages(['transfer' => ['This transfer is not awaiting receipt or has already been received.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->to_warehouse_id, 'receive');
            $submitted = collect($items)->keyBy(fn ($item) => (int) ($item['detail_id'] ?? 0));
            $returnRows = [];
            $totalAccepted = 0.0;
            $totalRejected = 0.0;
            $acceptedByDetail = [];

            foreach ($locked->details as $detail) {
                $row = $submitted->get((int) $detail->id);
                if (! $row) throw ValidationException::withMessages(["items.{$detail->id}" => ['A receiving result is required for every dispatched item.']]);
                $dispatched = (float) $detail->dispatched_quantity;
                $accepted = round((float) ($row['accepted_quantity'] ?? 0), 6);
                $rejected = round((float) ($row['rejected_quantity'] ?? 0), 6);
                if ($accepted < 0 || $rejected < 0 || abs(($accepted + $rejected) - $dispatched) > 0.000001) {
                    throw ValidationException::withMessages(["items.{$detail->id}" => ['Accepted quantity plus rejected quantity must equal the dispatched quantity.']]);
                }
                $reason = trim((string) ($row['rejection_reason_code'] ?? ''));
                $reasonNote = trim((string) ($row['rejection_note'] ?? ''));
                if ($rejected > 0 && $reason === '') throw ValidationException::withMessages(["items.{$detail->id}.rejection_reason_code" => ['A rejection reason is required.']]);
                if ($rejected > 0 && $reason === 'other' && $reasonNote === '') throw ValidationException::withMessages(["items.{$detail->id}.rejection_note" => ['A detailed explanation is required when Other is selected.']]);

                if ($accepted > 0) {
                    $destination = $this->lockedDestinationStockRow($locked, $detail);
                    $destination->qte = (float) $destination->qte + $this->toBase($accepted, $this->unitFor($detail));
                    $destination->save();
                    $acceptedByDetail[$detail->id] = $accepted;
                    $this->movement($locked, $detail, 'receive_in', 'available', $accepted, $user, "receive:{$detail->id}", (int) $locked->to_warehouse_id);
                }
                if ($rejected > 0) {
                    $this->movement($locked, $detail, 'reject_for_return', 'return_pending', $rejected, $user, "reject:{$detail->id}", null, ['reason' => $reason, 'note' => $reasonNote]);
                    $returnRows[] = compact('detail', 'rejected', 'reason', 'reasonNote');
                }
                $detail->update([
                    'accepted_quantity' => $accepted, 'received_quantity' => $accepted,
                    'rejected_quantity' => $rejected, 'rejection_reason_code' => $reason ?: null,
                    'rejection_note' => $reasonNote ?: null, 'rejected_by' => $rejected > 0 ? $user->id : null,
                    'rejected_at' => $rejected > 0 ? now() : null,
                ]);
                $totalAccepted += $accepted;
                $totalRejected += $rejected;
            }

            if ($totalRejected > 0 && ! $user->isSuperAdmin()) {
                $granted = $user->effectivePermissionNames();
                $required = collect(['transfer_partial_receive', 'transfer_reject_items', 'transfer_return_create']);
                if ($required->diff($granted)->isNotEmpty()) {
                    throw ValidationException::withMessages(['items' => ['You do not have permission to reject items and create a transfer return.']]);
                }
            }

            $this->receiveBatches($locked, $acceptedByDetail);
            $locked->receiving_note = $note ? trim($note) : null;
            $locked->received_by = $user->id;
            $locked->received_at = now();

            if ($totalRejected > 0) {
                $previous = $locked->workflow_status;
                $locked->workflow_status = Transfer::WORKFLOW_PARTIALLY_RECEIVED;
                $locked->save();
                $this->record($locked, $user, $previous, Transfer::WORKFLOW_PARTIALLY_RECEIVED, 'partial_receipt', $note, compact('totalAccepted', 'totalRejected'), (int) $locked->to_warehouse_id);
                $return = $this->createReturn($locked, $user, $returnRows, $returnNote);
                $locked->workflow_status = Transfer::WORKFLOW_RETURN_PENDING;
                $locked->save();
                $this->record($locked, $user, Transfer::WORKFLOW_PARTIALLY_RECEIVED, Transfer::WORKFLOW_RETURN_PENDING, 'return_created', $returnNote, ['return_id' => $return->id, 'return_reference' => $return->reference], (int) $locked->to_warehouse_id);
                $this->notifyWarehouse($locked, (int) $locked->from_warehouse_id, 'transfer_return_receive', 'items_rejected',
                    "{$locked->to_warehouse->name} partially received {$locked->Ref}: {$totalAccepted} accepted, {$totalRejected} returning. " . ($returnNote ?: 'See item reasons.'));
            } else {
                $previous = $locked->workflow_status;
                $locked->workflow_status = Transfer::WORKFLOW_RECEIVED;
                $locked->statut = 'completed';
                $locked->save();
                $this->record($locked, $user, $previous, Transfer::WORKFLOW_RECEIVED, 'full_receipt', $note, compact('totalAccepted'), (int) $locked->to_warehouse_id);
                $locked->workflow_status = Transfer::WORKFLOW_COMPLETED;
                $locked->save();
                $this->record($locked, $user, Transfer::WORKFLOW_RECEIVED, Transfer::WORKFLOW_COMPLETED, 'transfer_completed', null, [], (int) $locked->to_warehouse_id);
                $this->notifyWarehouse($locked, (int) $locked->from_warehouse_id, 'transfer_view', 'received', "Transfer {$locked->Ref} was fully received and completed.");
            }
            return $locked->fresh(['details', 'transferReturn.details']);
        }, 10);
    }

    public function dispatchReturn(TransferReturn $return, User $user, int $driverId, ?string $note = null, ?string $vehicle = null): TransferReturn
    {
        return DB::transaction(function () use ($return, $user, $driverId, $note, $vehicle) {
            $locked = TransferReturn::with(['transfer', 'details'])->lockForUpdate()->findOrFail($return->id);
            if (! in_array($locked->status, [TransferReturn::PENDING, TransferReturn::READY], true)) throw ValidationException::withMessages(['return' => ['This return has already been dispatched.']]);
            $this->assertWarehouseAccess($user, (int) $locked->from_warehouse_id, 'dispatch return');
            $driver = $this->activeDriver($driverId);
            $locked->update(['driver_id' => $driver->id, 'vehicle_details' => $vehicle ?: null, 'note' => $note ?: $locked->note, 'status' => TransferReturn::IN_TRANSIT, 'dispatched_by' => $user->id, 'dispatched_at' => now()]);
            $locked->transfer->update(['workflow_status' => Transfer::WORKFLOW_RETURN_IN_TRANSIT]);
            foreach ($locked->details as $row) $this->movement($locked->transfer, $row->transferDetail, 'return_dispatch', 'return_in_transit', (float) $row->quantity, $user, "return-dispatch:{$row->id}", null, [], $locked);
            $this->record($locked->transfer, $user, Transfer::WORKFLOW_RETURN_PENDING, Transfer::WORKFLOW_RETURN_IN_TRANSIT, 'return_dispatched', $note, ['return_reference' => $locked->reference, 'driver_id' => $driver->id], (int) $locked->from_warehouse_id);
            $this->notifyWarehouse($locked->transfer, (int) $locked->to_warehouse_id, 'transfer_return_receive', 'return_dispatched', "Return {$locked->reference} for {$locked->transfer->Ref} is on the way.");
            return $locked->fresh(['details', 'driver']);
        }, 10);
    }

    public function receiveReturn(TransferReturn $return, User $user, array $items, ?string $note = null): TransferReturn
    {
        return DB::transaction(function () use ($return, $user, $items, $note) {
            $locked = TransferReturn::with(['transfer.details', 'details.transferDetail'])->lockForUpdate()->findOrFail($return->id);
            if ($locked->status !== TransferReturn::IN_TRANSIT) throw ValidationException::withMessages(['return' => ['This return is not in transit or was already received.']]);
            $this->assertWarehouseAccess($user, (int) $locked->to_warehouse_id, 'receive return');
            $submitted = collect($items)->keyBy(fn ($item) => (int) ($item['return_detail_id'] ?? 0));
            $damage = null;
            foreach ($locked->details as $row) {
                $condition = (string) ($submitted->get((int) $row->id)['condition'] ?? '');
                if (! in_array($condition, ['saleable', 'faulty', 'damaged', 'repair_required', 'quarantine'], true)) throw ValidationException::withMessages(["items.{$row->id}.condition" => ['Select the condition of every returned item.']]);
                $detail = $row->transferDetail;
                if ($condition === 'saleable') {
                    $stock = $this->lockedSourceStockRow($locked->transfer, $detail);
                    $stock->qte = (float) $stock->qte + $this->toBase((float) $row->quantity, $this->unitFor($detail));
                    $stock->save();
                    $this->restoreReturnedBatches($detail, (float) $row->quantity);
                } elseif (in_array($condition, ['faulty', 'damaged'], true)) {
                    $damage ??= Damage::create(['date' => now()->toDateString(), 'time' => now()->format('H:i:s'), 'Ref' => 'DMG-' . $locked->reference, 'user_id' => $user->id, 'warehouse_id' => $locked->to_warehouse_id, 'items' => 0, 'notes' => "Returned via {$locked->reference}: {$note}"]);
                    DamageDetail::create(['damage_id' => $damage->id, 'product_id' => $row->product_id, 'product_variant_id' => $row->product_variant_id, 'quantity' => $row->quantity]);
                    $damage->increment('items');
                }
                $row->update(['received_condition' => $condition]);
                $this->movement($locked->transfer, $detail, 'return_receive', $condition === 'saleable' ? 'available' : $condition, (float) $row->quantity, $user, "return-receive:{$row->id}", (int) $locked->to_warehouse_id, ['condition' => $condition, 'damage_id' => $damage?->id], $locked);
            }
            $locked->update(['status' => TransferReturn::RECEIVED, 'received_by' => $user->id, 'received_at' => now(), 'note' => $note ?: $locked->note]);
            $locked->transfer->update(['workflow_status' => Transfer::WORKFLOW_RETURN_RECEIVED]);
            $this->record($locked->transfer, $user, Transfer::WORKFLOW_RETURN_IN_TRANSIT, Transfer::WORKFLOW_RETURN_RECEIVED, 'return_received', $note, ['return_reference' => $locked->reference], (int) $locked->to_warehouse_id);
            $locked->transfer->update(['workflow_status' => Transfer::WORKFLOW_COMPLETED, 'statut' => 'completed']);
            $this->record($locked->transfer, $user, Transfer::WORKFLOW_RETURN_RECEIVED, Transfer::WORKFLOW_COMPLETED, 'transfer_completed', null, [], (int) $locked->to_warehouse_id);
            $this->notifyWarehouse($locked->transfer, (int) $locked->from_warehouse_id, 'transfer_view', 'return_received', "Return {$locked->reference} was received; transfer {$locked->transfer->Ref} is complete.");
            return $locked->fresh('details');
        }, 10);
    }

    public function cancelTransfer(Transfer $transfer, User $user, string $reason): Transfer
    {
        return DB::transaction(function () use ($transfer, $user, $reason) {
            $locked = Transfer::whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            $allowed = [
                Transfer::WORKFLOW_DRAFT,
                Transfer::WORKFLOW_PENDING_APPROVAL,
                Transfer::WORKFLOW_PENDING_ACKNOWLEDGEMENT,
                Transfer::WORKFLOW_ACKNOWLEDGED,
                Transfer::WORKFLOW_READY_FOR_DISPATCH,
            ];
            if (! in_array($locked->workflow_status, $allowed, true)) {
                throw ValidationException::withMessages(['transfer' => ['A transfer can only be cancelled before stock is dispatched.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->from_warehouse_id, 'cancel');
            $reason = trim($reason);
            if ($reason === '') throw ValidationException::withMessages(['note' => ['A cancellation reason is required.']]);
            $previous = $locked->workflow_status;
            $locked->update([
                'workflow_status' => Transfer::WORKFLOW_CANCELLED,
                'statut' => 'cancelled',
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
            ]);
            $this->record($locked, $user, $previous, Transfer::WORKFLOW_CANCELLED, 'transfer_cancelled', $reason, [], (int) $locked->from_warehouse_id);
            $this->notifyWarehouse($locked, (int) $locked->to_warehouse_id, 'transfer_view', 'cancelled', "Transfer {$locked->Ref} was cancelled: {$reason}");

            return $locked->fresh('details');
        }, 10);
    }

    public function record(Transfer $transfer, ?User $user, ?string $previous, string $next, string $action, ?string $note = null, array $metadata = [], ?int $warehouseId = null): void
    {
        TransferStatusHistory::create([
            'transfer_id' => $transfer->id,
            'performed_by' => $user?->id,
            'warehouse_id' => $warehouseId,
            'previous_status' => $previous,
            'new_status' => $next,
            'action' => $action,
            'note' => $note !== null ? trim($note) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    public function notifyNewRequest(Transfer $transfer): void
    {
        $this->notifyWarehouse($transfer, (int) $transfer->from_warehouse_id, 'transfer_approve', 'submitted',
            "New stock request {$transfer->Ref} is awaiting approval.");
    }

    public function submitDestinationRequest(Transfer $transfer, User $user): Transfer
    {
        return DB::transaction(function () use ($transfer, $user) {
            $locked = Transfer::whereNull('deleted_at')->lockForUpdate()->findOrFail($transfer->id);
            if ($locked->workflow_status !== Transfer::WORKFLOW_DRAFT || $locked->transfer_type !== 'destination_request') {
                throw ValidationException::withMessages(['transfer' => ['Only a draft destination request can be submitted for approval.']]);
            }
            $this->assertWarehouseAccess($user, (int) $locked->to_warehouse_id, 'submit destination request');
            if (trim((string) $locked->request_note) === '') {
                throw ValidationException::withMessages(['note' => ['A stock request note is required.']]);
            }
            $locked->update([
                'workflow_status' => Transfer::WORKFLOW_PENDING_APPROVAL,
                'approval_status' => 'pending',
                'requested_at' => now(),
            ]);
            $this->record($locked, $user, Transfer::WORKFLOW_DRAFT, Transfer::WORKFLOW_PENDING_APPROVAL, 'request_submitted', $locked->request_note, ['transfer_type' => $locked->transfer_type], (int) $locked->to_warehouse_id);
            $this->notifyNewRequest($locked);

            return $locked->fresh('details');
        }, 10);
    }

    public function assertWarehouseAccess(User $user, int $warehouseId, string $action): void
    {
        if ($user->isSuperAdmin() || (bool) $user->is_all_warehouses) {
            return;
        }
        if (! UserWarehouse::where('user_id', $user->id)->where('warehouse_id', $warehouseId)->exists()) {
            throw ValidationException::withMessages(['warehouse' => ["You are not authorized to {$action} transfers for this warehouse."]]);
        }
    }

    private function stockRow(Transfer $transfer, TransferDetail $detail): ?product_warehouse
    {
        return $this->stockQuery((int) $transfer->from_warehouse_id, $detail)->first();
    }

    private function lockedStockRow(Transfer $transfer, TransferDetail $detail): ?product_warehouse
    {
        return $this->stockQuery((int) $transfer->from_warehouse_id, $detail)->lockForUpdate()->first();
    }

    private function lockedDestinationStockRow(Transfer $transfer, TransferDetail $detail): product_warehouse
    {
        $query = $this->stockQuery((int) $transfer->to_warehouse_id, $detail);
        $row = $query->lockForUpdate()->first();
        if ($row) {
            return $row;
        }

        return product_warehouse::create([
            'warehouse_id' => $transfer->to_warehouse_id,
            'product_id' => $detail->product_id,
            'product_variant_id' => $detail->product_variant_id,
            'qte' => 0,
            'manage_stock' => 1,
        ]);
    }

    private function lockedSourceStockRow(Transfer $transfer, TransferDetail $detail): product_warehouse
    {
        $row = $this->stockQuery((int) $transfer->from_warehouse_id, $detail)->lockForUpdate()->first();
        if ($row) return $row;
        return product_warehouse::create([
            'warehouse_id' => $transfer->from_warehouse_id,
            'product_id' => $detail->product_id,
            'product_variant_id' => $detail->product_variant_id,
            'qte' => 0,
            'manage_stock' => 1,
        ]);
    }

    private function stockQuery(int $warehouseId, TransferDetail $detail)
    {
        $query = product_warehouse::whereNull('deleted_at')
            ->where('warehouse_id', $warehouseId)
            ->where('product_id', $detail->product_id);

        return $detail->product_variant_id
            ? $query->where('product_variant_id', $detail->product_variant_id)
            : $query->whereNull('product_variant_id');
    }

    private function reservedBaseFor(Transfer $transfer, TransferDetail $detail): float
    {
        $query = TransferDetail::query()
            ->join('transfers', 'transfers.id', '=', 'transfer_details.transfer_id')
            ->whereNull('transfers.deleted_at')
            ->where('transfers.from_warehouse_id', $transfer->from_warehouse_id)
            ->whereIn('transfers.workflow_status', self::RESERVED_STATUSES)
            ->where('transfer_details.transfer_id', '<>', $transfer->id)
            ->where('transfer_details.product_id', $detail->product_id)
            ->where('transfer_details.approved_quantity', '>', 0);

        $detail->product_variant_id
            ? $query->where('transfer_details.product_variant_id', $detail->product_variant_id)
            : $query->whereNull('transfer_details.product_variant_id');

        return $query->get(['transfer_details.approved_quantity', 'transfer_details.purchase_unit_id'])
            ->sum(fn ($row) => $this->toBase((float) $row->approved_quantity, $row->purchase_unit_id ? Unit::find($row->purchase_unit_id) : null));
    }

    private function unitFor(TransferDetail $detail): ?Unit
    {
        if ($detail->purchase_unit_id) {
            return Unit::find($detail->purchase_unit_id);
        }
        $product = $detail->relationLoaded('product') ? $detail->product : Product::with('unitPurchase')->find($detail->product_id);

        return $product?->unitPurchase;
    }

    private function toBase(float $quantity, ?Unit $unit): float
    {
        if (! $unit || (float) $unit->operator_value == 0) {
            return $quantity;
        }

        return $unit->operator === '/'
            ? $quantity / (float) $unit->operator_value
            : $quantity * (float) $unit->operator_value;
    }

    private function fromBase(float $quantity, ?Unit $unit): float
    {
        if (! $unit || (float) $unit->operator_value == 0) {
            return round($quantity, 6);
        }

        return round($unit->operator === '/'
            ? $quantity * (float) $unit->operator_value
            : $quantity / (float) $unit->operator_value, 6);
    }

    private function dispatchBatches(Transfer $transfer): void
    {
        $batchService = app(BatchService::class);
        if (! $batchService->isSupported()) {
            return;
        }

        $details = $transfer->details()->with('product')->get();
        $input = $details->map(function (TransferDetail $detail) use ($batchService, $transfer) {
            $remaining = (float) $detail->approved_quantity;
            $raw = is_array($detail->requested_batches) ? $detail->requested_batches : [];
            if (empty($raw) && ($detail->product?->is_batch_tracked ?? false)) {
                $raw = array_map(fn ($batch) => [
                    'product_batch_id' => $batch['id'],
                    'qty' => $batch['qty_available'],
                    'unit_cost' => $batch['unit_cost'],
                ], $batchService->availableBatchesForSale(
                    (int) $detail->product_id,
                    $detail->product_variant_id ? (int) $detail->product_variant_id : null,
                    (int) $transfer->from_warehouse_id
                ));
            }

            $allocated = [];
            foreach ($raw as $batch) {
                if ($remaining <= 0) {
                    break;
                }
                $available = (float) ($batch['qty'] ?? $batch['qty_available'] ?? 0);
                $take = min($available, $remaining);
                if ($take > 0) {
                    $allocated[] = [
                        'product_batch_id' => (int) ($batch['product_batch_id'] ?? $batch['id'] ?? 0),
                        'qty' => $take,
                        'unit_cost' => $batch['unit_cost'] ?? null,
                    ];
                    $remaining -= $take;
                }
            }
            if (($detail->product?->is_batch_tracked ?? false) && $remaining > 0.000001) {
                throw ValidationException::withMessages(["items.{$detail->id}" => ['Insufficient active batch stock to dispatch this item.']]);
            }

            return ['product_id' => $detail->product_id, 'batches' => $allocated];
        })->all();

        $batchService->applyForTransferWithAutoFallback($transfer, $input, $details);
    }

    private function receiveBatches(Transfer $transfer, array $receivedDeltas): void
    {
        if (! Schema::hasTable('transfer_detail_batches') || ! Schema::hasColumn('transfer_detail_batches', 'received_qty')) {
            return;
        }

        foreach ($receivedDeltas as $detailId => $delta) {
            $remaining = (float) $delta;
            $rows = TransferDetailBatch::where('transfer_detail_id', $detailId)->lockForUpdate()->get();
            foreach ($rows as $row) {
                if ($remaining <= 0) {
                    break;
                }
                $available = (float) $row->qty - (float) $row->received_qty;
                $take = min($available, $remaining);
                if ($take <= 0) {
                    continue;
                }
                $source = $row->sourceBatch;
                if ($source) {
                    $destination = $row->destBatch ?: \App\Models\ProductBatch::firstOrCreate([
                        'product_id' => $source->product_id,
                        'product_variant_id' => $source->product_variant_id,
                        'warehouse_id' => $transfer->to_warehouse_id,
                        'batch_no' => $source->batch_no,
                    ], [
                        'expiry_date' => $source->expiry_date,
                        'mfg_date' => $source->mfg_date,
                        'qty' => 0,
                        'unit_cost' => $source->unit_cost,
                        'status' => $source->status,
                    ]);
                    $destination->qty = (float) $destination->qty + $take;
                    $destination->save();
                    $row->dest_batch_id = $destination->id;
                }
                $row->received_qty = (float) $row->received_qty + $take;
                $row->save();
                $remaining -= $take;
            }
        }
    }

    private function restoreReturnedBatches(TransferDetail $detail, float $quantity): void
    {
        if (! Schema::hasTable('transfer_detail_batches')) return;
        $remaining = $quantity;
        $rows = TransferDetailBatch::where('transfer_detail_id', $detail->id)->lockForUpdate()->get();
        foreach ($rows as $row) {
            if ($remaining <= 0.000001) break;
            $returnable = max(0, (float) $row->qty - (float) $row->received_qty);
            $take = min($remaining, $returnable);
            if ($take <= 0) continue;
            $source = $row->sourceBatch()->lockForUpdate()->first();
            if ($source) {
                $source->qty = (float) $source->qty + $take;
                $source->save();
            }
            $remaining -= $take;
        }
        if ($remaining > 0.000001) {
            throw ValidationException::withMessages(['return' => ['Returned batch quantities do not match the rejected stock.']]);
        }
    }

    private function activeDriver(int $driverId): Employee
    {
        $driver = Employee::whereNull('deleted_at')
            ->whereNull('leaving_date')
            ->whereHas('designation', fn ($query) => $query->whereRaw('LOWER(designation) = ?', ['driver']))
            ->find($driverId);
        if (! $driver) throw ValidationException::withMessages(['driver_id' => ['Select an active driver.']]);
        return $driver;
    }

    private function createReturn(Transfer $transfer, User $user, array $rows, ?string $note): TransferReturn
    {
        $return = TransferReturn::firstOrCreate(
            ['transfer_id' => $transfer->id],
            [
                'reference' => 'RT-' . $transfer->Ref,
                'from_warehouse_id' => $transfer->to_warehouse_id,
                'to_warehouse_id' => $transfer->from_warehouse_id,
                'status' => TransferReturn::PENDING,
                'note' => $note ? trim($note) : null,
                'created_by' => $user->id,
            ]
        );
        foreach ($rows as $row) {
            $detail = $row['detail'];
            TransferReturnDetail::updateOrCreate(
                ['transfer_return_id' => $return->id, 'transfer_detail_id' => $detail->id],
                [
                    'product_id' => $detail->product_id,
                    'product_variant_id' => $detail->product_variant_id,
                    'purchase_unit_id' => $detail->purchase_unit_id,
                    'quantity' => $row['rejected'],
                    'reason_code' => $row['reason'],
                    'reason_note' => $row['reasonNote'] ?: null,
                    'identifiers' => $detail->identifiers,
                ]
            );
        }
        return $return->fresh('details');
    }

    private function movement(
        Transfer $transfer,
        TransferDetail $detail,
        string $type,
        string $state,
        float $quantity,
        User $user,
        string $key,
        ?int $warehouseId = null,
        array $metadata = [],
        ?TransferReturn $return = null
    ): void {
        TransferStockMovement::firstOrCreate(
            ['idempotency_key' => "transfer:{$transfer->id}:{$key}"],
            [
                'transfer_id' => $transfer->id,
                'transfer_return_id' => $return?->id,
                'transfer_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'product_variant_id' => $detail->product_variant_id,
                'warehouse_id' => $warehouseId,
                'movement_type' => $type,
                'stock_state' => $state,
                'quantity' => $quantity,
                'reference' => $return?->reference ?: $transfer->Ref,
                'performed_by' => $user->id,
                'metadata' => $metadata ?: null,
            ]
        );
    }

    private function notifyWarehouse(Transfer $transfer, int $warehouseId, string $permission, string $action, string $message): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }
        $userIds = UserWarehouse::where('warehouse_id', $warehouseId)->pluck('user_id');
        $users = User::whereNull('deleted_at')
            ->where(function ($query) use ($userIds) {
                $query->whereIn('id', $userIds)->orWhere('is_all_warehouses', 1);
            })->get();

        foreach ($users as $recipient) {
            if ($recipient->isSuperAdmin() || $recipient->effectivePermissionNames()->contains($permission)) {
                $recipient->notify(new TransferWorkflowNotification($transfer, $action, $message));
            }
        }
    }
}
