<template>
  <div class="main-content">
    <breadcumb :page="$t('EditPurchase')" :folder="$t('ListPurchases')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="edit_purchase" v-if="!isLoading">
      <b-form @submit.prevent="Submit_Purchase">
        <b-row>
          <b-col lg="12" md="12" sm="12">
            <b-card>
              <b-row>

                <b-modal hide-footer id="open_scan" size="md" title="Barcode Scanner">
                  <qrcode-scanner
                    :qrbox="250" 
                    :fps="10" 
                    style="width: 100%; height: calc(100vh - 56px);"
                    @result="onScan"
                  />
                </b-modal>


                  <!-- date  -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider
                    name="date"
                    :rules="{ required: true}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('date') + ' ' + '*'">
                      <b-form-input
                        :state="getValidationState(validationContext)"
                        aria-describedby="date-feedback"
                        type="date"
                        v-model="purchase.date"
                      ></b-form-input>
                      <b-form-invalid-feedback
                        id="OrderTax-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
                <!-- Supplier -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Supplier" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Supplier') + ' ' + '*'">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        :disabled="linked_gate_passes.length > 0"
                        v-model="purchase.supplier_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Supplier')"
                        :options="suppliers.map(suppliers => ({label: suppliers.name, value: suppliers.id}))"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <!-- warehouse -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="warehouse" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('warehouse') + ' ' + '*'">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        :disabled="details.length > 0"
                        @input="Selected_Warehouse"
                        v-model="purchase.warehouse_id"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Warehouse')"
                        :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                      />
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Sales Tax Invoice No.">
                    <b-form-input
                      v-model.trim="purchase.sales_tax_invoice_no"
                      maxlength="100"
                      placeholder="Enter sales tax invoice number"
                    />
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Delivery Note No.">
                    <b-form-input
                      v-model.trim="purchase.delivery_note_no"
                      maxlength="100"
                      placeholder="Enter delivery note number"
                    />
                  </b-form-group>
                </b-col>

                <b-col v-if="linked_gate_passes.length" lg="12" md="12" sm="12" class="mb-3">
                  <b-form-group label="Gate Pass">
                    <div class="d-flex flex-wrap">
                      <span v-for="gatePass in linked_gate_passes" :key="gatePass.id" class="badge badge-light-primary mr-2 mb-2 p-2">
                        {{ gatePass.number }}<span v-if="gatePass.supplier_gate_pass_number"> ({{ gatePass.supplier_gate_pass_number }})</span>
                      </span>
                    </div>
                  </b-form-group>
                </b-col>

                <!-- Product -->
                <b-col md="12" class="mb-5">
                  <h6>{{$t('ProductName')}}</h6>
                 
                  <div id="autocomplete" class="autocomplete">
                    <div class="input-with-icon">
                      <img src="/assets_setup/scan.png" alt="Scan" class="scan-icon" @click="showModal">
                    <input 
                     :placeholder="$t('Scan_Search_Product_by_Code_Name')"
                      :disabled="linked_gate_passes.length > 0"
                      @input='e => search_input = e.target.value' 
                      @keyup="search(search_input)"
                      @focus="handleFocus"
                      @blur="handleBlur"
                      ref="product_autocomplete"
                      class="autocomplete-input" />
                    </div>
                    <ul class="autocomplete-result-list" v-show="focused">
                      <li class="autocomplete-result" v-for="product_fil in product_filter" @mousedown="SearchProduct(product_fil)">{{getResultValue(product_fil)}}</li>
                    </ul>
                </div>
                </b-col>

                <!-- Order products  -->
                <b-col md="12">
                  <h5>{{$t('order_products')}} *</h5>
                  <div class="table-responsive">
                    <table class="table table-hover purchase-products-table">
                      <thead class="bg-gray-300">
                        <tr>
                          <th scope="col">{{$t('ProductName')}}</th>
                          <th scope="col" class="purchase-quantity-column">{{$t('Qty')}}</th>
                          <th scope="col">RB Price</th>
                          <th scope="col">Trade Discount</th>
                          <th scope="col">Net Value Without Tax</th>
                          <th scope="col">GST Tax ({{formatNumber(purchase.tax_rate, 2)}}%)</th>
                          <th scope="col">Total Value With Tax</th>
                          <th scope="col">MRP Without Tax</th>
                          <th scope="col">MRP With Tax</th>
                          <th scope="col">I.Tax Withholding Amount</th>
                          <th scope="col">Grand Total</th>
                          <th scope="col" class="text-center">
                            <i class="fa fa-trash"></i>
                          </th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-if="details.length <=0">
                          <td colspan="12">{{$t('NodataAvailable')}}</td>
                        </tr>
                        <template v-for="detail in details">
                        <tr
                          :key="'detail-'+detail.detail_id"
                          :ref="'purchase_detail_' + detail.detail_id"
                          :class="{
                            'row_deleted': detail.del === 1 || detail.no_unit === 0,
                            'purchase-row-highlight': highlightedDetailId === detail.detail_id
                          }"
                        >
                          <td>
                            <strong>{{detail.name}}</strong>
                            <br>
                            <span class="badge badge-success">{{detail.code}}</span>
                            <div v-if="detail.warehouse_location" class="text-muted mt-1" style="font-size: 12px;">
                              {{ $t('Warehouse_Locations') }}: <strong>{{ detail.warehouse_location }}</strong>
                            </div>
                            <div v-if="detail.is_batch_tracked" class="text-info mt-1" style="font-size: 12px;">
                              <i class="fa fa-flask"></i> {{ $t('Track_Batches_Expiry') }}
                            </div>
                            <div v-if="detail.gate_pass_number" class="text-primary mt-1" style="font-size: 12px;">
                              Gate Pass: <strong>{{detail.gate_pass_number}}</strong>
                            </div>
                          </td>
                          <td class="purchase-quantity-column">
                            <div class="purchase-quantity-control">
                              <button type="button" class="btn btn-primary purchase-quantity-button" :disabled="detail.is_gate_pass_item" @click="decrement(detail, detail.detail_id)">−</button>
                              <input
                                type="number"
                                class="form-control purchase-quantity-input"
                                @input="Verified_Qty(detail, detail.detail_id)"
                                :min="1"
                                v-model.number="detail.quantity"
                                :disabled="detail.del === 1 || detail.no_unit === 0 || detail.is_gate_pass_item"
                              >
                              <button type="button" class="btn btn-primary purchase-quantity-button" :disabled="detail.is_gate_pass_item" @click="increment(detail, detail.detail_id)">+</button>
                            </div>
                          </td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.company_rb_price * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.DiscountNet * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.Net_cost * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.taxe * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{detail.subtotal.toFixed(2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.mrp_price * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(pricingPreview(detail).mrpWithTax * detail.quantity, 2)}}</td>
                          <td>{{currentUser.currency}} {{formatNumber(detail.withholding_tax * detail.quantity, 2)}}</td>
                          <td class="font-weight-bold">{{currentUser.currency}} {{formatNumber(pricingPreview(detail).netPayable * detail.quantity, 2)}}</td>
                          <td v-show="detail.no_unit !== 0" class="text-center">
                            <lucide-icon class="text-25 text-success" name="pencil" v-if="currentUserPermissions && currentUserPermissions.includes('edit_product_purchase')" @click="Modal_Updat_Detail(detail)" />
                            <lucide-icon v-if="!detail.is_gate_pass_item" class="text-25 text-danger" name="x" @click="delete_Product_Detail(detail.detail_id)" />
                          </td>
                        </tr>
                        <tr v-if="detail.is_batch_tracked && detail.no_unit !== 0 && detail.del !== 1"
                            :key="'batches-'+detail.detail_id" :style="{ background: 'transparent' }">
                          <td colspan="12" :style="{ padding: '0 8px 16px 8px', border: 'none' }">
                            <div
                              :style="{
                                background: 'linear-gradient(135deg, #f0f9ff 0%, #eef2ff 100%)',
                                border: '1px solid #e0e7ff',
                                borderLeft: '4px solid #6366f1',
                                borderRadius: '10px',
                                padding: '14px 18px',
                                boxShadow: '0 1px 3px rgba(15,23,42,0.04)'
                              }"
                            >
                              <!-- Header -->
                              <div
                                :style="{
                                  display: 'flex',
                                  justifyContent: 'space-between',
                                  alignItems: 'center',
                                  marginBottom: '12px',
                                  flexWrap: 'wrap',
                                  gap: '8px'
                                }"
                              >
                                <div :style="{ display: 'flex', alignItems: 'center', gap: '10px', flexWrap: 'wrap' }">
                                  <div
                                    :style="{
                                      width: '32px', height: '32px',
                                      borderRadius: '8px',
                                      background: '#6366f1',
                                      color: '#fff',
                                      display: 'flex',
                                      alignItems: 'center',
                                      justifyContent: 'center',
                                      boxShadow: '0 2px 6px rgba(99,102,241,0.35)'
                                    }"
                                  >
                                    <lucide-icon name="receipt-text" :style="{ fontSize: '16px' }" />
                                  </div>
                                  <div>
                                    <div :style="{ fontSize: '14px', fontWeight: '700', color: '#1e293b', lineHeight: '1.1' }">
                                      {{ $t('Batches') }}
                                    </div>
                                    <div :style="{ fontSize: '11px', color: '#64748b', marginTop: '2px' }">
                                      {{ (detail.batches || []).length }} {{ $t('items') || 'items' }}
                                      <span v-if="(detail.batches || []).length" :style="{ marginLeft: '6px' }">
                                        · {{ $t('Total') }}: <strong :style="{ color: '#0f172a' }">{{ formatNumber(batchTotalQty(detail), 2) }}</strong>
                                        / {{ formatNumber(detail.quantity || 0, 2) }}
                                      </span>
                                    </div>
                                  </div>
                                </div>
                                <button
                                  type="button"
                                  @click="add_batch(detail)"
                                  :style="{
                                    background: '#6366f1',
                                    color: '#fff',
                                    border: 'none',
                                    padding: '7px 14px',
                                    borderRadius: '8px',
                                    fontSize: '12px',
                                    fontWeight: '600',
                                    cursor: 'pointer',
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: '6px',
                                    boxShadow: '0 2px 6px rgba(99,102,241,0.25)',
                                    transition: 'all 0.2s'
                                  }"
                                  onmouseover="this.style.background='#4f46e5'; this.style.transform='translateY(-1px)'"
                                  onmouseout="this.style.background='#6366f1'; this.style.transform='translateY(0)'"
                                >
                                  <lucide-icon name="plus" /> {{ $t('Add') }}
                                </button>
                              </div>

                              <!-- Batches table -->
                              <div v-if="(detail.batches || []).length" :style="{ background: '#ffffff', borderRadius: '8px', overflow: 'hidden', border: '1px solid #e2e8f0' }">
                                <table :style="{ width: '100%', marginBottom: '0', borderCollapse: 'separate', borderSpacing: '0', fontSize: '12px' }">
                                  <thead>
                                    <tr>
                                      <th :style="batchThStyle">{{ $t('Batch_No') }}</th>
                                      <th :style="batchThStyle">{{ $t('Expiry_Date') }}</th>
                                      <th :style="batchThStyle">{{ $t('Mfg_Date') }}</th>
                                      <th :style="{ ...batchThStyle, textAlign: 'right' }">{{ $t('quantity') }}</th>
                                      <th :style="{ ...batchThStyle, textAlign: 'right' }">{{ $t('cost') }}</th>
                                      <th :style="{ ...batchThStyle, width: '40px', textAlign: 'center' }"></th>
                                    </tr>
                                  </thead>
                                  <tbody>
                                    <tr v-for="(b, bIdx) in detail.batches" :key="'b-'+detail.detail_id+'-'+bIdx" :style="{ background: bIdx % 2 === 0 ? '#ffffff' : '#fafbff' }">
                                      <td :style="batchTdStyle">
                                        <b-form-input size="sm" v-model="b.batch_no" :placeholder="$t('Batch_No')" :style="batchInputStyle" />
                                      </td>
                                      <td :style="batchTdStyle">
                                        <b-form-input size="sm" type="date" v-model="b.expiry_date" :style="batchInputStyle" />
                                      </td>
                                      <td :style="batchTdStyle">
                                        <b-form-input size="sm" type="date" v-model="b.mfg_date" :style="batchInputStyle" />
                                      </td>
                                      <td :style="batchTdStyle">
                                        <b-form-input
                                          size="sm"
                                          type="text"
                                          inputmode="decimal"
                                          lang="en"
                                          pattern="[0-9]*[.,]?[0-9]*"
                                          :value="b.qty"
                                          @input="val => onBatchNumberInput(b, 'qty', val)"
                                          :style="{ ...batchInputStyle, textAlign: 'right', fontWeight: '600' }"
                                        />
                                      </td>
                                      <td :style="batchTdStyle">
                                        <b-form-input
                                          size="sm"
                                          type="text"
                                          inputmode="decimal"
                                          lang="en"
                                          pattern="[0-9]*[.,]?[0-9]*"
                                          :value="b.unit_cost"
                                          @input="val => onBatchNumberInput(b, 'unit_cost', val)"
                                          :style="{ ...batchInputStyle, textAlign: 'right' }"
                                        />
                                      </td>
                                      <td :style="{ ...batchTdStyle, textAlign: 'center' }">
                                        <button
                                          type="button"
                                          @click="remove_batch(detail, bIdx)"
                                          :style="{
                                            width: '28px', height: '28px',
                                            borderRadius: '6px',
                                            border: '1px solid #fecaca',
                                            background: '#fef2f2',
                                            color: '#dc2626',
                                            cursor: 'pointer',
                                            display: 'inline-flex',
                                            alignItems: 'center',
                                            justifyContent: 'center',
                                            transition: 'all 0.2s'
                                          }"
                                          onmouseover="this.style.background='#dc2626'; this.style.color='#fff'; this.style.borderColor='#dc2626'"
                                          onmouseout="this.style.background='#fef2f2'; this.style.color='#dc2626'; this.style.borderColor='#fecaca'"
                                        >
                                          <lucide-icon name="x" />
                                        </button>
                                      </td>
                                    </tr>
                                  </tbody>
                                </table>
                              </div>

                              <!-- Empty state -->
                              <div
                                v-else
                                :style="{
                                  background: '#ffffff',
                                  border: '1px dashed #cbd5e1',
                                  borderRadius: '8px',
                                  padding: '24px 16px',
                                  textAlign: 'center',
                                  color: '#64748b'
                                }"
                              >
                                <div :style="{ fontSize: '24px', marginBottom: '6px', opacity: '0.5' }">
                                  <lucide-icon name="inbox" />
                                </div>
                                <div :style="{ fontSize: '13px', fontWeight: '500' }">
                                  {{ $t('NodataAvailable') }}
                                </div>
                                <div :style="{ fontSize: '11px', marginTop: '4px', opacity: '0.8' }">
                                  {{ $t('Click_Add_To_Start') || 'Click "Add" to create a batch' }}
                                </div>
                              </div>

                              <!-- Mismatch warning -->
                              <div
                                v-if="batchQtyMismatch(detail)"
                                :style="{
                                  marginTop: '10px',
                                  background: '#fef2f2',
                                  border: '1px solid #fecaca',
                                  borderLeft: '3px solid #dc2626',
                                  color: '#991b1b',
                                  padding: '8px 12px',
                                  borderRadius: '6px',
                                  fontSize: '12px',
                                  display: 'flex',
                                  alignItems: 'center',
                                  gap: '8px'
                                }"
                              >
                                <lucide-icon name="alert-triangle" :style="{ color: '#dc2626', fontSize: '14px' }" />
                                <span>{{ $t('Batch_Qty_Mismatch') }}</span>
                              </div>
                            </div>
                          </td>
                        </tr>
                        </template>
                      </tbody>
                    </table>
                  </div>
                </b-col>

                <div class="offset-md-9 col-md-3 mt-4">
                  <table class="table table-striped table-sm">
                    <tbody>
                      <tr>
                        <td class="bold">Total Value With Tax</td>
                        <td>{{currentUser.currency}} {{formatNumber(total, 2)}}</td>
                      </tr>
                      <tr>
                        <td class="bold">{{$t('OrderTax')}}</td>
                        <td>
                          <span>{{currentUser.currency}} {{purchase.TaxNet.toFixed(2)}} ({{formatNumber(purchase.tax_rate ,2)}} %)</span>
                        </td>
                      </tr>
                      <tr>
                        <td class="bold">I.Tax WithHold</td>
                        <td>+{{currentUser.currency}} {{purchase.withholding_tax.toFixed(2)}} ({{formatNumber(withholding_tax_rate, 2)}} %)</td>
                      </tr>
                      <tr>
                        <td class="bold">{{$t('Discount')}}</td>
                        <td>{{currentUser.currency}} {{purchase.discount.toFixed(2)}}</td>
                      </tr>
                      <tr>
                        <td class="bold">{{$t('Shipping')}}</td>
                        <td>{{currentUser.currency}} {{purchase.shipping.toFixed(2)}}</td>
                      </tr>
                      <tr>
                        <td>
                          <span class="font-weight-bold">Grand Total</span>
                        </td>
                        <td>
                          <span
                            class="font-weight-bold"
                          >{{currentUser.currency}} {{GrandTotal.toFixed(2)}}</span>
                        </td>
                      </tr>
                    </tbody>
                  </table>
                </div>

                <!-- Shipping  -->
                <b-col lg="4" md="4" sm="12" class="mb-3" v-if="currentUserPermissions && currentUserPermissions.includes('edit_tax_discount_shipping_purchase')">
                  <validation-provider
                    name="Shipping"
                    :rules="{ regex: /^\d*\.?\d*$/}"
                    v-slot="validationContext"
                  >
                    <b-form-group :label="$t('Shipping')">
                      <b-input-group :append="currentUser.currency">
                        <b-form-input
                          :state="getValidationState(validationContext)"
                          aria-describedby="Shipping-feedback"
                          label="Shipping"
                          v-model.number="purchase.shipping"
                          @keyup="keyup_Shipping()"
                        ></b-form-input>
                      </b-input-group>
                      <b-form-invalid-feedback
                        id="Shipping-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>

                 <!-- Status  -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Status" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Status') + ' ' + '*'">
                      <v-select
                        :class="{'is-invalid': !!errors.length}"
                        :state="errors[0] ? false : (valid ? true : null)"
                        v-model="purchase.statut"
                        :disabled="linked_gate_passes.length > 0"
                        :reduce="label => label.value"
                        :placeholder="$t('Choose_Status')"
                        :options="
                            [
                              {label: 'received', value: 'received'},
                              {label: 'pending', value: 'pending'},
                               {label: 'ordered', value: 'ordered'}
                            ]"
                      ></v-select>
                      <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>


                <b-col md="12">
                  <b-form-group :label="$t('Note')">
                    <textarea
                      v-model="purchase.notes"
                      rows="4"
                      class="form-control"
                      :placeholder="$t('Afewwords')"
                    ></textarea>
                  </b-form-group>
                </b-col>
                <b-col md="12">
                  <b-form-group>
                    <b-button
                      variant="primary"
                      @click="Submit_Purchase"
                      :disabled="SubmitProcessing || hasBatchValidationErrors"
                      :title="hasBatchValidationErrors ? $t('Batch_Qty_Mismatch') : ''"
                    >
                      <span v-if="SubmitProcessing" class="spinner sm spinner-white mr-2"></span>
                      <lucide-icon v-else class="me-2 font-weight-bold" name="check" />
                      {{ SubmitProcessing ? ($t('Saving') || 'Saving...') : $t('submit') }}
                    </b-button>
                    <div v-if="hasBatchValidationErrors" class="text-danger mt-2" style="font-size: 13px;">
                      <lucide-icon name="alert-triangle" style="vertical-align: middle; margin-right: 4px;" />
                      <template v-if="firstBatchErrorDetail && (!firstBatchErrorDetail.batches || firstBatchErrorDetail.batches.length === 0)">
                        {{ $t('Batch_Required_For_Item') || 'Add at least one batch for' }}: <strong>{{ firstBatchErrorDetail.name }}</strong>
                      </template>
                      <template v-else>
                        {{ $t('Batch_Qty_Mismatch') }}
                      </template>
                    </div>
                     <div v-once class="typo__p" v-if="SubmitProcessing">
                      <div class="spinner sm spinner-primary mt-3"></div>
                    </div>
                  </b-form-group>
                </b-col>
              </b-row>
            </b-card>
          </b-col>
        </b-row>
      </b-form>
    </validation-observer>

    <!-- Show Modal Update Detail Product -->
    <validation-observer ref="Update_Detail_purchase">
      <b-modal hide-footer size="xl" dialog-class="purchase-detail-dialog" id="form_Update_Detail" :title="detail.name">
        <b-form @submit.prevent="submit_Update_Detail">
          <b-row>
            <b-col lg="6" md="6" sm="12">
              <validation-provider name="RB Price" :rules="{ required: true, regex: /^\d*\.?\d*$/}" v-slot="validationContext">
                <b-form-group label="RB Price *">
                  <b-form-input v-model.number="detail.Unit_cost" :state="getValidationState(validationContext)" />
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col lg="6" md="6" sm="12">
              <validation-provider name="Discount Method" :rules="{ required: true}">
                <b-form-group slot-scope="{ valid, errors }" label="Discount Type *">
                  <v-select
                    v-model="detail.discount_Method"
                    @input="discountMethodChanged"
                    :reduce="label => label.value"
                    placeholder="Choose discount type"
                    :class="{'is-invalid': !!errors.length}"
                    :state="errors[0] ? false : (valid ? true : null)"
                    :options="[{label: 'Fixed', value: '2'}, {label: 'Percentage', value: '1'}]"
                  />
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col v-if="detail.discount_Method == '1'" lg="6" md="6" sm="12">
              <validation-provider name="Discount Percentage" :rules="{ required: true, min_value: 0, max_value: 100 }" v-slot="validationContext">
                <b-form-group label="Discount Percentage *">
                  <b-input-group append="%" class="discount-percentage-input">
                    <b-form-input type="number" min="0" max="100" step="0.01" v-model.number="detail.discount" :state="getValidationState(validationContext)" />
                  </b-input-group>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col lg="6" md="6" sm="12">
              <validation-provider v-if="detail.discount_Method == '2'" name="Trade Discount" :rules="{ required: true, min_value: 0 }" v-slot="validationContext">
                <b-form-group label="Trade Discount *">
                  <b-input-group :prepend="currentUser.currency">
                    <b-form-input type="number" min="0" step="0.01" v-model.number="detail.discount" :state="getValidationState(validationContext)" />
                  </b-input-group>
                </b-form-group>
              </validation-provider>
              <b-form-group v-else label="Trade Discount">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).tradeDiscount, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Net Value Without Tax"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).netValue, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group :label="'GST (' + formatNumber(purchase.tax_rate, 2) + '%)'"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).gstAmount, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Total Value With Tax"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).totalWithTax, 2)" readonly /></b-input-group></b-form-group>
            </b-col>

            <b-col lg="6" md="6" sm="12">
              <validation-provider name="MRP Without Tax" :rules="{ regex: /^\d*\.?\d*$/}" v-slot="validationContext">
                <b-form-group label="MRP Without Tax"><b-form-input v-model.number="detail.mrp_price" :state="getValidationState(validationContext)" /></b-form-group>
              </validation-provider>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="MRP With Tax"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).mrpWithTax, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="I.Tax Withholding Rate"><b-input-group append="%"><b-form-input :value="formatNumber(withholding_tax_rate, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="I.Tax Withholding Amount"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).withholdingAmount, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Net Payable Including Withholding"><b-input-group :prepend="currentUser.currency"><b-form-input :value="formatNumber(pricingPreview(detail).netPayable, 2)" readonly /></b-input-group></b-form-group>
            </b-col>
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Purchase Unit *"><b-form-input :value="detail.unitPurchase" readonly /></b-form-group>
            </b-col>

            <b-col lg="12" md="12" sm="12" v-show="detail.is_imei">
              <b-form-group :label="$t('Add_product_IMEI_Serial_number')"><b-form-input v-model="detail.imei_number" :placeholder="$t('Add_product_IMEI_Serial_number')" /></b-form-group>
            </b-col>
            <b-col md="12">
              <b-form-group>
                <b-button variant="primary" type="submit" :disabled="Submit_Processing_detail">{{$t('submit')}}</b-button>
              </b-form-group>
            </b-col>
          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Edit Purchase"
  },
  data() {
    return {
      focused: false,
      timer:null,
      highlightTimer: null,
      highlightedDetailId: null,
      withholding_tax_rate: 0,
      managed_taxes: [],
      linked_gate_passes: [],
      search_input:'',
      product_filter:[],
      isLoading: true,
      SubmitProcessing:false,
      Submit_Processing_detail:false,

      // ——— Inline styles for batches section ———
      batchThStyle: {
        padding: '8px 10px',
        background: '#f1f5f9',
        borderBottom: '1px solid #e2e8f0',
        fontSize: '10px',
        fontWeight: '700',
        color: '#475569',
        textTransform: 'uppercase',
        letterSpacing: '0.5px',
        textAlign: 'left'
      },
      batchTdStyle: {
        padding: '6px 10px',
        borderBottom: '1px solid #f1f5f9',
        verticalAlign: 'middle'
      },
      batchInputStyle: {
        height: '30px',
        fontSize: '12px',
        padding: '4px 8px'
      },

      warehouses: [],
      suppliers: [],
      products: [],
      details: [],
      detail: {},
      purchases: [],
      purchase: {
        id: "",
        statut: "",
        date: "",
        notes: "",
        supplier_id: "",
        warehouse_id: "",
        sales_tax_invoice_no: "",
        delivery_note_no: "",
        tax_rate: 0,
        TaxNet: 0,
        withholding_tax: 0,
        shipping: 0,
        discount: 0
      },
      total: 0,
      GrandTotal: 0,
      product: {
        id: "",
        code: "",
        stock: "",
        quantity: 1,
        discount: "",
        DiscountNet: "",
        discount_Method: "",
        name: "",
        no_unit:"",
        unitPurchase: "",
        purchase_unit_id: "",
        Net_cost: "",
        Total_cost: "",
        Unit_cost: "",
        company_rb_price: "",
        mrp_price: "",
        subtotal: "",
        product_id: "",
        detail_id: "",
        taxe: "",
        tax_percent: "",
        tax_method: "",
        product_variant_id: "",
        del: "",
        is_imei: "",
        imei_number:"",
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions","currentUser"]),

    // Only validate lines that are visible & not marked for deletion
    activeBatchTrackedDetails() {
      if (!Array.isArray(this.details)) return [];
      return this.details.filter(d =>
        d && d.is_batch_tracked && d.no_unit !== 0 && d.del !== 1
      );
    },

    // True if any batch-tracked line has missing batches or a qty mismatch
    hasBatchValidationErrors() {
      return this.activeBatchTrackedDetails.some(d => {
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return this.batchQtyMismatch(d);
      });
    },

    // First detail that failed batch validation — used for the banner
    firstBatchErrorDetail() {
      return this.activeBatchTrackedDetails.find(d => {
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return this.batchQtyMismatch(d);
      }) || null;
    }
  },

  methods: {

       
    showModal() {
      this.$bvModal.show('open_scan');
      
    },

    onScan (decodedText, decodedResult) {
      const code = decodedText;
      this.search_input = code;
      this.search();
      this.$bvModal.hide('open_scan');
    },

    handleFocus() {
      this.focused = true
    },

    handleBlur() {
      this.focused = false
    },

    //--- Submit Validate Update Purchase
    Submit_Purchase() {
      if (this.SubmitProcessing) {
        return;
      }

      this.SubmitProcessing = true;
      // Block submission when any active batch-tracked line has missing
      // batches or batch quantities that don't sum to the line quantity.
      if (this.hasBatchValidationErrors) {
        this.SubmitProcessing = false;
        const d = this.firstBatchErrorDetail;
        const missing = d && (!Array.isArray(d.batches) || d.batches.length === 0);
        const msg = missing
          ? `${this.$t('Batch_Required_For_Item') || 'Add at least one batch for'}: ${d ? d.name : ''}`
          : this.$t('Batch_Qty_Mismatch');
        this.makeToast("danger", msg, this.$t("Failed"));
        return;
      }
      this.$refs.edit_purchase.validate().then(success => {
        if (!success) {
          this.SubmitProcessing = false;
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Purchase();
        }
      }).catch(() => {
        this.SubmitProcessing = false;
      });
    },
    //---Submit Validation Update Detail
    submit_Update_Detail() {
      this.$refs.Update_Detail_purchase.validate().then(success => {
        if (!success) {
          return;
        } else {
          this.Update_Detail();
        }
      });
    },

    //---Validate State Fields
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------  Show Modal Update Detail Product
    Modal_Updat_Detail(detail) {
      NProgress.start();
      NProgress.set(0.1);
      this.detail = {
        name: detail.name,
        detail_id: detail.detail_id,
        gate_pass_item_id: detail.gate_pass_item_id || null,
        Unit_cost: detail.Unit_cost,
        company_rb_price: detail.company_rb_price,
        mrp_price: detail.mrp_price,
        unitPurchase: detail.unitPurchase,
        purchase_unit_id: detail.purchase_unit_id,
        tax_method: detail.tax_method,
        discount_Method: detail.discount_Method,
        discount: detail.discount,
        quantity: detail.quantity,
        tax_percent: detail.tax_percent,
        is_imei: detail.is_imei,
        imei_number: detail.imei_number
      };

      setTimeout(() => {
        NProgress.done();
        this.$bvModal.show("form_Update_Detail");
      }, 1000);
    },

    //------ Submit Detail Product

    Update_Detail() {
      NProgress.start();
      NProgress.set(0.1);
      this.Submit_Processing_detail = true;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === this.detail.detail_id) {
          this.details[i].tax_percent = this.detail.tax_percent;
          this.details[i].Unit_cost = this.detail.Unit_cost;
          this.details[i].company_rb_price = this.detail.Unit_cost;
          this.details[i].mrp_price = this.detail.mrp_price;
          this.details[i].quantity = this.detail.quantity;
          this.details[i].tax_method = this.detail.tax_method;
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          this.details[i].imei_number = this.detail.imei_number;

          this.recalculatePurchaseDetail(this.details[i]);

          this.$forceUpdate();
        }
      }
      this.Calcul_Total();

       setTimeout(() => {
        NProgress.done();
        this.Submit_Processing_detail = false;
        this.$bvModal.hide("form_Update_Detail");
      }, 1000);
    },

  // Search Products
    search(){

      if (this.timer) {
            clearTimeout(this.timer);
            this.timer = null;
      }

      if (this.search_input.length < 2) {

        return this.product_filter= [];
      }
      if (this.purchase.warehouse_id != "" &&  this.purchase.warehouse_id != null) {
        this.timer = setTimeout(() => {
          const product_filter = this.products.filter(product => product.code === this.search_input || product.barcode.includes(this.search_input));
            if(product_filter.length === 1){
                this.SearchProduct(product_filter[0])
            }else{
                this.product_filter=  this.products.filter(product => {
                  return (
                    product.name.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.code.toLowerCase().includes(this.search_input.toLowerCase()) ||
                    product.barcode.toLowerCase().includes(this.search_input.toLowerCase())
                    );
                });

                // Check if product_filter is empty and show alert
                if (this.product_filter.length <= 0) {
                  this.makeToast(
                    "warning",
                    "Product Not Found",
                    "Warning"
                  );
                }
            }
        }, 800);
      } else {
        this.makeToast(
          "warning",
          this.$t("SelectWarehouse"),
          this.$t("Warning")
        );
      }


    },

    //------  get Result Value Search Products

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    //------  Submit Search Products
    SearchProduct(result) {
      this.product = {};
      const existingDetail = this.details.find(detail => detail.code === result.code);
      if (existingDetail) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
        this.scrollToDetail(existingDetail.detail_id);
      } else {
        this.product.code = result.code;
        this.product.quantity = 1;
        this.product.no_unit = 1;
        this.product.stock = result.qte_purchase;
        this.product.product_variant_id = result.product_variant_id;
        this.Get_Product_Details(result.id, result.product_variant_id);
      }

      this.search_input= '';
      this.$refs.product_autocomplete.value = "";
      this.product_filter = [];
    },

    //---------------------- Event Select Warehouse ------------------------------\\
    Selected_Warehouse(value) {
      this.search_input= '';
      this.product_filter = [];
      this.Get_Products_By_Warehouse(value);
    },

     //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id) {
      // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
      axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 0 + "&product_service=" + 0)
         .then(response => {
            this.products = response.data;
             NProgress.done();

            })
          .catch(error => {
          });
    },

    //----------------------------------------- Add Product -------------------------\\
    add_product() {
      if (this.details.length > 0) {
        this.Last_Detail_id();
      } else if (this.details.length === 0) {
        this.product.detail_id = 1;
      }
      this.details.unshift(this.product);

      const addedDetail = this.details[0];
      this.highlightDetail(addedDetail.detail_id);

      if(this.product.is_imei){
        this.Modal_Updat_Detail(this.product);
      }
    },

    //----------------------------------------- Locate a product row -------------------------\\
    scrollToDetail(detailId) {
      this.highlightDetail(detailId);
      this.$nextTick(() => {
        let row = this.$refs["purchase_detail_" + detailId];
        if (Array.isArray(row)) row = row[0];
        if (!row) return;

        if (typeof row.scrollIntoView === "function") {
          row.scrollIntoView({ behavior: "smooth", block: "center" });
        }
      });
    },

    highlightDetail(detailId) {
      this.highlightedDetailId = detailId;
      if (this.highlightTimer) clearTimeout(this.highlightTimer);
      this.highlightTimer = setTimeout(() => {
        this.highlightedDetailId = null;
        this.highlightTimer = null;
      }, 1800);
    },

    //----------------------------------------- Batch helpers (pharmacy) ------------\\
    add_batch(detail) {
      if (!detail.batches) this.$set(detail, 'batches', []);
      detail.batches.push({
        batch_no: '',
        expiry_date: '',
        mfg_date: '',
        qty: detail.batches.length === 0 ? Number(detail.quantity) || 0 : 0,
        unit_cost: Number(detail.Unit_cost) || 0
      });
    },
    // Locale-proof decimal input: allow digits + one separator, accept both "." and ","
    onBatchNumberInput(batchRow, field, raw) {
      let s = (raw == null ? '' : String(raw)).replace(',', '.');
      s = s.replace(/[^0-9.]/g, '');
      const firstDot = s.indexOf('.');
      if (firstDot !== -1) {
        s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '');
      }
      this.$set(batchRow, field, s);
    },
    remove_batch(detail, idx) {
      if (!detail.batches) return;
      detail.batches.splice(idx, 1);
    },
    batchTotalQty(detail) {
      if (!detail || !Array.isArray(detail.batches)) return 0;
      return detail.batches.reduce((s, b) => s + (Number(b.qty) || 0), 0);
    },
    batchQtyMismatch(detail) {
      if (!detail || !detail.is_batch_tracked) return false;
      if (!Array.isArray(detail.batches) || detail.batches.length === 0) return false;
      return Math.abs(this.batchTotalQty(detail) - (Number(detail.quantity) || 0)) > 0.0001;
    },

    //-----------------------------------Verified QTY ------------------------------\\
    Verified_Qty(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (isNaN(detail.quantity)) {
            this.details[i].quantity = 1;
          }
          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      if (detail.is_gate_pass_item) return;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          this.formatNumber(this.details[i].quantity++, 2);
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------decrement QTY ------------------------------\\

    decrement(detail, id) {
      if (detail.is_gate_pass_item) return;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (detail.quantity - 1 > 0) {
            this.formatNumber(this.details[i].quantity--, 2);
          }
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //---------- keyup OrderTax
    keyup_OrderTax() {
      if (isNaN(this.purchase.tax_rate)) {
        this.purchase.tax_rate = 0;
      } else if(this.purchase.tax_rate == ''){
         this.purchase.tax_rate = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Discount

    keyup_Discount() {
      if (isNaN(this.purchase.discount)) {
        this.purchase.discount = 0;
      } else if(this.purchase.discount == ''){
         this.purchase.discount = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //---------- keyup Shipping

    keyup_Shipping() {
      if (isNaN(this.purchase.shipping)) {
        this.purchase.shipping = 0;
      } else if(this.purchase.shipping == ''){
         this.purchase.shipping = 0;
        this.Calcul_Total();
      }else {
        this.Calcul_Total();
      }
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      number = Number(number) || 0;
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

    roundUpMoney(value) {
      const amount = Number(value) || 0;
      return Math.ceil(Number(amount.toFixed(6)));
    },

    discountMethodChanged() {
      this.detail.discount = 0;
    },

    pricingPreview(detail) {
      const rbPrice = Math.max(Number(detail && detail.Unit_cost) || 0, 0);
      const mrpWithoutTax = Math.max(Number(detail && detail.mrp_price) || 0, 0);
      const discountValue = Math.max(Number(detail && detail.discount) || 0, 0);
      const gstRate = Math.max(Number(this.purchase.tax_rate) || 0, 0);
      const withholdingRate = Math.max(Number(this.withholding_tax_rate) || 0, 0);
      let tradeDiscount = detail && detail.discount_Method == "1"
        ? (rbPrice * discountValue) / 100
        : discountValue;
      tradeDiscount = Math.min(tradeDiscount, rbPrice);
      const netValue = this.roundUpMoney(rbPrice - tradeDiscount);
      const gstAmount = this.roundUpMoney((rbPrice * gstRate) / 100);
      const totalWithTax = this.roundUpMoney(netValue + gstAmount);
      const mrpWithTax = mrpWithoutTax > 0
        ? this.roundUpMoney(mrpWithoutTax + (mrpWithoutTax * gstRate) / 100)
        : 0;
      const withholdingAmount = this.roundUpMoney((netValue * withholdingRate) / 100);

      return {
        tradeDiscount,
        netValue,
        gstAmount,
        totalWithTax,
        mrpWithTax,
        withholdingAmount,
        netPayable: this.roundUpMoney(totalWithTax + withholdingAmount)
      };
    },

    recalculatePurchaseDetail(detail) {
      const rbPrice = Math.max(Number(detail.company_rb_price !== undefined
        ? detail.company_rb_price
        : detail.Unit_cost) || 0, 0);
      const discountValue = Math.max(Number(detail.discount) || 0, 0);
      const taxRate = Math.max(Number(this.purchase.tax_rate) || 0, 0);
      let tradeDiscount = detail.discount_Method == "1"
        ? (rbPrice * discountValue) / 100
        : discountValue;
      tradeDiscount = Math.min(tradeDiscount, rbPrice);

      detail.company_rb_price = rbPrice;
      detail.Unit_cost = rbPrice;
      detail.DiscountNet = tradeDiscount;
      detail.Net_cost = this.roundUpMoney(rbPrice - tradeDiscount);
      detail.tax_percent = taxRate;
      detail.tax_method = "1";
      detail.taxe = this.roundUpMoney((rbPrice * taxRate) / 100);
      detail.withholding_tax = this.roundUpMoney(detail.Net_cost * (this.withholding_tax_rate / 100));
      detail.Total_cost = this.roundUpMoney(detail.Net_cost + detail.taxe);
      detail.subtotal = (Number(detail.quantity) || 0) * detail.Total_cost;
    },

    //-----------------------------------------Calcul Total ------------------------------\\
    Calcul_Total() {
      this.total = 0;
      let salesTaxTotal = 0;
      let withholdingTaxTotal = 0;
      for (var i = 0; i < this.details.length; i++) {
        this.recalculatePurchaseDetail(this.details[i]);
        var tax = (Number(this.details[i].taxe) || 0) * this.details[i].quantity;
        var wht = (Number(this.details[i].withholding_tax) || 0) * this.details[i].quantity;
        this.details[i].subtotal = parseFloat(
          this.details[i].quantity * this.details[i].Net_cost + tax
        );
        salesTaxTotal += tax;
        withholdingTaxTotal += wht;
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_without_discount = parseFloat(
        this.total - this.purchase.discount
      );
      this.purchase.TaxNet = this.roundUpMoney(salesTaxTotal);
      this.purchase.withholding_tax = this.roundUpMoney(withholdingTaxTotal);
      this.GrandTotal = this.roundUpMoney(
        total_without_discount + withholdingTaxTotal + (Number(this.purchase.shipping) || 0)
      );
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          this.Calcul_Total();
        }
      }
    },

    //-----------------------------------Verified Detail Qty If Null ------------------------------\\

    verifiedForm() {
      if (this.details.length <= 0) {
        this.makeToast(
          "warning",
          this.$t("AddProductToList"),
          this.$t("Warning")
        );
        return false;
      } else {
        var count = 0;
        for (var i = 0; i < this.details.length; i++) {
          if (
            this.details[i].quantity == "" ||
            this.details[i].quantity === 0
          ) {
            count += 1;
          }
        }

        if (count > 0) {
          this.makeToast("warning", this.$t("AddQuantity"), this.$t("Warning"));
          return false;
        } else {
          return true;
        }
      }
    },

    //--------------------------------- Update Purchase -------------------------\\
    Update_Purchase() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        let id = this.$route.params.id;
        axios
          .put(`purchases/${id}`, {
            date: this.purchase.date,
            supplier_id: this.purchase.supplier_id,
            warehouse_id: this.purchase.warehouse_id,
            sales_tax_invoice_no: this.purchase.sales_tax_invoice_no || null,
            delivery_note_no: this.purchase.delivery_note_no || null,
            statut: this.purchase.statut,
            notes: this.purchase.notes,
            tax_rate: this.purchase.tax_rate?this.purchase.tax_rate:0,
            TaxNet: this.purchase.TaxNet?this.purchase.TaxNet:0,
            withholding_tax: this.purchase.withholding_tax?this.purchase.withholding_tax:0,
            discount: this.purchase.discount?this.purchase.discount:0,
            shipping: this.purchase.shipping?this.purchase.shipping:0,
            GrandTotal: this.GrandTotal,
            details: this.details
          })
          .then(response => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast(
              "success",
              this.$t("Successfully_Updated"),
              this.$t("Success")
            );

            this.SubmitProcessing = false;
            this.$router.push({ name: "index_purchases" });
          })
          .catch(error => {
            // Complete the animation of theprogress bar.
            NProgress.done();
            this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            this.SubmitProcessing = false;
          });
      } else {
        this.SubmitProcessing = false;
      }
    },

    //-------------------------------- Get Last Detail Id -------------------------\\
    Last_Detail_id() {
      const highestDetailId = this.details.reduce((highest, detail) => {
        return Math.max(highest, Number(detail.detail_id) || 0);
      }, 0);
      this.product.detail_id = highestDetailId + 1;
    },

    //---------------------------------get Product Details ------------------------\\

    Get_Product_Details(product_id, variant_id) {
      const wid = this.purchase && this.purchase.warehouse_id ? this.purchase.warehouse_id : null;
      const url = wid
        ? `/show_product_data/${product_id}/${variant_id}/${wid}`
        : `/show_product_data/${product_id}/${variant_id}`;

      axios.get(url).then(response => {
        this.product.del = 0;
        this.product.id = 0;
        this.product.discount           = response.data.discount;
        this.product.DiscountNet        = response.data.DiscountNet;
        this.product.discount_Method    = response.data.discount_method;
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.Net_cost = response.data.Net_cost;
        this.product.Unit_cost = response.data.Unit_cost;
        this.product.company_rb_price = Number(response.data.company_rb_price || response.data.Unit_cost) || 0;
        this.product.mrp_price = Number(response.data.mrp_price) || 0;
        this.product.taxe = response.data.tax_cost;
        this.product.tax_method = response.data.tax_method;
        this.product.tax_percent = response.data.tax_percent;
        this.product.unitPurchase = response.data.unitPurchase;
        this.product.purchase_unit_id = response.data.purchase_unit_id;
        this.product.is_imei = response.data.is_imei;
        this.product.imei_number = '';
        this.product.is_batch_tracked = !!response.data.is_batch_tracked;
        this.$set(this.product, 'batches', []);
        this.product.warehouse_location = response.data.warehouse_location
          ? (response.data.warehouse_location.name
              ? `${response.data.warehouse_location.code} - ${response.data.warehouse_location.name}`
              : response.data.warehouse_location.code)
          : null;
        this.add_product();
        this.Calcul_Total();
      });
    },

    //---------------------------------------Get Elements Purchase ------------------------------\\
    GetElements() {
      let id = this.$route.params.id;
      axios
        .get(`purchases/${id}/edit`)
        .then(response => {
          this.purchase = response.data.purchase;
          this.details = response.data.details;
          this.suppliers = response.data.suppliers;
          this.warehouses = response.data.warehouses;
          this.linked_gate_passes = response.data.gate_passes || [];
          this.managed_taxes = response.data.managed_taxes || [];
          const wht = this.managed_taxes.find(t => t.code === 'WHT' && t.calculation_type === 'percentage');
          this.withholding_tax_rate = wht ? (parseFloat(wht.rate) || 0) : 0;
          this.Get_Products_By_Warehouse(this.purchase.warehouse_id);
          this.Calcul_Total();
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //----------------------------- Created function-------------------
  created() {
    this.GetElements();
  },

  beforeDestroy() {
    if (this.timer) clearTimeout(this.timer);
    if (this.highlightTimer) clearTimeout(this.highlightTimer);
  }
};
</script>

<style>

  .purchase-detail-dialog {
    width: calc(100% - 32px);
    max-width: 1200px !important;
  }

  .input-with-icon {
    display: flex;
    align-items: center;
  }

  .scan-icon {
    width: 50px; /* Adjust size as needed */
    height: 50px;
    margin-right: 8px; /* Adjust spacing as needed */
    cursor: pointer;
  }

  .purchase-row-highlight > td {
    animation: purchase-row-pulse 1.8s ease-out;
  }

  .purchase-products-table {
    min-width: 1760px;
  }

  .purchase-products-table .purchase-quantity-column {
    width: 126px;
    min-width: 126px;
  }

  .purchase-quantity-control {
    display: grid;
    grid-template-columns: 32px minmax(54px, 1fr) 32px;
    width: 118px;
    overflow: hidden;
    border-radius: 5px;
  }

  .purchase-quantity-control .purchase-quantity-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 36px !important;
    min-width: 32px;
    padding: 0;
    border-radius: 0;
    font-size: 18px;
    line-height: 1;
  }

  .purchase-quantity-control .purchase-quantity-input {
    width: 100%;
    min-width: 0;
    height: 36px;
    padding: 4px 6px;
    border-radius: 0;
    text-align: center;
  }

  .purchase-quantity-control .purchase-quantity-input::-webkit-outer-spin-button,
  .purchase-quantity-control .purchase-quantity-input::-webkit-inner-spin-button {
    margin: 0;
    appearance: none;
  }

  .purchase-quantity-control .purchase-quantity-input[type='number'] {
    -moz-appearance: textfield;
  }

  .discount-percentage-input {
    max-width: 210px;
  }

  @keyframes purchase-row-pulse {
    0%, 35% {
      background-color: rgba(102, 84, 241, 0.22);
      box-shadow: inset 0 2px 0 rgba(102, 84, 241, 0.55), inset 0 -2px 0 rgba(102, 84, 241, 0.55);
    }
    100% {
      background-color: transparent;
      box-shadow: none;
    }
  }
</style>
