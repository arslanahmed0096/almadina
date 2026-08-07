<template>
  <div class="main-content">
    <breadcumb :page="$t('Shipments')" :folder="$t('Sales')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <div v-else>
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="shipments"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{
        placeholder: $t('Search_this_table'),
        enabled: true,
      }"
        :select-options="{ 
          enabled: true ,
          clearSelectionText: '',
        }"
        @on-selected-rows-change="selectionChanged"
        :pagination-options="{
        enabled: true,
        mode: 'records',
        nextLabel: 'next',
        prevLabel: 'prev',
      }"
        :styleClass="showDropdown?'tableOne table-hover vgt-table full-height':'tableOne table-hover vgt-table non-height'"
      >
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="Shipments_pdf()" size="sm" variant="outline-success ripple m-1">
            <lucide-icon name="copy" /> PDF
          </b-button>
           <vue-excel-xlsx
              class="btn btn-sm btn-outline-danger ripple m-1"
              :data="shipments"
              :columns="columns"
              :file-name="'shipments'"
              :file-type="'xlsx'"
              :sheet-name="'shipments'"
              >
              <lucide-icon name="file-spreadsheet" /> EXCEL
          </vue-excel-xlsx>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a
              @click="Edit_Shipment(props.row)"
              v-if="currentUserPermissions && currentUserPermissions.includes('shipment')"
              title="Edit"
              class="cursor-pointer"
              v-b-tooltip.hover
            >
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a
              title="Delete"
              class="cursor-pointer"
              v-b-tooltip.hover
              v-if="currentUserPermissions && currentUserPermissions.includes('shipment')"
              @click="Remove_Shipment(props.row.id)"
            >
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>

          <div v-else-if="props.column.field == 'status'">
            <span
              v-if="props.row.status == 'ordered'"
              class="badge badge-outline-warning"
            >{{$t('Ordered')}}</span>

            <span
              v-else-if="props.row.status == 'packed'"
              class="badge badge-outline-info"
            >{{$t('Packed')}}</span>

            <span
              v-else-if="props.row.status == 'shipped'"
              class="badge badge-outline-secondary"
            >{{$t('Shipped')}}</span>

             <span
              v-else-if="props.row.status == 'delivered'"
              class="badge badge-outline-success"
            >{{$t('Delivered')}}</span>

            <span v-else class="badge badge-outline-danger">{{$t('Cancelled')}}</span>
          </div>
        </template>
      </vue-good-table>
    </div>

    <!-- Modal Edit Shipment -->
    <validation-observer ref="shipment_ref">
      <b-modal hide-footer size="xl" dialog-class="shipment-modal-wide" id="modal_shipment" title="Edit Shipment">
        <b-form @submit.prevent="Submit_Shipment">
          <div v-if="shipmentEligibilityLoading" class="text-center py-5">
            <div class="spinner spinner-primary"></div>
            <div class="mt-3 text-muted">Checking item payment and credit eligibility...</div>
          </div>

          <b-row v-else>
            <b-col md="12" v-if="shipmentValidationError">
              <b-alert show variant="danger" class="mb-3">
                <lucide-icon name="alert-circle" class="mr-1" />
                {{ shipmentValidationError }}
              </b-alert>
            </b-col>

            <b-col md="12">
              <div class="shipment-credit-summary mb-3">
                <div><small>Sale Status</small><strong>{{ shipmentEligibility.sale_status || 'Ordered' }}</strong></div>
                <div><small>Available Customer Credit</small><strong>{{ shipmentCreditUnlimited ? 'Unlimited' : shipmentMoney(shipmentAvailableCredit) }}</strong></div>
                <div><small>Selected Credit</small><strong>{{ shipmentMoney(selectedShipmentCredit) }}</strong></div>
                <div><small>Remaining Credit</small><strong>{{ shipmentCreditUnlimited ? 'Unlimited' : shipmentMoney(remainingShipmentCredit) }}</strong></div>
              </div>
              <div v-if="shipmentHasZeroCreditLimit" class="shipment-credit-limit-action mb-3">
                <div>
                  <strong>This customer has no credit limit.</strong>
                  <div class="small text-muted">Unpaid items cannot be shipped until an authorized user adds a credit limit.</div>
                </div>
                <div v-if="canUpdateCustomerCreditLimit" class="shipment-credit-limit-controls">
                  <b-button v-if="!creditLimitEditorOpen" type="button" size="sm" variant="outline-primary" @click="openCreditLimitEditor">Add Credit Limit</b-button>
                  <template v-else>
                    <b-input-group size="sm" :prepend="currentUser && currentUser.currency ? currentUser.currency : ''">
                      <b-form-input v-model="creditLimitAmount" type="number" min="0.01" step="0.01" placeholder="Enter credit limit" :disabled="creditLimitSaving" />
                    </b-input-group>
                    <b-button type="button" size="sm" variant="primary" :disabled="creditLimitSaving" @click="saveInitialCreditLimit">
                      <span v-if="creditLimitSaving" class="spinner sm spinner-white mr-1"></span>Save
                    </b-button>
                    <b-button type="button" size="sm" variant="light" :disabled="creditLimitSaving" @click="closeCreditLimitEditor">Cancel</b-button>
                  </template>
                </div>
                <small v-else class="text-muted">You do not have permission to add customer credit.</small>
                <div v-if="creditLimitError" class="text-danger small w-100">{{ creditLimitError }}</div>
              </div>
            </b-col>

            <b-col md="12">
              <div class="table-responsive shipment-items-table mb-3">
                <table class="table table-hover mb-0">
                  <thead>
                    <tr>
                      <th class="text-center">Select</th>
                      <th>Product</th>
                      <th>Code</th>
                      <th class="text-right">Qty</th>
                      <th class="text-right">Item Total</th>
                      <th class="text-right">Paid</th>
                      <th class="text-right">Outstanding</th>
                      <th>Eligibility</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="item in shipmentItems" :key="item.sale_detail_id" :class="{'shipment-item-eligible': item.eligible, 'shipment-item-disabled': shipmentItemDisabled(item)}">
                      <td class="text-center align-middle">
                        <b-form-checkbox v-model="selectedShipmentItemIds" :value="item.sale_detail_id" :disabled="shipmentItemDisabled(item)" :aria-label="'Select ' + item.product_name" />
                      </td>
                      <td class="align-middle font-weight-bold">{{ item.product_name }}</td>
                      <td class="align-middle">{{ item.product_code || '—' }}</td>
                      <td class="text-right align-middle">{{ formatNumber(item.quantity, 2) }}</td>
                      <td class="text-right align-middle">{{ shipmentMoney(item.item_total) }}</td>
                      <td class="text-right align-middle text-success">{{ shipmentMoney(item.paid_amount) }}</td>
                      <td class="text-right align-middle" :class="item.outstanding_amount > 0 ? 'text-danger' : 'text-success'">{{ shipmentMoney(item.outstanding_amount) }}</td>
                      <td class="align-middle shipment-eligibility-cell">
                        <b-badge :variant="shipmentItemDisabled(item) ? 'secondary' : 'success'">{{ shipmentItemDisabled(item) ? 'Unavailable' : 'Eligible for shipment' }}</b-badge>
                        <button type="button" class="btn btn-link btn-sm p-0 ml-1" v-b-tooltip.hover :title="shipmentItemTooltip(item)" aria-label="Shipment eligibility details">
                          <lucide-icon name="info" />
                        </button>
                        <div class="small mt-1">{{ shipmentItemMessage(item) }}</div>
                      </td>
                    </tr>
                    <tr v-if="!shipmentItems.length"><td colspan="8" class="text-center text-muted py-4">All sale items have already been shipped.</td></tr>
                  </tbody>
                </table>
              </div>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('delivered_to')">
                <b-form-input
                  label="delivered_to"
                  v-model="shipment.delivered_to"
                  :placeholder="$t('delivered_to')"
                ></b-form-input>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Adress')">
                <textarea
                  v-model="shipment.shipping_address"
                  rows="4"
                  class="form-control"
                  :placeholder="$t('Enter_Address')"
                ></textarea>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Please_provide_any_details')">
                <textarea
                  v-model="shipment.shipping_details"
                  rows="4"
                  class="form-control"
                  :placeholder="$t('Please_provide_any_details')"
                ></textarea>
              </b-form-group>
            </b-col>

            <b-col md="12" class="mt-3">
              <b-button
                variant="primary"
                type="submit"
                :disabled="SubmitProcessing || !canSubmitShipment"
              >
                <span v-if="SubmitProcessing" class="spinner sm spinner-white mr-2"></span>
                <lucide-icon v-else class="me-2 font-weight-bold" name="check" />
                {{ SubmitProcessing ? ($t('Saving') || 'Saving...') : $t('submit') }}
              </b-button>
              <small v-if="!selectedShipmentItemIds.length && shipmentItems.length" class="text-muted ml-2">Select at least one eligible item.</small>
              <div v-once class="typo__p" v-if="SubmitProcessing">
                <div class="spinner sm spinner-primary mt-3"></div>
              </div>
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
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  metaInfo: {
    title: "Shipment"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      shipmentEligibilityLoading: false,
      shipmentValidationError: "",
      shipmentEligibility: {},
      selectedShipmentItemIds: [],
      creditLimitEditorOpen: false,
      creditLimitAmount: "",
      creditLimitSaving: false,
      creditLimitError: "",
      price_format_key: null,
      ImportProcessing: false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      totalRows: "",
      search: "",
      limit: "10",
      shipments: [],
      shipment: {}
    };
  },

  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),

    shipmentItems() {
      return Array.isArray(this.shipmentEligibility.items) ? this.shipmentEligibility.items : [];
    },

    shipmentCreditUnlimited() {
      return !!(this.shipmentEligibility.credit && this.shipmentEligibility.credit.unlimited);
    },

    shipmentAvailableCredit() {
      return Number((this.shipmentEligibility.credit || {}).available_credit || 0);
    },

    shipmentCreditLimit() {
      return Number((this.shipmentEligibility.credit || {}).credit_limit || 0);
    },

    shipmentHasZeroCreditLimit() {
      return !!this.shipmentEligibility.customer && this.shipmentCreditLimit <= 0;
    },

    canUpdateCustomerCreditLimit() {
      return Array.isArray(this.currentUserPermissions)
        && this.currentUserPermissions.includes("customer_credit_limit_update");
    },

    selectedShipmentCredit() {
      return this.shipmentItems.reduce((total, item) => {
        return this.selectedShipmentItemIds.includes(item.sale_detail_id)
          ? total + Number(item.outstanding_amount || 0)
          : total;
      }, 0);
    },

    remainingShipmentCredit() {
      if (this.shipmentCreditUnlimited) return null;
      return Math.max(this.shipmentAvailableCredit - this.selectedShipmentCredit, 0);
    },

    canSubmitShipment() {
      return this.selectedShipmentItemIds.length > 0 && (
        this.shipmentCreditUnlimited ||
        this.selectedShipmentCredit <= this.shipmentAvailableCredit + 0.005
      );
    },
    columns() {
      return [
        {
          label: this.$t("date"),
          field: "date",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("shipment_ref"),
          field: "shipment_ref",
          tdClass: "text-left",
          thClass: "text-left"
        },

        {
          label: this.$t("sale_ref"),
          field: "sale_ref",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Customer"),
          field: "customer_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("warehouse"),
          field: "warehouse_name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Status"),
          field: "status",
          tdClass: "text-left",
          thClass: "text-left"
        },

        {
          label: this.$t("Action"),
          field: "actions",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    }
  },

  methods: {
    formatNumber(number, dec) {
      const numeric = Number(number || 0);
      return Number.isFinite(numeric) ? numeric.toFixed(dec) : Number(0).toFixed(dec);
    },

    shipmentMoney(amount) {
      const symbol = this.currentUser && this.currentUser.currency ? this.currentUser.currency : "";
      const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
      if (key) this.price_format_key = key;
      const value = formatPriceDisplayHelper(Number(amount || 0), 2, key || null);
      return symbol ? `${symbol} ${value}` : value;
    },

    shipmentItemDisabled(item) {
      if (!item || !item.eligible) return true;
      if (this.selectedShipmentItemIds.includes(item.sale_detail_id)) return false;
      if (Number(item.outstanding_amount || 0) <= 0 || this.shipmentCreditUnlimited) return false;
      return Number(item.outstanding_amount || 0) > Number(this.remainingShipmentCredit || 0) + 0.005;
    },

    openCreditLimitEditor() {
      if (!this.canUpdateCustomerCreditLimit) return;
      const credit = this.shipmentEligibility.credit || {};
      const largestOutstanding = this.shipmentItems.reduce((maximum, item) => {
        return Math.max(maximum, Number(item.outstanding_amount || 0));
      }, 0);
      this.creditLimitAmount = (Number(credit.current_usage || 0) + largestOutstanding).toFixed(2);
      this.creditLimitError = "";
      this.creditLimitEditorOpen = true;
    },

    closeCreditLimitEditor() {
      this.creditLimitEditorOpen = false;
      this.creditLimitAmount = "";
      this.creditLimitError = "";
    },

    saveInitialCreditLimit() {
      const customer = this.shipmentEligibility.customer || {};
      const amount = Number(this.creditLimitAmount);
      if (!customer.id || !Number.isFinite(amount) || amount <= 0) {
        this.creditLimitError = "Enter a credit limit greater than zero.";
        return;
      }

      this.creditLimitSaving = true;
      this.creditLimitError = "";
      axios.post(`/customers/${customer.id}/initial-credit-limit`, { credit_limit: amount })
        .then(response => {
          this.makeToast("success", response.data.message, this.$t("Success"));
          this.closeCreditLimitEditor();
          this.shipmentEligibilityLoading = true;
          return axios.get("/shipments/" + this.shipment.sale_id);
        })
        .then(response => {
          const shipmentId = this.shipment.id;
          this.shipment = Object.assign({}, this.shipment, response.data.shipment || {}, { id: shipmentId });
          this.shipmentEligibility = response.data.eligibility || {};
          this.selectedShipmentItemIds = [];
        })
        .catch(error => {
          this.creditLimitError = this.shipmentErrorMessage(error);
        })
        .finally(() => {
          this.creditLimitSaving = false;
          this.shipmentEligibilityLoading = false;
        });
    },

    shipmentItemMessage(item) {
      if (!item) return "";
      if (item.eligible && this.shipmentItemDisabled(item)) {
        return "Cannot select this item because the other selected items use the remaining available credit.";
      }
      if (item.eligibility_type === "credit") {
        return "This item can be shipped within the customer’s available credit.";
      }
      return item.eligibility_message || "";
    },

    shipmentItemTooltip(item) {
      const effectiveAvailable = item && item.eligible && this.shipmentItemDisabled(item)
        ? Number(this.remainingShipmentCredit || 0)
        : this.shipmentAvailableCredit;
      const available = this.shipmentCreditUnlimited ? "Unlimited" : this.shipmentMoney(effectiveAvailable);
      const additional = this.shipmentCreditUnlimited
        ? 0
        : Math.max(Number(item.outstanding_amount || 0) - effectiveAvailable, Number(item.additional_required || 0));
      return `Outstanding amount: ${this.shipmentMoney(item.outstanding_amount)} | ` +
        `Available credit: ${available} | ` +
        `Additional payment or credit required: ${this.shipmentMoney(additional)}`;
    },

    shipmentErrorMessage(error) {
      const response = error && error.response ? error.response.data : null;
      if (response && response.errors) {
        const first = Object.keys(response.errors)[0];
        const messages = response.errors[first];
        if (Array.isArray(messages) && messages.length) return messages[0];
      }
      return (response && response.message) || "Unable to update the shipment.";
    },

    //------------- Submit Validation Edit shipment
    Submit_Shipment() {
      if (this.SubmitProcessing) {
        return;
      }

      if (!this.canSubmitShipment) {
        this.shipmentValidationError = "Select at least one eligible, unshipped item.";
        return;
      }

      this.SubmitProcessing = true;
      this.shipmentValidationError = "";
      this.$refs.shipment_ref.validate().then(success => {
        if (!success) {
          this.SubmitProcessing = false;
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_Shipment();
        }
      }).catch(() => {
        this.SubmitProcessing = false;
      });
    },

    //------ update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    //---- Event Page Change
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_shipments(currentPage);
      }
    },

    //---- Event Per Page Change
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_shipments(1);
      }
    },

    //---- Event Select Rows
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach((row, index) => {
        this.selectedIds.push(row.id);
      });
    },

    //------ Event Sort Change
    onSortChange(params) {
      this.updateParams({
        sort: {
          type: params[0].type,
          field: params[0].field
        }
      });
      this.Get_shipments(this.serverParams.page);
    },

    //------ Event Search
    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_shipments(this.serverParams.page);
    },

    //------ Event Validation State
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

    //--------------------------------- Shipments PDF -------------------------------\\
    Shipments_pdf() {
      var self = this;
      let pdf = new jsPDF("p", "pt");

      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) {}
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        self.$t("date"),
        self.$t("ShipmentRef") || "Shipment Ref",
        self.$t("Reference") || "Sale Ref",
        self.$t("Customer"),
        self.$t("warehouse"),
        self.$t("Status")
      ];

      const body = (self.shipments || []).map(shipment => ([
        shipment.date,
        shipment.shipment_ref,
        shipment.sale_ref,
        shipment.customer_name,
        shipment.warehouse_name,
        shipment.status
      ]));

      const marginX = 40;
      const rtl =
        (self.$i18n && ['ar','fa','ur','he'].includes(self.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body: body,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          // Header banner
          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');

          // Title
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = self.$t('Shipments') || 'Shipments';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          // Reset text color
          pdf.setTextColor(33);

          // Footer page numbers
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Shipments.pdf");
    },

    //--------------------------------------- Get All Shipments -------------------------------\\
    Get_shipments(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "shipments?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.shipments = response.data.shipments;
          this.totalRows = response.data.totalRows;

          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },


    //------------------------------ Show Modal (Edit shipment) -------------------------------\\
    Edit_Shipment(shipment) {
      NProgress.start();
      NProgress.set(0.1);
      this.reset_Form();
      this.shipment = Object.assign({}, shipment);
      this.shipmentEligibilityLoading = true;
      this.$bvModal.show("modal_shipment");
      axios.get("/shipments/" + shipment.sale_id)
        .then(response => {
          this.shipment = Object.assign({}, this.shipment, response.data.shipment || {});
          this.shipment.id = shipment.id;
          this.shipmentEligibility = response.data.eligibility || {};
          this.shipmentEligibilityLoading = false;
          NProgress.done();
        })
        .catch(error => {
          this.shipmentEligibilityLoading = false;
          this.shipmentValidationError = this.shipmentErrorMessage(error);
          NProgress.done();
        });
     
    },

    //----------------------- Update_Shipment ---------------------------\\
    Update_Shipment() {
      var self = this;
      self.SubmitProcessing = true;
      axios
        .put("shipments/" + self.shipment.id, {
          sale_id: self.shipment.sale_id,
          shipping_address: self.shipment.shipping_address,
          delivered_to: self.shipment.delivered_to,
          shipping_details: self.shipment.shipping_details,
          sale_detail_ids: self.selectedShipmentItemIds
        })
        .then(response => {
          this.makeToast(
            "success",
            response.data.message || this.$t("Updated_in_successfully"),
            this.$t("Success")
          );
          Fire.$emit("event_update_shipment");
          self.SubmitProcessing = false;
        })
        .catch(error => {
          this.shipmentValidationError = this.shipmentErrorMessage(error);
          this.makeToast("danger", this.shipmentValidationError, this.$t("Failed"));
          self.SubmitProcessing = false;
        });
    },

    //-------------------------------- Reset Form -------------------------------\\
    reset_Form() {
      this.shipment = {
        id: "",
        date: "",
        Ref: "",
        sale_id: "",
        attachment: "",
        delivered_to: "",
        shipping_address: "",
        status: "",
        shipping_details: ""
      };
      this.shipmentEligibility = {};
      this.selectedShipmentItemIds = [];
      this.creditLimitEditorOpen = false;
      this.creditLimitAmount = "";
      this.creditLimitSaving = false;
      this.creditLimitError = "";
      this.shipmentValidationError = "";
      this.shipmentEligibilityLoading = false;
    },

    //------------------------------- Remove shipment -------------------------------\\
    Remove_Shipment(id) {
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
          axios
            .delete("shipments/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );
              Fire.$emit("event_delete_shipment");
            })
            .catch(() => {
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  }, // END METHODS

  //----------------------------- Created function-------------------

  created: function() {
    this.Get_shipments(1);

    Fire.$on("event_update_shipment", () => {
      setTimeout(() => {
        this.Get_shipments(this.serverParams.page);
        this.$bvModal.hide("modal_shipment");
      }, 500);
    });

    Fire.$on("event_delete_shipment", () => {
      setTimeout(() => {
        this.Get_shipments(this.serverParams.page);
      }, 500);
    });
  }
};
</script>

<style>
.shipment-credit-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  padding: 12px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  background: #f8fafc;
}
.shipment-modal-wide {
  width: 96vw !important;
  max-width: 1500px !important;
}
.shipment-credit-summary > div {
  display: flex;
  flex-direction: column;
  gap: 3px;
}
.shipment-credit-summary small { color: #6b7280; }
.shipment-credit-summary strong { color: #111827; }
.shipment-credit-limit-action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 12px;
  border: 1px solid #f6c86b;
  border-radius: 8px;
  background: #fffbeb;
}
.shipment-credit-limit-controls {
  display: flex;
  align-items: center;
  gap: 8px;
}
.shipment-credit-limit-controls .input-group { width: 240px; }
.shipment-items-table {
  border: 1px solid #e5e7eb;
  border-radius: 8px;
}
.shipment-items-table thead th {
  white-space: nowrap;
  background: #f3f4f6;
  border-top: 0;
}
.shipment-item-eligible { box-shadow: inset 3px 0 0 #28a745; }
.shipment-item-disabled { background: #f8f9fa; }
.shipment-eligibility-cell { min-width: 260px; }
@media (max-width: 767px) {
  .shipment-credit-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
</style>
