<template>
  <div class="main-content">
    <breadcumb :page="$t('SuppliersReport')" :folder="$t('Reports')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-if="!isLoading" class="supplier-report-identity mb-3">
      <div class="d-flex flex-wrap align-items-center justify-content-between">
        <div>
          <small class="text-muted text-uppercase">Supplier statement</small>
          <h4 class="mb-1">{{ provider.provider_name || '---' }}</h4>
          <span class="text-muted">Code: {{ provider.provider_code || '---' }}</span>
          <span v-if="provider.provider_phone" class="text-muted ml-3">{{ provider.provider_phone }}</span>
        </div>
        <div class="text-right">
          <small class="text-muted d-block">Total supplier due</small>
          <strong class="text-primary text-24">{{ formatPriceWithSymbol(currentUser.currency, provider.total_due, 2) }}</strong>
          <div class="mt-2">
            <b-button
              size="sm"
              variant="success"
              :disabled="exportingStatement"
              @click="exportSupplierStatement"
            >
              <span v-if="exportingStatement" class="spinner-border spinner-border-sm mr-1" aria-hidden="true"></span>
              <lucide-icon v-else name="download" />
              Export Supplier Statement
            </b-button>
          </div>
        </div>
      </div>
    </b-card>

    <b-row v-if="!isLoading">
      <!-- ICON BG -->

      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary   mb-30 text-center">
          <lucide-icon name="shopping-cart" />
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('Purchases')}}</p>
            <p class="text-primary text-24 line-height-1 mb-2">{{provider.total_purchase}}</p>
          </div>
        </b-card>
      </b-col>
      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary   mb-30 text-center">
          <lucide-icon name="trending-up" />
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('TotalAmount')}}</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber(provider.total_amount ,2)}}</p>
          </div>
        </b-card>
      </b-col>
      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary   mb-30 text-center">
          <lucide-icon name="banknote" />
          <div class="content">
            <p class="text-muted mt-2 mb-0">{{$t('TotalPaid')}}</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{ formatPriceWithSymbol(currentUser.currency, provider.total_paid, 2) }}</p>
          </div>
        </b-card>
      </b-col>
      <b-col lg="3" md="6" sm="12">
        <b-card class="card-icon-bg card-icon-bg-primary   mb-30 text-center">
          <lucide-icon name="wallet" />
          <div class="content">
            <p class="text-muted mt-2 mb-0">Purchase Due</p>
            <p
              class="text-primary text-24 line-height-1 mb-2"
            >{{currentUser.currency}} {{formatNumber((provider.purchase_due),2)}}</p>
          </div>
        </b-card>
      </b-col>
    </b-row>

    <b-row v-if="!isLoading">
      <b-col md="12">
        <b-card class="opening-balance-panel mb-30" no-body>
          <div class="opening-balance-header">
            <div>
              <h5 class="mb-1"><lucide-icon name="wallet" /> Opening Balance Details</h5>
              <small>Previous supplier dues recorded before regular purchase transactions.</small>
            </div>
            <b-badge variant="light" class="opening-balance-date">
              Effective date: {{ provider.opening_balance_date || 'Not recorded' }}
            </b-badge>
          </div>

          <div class="opening-balance-summary">
            <div class="opening-balance-stat">
              <small>Original opening balance</small>
              <strong>{{ formatPriceWithSymbol(currentUser.currency, provider.opening_balance_original, 2) }}</strong>
            </div>
            <div class="opening-balance-stat opening-balance-stat--paid">
              <small>Paid against opening balance</small>
              <strong>{{ formatPriceWithSymbol(currentUser.currency, provider.opening_balance_paid, 2) }}</strong>
            </div>
            <div class="opening-balance-stat opening-balance-stat--remaining">
              <small>Remaining opening balance</small>
              <strong>{{ formatPriceWithSymbol(currentUser.currency, provider.opening_balance_remaining, 2) }}</strong>
            </div>
            <div class="opening-balance-stat opening-balance-stat--total">
              <small>Total supplier due</small>
              <strong>{{ formatPriceWithSymbol(currentUser.currency, provider.total_due, 2) }}</strong>
            </div>
          </div>

          <div class="opening-balance-history">
            <h6>Opening Balance Payment History</h6>
            <b-table
              responsive
              small
              hover
              show-empty
              class="mb-0"
              :items="opening_balance_payments"
              :fields="openingPaymentFields"
              empty-text="No opening-balance payments have been recorded."
            >
              <template v-slot:cell(montant)="data">
                <strong>{{ formatPriceWithSymbol(currentUser.currency, data.item.montant, 2) }}</strong>
              </template>
            </b-table>
          </div>
        </b-card>
      </b-col>
    </b-row>

    <b-row v-if="!isLoading">
      <b-col md="12">
        <b-card class="card mb-30" header-bg-variant="transparent ">
          <b-tabs active-nav-item-class="nav nav-tabs" content-class="mt-3">

            <!-- Purchase Order Lines -->
            <b-tab title="Purchase Orders">
              <vue-good-table
                mode="remote"
                :columns="columns_purchase_orders"
                :totalRows="totalRows_purchase_orders"
                :rows="purchase_orders"
                @on-page-change="PageChangePurchaseOrders"
                @on-per-page-change="onPerPageChangePurchaseOrders"
                @on-search="onSearch_purchase_orders"
                :search-options="{ placeholder: $t('Search_this_table'), enabled: true }"
                :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
                styleClass="tableOne table-hover vgt-table supplier-detail-table"
              >
                <div slot="table-actions" class="mt-2 mb-3">
                  <b-button @click="printTableOnly('purchase_orders')" size="sm" variant="outline-secondary ripple m-1">
                    <lucide-icon name="printer" /> {{ $t("print") }}
                  </b-button>
                </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field === 'purchase_order_number'">
                    <router-link v-if="canViewPurchaseOrders" :to="'/app/procurement/purchase-orders/' + props.row.purchase_order_id">{{ props.row.purchase_order_number }}</router-link>
                    <span v-else>{{ props.row.purchase_order_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'product_name'" class="product-cell">
                    <strong>{{ props.row.product_name }}</strong>
                    <small v-if="props.row.variant_name || props.row.sku" class="d-block text-muted">
                      {{ props.row.variant_name || '' }}<span v-if="props.row.sku"> · {{ props.row.sku }}</span>
                    </small>
                  </div>
                  <div v-else-if="props.column.field === 'unit_price' || props.column.field === 'line_total' || props.column.field === 'order_grand_total'">
                    {{ formatPriceWithSymbol(currentUser.currency, props.row[props.column.field], 2) }}
                  </div>
                  <div v-else-if="props.column.field === 'discount'">{{ discountLabel(props.row) }}</div>
                  <div v-else-if="props.column.field === 'tax_amount'">{{ taxLabel(props.row) }}</div>
                  <div v-else-if="props.column.field === 'status'"><b-badge variant="light">{{ readableStatus(props.row.status) }}</b-badge></div>
                </template>
              </vue-good-table>
            </b-tab>

            <!-- Gate Pass Lines -->
            <b-tab title="Gate Passes">
              <vue-good-table
                mode="remote"
                :columns="columns_gate_passes"
                :totalRows="totalRows_gate_passes"
                :rows="gate_passes"
                @on-page-change="PageChangeGatePasses"
                @on-per-page-change="onPerPageChangeGatePasses"
                @on-search="onSearch_gate_passes"
                :search-options="{ placeholder: $t('Search_this_table'), enabled: true }"
                :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
                styleClass="tableOne table-hover vgt-table supplier-detail-table"
              >
                <div slot="table-actions" class="mt-2 mb-3">
                  <b-button @click="printTableOnly('gate_passes')" size="sm" variant="outline-secondary ripple m-1">
                    <lucide-icon name="printer" /> {{ $t("print") }}
                  </b-button>
                </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field === 'gate_pass_number'">
                    <router-link v-if="canViewGatePasses" :to="'/app/procurement/gate-passes/' + props.row.gate_pass_id">{{ props.row.gate_pass_number }}</router-link>
                    <span v-else>{{ props.row.gate_pass_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'purchase_order_number' && props.row.purchase_order_id">
                    <router-link v-if="canViewPurchaseOrders" :to="'/app/procurement/purchase-orders/' + props.row.purchase_order_id">{{ props.row.purchase_order_number }}</router-link>
                    <span v-else>{{ props.row.purchase_order_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'supplier_invoice_number' && props.row.supplier_invoice_id">
                    <router-link v-if="canViewSupplierInvoices" :to="'/app/procurement/supplier-invoices/' + props.row.supplier_invoice_id">{{ props.row.supplier_invoice_number }}</router-link>
                    <span v-else>{{ props.row.supplier_invoice_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'product_name'" class="product-cell">
                    <strong>{{ props.row.product_name }}</strong>
                    <small v-if="props.row.variant_name || props.row.sku" class="d-block text-muted">
                      {{ props.row.variant_name || '' }}<span v-if="props.row.sku"> · {{ props.row.sku }}</span>
                    </small>
                  </div>
                  <div v-else-if="props.column.field === 'status'"><b-badge variant="light">{{ readableStatus(props.row.status) }}</b-badge></div>
                </template>
              </vue-good-table>
            </b-tab>

            <!-- Supplier Invoice Lines -->
            <b-tab title="Supplier Invoices">
              <vue-good-table
                mode="remote"
                :columns="columns_supplier_invoices"
                :totalRows="totalRows_supplier_invoices"
                :rows="supplier_invoices"
                @on-page-change="PageChangeSupplierInvoices"
                @on-per-page-change="onPerPageChangeSupplierInvoices"
                @on-search="onSearch_supplier_invoices"
                :search-options="{ placeholder: $t('Search_this_table'), enabled: true }"
                :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
                styleClass="tableOne table-hover vgt-table supplier-detail-table"
              >
                <div slot="table-actions" class="mt-2 mb-3">
                  <b-button @click="printTableOnly('supplier_invoices')" size="sm" variant="outline-secondary ripple m-1">
                    <lucide-icon name="printer" /> {{ $t("print") }}
                  </b-button>
                </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field === 'supplier_invoice_number'">
                    <router-link v-if="canViewSupplierInvoices" :to="'/app/procurement/supplier-invoices/' + props.row.supplier_invoice_id">{{ props.row.supplier_invoice_number }}</router-link>
                    <span v-else>{{ props.row.supplier_invoice_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'purchase_reference' && props.row.purchase_id">
                    <router-link :to="'/app/purchases/detail/' + props.row.purchase_id">{{ props.row.purchase_reference }}</router-link>
                  </div>
                  <div v-else-if="props.column.field === 'purchase_order_number' && props.row.purchase_order_id">
                    <router-link v-if="canViewPurchaseOrders" :to="'/app/procurement/purchase-orders/' + props.row.purchase_order_id">{{ props.row.purchase_order_number }}</router-link>
                    <span v-else>{{ props.row.purchase_order_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'gate_pass_number' && props.row.gate_pass_id">
                    <router-link v-if="canViewGatePasses" :to="'/app/procurement/gate-passes/' + props.row.gate_pass_id">{{ props.row.gate_pass_number }}</router-link>
                    <span v-else>{{ props.row.gate_pass_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'product_name'" class="product-cell">
                    <strong>{{ props.row.product_name }}</strong>
                    <small v-if="props.row.variant_name || props.row.sku" class="d-block text-muted">
                      {{ props.row.variant_name || '' }}<span v-if="props.row.sku"> · {{ props.row.sku }}</span>
                    </small>
                  </div>
                  <div v-else-if="props.column.field === 'unit_cost' || props.column.field === 'line_total' || props.column.field === 'invoice_grand_total'">
                    {{ formatPriceWithSymbol(currentUser.currency, props.row[props.column.field], 2) }}
                  </div>
                  <div v-else-if="props.column.field === 'discount'">{{ discountLabel(props.row) }}</div>
                  <div v-else-if="props.column.field === 'tax_amount'">{{ taxLabel(props.row) }}</div>
                  <div v-else-if="props.column.field === 'tax_type'">
                    <b-badge :variant="props.row.tax_type === 'gst' ? 'success' : 'secondary'">{{ props.row.tax_type === 'gst' ? 'GST Invoice' : 'Non-GST Invoice' }}</b-badge>
                  </div>
                  <div v-else-if="props.column.field === 'status'"><b-badge variant="light">{{ readableStatus(props.row.status) }}</b-badge></div>
                </template>
              </vue-good-table>
            </b-tab>

            <!-- Purchases Table -->
            <b-tab :title="$t('Purchases')">
              <b-alert v-if="!purchases.length" show variant="light" class="mb-3">
                No purchase transactions are recorded for this supplier.
              </b-alert>
              <vue-good-table
                mode="remote"
                :columns="columns_purchases"
                :totalRows="totalRows_purchases"
                :rows="purchases"
                @on-page-change="PageChangePurchases"
                @on-per-page-change="onPerPageChangePurchases"
                @on-search="onSearch_purchases"
                :search-options="{
                  placeholder: $t('Search_this_table'),
                  enabled: true,
                }"
                :pagination-options="{
                  enabled: true,
                  mode: 'records',
                  nextLabel: 'next',
                  prevLabel: 'prev',
                }"
                styleClass="tableOne table-hover vgt-table"
              >
               <div slot="table-actions" class="mt-2 mb-3">
                <b-button @click="printTableOnly('purchases')" size="sm" variant="outline-secondary ripple m-1">
                  <lucide-icon name="printer" /> {{ $t("print") }}
                </b-button>
                <b-button @click="Purchase_PDF()" size="sm" variant="outline-success ripple m-1">
                  <lucide-icon name="copy" /> PDF
                </b-button>
              </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field == 'statut'">
                    <span
                      v-if="props.row.statut == 'received'"
                      class="badge badge-outline-success"
                    >{{$t('Received')}}</span>
                    <span
                      v-else-if="props.row.statut == 'pending'"
                      class="badge badge-outline-info"
                    >{{$t('Pending')}}</span>
                    <span v-else class="badge badge-outline-warning">{{$t('Ordered')}}</span>
                  </div>
                  <div v-else-if="props.column.field == 'payment_status'">
                    <span
                      v-if="props.row.payment_status == 'paid'"
                      class="badge badge-outline-success"
                    >{{$t('Paid')}}</span>
                    <span
                      v-else-if="props.row.payment_status == 'partial'"
                      class="badge badge-outline-primary"
                    >{{$t('partial')}}</span>
                    <span v-else class="badge badge-outline-warning">{{$t('Unpaid')}}</span>
                  </div>
                  <div v-else-if="props.column.field == 'Ref'">
                    <router-link
                      :to="'/app/purchases/detail/'+props.row.id"
                    >
                      <span class="ul-btn__text ml-1">{{props.row.Ref}}</span>
                    </router-link>
                  </div>
                  <div v-else-if="props.column.field === 'purchase_order_number' && props.row.purchase_order_id">
                    <router-link v-if="canViewPurchaseOrders" :to="'/app/procurement/purchase-orders/' + props.row.purchase_order_id">{{ props.row.purchase_order_number }}</router-link>
                    <span v-else>{{ props.row.purchase_order_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'gate_pass_number' && props.row.gate_pass_id">
                    <router-link v-if="canViewGatePasses" :to="'/app/procurement/gate-passes/' + props.row.gate_pass_id">{{ props.row.gate_pass_number }}</router-link>
                    <span v-else>{{ props.row.gate_pass_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'products_summary'" class="products-summary" :title="props.row.products_summary">
                    {{ props.row.products_summary }}
                  </div>
                  <div v-else-if="props.column.field === 'discount_total' || props.column.field === 'tax_total' || props.column.field === 'GrandTotal' || props.column.field === 'paid_amount' || props.column.field === 'due'">
                    {{ formatPriceWithSymbol(currentUser.currency, props.row[props.column.field], 2) }}
                  </div>
                  <div v-else-if="props.column.field === 'invoice_tax_type'">
                    <b-badge v-if="props.row.invoice_tax_type" :variant="props.row.invoice_tax_type === 'gst' ? 'success' : 'secondary'">{{ props.row.invoice_tax_type === 'gst' ? 'GST' : 'Non-GST' }}</b-badge>
                    <span v-else>---</span>
                  </div>
                </template>
              </vue-good-table>
            </b-tab>
            
            <!-- Returns Table -->
            <b-tab :title="$t('Returns')">
              <vue-good-table
                mode="remote"
                :columns="columns_returns"
                :totalRows="totalRows_returns"
                :rows="returns_supplier"
                @on-page-change="PageChangeReturns"
                @on-per-page-change="onPerPageChangeReturns"
                @on-search="onSearch_return_purchases"
                :search-options="{
                  placeholder: $t('Search_this_table'),
                  enabled: true,
                }"
                :pagination-options="{
                  enabled: true,
                  mode: 'records',
                  nextLabel: 'next',
                  prevLabel: 'prev',
                }"
                styleClass="tableOne table-hover vgt-table"
              >
              <div slot="table-actions" class="mt-2 mb-3">
                <b-button @click="printTableOnly('returns')" size="sm" variant="outline-secondary ripple m-1">
                  <lucide-icon name="printer" /> {{ $t("print") }}
                </b-button>
                <b-button @click="Returns_Purchase_PDF()" size="sm" variant="outline-success ripple m-1">
                  <lucide-icon name="copy" /> PDF
                </b-button>
              </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field == 'statut'">
                    <span
                      v-if="props.row.statut == 'completed'"
                      class="badge badge-outline-success"
                    >{{$t('complete')}}</span>
                    <span v-else class="badge badge-outline-info">{{$t('Pending')}}</span>
                  </div>

                  <div v-else-if="props.column.field == 'payment_status'">
                    <span
                      v-if="props.row.payment_status == 'paid'"
                      class="badge badge-outline-success"
                    >{{$t('Paid')}}</span>
                    <span
                      v-else-if="props.row.payment_status == 'partial'"
                      class="badge badge-outline-primary"
                    >{{$t('partial')}}</span>
                    <span v-else class="badge badge-outline-warning">{{$t('Unpaid')}}</span>
                  </div>
                   <div v-else-if="props.column.field == 'Ref'">
                    <router-link
                      :to="'/app/purchase_return/detail/'+props.row.id"
                    >
                      <span class="ul-btn__text ml-1">{{props.row.Ref}}</span>
                    </router-link>
                  </div>
                  <div v-else-if="props.column.field == 'purchase_ref' && props.row.purchase_id">
                    <router-link
                      :to="'/app/purchases/detail/'+props.row.purchase_id"
                    >
                      <span class="ul-btn__text ml-1">{{props.row.purchase_ref}}</span>
                    </router-link>
                  </div>
                </template>
              </vue-good-table>
            </b-tab>
            
            <!-- Payments Table -->
            <b-tab title="Supplier Payments">
              <vue-good-table
                mode="remote"
                :columns="columns_payments"
                :totalRows="totalRows_payments"
                :rows="payments"
                @on-page-change="PageChangePayments"
                @on-per-page-change="onPerPageChangePayments"
                @on-search="onSearch_payments"
                :search-options="{
                  placeholder: $t('Search_this_table'),
                  enabled: true,
                }"
                :pagination-options="{
                  enabled: true,
                  mode: 'records',
                  nextLabel: 'next',
                  prevLabel: 'prev',
                }"
                styleClass="tableOne table-hover vgt-table"
              >
               <div slot="table-actions" class="mt-2 mb-3">
                <b-button @click="printTableOnly('payments')" size="sm" variant="outline-secondary ripple m-1">
                  <lucide-icon name="printer" /> {{ $t("print") }}
                </b-button>
                <b-button @click="Payments_PDF()" size="sm" variant="outline-success ripple m-1">
                  <lucide-icon name="copy" /> PDF
                </b-button>
              </div>
                <template slot="table-row" slot-scope="props">
                  <div v-if="props.column.field === 'purchase_Ref'">
                    <router-link :to="'/app/purchases/detail/' + props.row.purchase_id">{{ props.row.purchase_Ref }}</router-link>
                  </div>
                  <div v-else-if="props.column.field === 'supplier_invoice_number' && props.row.supplier_invoice_id">
                    <router-link v-if="canViewSupplierInvoices" :to="'/app/procurement/supplier-invoices/' + props.row.supplier_invoice_id">{{ props.row.supplier_invoice_number }}</router-link>
                    <span v-else>{{ props.row.supplier_invoice_number }}</span>
                  </div>
                  <div v-else-if="props.column.field === 'account_name'">
                    <strong>{{ props.row.account_name || '---' }}</strong>
                    <small v-if="props.row.account_num" class="d-block text-muted">{{ props.row.account_num }}</small>
                  </div>
                  <div v-else-if="props.column.field === 'montant'">{{ formatPriceWithSymbol(currentUser.currency, props.row.montant, 2) }}</div>
                </template>
              </vue-good-table>
            </b-tab>
          </b-tabs>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
import { mapActions, mapGetters } from "vuex";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  data() {
    return {
      totalRows_purchases: "",
      totalRows_returns: "",
      totalRows_payments: "",
      totalRows_purchase_orders: 0,
      totalRows_gate_passes: 0,
      totalRows_supplier_invoices: 0,
      limit_returns: "10",
      limit_purchases: "10",
      limit_payments: "10",
      limit_purchase_orders: "10",
      limit_gate_passes: "10",
      limit_supplier_invoices: "10",
      purchases_page: 1,
      Return_page: 1,
      Payment_page: 1,
      PurchaseOrder_page: 1,
      GatePass_page: 1,
      SupplierInvoice_page: 1,
      isLoading: true,
      exportingStatement: false,
      returns_supplier: [],
      payments: [],
      purchases: [],
      purchase_orders: [],
      gate_passes: [],
      supplier_invoices: [],
      opening_balance_payments: [],

      search_purchases:"",
      search_payments:"",
      search_return_purchases:"",
      search_purchase_orders: "",
      search_gate_passes: "",
      search_supplier_invoices: "",

      provider: {
        id: "",
        name: "",
        total_purchase: 0,
        total_amount: 0,
        total_paid: 0,
        due: 0,
        purchase_due: 0,
        total_due: 0,
        opening_balance_original: 0,
        opening_balance_paid: 0,
        opening_balance_remaining: 0,
        opening_balance_date: null
      },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },

  computed: {
    ...mapGetters(["currentUser", "currentUserPermissions"]),
    canViewPurchaseOrders() {
      return (this.currentUserPermissions || []).includes('purchase_orders_view');
    },
    canViewGatePasses() {
      return (this.currentUserPermissions || []).includes('gate_passes_view');
    },
    canViewSupplierInvoices() {
      return (this.currentUserPermissions || []).includes('supplier_invoices_view');
    },
    openingPaymentFields() {
      return [
        { key: 'date', label: this.$t('date') },
        { key: 'Ref', label: this.$t('Reference') },
        { key: 'payment_method', label: this.$t('ModePaiement') },
        { key: 'account', label: 'Cash / Bank Account' },
        { key: 'montant', label: this.$t('Amount'), class: 'text-right' },
        { key: 'notes', label: this.$t('Note') }
      ];
    },
    columns_purchase_orders() {
      return [
        { label: 'Order Date', field: 'order_date', sortable: false },
        { label: 'Purchase Order', field: 'purchase_order_number', sortable: false },
        { label: this.$t('warehouse'), field: 'warehouse_name', sortable: false },
        { label: 'Product / Model', field: 'product_name', sortable: false },
        { label: 'Qty', field: 'ordered_quantity', type: 'decimal', sortable: false },
        { label: 'Unit Price', field: 'unit_price', sortable: false },
        { label: 'Discount', field: 'discount', sortable: false },
        { label: 'GST / Tax', field: 'tax_amount', sortable: false },
        { label: 'Line Total', field: 'line_total', sortable: false },
        { label: 'Order Total', field: 'order_grand_total', sortable: false },
        { label: this.$t('Status'), field: 'status', sortable: false }
      ];
    },
    columns_gate_passes() {
      return [
        { label: 'Delivered At', field: 'delivered_at', sortable: false },
        { label: 'Gate Pass', field: 'gate_pass_number', sortable: false },
        { label: 'Supplier Gate Pass', field: 'supplier_gate_pass_number', sortable: false },
        { label: 'Purchase Order', field: 'purchase_order_number', sortable: false },
        { label: this.$t('warehouse'), field: 'warehouse_name', sortable: false },
        { label: 'Product / Model', field: 'product_name', sortable: false },
        { label: 'Delivered', field: 'delivered_quantity', type: 'decimal', sortable: false },
        { label: 'Accepted', field: 'accepted_quantity', type: 'decimal', sortable: false },
        { label: 'Rejected', field: 'rejected_quantity', type: 'decimal', sortable: false },
        { label: 'Short', field: 'short_quantity', type: 'decimal', sortable: false },
        { label: this.$t('Status'), field: 'status', sortable: false }
      ];
    },
    columns_supplier_invoices() {
      return [
        { label: 'Invoice Date', field: 'invoice_date', sortable: false },
        { label: 'Supplier Invoice', field: 'supplier_invoice_number', sortable: false },
        { label: 'Purchase', field: 'purchase_reference', sortable: false },
        { label: 'Purchase Order', field: 'purchase_order_number', sortable: false },
        { label: 'Gate Pass', field: 'gate_pass_number', sortable: false },
        { label: 'Product / Model', field: 'product_name', sortable: false },
        { label: 'Qty', field: 'quantity', type: 'decimal', sortable: false },
        { label: 'Unit Cost', field: 'unit_cost', sortable: false },
        { label: 'Discount', field: 'discount', sortable: false },
        { label: 'GST / Tax', field: 'tax_amount', sortable: false },
        { label: 'Tax Type', field: 'tax_type', sortable: false },
        { label: 'Line Total', field: 'line_total', sortable: false },
        { label: 'Invoice Total', field: 'invoice_grand_total', sortable: false },
        { label: this.$t('Status'), field: 'status', sortable: false }
      ];
    },
    columns_purchases() {
      return [
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Supplier"),
          field: "provider_name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("warehouse"),
          field: "warehouse_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        { label: 'Supplier Invoice', field: 'supplier_invoice_number', sortable: false },
        { label: 'Purchase Order', field: 'purchase_order_number', sortable: false },
        { label: 'Gate Pass', field: 'gate_pass_number', sortable: false },
        { label: 'Products', field: 'products_summary', sortable: false },
        { label: 'Discount', field: 'discount_total', sortable: false },
        { label: 'GST / Tax', field: 'tax_total', sortable: false },
        { label: 'Tax Type', field: 'invoice_tax_type', sortable: false },
        {
          label: this.$t("Total"),
          field: "GrandTotal",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Paid"),
          field: "paid_amount",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Due"),
          field: "due",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
         {
          label: this.$t("Status"),
          field: "statut",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("PaymentStatus"),
          field: "payment_status",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    },
    columns_returns() {
      return [
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Supplier"),
          field: "provider_name",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("warehouse"),
          field: "warehouse_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Purchase_Ref"),
          field: "purchase_ref",
          tdClass: "text-left",
          thClass: "text-left"
        },
       
        {
          label: this.$t("Total"),
          field: "GrandTotal",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Paid"),
          field: "paid_amount",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Due"),
          field: "due",
          type: "decimal",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
         {
          label: this.$t("Status"),
          field: "statut",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("PaymentStatus"),
          field: "payment_status",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    },
    columns_payments() {
      return [
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Reference"),
          field: "Ref",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: this.$t("Purchase"),
          field: "purchase_Ref",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: 'Supplier Invoice',
          field: 'supplier_invoice_number',
          sortable: false
        },
        {
          label: this.$t("ModePaiement"),
          field: "payment_method",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        },
        {
          label: 'Cash / Bank Account',
          field: 'account_name',
          sortable: false
        },
        {
          label: this.$t("Amount"),
          field: "montant",
          tdClass: "text-left",
          thClass: "text-left",
          type: "decimal",
          sortable: false
        },
        {
          label: this.$t("Note"),
          field: "notes",
          sortable: false
        }
      ];
    }
  },

  methods: {

    async exportSupplierStatement() {
      this.exportingStatement = true;

      try {
        const response = await axios.get(
          `report/provider_statement_excel/${this.$route.params.id}`,
          { responseType: 'blob' }
        );
        const url = window.URL.createObjectURL(new Blob([response.data], {
          type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
        }));
        const link = document.createElement('a');
        const safeName = String(this.provider.provider_name || 'supplier')
          .replace(/[^a-z0-9_-]+/gi, '-')
          .replace(/^-+|-+$/g, '');

        link.href = url;
        link.setAttribute('download', `supplier-statement-${safeName || 'supplier'}.xlsx`);
        document.body.appendChild(link);
        link.click();
        link.remove();
        window.URL.revokeObjectURL(url);
      } catch (error) {
        this.$bvToast.toast('Unable to export the supplier statement.', {
          title: 'Export failed',
          variant: 'danger',
          solid: true
        });
      } finally {
        this.exportingStatement = false;
      }
    },

      //---------------------- Purchases PDF -------------------------------\\
    Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold"); 
      pdf.setFont("VazirmatnBold"); 

      let columns = [
        { header: self.$t("date"), dataKey: "date" },
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: "Supplier Invoice", dataKey: "supplier_invoice_number" },
        { header: "Purchase Order", dataKey: "purchase_order_number" },
        { header: "Gate Pass", dataKey: "gate_pass_number" },
        { header: "Products", dataKey: "products_summary" },
        { header: "Discount", dataKey: "discount_total" },
        { header: "GST / Tax", dataKey: "tax_total" },
        { header: "Tax Type", dataKey: "invoice_tax_type" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" }
      ];

      autoTable(pdf, {
             columns: columns,
             body: self.purchases,
             startY: 70,
             theme: "grid", 
             didDrawPage: (data) => {
               pdf.setFont("VazirmatnBold");
               pdf.setFontSize(18);
               pdf.text("Purchase List", 40, 25);   
             },
             styles: {
               font: "VazirmatnBold", 
               halign: "center", // 
             },
             headStyles: {
               fillColor: [26, 86, 219], 
               textColor: 255, 
               fontStyle: "bold", 
             },
      });

      pdf.save("Purchase_List.pdf");
    },

       //----------------------------------------- Returns Purchase PDF -----------------------\\
    Returns_Purchase_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold"); 
      pdf.setFont("VazirmatnBold"); 

      let columns = [
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Supplier"), dataKey: "provider_name" },
        { header: self.$t("warehouse"), dataKey: "warehouse_name" },
        { header: self.$t("Purchase"), dataKey: "purchase_ref" },
        { header: self.$t("Total"), dataKey: "GrandTotal" },
        { header: self.$t("Paid"), dataKey: "paid_amount" },
        { header: self.$t("Due"), dataKey: "due" },
        { header: self.$t("Status"), dataKey: "statut" },
        { header: self.$t("PaymentStatus"), dataKey: "payment_status" }
      ];

      autoTable(pdf, {
             columns: columns,
             body: self.returns_supplier,
             startY: 70,
             theme: "grid", 
             didDrawPage: (data) => {
               pdf.setFont("VazirmatnBold");
               pdf.setFontSize(18);
               pdf.text("Purchase Return List", 40, 25);   
             },
             styles: {
               font: "VazirmatnBold", 
               halign: "center", // 
             },
             headStyles: {
               fillColor: [26, 86, 219], 
               textColor: 255, 
               fontStyle: "bold", 
             },
      });

      pdf.save("purchase_returns.pdf");
    },

       //----------------------------------- Sales PDF ------------------------------\\
    Payments_PDF() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      pdf.addFont(fontPath, "VazirmatnBold", "bold"); 
      pdf.setFont("VazirmatnBold"); 

      let columns = [
        { header: self.$t("date"), dataKey: "date" },
        { header: self.$t("Reference"), dataKey: "Ref" },
        { header: self.$t("Purchase"), dataKey: "purchase_Ref" },
        { header: "Supplier Invoice", dataKey: "supplier_invoice_number" },
        { header: self.$t("ModePaiement"), dataKey: "payment_method" },
        { header: "Cash / Bank Account", dataKey: "account_name" },
        { header: "Account Number", dataKey: "account_num" },
        { header: self.$t("Amount"), dataKey: "montant" },
        { header: self.$t("Note"), dataKey: "notes" },
      ];

      autoTable(pdf, {
             columns: columns,
             body: self.payments,
             startY: 70,
             theme: "grid", 
             didDrawPage: (data) => {
               pdf.setFont("VazirmatnBold");
               pdf.setFontSize(18);
               pdf.text("Payments List", 40, 25);   
             },
             styles: {
               font: "VazirmatnBold", 
               halign: "center", // 
             },
             headStyles: {
               fillColor: [26, 86, 219], 
               textColor: 255, 
               fontStyle: "bold", 
             },
      });

      pdf.save("Payments_List.pdf");
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
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

    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing formatNumber helper to preserve current behavior.
    formatPriceDisplay(number, dec) {
      try {
        const decimals = Number.isInteger(dec) ? dec : 0;
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        const effectiveKey = key || null;
        return formatPriceDisplayHelper(number, decimals, effectiveKey);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

    readableStatus(value) {
      return String(value || '---')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, character => character.toUpperCase());
    },

    discountLabel(row) {
      const amount = Number(row.discount || 0);
      if (row.discount_method === 'percentage' || String(row.discount_method) === '1') {
        return `${this.formatNumber(amount, 2)}%`;
      }
      return this.formatPriceWithSymbol(this.currentUser.currency, amount, 2);
    },

    taxLabel(row) {
      const name = row.tax_name || (row.tax_type === 'gst' ? 'GST' : 'Tax');
      const rate = Number(row.tax_rate || 0);
      const amount = this.formatPriceWithSymbol(this.currentUser.currency, row.tax_amount || 0, 2);
      return `${name}${rate ? ` ${this.formatNumber(rate, 2)}%` : ''} · ${amount}`;
    },

    //------ Print Table Only - Print data with all columns based on table type
    printTableOnly(tableType) {
      let title, rows, columns;
      
      if (tableType === 'purchases') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / ${this.$t("Purchases")}`;
        rows = Array.isArray(this.purchases) ? this.purchases : [];
        columns = this.columns_purchases;
      } else if (tableType === 'purchase_orders') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / Purchase Orders`;
        rows = Array.isArray(this.purchase_orders) ? this.purchase_orders : [];
        columns = this.columns_purchase_orders;
      } else if (tableType === 'gate_passes') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / Gate Passes`;
        rows = Array.isArray(this.gate_passes) ? this.gate_passes : [];
        columns = this.columns_gate_passes;
      } else if (tableType === 'supplier_invoices') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / Supplier Invoices`;
        rows = Array.isArray(this.supplier_invoices) ? this.supplier_invoices : [];
        columns = this.columns_supplier_invoices;
      } else if (tableType === 'returns') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / ${this.$t("Returns")}`;
        rows = Array.isArray(this.returns_supplier) ? this.returns_supplier : [];
        columns = this.columns_returns;
      } else if (tableType === 'payments') {
        title = `${this.$t("Reports")} / ${this.$t("SuppliersReport")} / Supplier Payments`;
        rows = Array.isArray(this.payments) ? this.payments : [];
        columns = this.columns_payments;
      } else {
        return;
      }
      
      // Build table header with all columns
      let tableHTML = '<table style="width: 100%; border-collapse: collapse; font-size: 10px;">';
      tableHTML += '<thead><tr>';
      
      columns.forEach(col => {
        tableHTML += `<th style="border: 1px solid #ddd; padding: 6px 8px; background-color: #f5f5f5; font-weight: bold; text-align: left;">${col.label}</th>`;
      });
      tableHTML += '</tr></thead><tbody>';
      
      // Build table rows with all data - format each cell according to column type
      rows.forEach(row => {
        tableHTML += '<tr>';
        columns.forEach(col => {
          let cellValue = '';
          
          // Handle status fields with badges
          if (col.field === 'statut') {
            if (tableType === 'purchases') {
              if (row.statut === 'received') cellValue = this.$t('Received');
              else if (row.statut === 'pending') cellValue = this.$t('Pending');
              else cellValue = this.$t('Ordered');
            } else if (tableType === 'returns') {
              if (row.statut === 'completed') cellValue = this.$t('complete');
              else cellValue = this.$t('Pending');
            } else {
              cellValue = row.statut || '';
            }
          } else if (col.field === 'payment_status') {
            if (row.payment_status === 'paid') cellValue = this.$t('Paid');
            else if (row.payment_status === 'partial') cellValue = this.$t('partial');
            else cellValue = this.$t('Unpaid');
          } else if (col.field === 'product_name') {
            cellValue = `${row.product_name || ''}${row.variant_name ? ` - ${row.variant_name}` : ''}${row.sku ? ` (${row.sku})` : ''}`;
          } else if (col.field === 'discount') {
            cellValue = this.discountLabel(row);
          } else if (col.field === 'tax_amount') {
            cellValue = this.taxLabel(row);
          } else if (col.field === 'account_name') {
            cellValue = `${row.account_name || '---'}${row.account_num ? ` (${row.account_num})` : ''}`;
          } else if (col.field === 'tax_type' || col.field === 'invoice_tax_type') {
            cellValue = row[col.field] ? (row[col.field] === 'gst' ? 'GST' : 'Non-GST') : '---';
          } else if (col.field === 'status') {
            cellValue = this.readableStatus(row.status);
          } else if ([
            'GrandTotal', 'paid_amount', 'due', 'montant', 'unit_price', 'unit_cost',
            'line_total', 'order_grand_total', 'invoice_grand_total', 'discount_total', 'tax_total'
          ].includes(col.field)) {
            // Format monetary values
            cellValue = this.formatPriceDisplay(row[col.field] || 0, 2);
          } else {
            // Default: get value directly from row object
            cellValue = row[col.field] || '';
          }
          
          tableHTML += `<td style="border: 1px solid #ddd; padding: 6px 8px; text-align: left;">${cellValue}</td>`;
        });
        tableHTML += '</tr>';
      });
      
      tableHTML += '</tbody></table>';

      const w = window.open("", "_blank");
      if (!w) {
        alert("Please allow popups to print");
        return;
      }

      const links = Array.from(document.querySelectorAll('link[rel="stylesheet"]'))
        .map(l => l.outerHTML)
        .join("\n");

      const doc = w.document;
      doc.open();
      doc.write(`<!doctype html>
<html>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <base href="${window.location.origin}/" />
    <title>${title}</title>
    ${links}
    <style>
      /* Force visibility in print (some global POS print CSS hides body) */
      @media print { 
        body, body * { visibility: visible !important; }
        @page { size: A4 landscape; margin: 0.3cm; }
      }
      body { margin: 0.3cm; font-family: Arial, sans-serif; }
      .print-header { font-weight: 600; margin-bottom: 10px; font-size: 14px; }
      table { width: 100%; border-collapse: collapse; }
      th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; font-size: 10px; }
      th { background-color: #f5f5f5; font-weight: bold; }
      tr:nth-child(even) { background-color: #f9f9f9; }
    </style>
  </head>
  <body>
    <div class="print-header">${title}</div>
    ${tableHTML}
  </body>
</html>`);
      doc.close();

      w.focus();
      setTimeout(() => {
        w.print();
        w.close();
      }, 400);
    },

    //------------------------------ Show Reports -------------------------\\
    Get_Reports() {
      let id = this.$route.params.id;
      axios
        .get(`report/provider/${id}`)
        .then(response => {
          this.provider = response.data.report;
          this.opening_balance_payments = response.data.report.opening_balance_payments || [];
          this.isLoading = false;
        })
        .catch(response => {
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    Get_Procurement(type, page) {
      const propertyMap = {
        purchase_orders: ['purchase_orders', 'totalRows_purchase_orders', 'limit_purchase_orders', 'search_purchase_orders'],
        gate_passes: ['gate_passes', 'totalRows_gate_passes', 'limit_gate_passes', 'search_gate_passes'],
        supplier_invoices: ['supplier_invoices', 'totalRows_supplier_invoices', 'limit_supplier_invoices', 'search_supplier_invoices']
      };
      const properties = propertyMap[type];
      if (!properties) return;

      axios.get('report/provider_procurement', {
        params: {
          type,
          page,
          limit: this[properties[2]],
          search: this[properties[3]],
          id: this.$route.params.id
        }
      }).then(response => {
        this[properties[0]] = response.data.rows || [];
        this[properties[1]] = response.data.totalRows || 0;
      }).catch(() => {
        this[properties[0]] = [];
        this[properties[1]] = 0;
      });
    },

    PageChangePurchaseOrders({ currentPage }) {
      this.PurchaseOrder_page = currentPage;
      this.Get_Procurement('purchase_orders', currentPage);
    },
    onPerPageChangePurchaseOrders({ currentPerPage }) {
      this.limit_purchase_orders = currentPerPage;
      this.PurchaseOrder_page = 1;
      this.Get_Procurement('purchase_orders', 1);
    },
    onSearch_purchase_orders({ searchTerm }) {
      this.search_purchase_orders = searchTerm;
      this.PurchaseOrder_page = 1;
      this.Get_Procurement('purchase_orders', 1);
    },
    PageChangeGatePasses({ currentPage }) {
      this.GatePass_page = currentPage;
      this.Get_Procurement('gate_passes', currentPage);
    },
    onPerPageChangeGatePasses({ currentPerPage }) {
      this.limit_gate_passes = currentPerPage;
      this.GatePass_page = 1;
      this.Get_Procurement('gate_passes', 1);
    },
    onSearch_gate_passes({ searchTerm }) {
      this.search_gate_passes = searchTerm;
      this.GatePass_page = 1;
      this.Get_Procurement('gate_passes', 1);
    },
    PageChangeSupplierInvoices({ currentPage }) {
      this.SupplierInvoice_page = currentPage;
      this.Get_Procurement('supplier_invoices', currentPage);
    },
    onPerPageChangeSupplierInvoices({ currentPerPage }) {
      this.limit_supplier_invoices = currentPerPage;
      this.SupplierInvoice_page = 1;
      this.Get_Procurement('supplier_invoices', 1);
    },
    onSearch_supplier_invoices({ searchTerm }) {
      this.search_supplier_invoices = searchTerm;
      this.SupplierInvoice_page = 1;
      this.Get_Procurement('supplier_invoices', 1);
    },

    //--------------------------- Event Page Change -------------\\
    PageChangePurchases({ currentPage }) {
      if (this.purchases_page !== currentPage) {
        this.purchases_page = currentPage;
        this.Get_Purchases(currentPage);
      }
    },

    //--------------------------- Limit Page Purchases -------------\\
    onPerPageChangePurchases({ currentPerPage }) {
      if (this.limit_purchases !== currentPerPage) {
        this.limit_purchases = currentPerPage;
        this.Get_Purchases(1);
      }
    },

    onSearch_purchases(value) {
      this.search_purchases = value.searchTerm;
      this.Get_Purchases(1);
    },

    //--------------------------- Get Purchases By Provider -------------\\
    Get_Purchases(page) {
      axios
        .get(
          "report/provider_purchases?page=" +
            page +
            "&limit=" +
            this.limit_purchases +
            "&search=" +
            this.search_purchases +
            "&id=" +
            this.$route.params.id
        )
        .then(response => {
          this.purchases = response.data.purchases;
          this.totalRows_purchases = response.data.totalRows;
          this.isLoading = false;
        })
        .catch(response => {
          this.isLoading = false;
        });
    },

    //--------------------------- Event Page Change -------------\\
    PageChangePayments({ currentPage }) {
      if (this.Payment_page !== currentPage) {
        this.Payment_page = currentPage;
        this.Get_Payments(currentPage);
      }
    },

    //--------------------------- Limit Page Payments -------------\\
    onPerPageChangePayments({ currentPerPage }) {
      if (this.limit_payments !== currentPerPage) {
        this.limit_payments = currentPerPage;
        this.Get_Payments(1);
      }
    },

     onSearch_payments(value) {
      this.search_payments = value.searchTerm;
      this.Get_Payments(1);
    },

    //--------------------------- Get Payments By Provider -------------\\
    Get_Payments(page) {
      axios
        .get(
          "/report/provider_payments?page=" +
            page +
            "&limit=" +
            this.limit_payments +
            "&search=" +
            this.search_payments +
            "&id=" +
            this.$route.params.id
        )
        .then(response => {
          this.payments = response.data.payments;
          this.totalRows_payments = response.data.totalRows;
        })
        .catch(response => {});
    },

    //--------------------------- Event Page Change -------------\\
    PageChangeReturns({ currentPage }) {
      if (this.Return_page !== currentPage) {
        this.Return_page = currentPage;
        this.Get_Returns(currentPage);
      }
    },

    //--------------------------- Limit Page Returns -------------\\
    onPerPageChangeReturns({ currentPerPage }) {
      if (this.limit_returns !== currentPerPage) {
        this.limit_returns = currentPerPage;
        this.Get_Returns(1);
      }
    },

     onSearch_return_purchases(value) {
      this.search_return_purchases = value.searchTerm;
      this.Get_Returns(1);
    },

    //--------------------------- Get Returns By Provider -------------\\
    Get_Returns(page) {
      axios
        .get(
          "/report/provider_returns?page=" +
            page +
            "&limit=" +
            this.limit_returns +
            "&search=" +
            this.search_return_purchases +
            "&id=" +
            this.$route.params.id
        )
        .then(response => {
          this.returns_supplier = response.data.returns_supplier;
          this.totalRows_returns = response.data.totalRows;
        })
        .catch(response => {});
    }
  }, //end Methods

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_Reports();
    this.Get_Procurement('purchase_orders', 1);
    this.Get_Procurement('gate_passes', 1);
    this.Get_Procurement('supplier_invoices', 1);
    this.Get_Purchases(1);
    this.Get_Payments(1);
    this.Get_Returns(1);
  }
};
</script>

<style scoped>
.supplier-report-identity {
  border: 1px solid #e2e8f0;
  border-radius: 12px;
}

.opening-balance-panel {
  overflow: hidden;
  border: 1px solid #dbeafe;
  border-radius: 14px;
}

.opening-balance-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.25rem;
  background: linear-gradient(135deg, #1e40af, #2563eb);
  color: #fff;
}

.opening-balance-header h5 {
  display: flex;
  align-items: center;
  gap: .45rem;
  color: #fff;
}

.opening-balance-header small {
  color: #dbeafe;
}

.opening-balance-date {
  padding: .5rem .75rem;
  color: #1e3a8a;
  font-size: .78rem;
}

.opening-balance-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: .85rem;
  padding: 1.15rem;
  background: #f8fafc;
}

.opening-balance-stat {
  display: flex;
  flex-direction: column;
  gap: .3rem;
  padding: .9rem 1rem;
  border: 1px solid #dbeafe;
  border-radius: 10px;
  background: #fff;
}

.opening-balance-stat small {
  color: #64748b;
}

.opening-balance-stat strong {
  color: #1e40af;
  font-size: 1.05rem;
}

.opening-balance-stat--paid strong {
  color: #047857;
}

.opening-balance-stat--remaining strong,
.opening-balance-stat--total strong {
  color: #b45309;
}

.opening-balance-stat--total {
  border-color: #fde68a;
  background: #fffbeb;
}

.opening-balance-history {
  padding: 0 1.15rem 1.15rem;
}

.opening-balance-history h6 {
  margin: .25rem 0 .75rem;
  color: #334155;
}

.product-cell {
  min-width: 180px;
}

.products-summary {
  max-width: 280px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

::v-deep .supplier-detail-table th,
::v-deep .supplier-detail-table td {
  white-space: nowrap;
  vertical-align: middle;
}

@media (max-width: 991px) {
  .opening-balance-summary {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 575px) {
  .opening-balance-header {
    align-items: flex-start;
    flex-direction: column;
  }

  .opening-balance-summary {
    grid-template-columns: 1fr;
  }
}
</style>
