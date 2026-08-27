<template>
  <div class="main-content">
    <breadcumb :page="$t('AddPurchase')" :folder="$t('ListPurchases')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <validation-observer ref="create_purchase" v-if="!isLoading">
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

                <!-- Quick Add Supplier Modal -->
                <validation-observer ref="Quick_Add_Supplier_Form">
                  <b-modal hide-footer size="lg" id="Quick_Add_Supplier" :title="$t('Quick_Add_Supplier')">
                    <b-form @submit.prevent="Submit_Quick_Add_Supplier" class="quick-add-supplier-form">
                      <b-row>
                        <!-- Supplier Name -->
                        <b-col md="6" sm="12">
                          <validation-provider
                            name="Name Supplier"
                            :rules="{ required: true}"
                            v-slot="validationContext"
                          >
                            <b-form-group :label="$t('SupplierName') + ' ' + '*'">
                              <b-form-input
                                :state="getValidationState(validationContext)"
                                aria-describedby="supplier-name-feedback"
                                label="name"
                                :placeholder="$t('SupplierName')"
                                v-model="supplier.name"
                              ></b-form-input>
                              <b-form-invalid-feedback id="supplier-name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                            </b-form-group>
                          </validation-provider>
                        </b-col>

                        <!-- Supplier Email -->
                        <b-col md="6" sm="12">
                          <b-form-group :label="$t('Email')">
                            <b-form-input
                              label="email"
                              v-model="supplier.email"
                              :placeholder="$t('Email')"
                            ></b-form-input>
                          </b-form-group>
                        </b-col>

                        <!-- Supplier Phone -->
                        <b-col md="6" sm="12">
                          <b-form-group :label="$t('Phone')">
                            <b-form-input
                              label="Phone"
                              v-model="supplier.phone"
                              :placeholder="$t('Phone')"
                            ></b-form-input>
                          </b-form-group>
                        </b-col>

                        <!-- Supplier Country -->
                        <b-col md="6" sm="12">
                          <b-form-group :label="$t('Country')">
                            <b-form-input
                              label="Country"
                              v-model="supplier.country"
                              :placeholder="$t('Country')"
                            ></b-form-input>
                          </b-form-group>
                        </b-col>

                        <!-- Supplier City -->
                        <b-col md="6" sm="12">
                          <b-form-group :label="$t('City')">
                            <b-form-input
                              label="City"
                              v-model="supplier.city"
                              :placeholder="$t('City')"
                            ></b-form-input>
                          </b-form-group>
                        </b-col>

                        <!-- Supplier Tax Number -->
                        <b-col md="6" sm="12">
                          <b-form-group :label="$t('Tax_Number')">
                            <b-form-input
                              label="Tax Number"
                              v-model="supplier.tax_number"
                              :placeholder="$t('Tax_Number')"
                            ></b-form-input>
                          </b-form-group>
                        </b-col>

                        <!-- Supplier Address -->
                        <b-col md="12" sm="12">
                          <b-form-group :label="$t('Adress')">
                            <textarea
                              label="Adress"
                              class="form-control"
                              rows="4"
                              v-model="supplier.adresse"
                              :placeholder="$t('Adress')"
                            ></textarea>
                          </b-form-group>
                        </b-col>

                        <b-col md="12" class="mt-3">
                          <b-button variant="secondary" class="mr-2" @click="$bvModal.hide('Quick_Add_Supplier')">{{ $t('Cancel') }}</b-button>
                          <b-button variant="primary" type="submit" :disabled="SubmitProcessing">{{$t('submit')}}</b-button>
                          <div v-once class="typo__p" v-if="SubmitProcessing">
                            <div class="spinner sm spinner-primary mt-3"></div>
                          </div>
                        </b-col>
                      </b-row>
                    </b-form>
                  </b-modal>
                </validation-observer>

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
                        id="date-feedback"
                      >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                    </b-form-group>
                  </validation-provider>
                </b-col>
                <!-- Supplier -->
                <b-col lg="4" md="4" sm="12" class="mb-3">
                  <validation-provider name="Supplier" :rules="{ required: true}">
                    <b-form-group slot-scope="{ valid, errors }" :label="$t('Supplier') + ' ' + '*'">
                      <b-input-group class="category-input-group">
                        <v-select
                          :class="{'is-invalid': !!errors.length}"
                          :state="errors[0] ? false : (valid ? true : null)"
                          :disabled="selectedGatePasses.length > 0"
                          v-model="purchase.supplier_id"
                          :reduce="label => label.value"
                          :placeholder="$t('Choose_Supplier')"
                          :options="suppliers.map(suppliers => ({label: suppliers.name, value: suppliers.id}))"
                        />
                        <b-input-group-append
                          v-if="currentUserPermissions && currentUserPermissions.includes('Suppliers_add')"
                        >
                          <b-button
                            variant="primary"
                            @click="Quick_Add_Supplier"
                            :title="$t('Quick_Add_Supplier')"
                            class="category-add-btn"
                          >
                            <lucide-icon name="plus" />
                          </b-button>
                        </b-input-group-append>
                      </b-input-group>
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

                <!-- Supplier invoice references -->
                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Sales Tax Invoice No.">
                    <b-form-input
                      v-model.trim="purchase.sales_tax_invoice_no"
                      maxlength="100"
                      placeholder="Enter sales tax invoice number"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col lg="6" md="6" sm="12" class="mb-3">
                  <b-form-group label="Delivery Note No.">
                    <b-form-input
                      v-model.trim="purchase.delivery_note_no"
                      maxlength="100"
                      placeholder="Enter delivery note number"
                    ></b-form-input>
                  </b-form-group>
                </b-col>

                <b-col lg="12" md="12" sm="12" class="mb-3">
                  <b-form-group label="Gate Pass">
                    <b-input-group>
                      <b-form-input
                        v-model.trim="gate_pass_search"
                        :disabled="gatePassLoading"
                        placeholder="Enter internal or supplier Gate Pass number"
                        @keyup.enter.prevent="addGatePass"
                      ></b-form-input>
                      <b-input-group-append>
                        <b-button variant="primary" :disabled="gatePassLoading || !gate_pass_search" @click="addGatePass">
                          <span v-if="gatePassLoading" class="spinner sm spinner-white mr-1"></span>
                          Add Gate Pass
                        </b-button>
                      </b-input-group-append>
                    </b-input-group>
                    <small class="text-muted">You can add multiple Gate Passes from the same supplier and warehouse.</small>
                    <div v-if="selectedGatePasses.length" class="d-flex flex-wrap mt-2">
                      <span v-for="gatePass in selectedGatePasses" :key="gatePass.id" class="badge badge-light-primary mr-2 mb-2 p-2">
                        {{ gatePass.number }}<span v-if="gatePass.supplier_gate_pass_number"> ({{ gatePass.supplier_gate_pass_number }})</span>
                        <button type="button" class="gate-pass-remove ml-2" title="Remove Gate Pass" @click="removeGatePass(gatePass.id)">&times;</button>
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
                          :class="{'purchase-row-highlight': highlightedDetailId === detail.detail_id}"
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
                            <div v-if="isGatePassDetail(detail)" class="text-primary mt-1" style="font-size: 12px;">
                              Gate Pass: <strong>{{ gatePassLabels(detail) }}</strong>
                            </div>
                          </td>
                          <td class="purchase-quantity-column">
                            <div class="purchase-quantity-control">
                              <button
                                type="button"
                                class="btn btn-primary purchase-quantity-button"
                                aria-label="Decrease quantity"
                                @click="decrement(detail, detail.detail_id)"
                              >−</button>
                              <input
                                type="number"
                                class="form-control purchase-quantity-input"
                                @input="Verified_Qty(detail, detail.detail_id)"
                                :min="1"
                                :max="detail.gate_pass_max_quantity || null"
                                :step="isGatePassDetail(detail) ? 1 : 'any'"
                                v-model.number="detail.quantity"
                              >
                              <button
                                type="button"
                                class="btn btn-primary purchase-quantity-button"
                                aria-label="Increase quantity"
                                @click="increment(detail, detail.detail_id)"
                              >+</button>
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
                          <td class="text-center">
                            <lucide-icon class="text-25 text-success" name="pencil" v-if="currentUserPermissions && currentUserPermissions.includes('edit_product_purchase')" @click="Modal_Updat_Detail(detail)" />
                            <lucide-icon class="text-25 text-danger" name="x" @click="delete_Product_Detail(detail.detail_id)" />
                          </td>
                        </tr>
                        <tr v-if="detail.is_batch_tracked" :key="'batches-'+detail.detail_id" :style="{ background: 'transparent' }">
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
            <!-- RB Price -->
            <b-col lg="6" md="6" sm="12">
              <validation-provider
                name="RB Price"
                :rules="{ required: true , regex: /^\d*\.?\d*$/}"
                v-slot="validationContext"
              >
                <b-form-group label="RB Price *" id="cost-input">
                  <b-form-input
                    label="RB Price"
                    v-model.number="detail.Unit_cost"
                    :state="getValidationState(validationContext)"
                    aria-describedby="cost-feedback"
                  ></b-form-input>
                  <b-form-invalid-feedback id="cost-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Discount Method -->
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
                    :options="[
                      {label: 'Fixed', value: '2'},
                      {label: 'Percentage', value: '1'}
                    ]"
                  ></v-select>
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Percentage rate is only needed for percentage discounts -->
            <b-col v-if="detail.discount_Method == '1'" lg="6" md="6" sm="12">
              <validation-provider name="Discount Percentage" :rules="{ required: true, min_value: 0, max_value: 100 }" v-slot="validationContext">
                <b-form-group label="Discount Percentage *">
                  <b-input-group append="%" class="discount-percentage-input">
                    <b-form-input
                      type="number"
                      min="0"
                      max="100"
                      step="0.01"
                      v-model.number="detail.discount"
                      :state="getValidationState(validationContext)"
                    />
                  </b-input-group>
                  <b-form-invalid-feedback :state="getValidationState(validationContext)">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Trade Discount -->
            <b-col lg="6" md="6" sm="12">
              <validation-provider v-if="detail.discount_Method == '2'" name="Trade Discount" :rules="{ required: true, min_value: 0 }" v-slot="validationContext">
                <b-form-group label="Trade Discount *">
                  <b-input-group :prepend="currentUser.currency">
                    <b-form-input
                      type="number"
                      min="0"
                      step="0.01"
                      v-model.number="detail.discount"
                      :state="getValidationState(validationContext)"
                    />
                  </b-input-group>
                  <b-form-invalid-feedback :state="getValidationState(validationContext)">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
              <b-form-group v-else label="Trade Discount">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).tradeDiscount, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Net Value Without Tax -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Net Value Without Tax">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).netValue, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Managed GST Amount -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group :label="'GST (' + formatNumber(purchase.tax_rate, 2) + '%)'">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).gstAmount, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Total Value With Tax -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Total Value With Tax">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).totalWithTax, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Optional MRP Without Tax -->
            <b-col lg="6" md="6" sm="12">
              <validation-provider name="MRP Without Tax" :rules="{ regex: /^\d*\.?\d*$/}" v-slot="validationContext">
                <b-form-group label="MRP Without Tax">
                  <b-form-input
                    label="MRP Without Tax"
                    v-model.number="detail.mrp_price"
                    :state="getValidationState(validationContext)"
                  ></b-form-input>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Read-only MRP With Tax -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="MRP With Tax">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).mrpWithTax, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Managed Withholding Tax Rate -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="I.Tax Withholding Rate">
                <b-input-group append="%">
                  <b-form-input :value="formatNumber(withholding_tax_rate, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Withholding calculated on net value after trade discount -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="I.Tax Withholding Amount">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).withholdingAmount, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Net payable includes withholding tax -->
            <b-col lg="6" md="6" sm="12">
              <b-form-group label="Net Payable Including Withholding">
                <b-input-group :prepend="currentUser.currency">
                  <b-form-input :value="formatNumber(pricingPreview(detail).netPayable, 2)" readonly />
                </b-input-group>
              </b-form-group>
            </b-col>

            <!-- Unit Purchase (last) -->
            <b-col lg="6" md="6" sm="12">
              <validation-provider name="Unit Purchase" :rules="{ required: true}">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('UnitPurchase') + ' ' + '*'">
                  <v-select
                    :class="{'is-invalid': !!errors.length}"
                    :state="errors[0] ? false : (valid ? true : null)"
                    v-model="detail.purchase_unit_id"
                    :disabled="!!detail.gate_pass_item_id"
                    :placeholder="$t('Choose_Unit_Purchase')"
                    :reduce="label => label.value"
                    :options="units.map(units => ({label: units.name, value: units.id}))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Imei or serial numbers -->
              <b-col lg="12" md="12" sm="12" v-show="detail.is_imei">
                <b-form-group :label="$t('Add_product_IMEI_Serial_number')">
                  <b-form-input
                    label="Add_product_IMEI_Serial_number"
                    v-model="detail.imei_number"
                    :placeholder="$t('Add_product_IMEI_Serial_number')"
                  ></b-form-input>
                </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group>
                <b-button variant="primary" type="submit" :disabled="Submit_Processing_detail">{{$t('submit')}}</b-button>
                <div v-once class="typo__p" v-if="Submit_Processing_detail">
                  <div class="spinner sm spinner-primary mt-3"></div>
                </div>
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
    title: "Create Purchase"
  },
  data() {
    return {
      focused: false,
      timer:null,
      highlightTimer: null,
      highlightedDetailId: null,
      search_input:'',
      product_filter:[],
      gate_pass_search: '',
      gatePassLoading: false,
      selectedGatePasses: [],
      productsLoading: false,
      productsWarehouseId: null,

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

      isLoading: true,
      SubmitProcessing:false,
      Submit_Processing_detail:false,
      warehouses: [],
      suppliers: [],
      withholding_tax_rate: 0,
      managed_taxes: [],
      supplier: {
        id: "",
        name: "",
        email: "",
        phone: "",
        country: "",
        city: "",
        tax_number: "",
        adresse: ""
      },
      products: [],
      details: [],
      units: [],
      detail: {
        quantity: "",
        discount: "",
        Unit_cost: "",
        company_rb_price: "",
        mrp_price: "",
        discount_Method: "",
        tax_percent: "",
        tax_method: "",
        imei_number:"",
      },
      purchases: [],
      purchase: {
        id: "",
        date: new Date().toISOString().slice(0, 10),
        statut: "received",
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
        unitPurchase: "",
        purchase_unit_id:"",
        fix_stock:"",
        fix_cost:"",
        Net_cost: "",
        Unit_cost: "",
        company_rb_price: "",
        mrp_price: "",
        Total_cost: "",
        subtotal: "",
        product_id: "",
        detail_id: "",
        taxe: "",
        withholding_tax: 0,
        tax_percent: "",
        tax_method: "",
        product_variant_id: "",
        is_imei: "",
        imei_number:"",
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions","currentUser"]),

    // True if any batch-tracked line has missing batches or a qty mismatch
    hasBatchValidationErrors() {
      if (!Array.isArray(this.details)) return false;
      return this.details.some(d => {
        if (!d || !d.is_batch_tracked) return false;
        if (!Array.isArray(d.batches) || d.batches.length === 0) return true;
        return this.batchQtyMismatch(d);
      });
    },

    // First detail that failed batch validation — used for the top-level banner
    firstBatchErrorDetail() {
      if (!Array.isArray(this.details)) return null;
      return this.details.find(d => {
        if (!d || !d.is_batch_tracked) return false;
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

    //--- Submit Validate Create Purchase
    Submit_Purchase() {
      if (this.SubmitProcessing) {
        return;
      }

      this.SubmitProcessing = true;
      // Block submission when any batch-tracked line has missing batches
      // or batch quantities that don't sum to the line quantity.
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
      this.$refs.create_purchase.validate().then(success => {
        if (!success) {
          this.SubmitProcessing = false;
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Create_Purchase();
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

    gatePassErrorMessage(error, fallback) {
      const data = error && error.response && error.response.data;
      if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
      }
      return (data && data.message) || (error && error.message) || fallback;
    },

    addGatePass() {
      const number = String(this.gate_pass_search || '').trim();
      if (!number || this.gatePassLoading) return Promise.resolve();
      this.gatePassLoading = true;
      return axios.get('purchase-gate-passes/lookup', { params: { number } })
        .then(response => this.appendGatePass(response.data.gate_pass))
        .then(() => { this.gate_pass_search = ''; })
        .catch(error => this.makeToast('danger', this.gatePassErrorMessage(error, 'Could not add the Gate Pass.'), this.$t('Failed')))
        .finally(() => { this.gatePassLoading = false; });
    },

    appendGatePass(gatePass) {
      if (this.selectedGatePasses.some(selected => Number(selected.id) === Number(gatePass.id))) {
        return Promise.reject(new Error('This Gate Pass is already added.'));
      }
      if (this.purchase.supplier_id && Number(this.purchase.supplier_id) !== Number(gatePass.provider_id)) {
        return Promise.reject(new Error('All Gate Passes must belong to the selected supplier.'));
      }
      if (this.purchase.warehouse_id && Number(this.purchase.warehouse_id) !== Number(gatePass.warehouse_id)) {
        return Promise.reject(new Error('All Gate Passes must belong to the selected warehouse.'));
      }

      const lines = gatePass.items.map(item => ({ item, product: item.product_data || {} }));
      return Promise.resolve().then(() => {
        this.purchase.supplier_id = gatePass.provider_id;
        this.purchase.warehouse_id = gatePass.warehouse_id;
        let nextDetailId = this.details.reduce((highest, detail) => Math.max(highest, Number(detail.detail_id) || 0), 0) + 1;

        lines.forEach(({ item, product }) => {
          const source = {
            gate_pass_id: gatePass.id,
            gate_pass_item_id: item.gate_pass_item_id,
            gate_pass_number: gatePass.number,
            quantity: Number(item.quantity) || 0
          };
          const detail = this.gatePassPurchaseDetail(item, product, source, nextDetailId++);
          this.details.unshift(detail);
        });

        this.selectedGatePasses.push({
          id: gatePass.id,
          number: gatePass.number,
          supplier_gate_pass_number: gatePass.supplier_gate_pass_number,
          provider_id: gatePass.provider_id,
          warehouse_id: gatePass.warehouse_id
        });
        this.Calcul_Total();
      });
    },

    gatePassPurchaseDetail(item, product, source, detailId) {
      const detail = {
        detail_id: detailId,
        gate_pass_item_id: Number(item.gate_pass_item_id),
        gate_pass_max_quantity: source.quantity,
        product_id: Number(item.product_id),
        product_variant_id: item.product_variant_id ? Number(item.product_variant_id) : null,
        purchase_unit_id: Number(item.unit_id || product.purchase_unit_id),
        code: item.sku || product.code,
        name: product.name || [item.model, item.product].filter(Boolean).join(' '),
        quantity: source.quantity,
        stock: product.qte,
        fix_stock: product.qte,
        discount: Number(product.discount) || 0,
        DiscountNet: Number(product.DiscountNet) || 0,
        discount_Method: product.discount_method,
        unitPurchase: product.unitPurchase,
        fix_cost: product.fix_cost,
        Unit_cost: Number(product.company_rb_price) || 0,
        company_rb_price: Number(product.company_rb_price) || 0,
        mrp_price: Number(product.mrp_price) || 0,
        Net_cost: 0,
        Total_cost: 0,
        subtotal: 0,
        taxe: 0,
        withholding_tax: 0,
        tax_percent: Number(this.purchase.tax_rate) || 0,
        tax_method: '1',
        is_imei: product.is_imei,
        imei_number: '',
        is_batch_tracked: false,
        batches: [],
        warehouse_location: product.warehouse_location
          ? (product.warehouse_location.name
              ? `${product.warehouse_location.code} - ${product.warehouse_location.name}`
              : product.warehouse_location.code)
          : null,
        gate_pass_items: [source]
      };
      this.recalculatePurchaseDetail(detail);
      return detail;
    },

    isGatePassDetail(detail) {
      return Array.isArray(detail.gate_pass_items) && detail.gate_pass_items.length > 0;
    },

    gatePassLabels(detail) {
      return [...new Set((detail.gate_pass_items || []).map(source => source.gate_pass_number))].join(', ');
    },

    gatePassIdsFromDetails() {
      const ids = this.details.reduce((all, detail) => all.concat((detail.gate_pass_items || []).map(source => Number(source.gate_pass_id))), []);
      return [...new Set(ids)];
    },

    removeGatePass(gatePassId) {
      this.details = this.details.filter(detail => {
        if (!this.isGatePassDetail(detail)) return true;
        const removed = detail.gate_pass_items
          .filter(source => Number(source.gate_pass_id) === Number(gatePassId))
          .reduce((total, source) => total + (Number(source.quantity) || 0), 0);
        if (!removed) return true;
        detail.gate_pass_items = detail.gate_pass_items.filter(source => Number(source.gate_pass_id) !== Number(gatePassId));
        detail.quantity = Math.max(0, (Number(detail.quantity) || 0) - removed);
        if (!detail.gate_pass_items.length || detail.quantity <= 0) return false;
        this.recalculatePurchaseDetail(detail);
        return true;
      });
      this.selectedGatePasses = this.selectedGatePasses.filter(gatePass => Number(gatePass.id) !== Number(gatePassId));
      this.Calcul_Total();
    },

    //---------------------- get_units ------------------------------\\
    get_units(value) {
      axios
        .get("get_units?id=" + value)
        .then(({ data }) => (this.units = data));
    },

    //------ Show Modal Update Detail Product
    Modal_Updat_Detail(detail) {
      NProgress.start();
      NProgress.set(0.1);
      this.get_units(detail.product_id);
      this.detail = {
        name: detail.name,
        detail_id: detail.detail_id,
        gate_pass_item_id: detail.gate_pass_item_id || null,
        purchase_unit_id: detail.purchase_unit_id,
        Unit_cost: detail.Unit_cost,
        company_rb_price: detail.company_rb_price,
        mrp_price: detail.mrp_price,
        tax_method: detail.tax_method,
        fix_cost: detail.fix_cost,
        fix_stock: detail.fix_stock,
        stock: detail.stock,
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

     //------ Submit Update Detail Product

    Update_Detail() {
      NProgress.start();
      NProgress.set(0.1);
      this.Submit_Processing_detail = true;
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id === this.detail.detail_id) {

          // this.convert_unit();
           for(var k=0; k<this.units.length; k++){
              if (this.units[k].id == this.detail.purchase_unit_id) {
                if(this.units[k].operator == '/'){
                  this.details[i].stock       = this.detail.fix_stock  * this.units[k].operator_value;
                  this.details[i].unitPurchase    = this.units[k].ShortName;

                }else{
                  this.details[i].stock       = this.detail.fix_stock  / this.units[k].operator_value;
                  this.details[i].unitPurchase    = this.units[k].ShortName;
                }
              }
            }
                      
          this.details[i].Unit_cost = Number(this.detail.Unit_cost) || 0;
          this.details[i].company_rb_price = this.details[i].Unit_cost;
          this.details[i].mrp_price = Number(this.detail.mrp_price) || 0;
          this.details[i].tax_percent = Number(this.purchase.tax_rate) || 0;
          this.details[i].tax_method = "1";
          this.details[i].discount_Method = this.detail.discount_Method;
          this.details[i].discount = this.detail.discount;
          this.details[i].purchase_unit_id = this.detail.purchase_unit_id;
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



    handleFocus() {
      this.focused = true;
      if (this.purchase.warehouse_id && Number(this.productsWarehouseId) !== Number(this.purchase.warehouse_id)) {
        this.Get_Products_By_Warehouse(this.purchase.warehouse_id, false);
      }
    },

    handleBlur() {
      this.focused = false
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
   

    // get Result Value Search Products

    getResultValue(result) {
      return result.code + " " + "(" + result.name + ")";
    },

    // Submit Search Products

    SearchProduct(result) {
      this.product = {};
      const existingDetail = this.details.find(detail => detail.code === result.code);
      if (existingDetail) {
        this.makeToast("warning", this.$t("AlreadyAdd"), this.$t("Warning"));
        this.scrollToDetail(existingDetail.detail_id);
      } else {
        this.product.code = result.code;
        this.product.quantity = 1;
        this.product.stock = result.qte_purchase;
        this.product.fix_stock = result.qte;
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
      this.products = [];
      this.productsWarehouseId = null;
      if (this.focused && value) this.Get_Products_By_Warehouse(value, false);
    },

    //------------------------------------ Get Products By Warehouse -------------------------\\

    Get_Products_By_Warehouse(id, showProgress = true) {
      if (!id) return Promise.resolve();
      if (Number(this.productsWarehouseId) === Number(id) && this.products.length) return Promise.resolve(this.products);
      if (this.productsLoading) return Promise.resolve();
      this.productsLoading = true;
      if (showProgress) {
        NProgress.start();
        NProgress.set(0.1);
      }
      return axios
        .get("get_Products_by_warehouse/" + id + "?stock=" + 0 + "&product_service=" + 0)
         .then(response => {
            this.products = response.data;
            this.productsWarehouseId = id;
            if (this.search_input.length >= 2) this.search();
          })
          .catch(() => {})
          .finally(() => {
            this.productsLoading = false;
            if (showProgress) NProgress.done();
          });
    },

    //----------------------------------------- Add product -------------------------\\
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
      // strip anything that isn't a digit or dot
      s = s.replace(/[^0-9.]/g, '');
      // keep only the first dot
      const firstDot = s.indexOf('.');
      if (firstDot !== -1) {
        s = s.slice(0, firstDot + 1) + s.slice(firstDot + 1).replace(/\./g, '');
      }
      // store the string so mid-typing "0." isn't lost; total helpers already coerce via Number()
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
          if (this.isGatePassDetail(detail) && Number(detail.quantity) > Number(detail.gate_pass_max_quantity)) {
            this.details[i].quantity = Number(detail.gate_pass_max_quantity);
            this.makeToast('warning', `Quantity cannot exceed the remaining Gate Pass quantity of ${detail.gate_pass_max_quantity}.`, this.$t('Warning'));
          }
          this.Calcul_Total();
          this.$forceUpdate();
        }
      }
    },

    //-----------------------------------increment QTY ------------------------------\\

    increment(detail, id) {
      for (var i = 0; i < this.details.length; i++) {
        if (this.details[i].detail_id == id) {
          if (this.isGatePassDetail(detail) && Number(this.details[i].quantity) + 1 > Number(detail.gate_pass_max_quantity)) {
            this.makeToast('warning', `Quantity cannot exceed the remaining Gate Pass quantity of ${detail.gate_pass_max_quantity}.`, this.$t('Warning'));
            return;
          }
          this.formatNumber(this.details[i].quantity++, 2);
        }
      }
      this.$forceUpdate();
      this.Calcul_Total();
    },

    //-----------------------------------decrement QTY ------------------------------\\

    decrement(detail, id) {
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

    // Purchase currency amounts are charged in whole rupees. Keep the values
    // numeric for calculations; formatNumber() adds the required .00 display.
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
      const gstBase = rbPrice;
      const gstAmount = this.roundUpMoney((gstBase * gstRate) / 100);
      const totalWithTax = this.roundUpMoney(netValue + gstAmount);
      const mrpWithTax = mrpWithoutTax > 0
        ? this.roundUpMoney(mrpWithoutTax + (mrpWithoutTax * gstRate) / 100)
        : 0;
      const withholdingAmount = this.roundUpMoney((netValue * withholdingRate) / 100);

      return {
        rbPrice,
        tradeDiscount,
        netValue,
        gstAmount,
        totalWithTax,
        mrpWithoutTax,
        mrpWithTax,
        withholdingAmount,
        netPayable: this.roundUpMoney(totalWithTax + withholdingAmount)
      };
    },

    // Purchase invoice calculation:
    // RB less trade discount = net value. Purchase GST is calculated on
    // the full RB value before discount; MRP tax remains a display reference.
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
      const salesTaxBase = rbPrice;
      detail.taxe = this.roundUpMoney((salesTaxBase * taxRate) / 100);
      detail.withholding_tax = this.roundUpMoney(
        detail.Net_cost * (this.withholding_tax_rate / 100)
      );
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
        salesTaxTotal += this.details[i].taxe * (Number(this.details[i].quantity) || 0);
        withholdingTaxTotal += this.details[i].withholding_tax * (Number(this.details[i].quantity) || 0);
        this.total = parseFloat(this.total + this.details[i].subtotal);
      }

      const total_after_discount = this.total - (Number(this.purchase.discount) || 0);
      this.purchase.TaxNet = this.roundUpMoney(salesTaxTotal);
      this.purchase.withholding_tax = this.roundUpMoney(withholdingTaxTotal);
      this.GrandTotal = this.roundUpMoney(
        total_after_discount + withholdingTaxTotal + (Number(this.purchase.shipping) || 0)
      );
    },

    //-----------------------------------Delete Detail Product ------------------------------\\
    delete_Product_Detail(id) {
      for (var i = 0; i < this.details.length; i++) {
        if (id === this.details[i].detail_id) {
          this.details.splice(i, 1);
          const usedGatePassIds = new Set(this.gatePassIdsFromDetails());
          this.selectedGatePasses = this.selectedGatePasses.filter(gatePass => usedGatePassIds.has(Number(gatePass.id)));
          this.Calcul_Total();
        }
      }
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

    //-----------------------------------Verified Form Validation ------------------------------\\
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

    //--------------------------------- Create Purchase -------------------------\\
    Create_Purchase() {
      if (this.verifiedForm()) {
        this.SubmitProcessing = true;
        // Start the progress bar.
        NProgress.start();
        NProgress.set(0.1);
        axios
          .post("purchases", {
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
            gate_pass_ids: this.gatePassIdsFromDetails(),
            details: this.details
          })
          .then(response => {
            // Complete the animation of theprogress bar.
            NProgress.done();

            this.makeToast(
              "success",
              this.$t("Successfully_Created"),
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

    //---------------------------------Get Product Details ------------------------\\

    Get_Product_Details(product_id, variant_id) {
      const wid = this.purchase && this.purchase.warehouse_id ? this.purchase.warehouse_id : null;
      const url = wid
        ? `/show_product_data/${product_id}/${variant_id}/${wid}`
        : `/show_product_data/${product_id}/${variant_id}`;

      axios.get(url).then(response => {
        this.product.discount           = response.data.discount;
        this.product.DiscountNet        = response.data.DiscountNet;
        this.product.discount_Method    = response.data.discount_method;
        this.product.product_id = response.data.id;
        this.product.name = response.data.name;
        this.product.company_rb_price = Number(response.data.company_rb_price) || 0;
        this.product.mrp_price = Number(response.data.mrp_price) || 0;
        this.product.Unit_cost = this.product.company_rb_price;
        this.product.tax_method = "1";
        this.product.tax_percent = Number(this.purchase.tax_rate) || 0;
        this.product.unitPurchase = response.data.unitPurchase;
        this.product.fix_cost = response.data.fix_cost;
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
        this.recalculatePurchaseDetail(this.product);
        this.add_product();
        this.Calcul_Total();
      });
    },

    //------------------------------ Quick Add Supplier -------------------------\\
    Quick_Add_Supplier() {
      this.reset_Form_supplier();
      this.$bvModal.show("Quick_Add_Supplier");
    },

    reset_Form_supplier() {
      this.supplier = {
        id: "",
        name: "",
        email: "",
        phone: "",
        country: "",
        city: "",
        tax_number: "",
        adresse: ""
      };
    },

    Submit_Quick_Add_Supplier() {
      NProgress.start();
      NProgress.set(0.1);
      this.SubmitProcessing = true;
      this.$refs.Quick_Add_Supplier_Form &&
        this.$refs.Quick_Add_Supplier_Form.validate().then(success => {
          if (!success) {
            NProgress.done();
            this.SubmitProcessing = false;
            this.makeToast(
              "danger",
              this.$t("Please_fill_the_form_correctly"),
              this.$t("Failed")
            );
            return;
          }

          axios
            .post("providers", {
              name: this.supplier.name,
              email: this.supplier.email || "",
              phone: this.supplier.phone || "",
              tax_number: this.supplier.tax_number || "",
              country: this.supplier.country || "",
              city: this.supplier.city || "",
              adresse: this.supplier.adresse || ""
            })
            .then(({ data }) => {
              NProgress.done();
              this.SubmitProcessing = false;

              const newSupplier = data && data.provider ? data.provider : data;
              if (newSupplier && newSupplier.id) {
                this.suppliers.push({
                  id: newSupplier.id,
                  name: newSupplier.name,
                  phone: newSupplier.phone || ""
                });
                this.purchase.supplier_id = newSupplier.id;
              }

              this.makeToast(
                "success",
                this.$t("Successfully_Created"),
                this.$t("Success")
              );
              this.$bvModal.hide("Quick_Add_Supplier");
              this.reset_Form_supplier();
            })
            .catch(error => {
              NProgress.done();
              this.SubmitProcessing = false;
              this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
            });
        });
    },

    //---------------------------------------Get Elements Purchase ------------------------------\\
    GetElements() {
      return axios
        .get("purchases/create")
        .then(response => {
          this.suppliers = response.data.suppliers;
          this.warehouses = response.data.warehouses;
          this.managed_taxes = response.data.managed_taxes || [];
          const gst = this.managed_taxes.find(t => t.code === 'GST' && t.calculation_type === 'percentage');
          const wht = this.managed_taxes.find(t => t.code === 'WHT' && t.calculation_type === 'percentage');
          this.purchase.tax_rate = gst ? (parseFloat(gst.rate) || 0) : 0;
          this.withholding_tax_rate = wht ? (parseFloat(wht.rate) || 0) : 0;
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    }
  },

  //-----------------------------  Created function-------------------
  created() {
    this.GetElements().then(() => {
      const gatePassNumber = String(this.$route.query.gate_pass || '').trim();
      if (!gatePassNumber) return;
      this.gate_pass_search = gatePassNumber;
      return this.addGatePass();
    });
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

  .gate-pass-remove {
    border: 0;
    background: transparent;
    color: inherit;
    padding: 0;
    font-size: 16px;
    line-height: 1;
    cursor: pointer;
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

  /* ===== v-select in input-group =====
     A global rule (specificity 0,4,0) sets
       .input-group:not(.input-group-sm):not(.input-group-lg) .btn { height: calc(1.5em + 0.7rem + 0px) }
     Override only the button height (with !important to beat that 0,4,0
     global selector) so the wrapped v-select lines up with its standalone
     neighbors. */
  .input-group.category-input-group {
    display: flex;
    align-items: stretch;
    flex-wrap: nowrap;
  }

  .input-group.category-input-group .v-select {
    flex: 1 1 auto;
    min-width: 0;
  }

  .input-group.category-input-group .v-select .vs__dropdown-toggle {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
  }

  .input-group.category-input-group .input-group-append {
    align-items: stretch;
  }

  .input-group.category-input-group .category-add-btn {
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
    white-space: nowrap;
    height: calc(1.5em + 0.7rem + 0px) !important;
  }
</style>
