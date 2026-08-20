<template>
  <div class="main-content">
    <breadcumb :page="$t('TransferDetail')" :folder="$t('ListTransfers')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-if="!isLoading" class="shadow-sm">
      <b-row>
        <b-col md="12" class="mb-3">
          <router-link
            :to="{ name: 'index_transfer' }"
            class="btn btn-secondary btn-icon ripple btn-sm mr-2"
          >
            <lucide-icon name="arrow-left" />
            <span>{{$t('Back')}}</span>
          </router-link>
          <router-link
            v-if="(!transfer.workflow_status || transfer.workflow_status === 'draft') && currentUserPermissions && currentUserPermissions.includes('transfer_edit')"
            title="Edit"
            class="btn btn-success btn-icon ripple btn-sm mr-2"
            :to="{ name:'edit_transfer', params: { id: $route.params.id } }"
          >
            <lucide-icon name="pencil" />
            <span>{{$t('Edit')}}</span>
          </router-link>
          <button @click="Print_Transfer_PDF()" class="btn btn-primary btn-icon ripple btn-sm mr-2">
            <lucide-icon name="file-text" />
            {{$t('PDF')}}
          </button>
          <button @click="print()" class="btn btn-warning btn-icon ripple btn-sm mr-2">
            <lucide-icon name="receipt" />
            {{$t('print')}}
          </button>
          <button
            v-if="actions.can_process"
            @click="$bvModal.show('review-transfer')"
            class="btn btn-info btn-icon ripple btn-sm mr-2"
          >
            <lucide-icon name="check" />
            Review Request
          </button>
          <button v-if="actions.can_acknowledge" @click="openAction('acknowledge')" class="btn btn-primary btn-icon ripple btn-sm mr-2">
            <lucide-icon name="check-circle" /> Acknowledge
          </button>
          <button v-if="actions.can_dispatch" @click="$bvModal.show('dispatch-transfer')" class="btn btn-warning btn-icon ripple btn-sm mr-2">
            <lucide-icon name="truck" /> Dispatch
          </button>
          <button v-if="actions.can_receive" @click="$bvModal.show('receive-transfer')" class="btn btn-success btn-icon ripple btn-sm mr-2">
            <lucide-icon name="package-check" /> Receive Stock
          </button>
          <button v-if="actions.can_dispatch_return" @click="$bvModal.show('dispatch-return')" class="btn btn-warning btn-icon ripple btn-sm mr-2">
            <lucide-icon name="undo-2" /> Dispatch Return
          </button>
          <button v-if="actions.can_receive_return" @click="$bvModal.show('receive-return')" class="btn btn-success btn-icon ripple btn-sm mr-2">
            <lucide-icon name="package-check" /> Receive Return
          </button>
          <button v-if="canPrintReturnVoucher" @click="printReturnVoucher" class="btn btn-info btn-icon ripple btn-sm mr-2">
            <lucide-icon name="printer" /> Print Return Voucher
          </button>
          <button v-if="actions.can_cancel" @click="cancelTransfer" class="btn btn-outline-danger btn-icon ripple btn-sm mr-2">
            <lucide-icon name="x-circle" /> Cancel Transfer
          </button>
          <button
            v-if="transfer.workflow_status === 'pending_approval' && currentUserPermissions && currentUserPermissions.includes('transfer_delete')"
            @click="Delete_Transfer()"
            class="btn btn-danger btn-icon ripple btn-sm"
          >
            <lucide-icon name="x" />
            {{$t('Del')}}
          </button>
        </b-col>
      </b-row>

      <div class="invoice mt-5" id="print_Invoice">
        <div class="invoice-print">
          <div class="transfer-print-header">
            <div class="transfer-print-brand">
              <div class="transfer-print-company">AL MADINA ELECTRONICS</div>
              <div class="transfer-print-title">Stock Transfer Voucher</div>
              <div class="transfer-print-subtitle">Warehouse stock movement document</div>
            </div>
            <div class="transfer-print-reference">
              <div class="transfer-print-reference-label">TRANSFER REFERENCE</div>
              <div class="transfer-print-reference-value">{{ transfer.Ref }}</div>
              <div class="transfer-print-reference-type">{{ transfer.transfer_type === 'destination_request' ? 'Destination Branch Request' : 'Transfer by This Branch' }}</div>
            </div>
          </div>

          <b-row class="mt-5">
            <b-col lg="4" md="6" sm="12" class="mb-4">
              <b-card class="h-100 shadow-sm">
                <h5 class="font-weight-bold mb-3 text-primary">
                  <lucide-icon class="mr-2" name="home" />{{$t('FromWarehouse')}}
                </h5>
                <div class="transfer-info">
                  <p class="mb-2"><strong>{{transfer.from_warehouse}}</strong></p>
                </div>
              </b-card>
            </b-col>
            <b-col lg="4" md="6" sm="12" class="mb-4">
              <b-card class="h-100 shadow-sm">
                <h5 class="font-weight-bold mb-3 text-success">
                  <lucide-icon class="mr-2" name="home" />{{$t('ToWarehouse')}}
                </h5>
                <div class="transfer-info">
                  <p class="mb-2"><strong>{{transfer.to_warehouse}}</strong></p>
                </div>
              </b-card>
            </b-col>
            <b-col lg="4" md="6" sm="12" class="mb-4">
              <b-card class="h-100 shadow-sm">
                <h5 class="font-weight-bold mb-3 text-info">
                  <lucide-icon class="mr-2" name="file-text" />{{$t('Transfer_Info')}}
                </h5>
                <div class="transfer-info">
                  <p class="mb-2">
                    <strong>{{$t('Reference')}}:</strong> {{transfer.Ref}}
                  </p>
                  <p class="mb-2">
                    <strong>{{$t('date')}}:</strong> {{formatDisplayDate(transfer.date)}}
                  </p>
                   <p class="mb-2">
                     <strong>Workflow Status:</strong>
                     <span :class="workflowBadgeClass(transfer.workflow_status)" class="ml-2">{{ workflowLabel(transfer.workflow_status) }}</span>
                   </p>
                   <p class="mb-2"><strong>Transfer Type:</strong> {{ transfer.transfer_type === 'destination_request' ? 'Destination Branch Request' : 'Transfer by This Branch' }}</p>
                   <p class="mb-2">
                     <strong>{{$t('Approval')}}:</strong>
                     <span v-if="transfer.approval_status === 'draft'" class="badge badge-outline-secondary ml-2">Not Submitted</span>
                     <span v-else-if="transfer.approval_status === 'not_required'" class="badge badge-outline-secondary ml-2">Not Required</span>
                     <span
                      v-else-if="!transfer.approval_status || transfer.approval_status === 'approved'"
                      class="badge badge-outline-success ml-2"
                    >{{ $t('Approved') }}</span>
                    <span
                      v-else-if="transfer.approval_status === 'pending'"
                      class="badge badge-outline-warning ml-2"
                    >{{ $t('Pending_Approval') }}</span>
                    <span
                      v-else-if="transfer.approval_status === 'declined' || transfer.approval_status === 'rejected'"
                      class="badge badge-outline-danger ml-2"
                    >Declined</span>
                    <span v-else-if="transfer.approval_status === 'partially_approved'" class="badge badge-outline-info ml-2">Partially Approved</span>
                  </p>
                  <p v-if="transfer.requested_by" class="mb-2"><strong>Requested By:</strong> {{ transfer.requested_by }}</p>
                  <p v-if="transfer.processed_by" class="mb-2"><strong>Processed By:</strong> {{ transfer.processed_by }}</p>
                   <p v-if="transfer.required_date" class="mb-2"><strong>Required Date:</strong> {{ transfer.required_date }}</p>
                   <p v-if="transfer.driver" class="mb-2"><strong>Driver:</strong> {{ transfer.driver.name }} <span v-if="transfer.driver.phone">({{ transfer.driver.phone }})</span></p>
                   <p v-if="transfer.vehicle_details" class="mb-2"><strong>Vehicle:</strong> {{ transfer.vehicle_details }}</p>
                   <p v-if="transfer.dispatched_at" class="mb-2"><strong>Dispatched At:</strong> {{ transfer.dispatched_at }}</p>
                   <p v-if="transfer.received_at" class="mb-2"><strong>Received At:</strong> {{ transfer.received_at }}</p>
                </div>
              </b-card>
            </b-col>
          </b-row>

          <b-row class="mt-4">
            <b-col md="12">
              <h5 class="font-weight-bold mb-3"><lucide-icon class="mr-2" name="clipboard-list" />Stock Request Decision</h5>
              <div class="table-responsive">
                <table class="table table-bordered table-hover">
                  <thead class="bg-light">
                    <tr>
                      <th>Product</th><th>SKU</th><th>Requested</th><th>On Hand</th><th>Reserved</th><th>Transferable</th>
                      <th>Approved</th><th>Unapproved</th><th>Dispatched</th><th>Accepted</th><th>Rejected</th><th>Decision / Reason</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="detail in details" :key="'workflow-' + detail.detail_id">
                      <td class="font-weight-bold">{{ detail.name }}</td>
                      <td>{{ detail.code }}</td>
                      <td>{{ formatNumber(detail.requested_quantity, 2) }} {{ detail.unit }}</td>
                      <td>{{ formatNumber(detail.on_hand, 2) }}</td>
                      <td>{{ formatNumber(detail.reserved, 2) }}</td>
                      <td>{{ formatNumber(detail.transferable, 2) }}</td>
                      <td class="text-success font-weight-bold">{{ formatNumber(detail.approved_quantity, 2) }}</td>
                      <td class="text-danger">{{ formatNumber(detail.unapproved_quantity, 2) }}</td>
                      <td>{{ formatNumber(detail.dispatched_quantity, 2) }}</td>
                      <td class="text-success">{{ formatNumber(detail.accepted_quantity, 2) }}</td>
                      <td class="text-danger">{{ formatNumber(detail.rejected_quantity, 2) }}</td>
                      <td>
                        <span :class="decisionBadgeClass(detail.decision_status)">{{ workflowLabel(detail.decision_status || 'pending') }}</span>
                        <div v-if="detail.response_reason" class="small text-muted mt-1">{{ detail.response_reason }}</div>
                        <div v-if="detail.rejection_reason_code" class="small text-danger mt-1">{{ rejectionReasonLabel(detail.rejection_reason_code) }}<span v-if="detail.rejection_note">: {{ detail.rejection_note }}</span></div>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </b-col>
          </b-row>

          <b-row class="mt-4">
            <b-col md="12">
              <h5 class="font-weight-bold mb-3">
                <lucide-icon class="mr-2" name="package" />{{$t('Order_Summary')}}
              </h5>
              <div class="table-responsive">
                <table class="table table-hover table-bordered">
                  <thead class="bg-light">
                    <tr>
                      <th scope="col" class="text-left">{{$t('ProductName')}}</th>
                      <th scope="col" class="text-center">{{$t('CodeProduct')}}</th>
                      <th scope="col" class="text-center">{{$t('Quantity')}}</th>
                      <th v-if="canViewTransferPrice" scope="col" class="text-right">{{$t('SubTotal')}}</th>
                    </tr>
                  </thead>
                  <tbody>
                    <template v-for="(detail, index) in details">
                      <tr :key="'r-' + index">
                        <td class="text-left">
                           <span class="font-weight-bold">{{detail.name}}</span>
                           <div v-if="detail.identifiers && detail.identifiers.length" class="small text-muted mt-1">Serial / IMEI: {{ detail.identifiers.join(', ') }}</div>
                          <span v-if="detail.is_batch_tracked" class="badge ml-1" style="background:#eef2ff; color:#4f46e5; font-weight:600; letter-spacing:0.3px;">
                            <lucide-icon name="package" style="margin-right:3px;" />{{ $t('Batches') || 'Batches' }}
                          </span>
                        </td>
                        <td class="text-center">{{detail.code}}</td>
                        <td class="text-center">
                          <span class="badge badge-primary">{{formatNumber(detail.quantity, 2)}} {{detail.unit}}</span>
                        </td>
                        <td v-if="canViewTransferPrice" class="text-right font-weight-bold">
                          {{currentUser.currency}} {{formatNumber(detail.total, 2)}}
                        </td>
                      </tr>
                      <tr v-if="detail.is_batch_tracked && (detail.batches || []).length" :key="'b-' + index" style="background:#ffffff;">
                        <td :colspan="canViewTransferPrice ? 4 : 3" style="padding:0; border-top:0;">
                          <div style="margin:6px 4px 12px 4px; border:1px solid #e0e7ff; border-radius:8px; overflow:hidden; background:#f8faff;">
                            <div style="display:flex; align-items:center; justify-content:space-between; padding:6px 12px; background:#4f46e5; color:#fff;">
                              <div style="display:flex; align-items:center; gap:8px;">
                                <lucide-icon name="package" />
                                <span style="font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.3px;">{{ $t('Batches') || 'Batches' }}</span>
                              </div>
                              <span style="font-size:11px; font-weight:600; background:rgba(255,255,255,0.22); padding:1px 8px; border-radius:10px;">
                                {{ detail.batches.length }} {{ $t('items') || 'items' }}
                              </span>
                            </div>
                            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                              <thead>
                                <tr style="background:#eef2ff;">
                                  <th style="padding:6px 10px; text-align:left; color:#3730a3; font-weight:700; text-transform:uppercase; font-size:10px; letter-spacing:0.3px;">{{ $t('Batch_No') || 'Batch No' }}</th>
                                  <th style="padding:6px 10px; text-align:left; color:#3730a3; font-weight:700; text-transform:uppercase; font-size:10px; letter-spacing:0.3px;">{{ $t('Mfg_Date') || 'Mfg Date' }}</th>
                                  <th style="padding:6px 10px; text-align:left; color:#3730a3; font-weight:700; text-transform:uppercase; font-size:10px; letter-spacing:0.3px;">{{ $t('Expiry_Date') || 'Expiry Date' }}</th>
                                  <th style="padding:6px 10px; text-align:right; color:#3730a3; font-weight:700; text-transform:uppercase; font-size:10px; letter-spacing:0.3px;">{{ $t('Quantity') || 'Quantity' }}</th>
                                  <th v-if="canViewTransferPrice" style="padding:6px 10px; text-align:right; color:#3730a3; font-weight:700; text-transform:uppercase; font-size:10px; letter-spacing:0.3px;">{{ $t('Cost') || 'Cost' }}</th>
                                </tr>
                              </thead>
                              <tbody>
                                <tr v-for="(b, bIdx) in detail.batches" :key="'tb-' + index + '-' + bIdx" :style="{ background: bIdx % 2 === 1 ? '#f8faff' : '#ffffff', borderTop: '1px solid #e0e7ff' }">
                                  <td style="padding:6px 10px; font-weight:600; color:#1f2937;">
                                    <span v-if="b.batch_no">{{ b.batch_no }}</span>
                                    <span v-else style="color:#9ca3af; font-style:italic;">—</span>
                                  </td>
                                  <td style="padding:6px 10px; color:#374151;">
                                    <span v-if="b.mfg_date">{{ b.mfg_date }}</span>
                                    <span v-else style="color:#9ca3af;">—</span>
                                  </td>
                                  <td style="padding:6px 10px;">
                                    <span v-if="b.expiry_date" :style="expiry_pill_style(b.expiry_date)">{{ b.expiry_date }}</span>
                                    <span v-else style="color:#9ca3af;">—</span>
                                  </td>
                                  <td style="padding:6px 10px; text-align:right; color:#1f2937; font-weight:600;">
                                    {{ formatNumber(b.qty, 2) }} {{ detail.unit }}
                                  </td>
                                  <td v-if="canViewTransferPrice" style="padding:6px 10px; text-align:right; color:#4f46e5; font-weight:600;">
                                    <span v-if="b.unit_cost != null">{{ currentUser.currency }} {{ formatNumber(b.unit_cost, 2) }}</span>
                                    <span v-else style="color:#9ca3af;">—</span>
                                  </td>
                                </tr>
                              </tbody>
                            </table>
                          </div>
                        </td>
                      </tr>
                    </template>
                  </tbody>
                </table>
              </div>
            </b-col>
          </b-row>

          <b-row class="mt-4">
            <b-col md="12" class="text-right">
              <div class="offset-md-8 col-md-4">
                <table class="table table-striped table-sm">
                  <tbody>
                    <tr>
                      <td class="font-weight-bold">{{$t('Items')}}:</td>
                      <td class="text-right">{{transfer.items}}</td>
                    </tr>
                    <tr v-if="canViewTransferPrice">
                      <td class="font-weight-bold">{{$t('Total')}}:</td>
                      <td class="text-right">
                        <span class="font-weight-bold text-primary" style="font-size: 1.2em">
                          {{currentUser.currency}} {{formatNumber(transfer.GrandTotal, 2)}}
                        </span>
                      </td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </b-col>
          </b-row>

          <hr v-if="transfer.note" class="mt-4">
          <b-row v-if="transfer.note" class="mt-4">
            <b-col md="12">
              <h5 class="font-weight-bold mb-2">
                <lucide-icon class="mr-2" name="sticky-note" />{{$t('Note')}}
              </h5>
              <div class="p-3 bg-light rounded">
                <p class="mb-0">{{transfer.note}}</p>
              </div>
            </b-col>
          </b-row>
          <b-row v-if="transfer.response_note" class="mt-4">
            <b-col md="6"><div class="p-3 border rounded bg-light"><h6 class="font-weight-bold">Warehouse Response</h6><p class="mb-0">{{ transfer.response_note }}</p></div></b-col>
            <b-col v-if="transfer.acknowledgement_note" md="6"><div class="p-3 border rounded bg-light"><h6 class="font-weight-bold">Branch Acknowledgement</h6><p class="mb-0">{{ transfer.acknowledgement_note }}</p></div></b-col>
          </b-row>
          <b-row v-if="transferReturn" class="mt-4 transfer-return-print-section">
            <b-col md="12">
              <div id="print_Return_Voucher" class="return-voucher-document">
                <div class="rv-header">
                  <div class="rv-header-brand">
                    <div class="rv-company">AL MADINA ELECTRONICS</div>
                    <div class="rv-title">Return Dispatch Voucher</div>
                    <div class="rv-subtitle">Stock return handover document</div>
                  </div>
                  <div class="rv-header-reference">
                    <div class="rv-reference-label">RETURN REFERENCE</div>
                    <div class="rv-reference-value">{{ transferReturn.reference }}</div>
                    <div class="rv-original-reference">Original Transfer: <strong>{{ transfer.Ref }}</strong></div>
                  </div>
                </div>

                <div class="rv-route">
                  <div class="rv-route-location">
                    <span>RETURN FROM</span>
                    <strong>{{ transferReturn.from_warehouse || '-' }}</strong>
                  </div>
                  <div class="rv-route-arrow">&#8594;</div>
                  <div class="rv-route-location">
                    <span>RETURN TO</span>
                    <strong>{{ transferReturn.to_warehouse || '-' }}</strong>
                  </div>
                </div>

                <table class="rv-meta-table">
                  <tbody>
                    <tr><th>Driver</th><td>{{ transferReturn.driver || '-' }}<span v-if="transferReturn.driver_code"> ({{ transferReturn.driver_code }})</span></td><th>Driver Phone</th><td>{{ transferReturn.driver_phone || '-' }}</td></tr>
                    <tr><th>Vehicle</th><td>{{ transferReturn.vehicle_details || '-' }}</td><th>Dispatched At</th><td>{{ transferReturn.dispatched_at || '-' }}</td></tr>
                    <tr><th>Status</th><td>{{ workflowLabel(transferReturn.status) }}</td><th>Created At</th><td>{{ transferReturn.created_at || '-' }}</td></tr>
                  </tbody>
                </table>

                <div class="rv-section-title">Returned Products</div>
                <table class="rv-items-table">
                  <colgroup><col class="rv-col-number"><col class="rv-col-product"><col class="rv-col-code"><col class="rv-col-quantity"><col class="rv-col-reason"></colgroup>
                  <thead><tr><th>#</th><th>Product</th><th>Code</th><th>Return Qty</th><th>Rejection Reason</th></tr></thead>
                  <tbody><tr v-for="(row, index) in transferReturn.details" :key="'return-row-' + row.id"><td>{{ index + 1 }}</td><td><strong>{{ row.product }}</strong><div v-if="row.identifiers && row.identifiers.length" class="rv-item-note">Serial / IMEI: {{ row.identifiers.join(', ') }}</div></td><td>{{ row.code || '-' }}</td><td class="rv-quantity">{{ formatNumber(row.quantity, 2) }}</td><td>{{ rejectionReasonLabel(row.reason_code) }}<div v-if="row.reason_note" class="rv-item-note">{{ row.reason_note }}</div></td></tr></tbody>
                  <tfoot><tr><th colspan="3">Total Return Quantity</th><th class="rv-quantity">{{ formatNumber(returnTotalQuantity, 2) }}</th><th></th></tr></tfoot>
                </table>

                <div v-if="transferReturn.note" class="rv-note"><strong>Return Note</strong><span>{{ transferReturn.note }}</span></div>
                <div class="rv-signatures">
                  <div class="rv-signature"><div class="rv-signature-line"></div><strong>Destination Warehouse</strong><span>Prepared / Handed Over By</span></div>
                  <div class="rv-signature"><div class="rv-signature-line"></div><strong>Driver Signature</strong><span>Stock Received for Transport</span></div>
                  <div class="rv-signature"><div class="rv-signature-line"></div><strong>Source Warehouse</strong><span>Received By</span></div>
                </div>
                <div class="rv-footer">This voucher must accompany the returned stock during transport.</div>
              </div>
            </b-col>
          </b-row>
          <b-row v-if="movements.length" class="mt-4 transfer-audit-print-section">
            <b-col md="12">
              <h5 class="font-weight-bold mb-3"><lucide-icon class="mr-2" name="activity" />Stock Movement Audit</h5>
              <div class="table-responsive"><table class="table table-bordered table-sm"><thead><tr><th>Date / Time</th><th>Reference</th><th>Movement</th><th>Stock State</th><th>Warehouse</th><th>Quantity</th></tr></thead><tbody><tr v-for="row in movements" :key="'movement-' + row.id"><td>{{ row.created_at }}</td><td>{{ row.reference }}</td><td>{{ workflowLabel(row.movement_type) }}</td><td>{{ workflowLabel(row.stock_state) }}</td><td>{{ row.warehouse || '-' }}</td><td>{{ formatNumber(row.quantity, 2) }}</td></tr></tbody></table></div>
            </b-col>
          </b-row>
          <b-row v-if="history.length" class="mt-4 transfer-history-print-section">
            <b-col md="12">
              <h5 class="font-weight-bold mb-3">Status Timeline</h5>
              <div v-for="event in history" :key="event.id" class="border-left border-primary pl-3 pb-3 mb-2">
                <div class="font-weight-bold">{{ workflowLabel(event.action) }} <span class="text-muted font-weight-normal">— {{ event.performed_by || 'System' }}</span></div>
                <div class="small text-muted">{{ event.created_at }} · {{ workflowLabel(event.previous_status) }} → {{ workflowLabel(event.new_status) }}</div>
                <div v-if="event.note" class="mt-1">{{ event.note }}</div>
              </div>
            </b-col>
          </b-row>
        </div>
      </div>

      <b-modal id="review-transfer" title="Review Stock Request" size="xl" hide-footer>
        <div class="table-responsive">
          <table class="table table-bordered">
            <thead><tr><th>Product</th><th>Requested</th><th>On Hand</th><th>Reserved</th><th>Transferable</th><th>Approved Qty</th><th>Decision</th><th>Reason</th></tr></thead>
            <tbody>
              <tr v-for="detail in details" :key="'review-' + detail.detail_id">
                <td><strong>{{ detail.name }}</strong><div class="small text-muted">{{ detail.code }}</div></td>
                <td>{{ formatNumber(detail.requested_quantity, 2) }}</td><td>{{ formatNumber(detail.on_hand, 2) }}</td><td>{{ formatNumber(detail.reserved, 2) }}</td><td>{{ formatNumber(detail.transferable, 2) }}</td>
                <td><b-form-input type="number" min="0" :max="Math.min(detail.requested_quantity, detail.transferable)" step="0.01" v-model.number="detail.review_quantity"></b-form-input></td>
                <td><span :class="decisionBadgeClass(reviewDecision(detail))">{{ workflowLabel(reviewDecision(detail)) }}</span></td>
                <td><b-form-input v-model="detail.review_reason" :required="reviewDecision(detail) !== 'approved'" placeholder="Required for partial/declined"></b-form-input></td>
              </tr>
            </tbody>
          </table>
        </div>
        <b-form-group label="General Warehouse Response *"><b-form-textarea v-model="reviewNote" rows="3" placeholder="Give the exact reason for this decision"></b-form-textarea></b-form-group>
        <div class="d-flex justify-content-between">
          <b-button variant="outline-danger" @click="declineAll">Decline All</b-button>
          <div><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('review-transfer')">Cancel</b-button><b-button variant="primary" :disabled="actionProcessing" @click="submitReview">Submit Decision</b-button></div>
        </div>
      </b-modal>

      <b-modal id="dispatch-transfer" title="Dispatch Stock Transfer" size="lg" hide-footer>
        <b-form-group label="Driver *"><v-select v-model="dispatchForm.driver_id" :reduce="driver => driver.id" :options="drivers" label="display_name" placeholder="Select an active driver"></v-select></b-form-group>
        <b-form-group label="Vehicle Details"><b-form-input v-model="dispatchForm.vehicle_details" placeholder="Registration number / vehicle"></b-form-input></b-form-group>
        <b-form-group label="Dispatch Note"><b-form-textarea v-model="dispatchForm.note" rows="3"></b-form-textarea></b-form-group>
        <div class="text-right"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('dispatch-transfer')">Cancel</b-button><b-button variant="warning" :disabled="actionProcessing" @click="submitDispatch(false)">Dispatch Transfer</b-button></div>
      </b-modal>

      <b-modal id="receive-transfer" title="Receive Dispatched Stock" size="xl" hide-footer>
        <div class="d-flex justify-content-end mb-2"><b-button size="sm" variant="outline-success" class="mr-2" @click="acceptAll">Accept All</b-button><b-button size="sm" variant="outline-danger" @click="rejectAll">Reject All</b-button></div>
        <div class="table-responsive"><table class="table table-bordered receive-table"><thead><tr><th>Product / SKU</th><th>Dispatched</th><th>Accepted *</th><th>Rejected *</th><th>Rejection Reason</th><th>Reason Details</th></tr></thead>
          <tbody><tr v-for="detail in receivableDetails" :key="'receive-' + detail.detail_id"><td><strong>{{ detail.name }}</strong><div class="small text-muted">{{ detail.code }}</div></td><td>{{ formatNumber(detail.dispatched_quantity, 2) }}</td><td><b-form-input type="number" min="0" :max="detail.dispatched_quantity" step="0.01" v-model.number="detail.accepted_quantity_input" @input="syncRejected(detail)"></b-form-input></td><td><b-form-input type="number" min="0" :max="detail.dispatched_quantity" step="0.01" v-model.number="detail.rejected_quantity_input" @input="syncAccepted(detail)"></b-form-input></td><td><b-form-select v-model="detail.rejection_reason_input" :options="rejectionReasons" :disabled="Number(detail.rejected_quantity_input || 0) <= 0"></b-form-select></td><td><b-form-input v-model="detail.rejection_note_input" :disabled="Number(detail.rejected_quantity_input || 0) <= 0" :placeholder="detail.rejection_reason_input === 'other' ? 'Required' : 'Optional'"></b-form-input></td></tr></tbody>
        </table></div>
        <b-form-group label="Receiving Note"><b-form-textarea v-model="receiveNote" rows="2"></b-form-textarea></b-form-group>
        <b-form-group v-if="hasRejectedReceipt" label="Return Note"><b-form-textarea v-model="returnNote" rows="2" placeholder="Instructions for returning rejected stock"></b-form-textarea></b-form-group>
        <div class="text-right"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('receive-transfer')">Cancel</b-button><b-button variant="success" :disabled="actionProcessing" @click="submitReceive">Confirm Receipt</b-button></div>
      </b-modal>

      <b-modal id="dispatch-return" title="Dispatch Rejected Stock Return" size="lg" hide-footer>
        <b-form-group label="Driver *"><v-select v-model="returnDispatchForm.driver_id" :reduce="driver => driver.id" :options="drivers" label="display_name" placeholder="Select an active driver"></v-select></b-form-group>
        <b-form-group label="Vehicle Details"><b-form-input v-model="returnDispatchForm.vehicle_details"></b-form-input></b-form-group>
        <b-form-group label="Return Dispatch Note"><b-form-textarea v-model="returnDispatchForm.note" rows="3"></b-form-textarea></b-form-group>
        <div class="text-right"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('dispatch-return')">Cancel</b-button><b-button variant="warning" :disabled="actionProcessing" @click="submitDispatch(true)">Dispatch Return</b-button></div>
      </b-modal>

      <b-modal id="receive-return" title="Receive Returned Stock" size="lg" hide-footer>
        <div class="table-responsive"><table class="table table-bordered"><thead><tr><th>Product</th><th>Quantity</th><th>Rejected Reason</th><th>Condition *</th></tr></thead><tbody><tr v-for="row in returnDetails" :key="'return-receive-' + row.id"><td>{{ row.product }}</td><td>{{ formatNumber(row.quantity, 2) }}</td><td>{{ rejectionReasonLabel(row.reason_code) }}</td><td><b-form-select v-model="row.condition_input" :options="returnConditions"></b-form-select></td></tr></tbody></table></div>
        <b-form-group label="Return Receipt Note"><b-form-textarea v-model="returnReceiveNote" rows="3"></b-form-textarea></b-form-group>
        <div class="text-right"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('receive-return')">Cancel</b-button><b-button variant="success" :disabled="actionProcessing" @click="submitReturnReceipt">Confirm Return Receipt</b-button></div>
      </b-modal>
    </b-card>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";
import Util from '../../../../utils';

export default {
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    canViewTransferPrice() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("transfer_price_view");
    },
    receivableDetails() {
      return (this.details || []).filter(detail => Number(detail.dispatched_quantity) > 0);
    },
    hasRejectedReceipt() {
      return this.receivableDetails.some(detail => Number(detail.rejected_quantity_input || 0) > 0);
    },
    returnDetails() {
      return this.transferReturn && Array.isArray(this.transferReturn.details) ? this.transferReturn.details : [];
    },
    returnTotalQuantity() {
      return this.returnDetails.reduce((total, row) => total + Number(row.quantity || 0), 0);
    },
    canPrintReturnVoucher() {
      return this.transferReturn && ['return_in_transit', 'return_received'].includes(this.transferReturn.status);
    }
  },
  metaInfo: {
    title: "Transfer Detail"
  },

  data() {
    return {
      isLoading: true,
      transfer: {},
      details: [],
      history: [],
      movements: [],
      transferReturn: null,
      drivers: [],
      actions: {},
      reviewNote: "",
      actionNote: "",
      receiveNote: "",
      returnNote: "",
      returnReceiveNote: "",
      dispatchForm: { driver_id: null, vehicle_details: '', note: '' },
      returnDispatchForm: { driver_id: null, vehicle_details: '', note: '' },
      rejectionReasons: [
        { value: null, text: 'Choose reason' },
        { value: 'damaged_transport', text: 'Damaged in transport' },
        { value: 'faulty_product', text: 'Faulty product' },
        { value: 'wrong_product', text: 'Wrong product' },
        { value: 'wrong_model', text: 'Wrong model' },
        { value: 'quantity_mismatch', text: 'Quantity mismatch' },
        { value: 'broken_packaging', text: 'Broken packaging' },
        { value: 'missing_accessories', text: 'Missing accessories' },
        { value: 'serial_mismatch', text: 'Serial / IMEI mismatch' },
        { value: 'not_requested', text: 'Not requested' },
        { value: 'other', text: 'Other' }
      ],
      returnConditions: [
        { value: null, text: 'Choose condition' },
        { value: 'saleable', text: 'Saleable - return to stock' },
        { value: 'faulty', text: 'Faulty' },
        { value: 'damaged', text: 'Damaged' },
        { value: 'repair_required', text: 'Repair required' },
        { value: 'quarantine', text: 'Quarantine' }
      ],
      actionProcessing: false
    };
  },

  methods: {
    //----------------------------------- Print Transfer PDF -------------------------\\
    Print_Transfer_PDF() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      let id = this.$route.params.id;
      return axios
        .get(`transfer_pdf/${id}`, {
          responseType: "blob", // important
          headers: {
            "Content-Type": "application/json"
          }
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute(
            "download",
            "Transfer_" + this.transfer.Ref + ".pdf"
          );
          document.body.appendChild(link);
          link.click();
          // Complete the animation of the  progress bar.
          setTimeout(() => NProgress.done(), 500);
        })
        .catch(() => {
          // Complete the animation of theprogress bar.
          setTimeout(() => NProgress.done(), 500);
        });
    },

    //------------------------------ Print -------------------------\\
    print() {
      if (!this.openPrintDocument('print_Invoice', 'Transfer Detail', this.transferDetailPrintStyles())) {
        this.makeToast('warning', 'Please allow pop-ups in the browser to print the transfer detail.', this.$t('Warning'));
      }
    },

    printReturnVoucher() {
      if (!this.canPrintReturnVoucher) {
        this.makeToast('warning', 'Dispatch the return and assign a driver before printing the voucher.', this.$t('Warning'));
        return;
      }
      if (!this.openPrintDocument('print_Return_Voucher', 'Return Dispatch Voucher', this.returnVoucherPrintStyles())) {
        this.makeToast('warning', 'Please allow pop-ups in the browser to print the return voucher.', this.$t('Warning'));
      }
    },

    openPrintDocument(elementId, title, styles) {
      const printable = document.getElementById(elementId);
      if (!printable) return false;
      const printWindow = window.open('', '_blank', 'width=1100,height=850,scrollbars=yes');
      if (!printWindow) return false;
      printWindow.document.open();
      printWindow.document.write(`<!doctype html><html><head><meta charset="utf-8"><title>${title}</title><style>${styles}</style></head><body>${printable.outerHTML}</body></html>`);
      printWindow.document.close();
      printWindow.focus();
      setTimeout(() => {
        printWindow.print();
        printWindow.close();
      }, 300);
      return true;
    },

    transferDetailPrintStyles() {
      return `
        @page { size: A4 landscape; margin: 8mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 8.5pt; line-height: 1.3; }
        #print_Invoice, .invoice-print { width: 100%; max-width: none; margin: 0; padding: 0; background: #fff; }
        .transfer-return-print-section, .transfer-audit-print-section, .transfer-history-print-section { display: none !important; }
        .transfer-print-header { display: table; width: 100%; border-bottom: 3px solid #663399; padding: 0 0 10px; margin: 0 0 12px; }
        .transfer-print-brand, .transfer-print-reference { display: table-cell; vertical-align: middle; }
        .transfer-print-brand { width: 62%; }
        .transfer-print-reference { width: 38%; text-align: right; }
        .transfer-print-company { color: #663399; font-size: 18pt; line-height: 1.1; font-weight: 800; letter-spacing: .4px; }
        .transfer-print-title { margin-top: 4px; color: #172033; font-size: 13pt; line-height: 1.15; font-weight: 800; text-transform: uppercase; }
        .transfer-print-subtitle { margin-top: 2px; color: #667085; font-size: 8.5pt; }
        .transfer-print-reference-label { color: #667085; font-size: 7.5pt; font-weight: 700; letter-spacing: .7px; }
        .transfer-print-reference-value { margin: 2px 0; color: #663399; font-size: 15pt; line-height: 1.1; font-weight: 800; }
        .transfer-print-reference-type { color: #172033; font-size: 8.5pt; }
        .row { display: flex; flex-wrap: wrap; width: 100%; margin: 0 0 9px; }
        .justify-content-md-center { justify-content: center; }
        .col-lg-4, .col-md-6, .col-sm-12 { width: 33.3333%; padding: 0 4px; }
        .col-md-12 { width: 100%; padding: 0; }
        .offset-md-8.col-md-4 { width: 34%; margin-left: 66%; padding: 0; }
        .card { height: 100%; border: 1px solid #d9dce3; border-radius: 3px; background: #fff; }
        .card-body { padding: 8px 9px; }
        h4 { margin: 0; color: #663399; font-size: 15pt; line-height: 1.2; text-transform: uppercase; }
        h5 { margin: 0 0 6px; color: #422461; font-size: 9pt; line-height: 1.2; }
        h6 { margin: 0 0 4px; font-size: 8.5pt; }
        p { margin: 0 0 3px; }
        hr { height: 2px; margin: 6px 0 10px; border: 0; background: #663399; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        svg { width: 11px; height: 11px; vertical-align: middle; }
        .mt-4, .mt-5 { margin-top: 8px !important; }
        .mb-4 { margin-bottom: 8px !important; }
        .mb-3 { margin-bottom: 5px !important; }
        .mb-2 { margin-bottom: 3px !important; }
        .p-3 { padding: 7px !important; }
        .bg-light { background: #f5f3f8 !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .rounded { border-radius: 3px; }
        .border { border: 1px solid #d9dce3; }
        .table-responsive { width: 100%; overflow: visible !important; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .table { width: 100%; margin: 0 0 8px; color: #172033; }
        .table th, .table td { border: 1px solid #cfd4dc; padding: 4px 3px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .table thead th { background: #eeeaf5 !important; color: #2e1b47; font-size: 6.7pt; line-height: 1.15; text-transform: uppercase; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .table tbody td { font-size: 7.2pt; }
        .table tbody td:first-child { font-weight: 600; }
        .table-striped tbody tr:nth-of-type(odd) { background: #f7f7f8; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .text-center { text-align: center !important; }
        .text-right { text-align: right !important; }
        .text-left { text-align: left !important; }
        .text-success { color: #087f5b !important; }
        .text-danger { color: #c92a2a !important; }
        .text-primary { color: #663399 !important; }
        .text-muted { color: #667085 !important; }
        .font-weight-bold, strong { font-weight: 700; }
        .small { font-size: 6.5pt; }
        .badge { display: inline-block; border: 1px solid currentColor; border-radius: 3px; padding: 1px 3px; font-size: 6.3pt; font-weight: 700; line-height: 1.15; white-space: normal; }
        .badge-primary { background: #663399; border-color: #663399; color: #fff; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        tr, td, th, .card { page-break-inside: avoid; }
      `;
    },

    returnVoucherPrintStyles() {
      return `
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #172033; font-family: Arial, Helvetica, sans-serif; font-size: 10pt; line-height: 1.35; }
        .return-voucher-document { width: 100%; max-width: none; margin: 0; padding: 0; border: 0; background: #fff; }
        .rv-header { display: table; width: 100%; border-bottom: 3px solid #663399; padding-bottom: 12px; margin-bottom: 14px; }
        .rv-header-brand, .rv-header-reference { display: table-cell; vertical-align: middle; }
        .rv-header-brand { width: 62%; }
        .rv-header-reference { width: 38%; text-align: right; }
        .rv-company { color: #663399; font-size: 18pt; line-height: 1.1; font-weight: 800; letter-spacing: .4px; }
        .rv-title { margin-top: 5px; font-size: 13pt; font-weight: 700; text-transform: uppercase; }
        .rv-subtitle { margin-top: 2px; color: #667085; font-size: 8.5pt; }
        .rv-reference-label { color: #667085; font-size: 7.5pt; font-weight: 700; letter-spacing: .7px; }
        .rv-reference-value { color: #663399; font-size: 15pt; font-weight: 800; margin: 2px 0; }
        .rv-original-reference { font-size: 8.5pt; }
        .rv-route { display: table; width: 100%; margin: 0 0 14px; table-layout: fixed; }
        .rv-route-location, .rv-route-arrow { display: table-cell; vertical-align: middle; }
        .rv-route-location { width: 46%; padding: 10px 12px; border: 1px solid #d9dce3; background: #f7f5fb; }
        .rv-route-location span { display: block; color: #667085; font-size: 7.5pt; font-weight: 700; letter-spacing: .5px; margin-bottom: 3px; }
        .rv-route-location strong { display: block; font-size: 10pt; }
        .rv-route-arrow { width: 8%; text-align: center; color: #663399; font-size: 18pt; font-weight: 700; }
        .rv-meta-table, .rv-items-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        .rv-meta-table { margin-bottom: 16px; }
        .rv-meta-table th, .rv-meta-table td { border: 1px solid #d9dce3; padding: 6px 8px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .rv-meta-table th { width: 14%; background: #f2f3f5; color: #475467; font-size: 8pt; }
        .rv-meta-table td { width: 36%; font-size: 9pt; }
        .rv-section-title { margin: 0; padding: 7px 9px; background: #663399; color: #fff; font-size: 10pt; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .rv-items-table { margin-bottom: 14px; }
        .rv-items-table th, .rv-items-table td { border: 1px solid #cfd4dc; padding: 7px 6px; text-align: left; vertical-align: top; overflow-wrap: anywhere; }
        .rv-items-table thead th { background: #eeeaf5; color: #2e1b47; font-size: 8pt; text-transform: uppercase; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .rv-items-table tbody td { font-size: 8.5pt; }
        .rv-items-table tfoot th { background: #f5f5f6; text-align: right; font-size: 9pt; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
        .rv-col-number { width: 5%; } .rv-col-product { width: 35%; } .rv-col-code { width: 18%; } .rv-col-quantity { width: 12%; } .rv-col-reason { width: 30%; }
        .rv-quantity { text-align: center !important; font-weight: 700; white-space: nowrap; }
        .rv-item-note { color: #667085; font-size: 7.5pt; margin-top: 3px; }
        .rv-note { border: 1px solid #d9dce3; background: #fafafa; padding: 9px 10px; margin: 0 0 32px; }
        .rv-note strong { display: block; color: #475467; font-size: 8pt; text-transform: uppercase; margin-bottom: 3px; }
        .rv-note span { display: block; font-size: 9pt; }
        .rv-signatures { display: table; width: 100%; table-layout: fixed; margin-top: 48px; page-break-inside: avoid; }
        .rv-signature { display: table-cell; width: 33.333%; text-align: center; vertical-align: top; padding: 0 14px; }
        .rv-signature-line { border-top: 1px solid #344054; margin-bottom: 7px; }
        .rv-signature strong, .rv-signature span { display: block; }
        .rv-signature strong { font-size: 8.5pt; }
        .rv-signature span { color: #667085; font-size: 7.5pt; margin-top: 2px; }
        .rv-footer { border-top: 1px solid #e4e7ec; margin-top: 28px; padding-top: 7px; color: #667085; font-size: 7.5pt; text-align: center; }
        tr, td, th { page-break-inside: avoid; }
      `;
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      number = Number(number || 0);
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    workflowLabel(status) {
      if (!status) return '—';
      return String(status).split('_').map(word => word.charAt(0).toUpperCase() + word.slice(1)).join(' ');
    },
    rejectionReasonLabel(code) {
      if (!code) return '-';
      const option = this.rejectionReasons.find(row => row.value === code);
      return option ? option.text : this.workflowLabel(code);
    },
    workflowBadgeClass(status) {
      if (['completed', 'received'].includes(status)) return 'badge badge-outline-success';
      if (['declined', 'cancelled'].includes(status)) return 'badge badge-outline-danger';
      if (['draft', 'pending_approval', 'pending_acknowledgement', 'ready_for_dispatch', 'return_pending'].includes(status)) return 'badge badge-outline-warning';
      return 'badge badge-outline-info';
    },
    decisionBadgeClass(status) {
      if (status === 'approved') return 'badge badge-outline-success';
      if (status === 'declined') return 'badge badge-outline-danger';
      if (status === 'partially_approved') return 'badge badge-outline-warning';
      return 'badge badge-outline-secondary';
    },
    reviewDecision(detail) {
      const approved = Number(detail.review_quantity || 0);
      const requested = Number(detail.requested_quantity || 0);
      if (approved <= 0) return 'declined';
      if (approved + 0.000001 >= requested) return 'approved';
      return 'partially_approved';
    },
    declineAll() {
      (this.details || []).forEach(detail => this.$set(detail, 'review_quantity', 0));
    },
    workflowError(error) {
      const data = error && error.response ? error.response.data : null;
      if (data && data.errors) {
        const values = Object.values(data.errors);
        if (values.length) return Array.isArray(values[0]) ? values[0][0] : values[0];
      }
      return (data && data.message) || 'The transfer could not be updated.';
    },

    expiry_pill_style(dateStr) {
      const base = {
        display: "inline-block",
        padding: "2px 8px",
        borderRadius: "10px",
        fontSize: "11px",
        fontWeight: "600",
      };
      if (!dateStr) return Object.assign({}, base, { background: "#f3f4f6", color: "#6b7280" });
      const today = new Date(); today.setHours(0, 0, 0, 0);
      const exp = new Date(dateStr);
      if (isNaN(exp.getTime())) return Object.assign({}, base, { background: "#f3f4f6", color: "#6b7280" });
      exp.setHours(0, 0, 0, 0);
      const diffDays = Math.round((exp - today) / (1000 * 60 * 60 * 24));
      if (diffDays < 0) return Object.assign({}, base, { background: "#fee2e2", color: "#991b1b" });
      if (diffDays <= 30) return Object.assign({}, base, { background: "#fef3c7", color: "#92400e" });
      return Object.assign({}, base, { background: "#dcfce7", color: "#166534" });
    },

    //----------------------------------------- Format Display Date -------------------------------\\
    formatDisplayDate(value) {
      if (!value) return '';
      // Get date format from Vuex store (loaded from database) or fallback
      const dateFormat = this.$store.getters.getDateFormat || Util.getDateFormat(this.$store);
      return Util.formatDisplayDate(value, dateFormat);
    },

    //----------------------------------- Get Details Transfer ------------------------------\\
    Get_Transfer_Details() {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      let id = this.$route.params.id;
      return axios
        .get("transfers/" + id)
        .then(response => {
          this.transfer = response.data.transfer;
          this.details = response.data.details;
          this.history = response.data.history || [];
          this.movements = response.data.movements || [];
          this.transferReturn = response.data.return || null;
          this.drivers = (response.data.drivers || []).map(driver => Object.assign({}, driver, {
            display_name: `${driver.name} (${driver.employee_code})${driver.phone ? ' - ' + driver.phone : ''}`
          }));
          this.actions = response.data.actions || {};
          this.reviewNote = this.transfer.response_note || '';
          this.actionNote = '';
          this.details.forEach(detail => {
            this.$set(detail, 'review_quantity', Math.min(Number(detail.requested_quantity || 0), Number(detail.transferable || 0)));
            this.$set(detail, 'review_reason', detail.response_reason || '');
            this.$set(detail, 'accepted_quantity_input', Number(detail.dispatched_quantity || 0));
            this.$set(detail, 'rejected_quantity_input', 0);
            this.$set(detail, 'rejection_reason_input', null);
            this.$set(detail, 'rejection_note_input', '');
          });
          (this.transferReturn && this.transferReturn.details || []).forEach(row => this.$set(row, 'condition_input', row.received_condition || null));
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
          this.$swal(
            this.$t("Failed"),
            this.$t("Failed_to_load_transfer_details"),
            "warning"
          );
        });
    },

    submitReview() {
      if (!String(this.reviewNote || '').trim()) {
        this.makeToast('warning', 'A general warehouse response is required.', this.$t('Warning'));
        return;
      }
      const invalid = this.details.find(detail => {
        const qty = Number(detail.review_quantity || 0);
        return qty < 0 || qty > Number(detail.requested_quantity) || qty > Number(detail.transferable)
          || (this.reviewDecision(detail) !== 'approved' && !String(detail.review_reason || '').trim());
      });
      if (invalid) {
        this.makeToast('warning', 'Check approved quantities and provide a reason for every partial or declined item.', this.$t('Warning'));
        return;
      }
      this.actionProcessing = true;
      axios.post(`transfers/${this.$route.params.id}/review`, {
        response_note: this.reviewNote,
        items: this.details.map(detail => ({ detail_id: detail.detail_id, approved_quantity: detail.review_quantity, response_reason: detail.review_reason }))
      }).then(() => {
        this.$bvModal.hide('review-transfer');
        this.makeToast('success', 'Stock request decision saved.', this.$t('Success'));
        this.Get_Transfer_Details();
      }).catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
        .finally(() => { this.actionProcessing = false; });
    },

    openAction(action) {
      const title = 'Acknowledge Warehouse Response';
      this.$swal({ title, input: 'textarea', inputPlaceholder: 'Optional note', showCancelButton: true, confirmButtonText: 'Acknowledge' })
        .then(result => {
          if (!result.value && result.dismiss) return;
          this.actionProcessing = true;
          axios.post(`transfers/${this.$route.params.id}/${action}`, { note: result.value || '' })
            .then(() => { this.makeToast('success', `${title} completed.`, this.$t('Success')); this.Get_Transfer_Details(); })
            .catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
            .finally(() => { this.actionProcessing = false; });
        });
    },

    syncRejected(detail) {
      const dispatched = Number(detail.dispatched_quantity || 0);
      const accepted = Math.max(0, Math.min(dispatched, Number(detail.accepted_quantity_input || 0)));
      this.$set(detail, 'accepted_quantity_input', accepted);
      this.$set(detail, 'rejected_quantity_input', Number((dispatched - accepted).toFixed(6)));
      if (detail.rejected_quantity_input <= 0) {
        this.$set(detail, 'rejection_reason_input', null);
        this.$set(detail, 'rejection_note_input', '');
      }
    },
    syncAccepted(detail) {
      const dispatched = Number(detail.dispatched_quantity || 0);
      const rejected = Math.max(0, Math.min(dispatched, Number(detail.rejected_quantity_input || 0)));
      this.$set(detail, 'rejected_quantity_input', rejected);
      this.$set(detail, 'accepted_quantity_input', Number((dispatched - rejected).toFixed(6)));
    },
    acceptAll() {
      this.receivableDetails.forEach(detail => {
        this.$set(detail, 'accepted_quantity_input', Number(detail.dispatched_quantity || 0));
        this.$set(detail, 'rejected_quantity_input', 0);
        this.$set(detail, 'rejection_reason_input', null);
        this.$set(detail, 'rejection_note_input', '');
      });
    },
    rejectAll() {
      this.receivableDetails.forEach(detail => {
        this.$set(detail, 'accepted_quantity_input', 0);
        this.$set(detail, 'rejected_quantity_input', Number(detail.dispatched_quantity || 0));
      });
    },
    submitDispatch(isReturn) {
      const form = isReturn ? this.returnDispatchForm : this.dispatchForm;
      if (!form.driver_id) {
        this.makeToast('warning', 'Select an active driver before dispatch.', this.$t('Warning'));
        return;
      }
      const url = isReturn ? `transfer-returns/${this.transferReturn.id}/dispatch` : `transfers/${this.$route.params.id}/dispatch`;
      this.actionProcessing = true;
      axios.post(url, form).then(() => {
        this.$bvModal.hide(isReturn ? 'dispatch-return' : 'dispatch-transfer');
        if (!isReturn) {
          this.makeToast('success', 'Stock transfer dispatched.', this.$t('Success'));
          return this.Get_Transfer_Details();
        }
        return this.Get_Transfer_Details().then(() => this.$swal({
          title: 'Return Dispatched',
          text: 'The return voucher is ready. Print it and hand it to the assigned driver with the returned products.',
          type: 'success',
          showCancelButton: true,
          confirmButtonText: 'Print Voucher',
          cancelButtonText: 'Close'
        }).then(result => { if (result.value) this.printReturnVoucher(); }));
      }).catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
        .finally(() => { this.actionProcessing = false; });
    },

    submitReceive() {
      const invalid = this.receivableDetails.find(detail => {
        const dispatched = Number(detail.dispatched_quantity || 0);
        const accepted = Number(detail.accepted_quantity_input || 0);
        const rejected = Number(detail.rejected_quantity_input || 0);
        return accepted < 0 || rejected < 0 || Math.abs((accepted + rejected) - dispatched) > 0.000001
          || (rejected > 0 && !detail.rejection_reason_input)
          || (rejected > 0 && detail.rejection_reason_input === 'other' && !String(detail.rejection_note_input || '').trim());
      });
      if (invalid) {
        this.makeToast('warning', 'Accepted plus rejected must equal dispatched quantity. Every rejection needs a reason; Other also needs details.', this.$t('Warning'));
        return;
      }
      const items = this.receivableDetails.map(detail => ({ detail_id: detail.detail_id, accepted_quantity: Number(detail.accepted_quantity_input || 0), rejected_quantity: Number(detail.rejected_quantity_input || 0), rejection_reason_code: detail.rejection_reason_input, rejection_note: detail.rejection_note_input }));
      const accepted = items.reduce((total, row) => total + row.accepted_quantity, 0);
      const rejected = items.reduce((total, row) => total + row.rejected_quantity, 0);
      this.$swal({ title: 'Confirm receipt?', text: `${accepted} accepted and ${rejected} rejected. This stock movement cannot be repeated.`, type: 'question', showCancelButton: true, confirmButtonText: 'Confirm Receipt' }).then(result => {
        if (!result.value) return;
        this.actionProcessing = true;
        axios.post(`transfers/${this.$route.params.id}/receive`, { note: this.receiveNote, return_note: this.returnNote, items })
          .then(() => { this.$bvModal.hide('receive-transfer'); this.makeToast('success', 'Stock receipt saved.', this.$t('Success')); this.Get_Transfer_Details(); })
          .catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
          .finally(() => { this.actionProcessing = false; });
      });
    },

    submitReturnReceipt() {
      if (!this.returnDetails.length || this.returnDetails.some(row => !row.condition_input)) {
        this.makeToast('warning', 'Select a condition for every returned item.', this.$t('Warning'));
        return;
      }
      const items = this.returnDetails.map(row => ({ return_detail_id: row.id, condition: row.condition_input }));
      this.$swal({ title: 'Confirm returned stock?', text: 'Only saleable items will be added back to available stock.', type: 'question', showCancelButton: true, confirmButtonText: 'Confirm Return' }).then(result => {
        if (!result.value) return;
        this.actionProcessing = true;
        axios.post(`transfer-returns/${this.transferReturn.id}/receive`, { note: this.returnReceiveNote, items })
          .then(() => { this.$bvModal.hide('receive-return'); this.makeToast('success', 'Returned stock receipt saved.', this.$t('Success')); this.Get_Transfer_Details(); })
          .catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
          .finally(() => { this.actionProcessing = false; });
      });
    },

    cancelTransfer() {
      this.$swal({ title: 'Cancel transfer?', input: 'textarea', inputPlaceholder: 'Cancellation reason (required)', showCancelButton: true, confirmButtonText: 'Cancel Transfer', confirmButtonColor: '#dc3545', inputValidator: value => !String(value || '').trim() && 'A cancellation reason is required.' }).then(result => {
        if (!result.value) return;
        this.actionProcessing = true;
        axios.post(`transfers/${this.$route.params.id}/cancel`, { note: result.value })
          .then(() => { this.makeToast('success', 'Transfer cancelled.', this.$t('Success')); this.Get_Transfer_Details(); })
          .catch(error => this.makeToast('danger', this.workflowError(error), this.$t('Failed')))
          .finally(() => { this.actionProcessing = false; });
      });
    },

    //---------------------------------- Approve Transfer ----------------------\\
    Approve_Transfer() {
      this.$swal({
        title: this.$t("Approve_Transfer"),
        text: this.$t("Are_you_sure_you_want_to_approve_this_transfer"),
        type: "question",
        showCancelButton: true,
        confirmButtonColor: "#28a745",
        cancelButtonColor: "#6c757d",
        cancelButtonText: this.$t("Cancel"),
        confirmButtonText: this.$t("Approve")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          let id = this.$route.params.id;
          axios
            .post("transfers/" + id + "/approve")
            .then(() => {
              this.$swal(
                this.$t("Success"),
                this.$t("Transfer_approved_successfully"),
                "success"
              );
              // Reload transfer details
              this.Get_Transfer_Details();
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Failed"),
                this.$t("Failed_to_approve_transfer"),
                "warning"
              );
            });
        }
      });
    },

    //---------------------------------- Delete Transfer ----------------------\\
    Delete_Transfer() {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          let id = this.$route.params.id;
          axios
            .delete("transfers/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              // Redirect to transfers list
              this.$router.push({ name: "index_transfer" });
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  },

  //-----------------------------Autoload function-------------------
  created: function() {
    this.Get_Transfer_Details();
  }
};
</script>

<style scoped>
.transfer-info p {
  margin-bottom: 0.5rem;
}

.invoice {
  background: #fff;
  padding: 20px;
}

.invoice-print {
  background: #fff;
}

.transfer-print-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 3px solid #663399;
  padding-bottom: 12px;
  margin-bottom: 16px;
}

.transfer-print-company {
  color: #663399;
  font-size: 24px;
  line-height: 1.1;
  font-weight: 800;
  letter-spacing: 0.4px;
}

.transfer-print-title {
  margin-top: 4px;
  font-size: 17px;
  line-height: 1.2;
  font-weight: 800;
  text-transform: uppercase;
}

.transfer-print-subtitle,
.transfer-print-reference-label {
  color: #667085;
}

.transfer-print-subtitle,
.transfer-print-reference-type {
  font-size: 12px;
}

.transfer-print-reference {
  text-align: right;
}

.transfer-print-reference-label {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.7px;
}

.transfer-print-reference-value {
  margin: 2px 0;
  color: #663399;
  font-size: 20px;
  line-height: 1.1;
  font-weight: 800;
}

.table th {
  border-top: 1px solid #dee2e6;
  font-weight: 600;
}

.bg-light {
  background-color: #f8f9fa !important;
}

.shadow-sm {
  box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075) !important;
}

.return-voucher-document {
  background: #fff;
  color: #1f2937;
  border: 1px solid #dee2e6;
  border-radius: 6px;
  padding: 24px;
}

.rv-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 3px solid #663399;
  padding-bottom: 12px;
  margin-bottom: 14px;
}

.rv-company { color: #663399; font-size: 24px; font-weight: 800; }
.rv-title { margin-top: 4px; font-size: 17px; font-weight: 700; text-transform: uppercase; }
.rv-subtitle, .rv-reference-label, .rv-item-note, .rv-signature span, .rv-footer { color: #667085; }
.rv-header-reference { text-align: right; }
.rv-reference-label { font-size: 11px; font-weight: 700; }
.rv-reference-value { color: #663399; font-size: 20px; font-weight: 800; }
.rv-original-reference { font-size: 12px; }
.rv-route { display: flex; align-items: center; margin-bottom: 14px; }
.rv-route-location { flex: 1; padding: 10px 12px; border: 1px solid #d9dce3; background: #f7f5fb; }
.rv-route-location span { display: block; color: #667085; font-size: 11px; font-weight: 700; }
.rv-route-location strong { display: block; }
.rv-route-arrow { width: 52px; text-align: center; color: #663399; font-size: 24px; }
.rv-meta-table, .rv-items-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
.rv-meta-table { margin-bottom: 16px; }
.rv-meta-table th, .rv-meta-table td, .rv-items-table th, .rv-items-table td { border: 1px solid #d9dce3; padding: 7px 8px; vertical-align: top; overflow-wrap: anywhere; }
.rv-meta-table th { width: 14%; background: #f2f3f5; }
.rv-meta-table td { width: 36%; }
.rv-section-title { padding: 8px 10px; background: #663399; color: #fff; font-weight: 700; text-transform: uppercase; }
.rv-items-table { margin-bottom: 14px; }
.rv-items-table thead th { background: #eeeaf5; }
.rv-items-table tfoot th { background: #f5f5f6; text-align: right; }
.rv-col-number { width: 5%; }
.rv-col-product { width: 35%; }
.rv-col-code { width: 18%; }
.rv-col-quantity { width: 12%; }
.rv-col-reason { width: 30%; }
.rv-quantity { text-align: center; white-space: nowrap; }
.rv-item-note { font-size: 11px; margin-top: 3px; }
.rv-note { border: 1px solid #d9dce3; background: #fafafa; padding: 9px 10px; margin-bottom: 32px; }
.rv-note strong, .rv-note span { display: block; }
.rv-signatures { display: flex; margin-top: 52px; }
.rv-signature { flex: 1; text-align: center; padding: 0 14px; }
.rv-signature-line { border-top: 1px solid #344054; margin-bottom: 7px; }
.rv-signature strong, .rv-signature span { display: block; }
.rv-signature span, .rv-footer { font-size: 11px; }
.rv-footer { border-top: 1px solid #e4e7ec; margin-top: 28px; padding-top: 7px; text-align: center; }

@media (max-width: 767.98px) {
  .transfer-print-header, .rv-header, .rv-route, .rv-signatures { display: block; }
  .transfer-print-reference { text-align: left; margin-top: 12px; }
  .rv-header-reference { text-align: left; margin-top: 12px; }
  .rv-route-arrow { width: 100%; transform: rotate(90deg); }
  .rv-signature { margin-top: 45px; }
}
</style>

<style>
#review-transfer .modal-dialog {
  width: calc(100vw - 48px);
  max-width: 1500px !important;
}

#review-transfer .table-responsive > .table {
  min-width: 1150px;
}

#receive-transfer .modal-dialog {
  width: calc(100vw - 48px);
  max-width: 1500px !important;
}

#receive-transfer .receive-table {
  min-width: 1200px;
}

@media (max-width: 575.98px) {
  #review-transfer .modal-dialog,
  #receive-transfer .modal-dialog {
    width: calc(100vw - 16px);
    margin: 0.5rem auto;
  }
}
</style>
