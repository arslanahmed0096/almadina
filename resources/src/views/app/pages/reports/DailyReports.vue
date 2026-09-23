<template>
  <div class="main-content daily-report-page">
    <breadcumb page="Daily Reports" :folder="$t('Reports')" />

    <b-card class="filter-card mb-3" no-body>
      <b-card-body>
        <b-row align-v="end">
          <b-col md="2" class="mb-2 report-filter">
            <label class="font-weight-bold">From Date</label>
            <b-form-input v-model="filters.start_date" type="date" @change="fetchReport" />
          </b-col>
          <b-col md="2" class="mb-2 report-filter">
            <label class="font-weight-bold">To Date</label>
            <b-form-input v-model="filters.end_date" type="date" @change="fetchReport" />
          </b-col>
          <b-col md="2" class="mb-2 report-filter">
            <label class="font-weight-bold">Branch</label>
            <b-form-select v-model="filters.warehouse_id" :options="warehouseOptions" @change="fetchReport" />
          </b-col>
          <b-col md="2" class="mb-2 report-filter">
            <label class="font-weight-bold">Supplier</label>
            <b-form-select v-model="filters.provider_id" :options="supplierOptions" @change="fetchReport" />
          </b-col>
          <b-col md="4" class="mb-2 report-actions">
            <b-button variant="primary" :disabled="loading" @click="fetchReport">
              <lucide-icon name="refresh-cw" class="mr-1" /> Refresh
            </b-button>
            <b-button v-if="canExport" variant="outline-primary" @click="printReport">
              <lucide-icon name="printer" class="mr-1" /> Print
            </b-button>
            <b-button v-if="canExport" variant="success" @click="exportCsv">
              <lucide-icon name="file-spreadsheet" class="mr-1" /> Excel CSV
            </b-button>
          </b-col>
        </b-row>
        <b-row>
          <b-col cols="12" class="quick-range">
            <span class="font-weight-bold mr-2">Quick Range:</span>
            <b-button size="sm" variant="outline-primary" @click="setDatePreset('today')">Today</b-button>
            <b-button size="sm" variant="outline-primary" @click="setDatePreset('week')">This Week</b-button>
            <b-button size="sm" variant="outline-primary" @click="setDatePreset('last_month')">Last Month</b-button>
          </b-col>
        </b-row>
      </b-card-body>
    </b-card>

    <div v-if="loading" class="text-center py-5">
      <b-spinner variant="primary" />
    </div>

    <div v-else-if="report" id="daily-report-sheet" class="report-sheet">
      <table class="report-table report-heading">
        <tbody>
          <tr>
            <th class="report-title">DAILY REPORT</th>
            <th>{{ displayDate }}</th>
            <th>{{ report.day_name }}</th>
          </tr>
          <tr>
            <td colspan="3" class="branch-title">{{ report.scope }} · {{ report.supplier_scope }} · {{ currency }}</td>
          </tr>
        </tbody>
      </table>

      <div class="section-title">CASH AVAILABLE</div>
      <table class="report-table">
        <thead><tr><th class="serial">S No</th><th>Description</th><th class="amount">Amount</th></tr></thead>
        <tbody>
          <tr><td></td><td>Opening Register Balance</td><td class="amount">{{ money(report.totals.opening_balance) }}</td></tr>
          <tr><td></td><td>Cash Received from Customers</td><td class="amount">{{ money(report.totals.customer_receipts) }}</td></tr>
          <tr><td></td><td>Cash Received from Supplier Returns</td><td class="amount">{{ money(report.totals.supplier_return_receipts) }}</td></tr>
          <tr><td></td><td>Manual Register Cash In</td><td class="amount">{{ money(report.totals.register_cash_in) }}</td></tr>
          <tr class="total-row"><td colspan="2">TOTAL CASH AVAILABLE FOR PAYMENTS</td><td class="amount">{{ money(report.totals.cash_available) }}</td></tr>
        </tbody>
      </table>

      <div class="section-title">BRANCH SALES</div>
      <table class="report-table">
        <thead><tr><th class="serial">S No</th><th>Branch</th><th class="amount">Gross Sales</th><th class="amount">Returns</th><th class="amount">Net Sales</th></tr></thead>
        <tbody>
          <tr v-for="(row, index) in report.sales_by_branch" :key="row.warehouse_id">
            <td>{{ index + 1 }}</td>
            <td>
              <button type="button" class="branch-link" @click="openBranchDetails(row)">
                {{ row.warehouse }}
                <lucide-icon name="external-link" />
              </button>
            </td>
            <td class="amount">{{ money(row.gross_sales) }}</td>
            <td class="amount">{{ money(row.sale_returns) }}</td><td class="amount">{{ money(row.net_sales) }}</td>
          </tr>
          <tr v-if="!report.sales_by_branch.length"><td colspan="5" class="empty-row">No branches are available.</td></tr>
          <tr class="total-row"><td colspan="2">TOTAL SALES</td><td class="amount">{{ money(report.totals.gross_sales) }}</td><td class="amount">{{ money(report.totals.sale_returns) }}</td><td class="amount">{{ money(report.totals.net_sales) }}</td></tr>
        </tbody>
      </table>

      <div class="section-title">EXPENSES AND PAYMENTS</div>
      <table class="report-table">
        <thead><tr><th class="serial">S No</th><th>Description</th><th>Type / Reference</th><th>Branch</th><th>Method</th><th class="amount">Amount</th></tr></thead>
        <tbody>
          <tr v-for="(row, index) in report.outflows" :key="row.key">
            <td>{{ index + 1 }}</td>
            <td>{{ row.description }}</td>
            <td>{{ row.type }}<span v-if="row.reference"> · {{ row.reference }}</span></td>
            <td>{{ row.warehouse || '-' }}</td>
            <td>{{ row.payment_method }}</td>
            <td class="amount">{{ money(row.amount) }}</td>
          </tr>
          <tr v-if="!report.outflows.length"><td colspan="6" class="empty-row">No expenses or outgoing payments for the selected period.</td></tr>
          <tr class="total-row"><td colspan="5">TOTAL EXPENSES AND PAYMENTS</td><td class="amount">{{ money(report.totals.total_outflows) }}</td></tr>
        </tbody>
      </table>

      <div class="section-title">PERIOD SUMMARY</div>
      <table class="report-table summary-table">
        <tbody>
          <tr><th>Operating Expenses</th><td class="amount">{{ money(report.totals.operating_expenses) }}</td></tr>
          <tr><th>Payments to Suppliers</th><td class="amount">{{ money(report.totals.supplier_payments) }}</td></tr>
          <tr><th>Refunds to Customers</th><td class="amount">{{ money(report.totals.customer_refunds) }}</td></tr>
          <tr><th>Manual Register Cash Out</th><td class="amount">{{ money(report.totals.register_cash_out) }}</td></tr>
          <tr class="total-row"><th>CALCULATED CLOSING CASH</th><td class="amount">{{ money(report.totals.calculated_closing) }}</td></tr>
          <tr><th>Actual Closed Register Balance</th><td class="amount">{{ money(report.totals.actual_register_closing) }}</td></tr>
          <tr><th>Cash Difference</th><td class="amount" :class="amountClass(report.totals.cash_difference)">{{ money(report.totals.cash_difference) }}</td></tr>
          <tr v-if="report.totals.account_balances !== null"><th>TOTAL CASH IN HAND INCLUDING BANK ACCOUNTS</th><td class="amount strong-amount">{{ money(report.totals.account_balances) }}</td></tr>
          <tr><th>TOTAL RECEIVABLE FROM CUSTOMERS</th><td class="amount strong-amount">{{ money(report.totals.customer_receivable) }}</td></tr>
          <tr><th>TOTAL PAYABLE TO SUPPLIERS</th><td class="amount strong-amount">{{ money(report.totals.supplier_payable) }}</td></tr>
        </tbody>
      </table>

      <div class="section-title">PAYMENT METHOD RECONCILIATION</div>
      <table class="report-table">
        <thead><tr><th>Payment Method</th><th class="amount">Received</th><th class="amount">Paid Out</th><th class="amount">Net Movement</th></tr></thead>
        <tbody>
          <tr v-for="row in report.payment_methods" :key="row.payment_method">
            <td>{{ row.payment_method }}</td><td class="amount">{{ money(row.inflow) }}</td><td class="amount">{{ money(row.outflow) }}</td><td class="amount">{{ money(row.net) }}</td>
          </tr>
          <tr v-if="!report.payment_methods.length"><td colspan="4" class="empty-row">No payment activity for the selected period.</td></tr>
        </tbody>
      </table>

      <p class="scope-note">{{ report.balance_scope_note }}</p>
    </div>

    <b-modal
      id="branch-details-modal"
      size="xl"
      scrollable
      hide-footer
      :title="branchDetailsTitle"
      dialog-class="branch-details-dialog"
    >
      <div v-if="branchDetailsLoading" class="text-center py-5">
        <b-spinner variant="primary" />
        <div class="mt-2">Loading branch transactions and receipts...</div>
      </div>

      <div v-else-if="branchDetails">
        <div class="branch-summary mb-3">
          <div><small>Transactions</small><strong>{{ branchDetails.totals.transaction_count }}</strong></div>
          <div><small>Items Quantity</small><strong>{{ money(branchDetails.totals.quantity) }}</strong></div>
          <div><small>Payments Received</small><strong>{{ currency }} {{ money(branchDetails.totals.payments_received) }}</strong></div>
          <div><small>Advance Payments</small><strong class="text-success">{{ currency }} {{ money(branchDetails.totals.advance_received) }}</strong></div>
          <div><small>Previous Balance Received</small><strong class="text-warning">{{ currency }} {{ money(branchDetails.totals.previous_balance_received) }}</strong></div>
          <div><small>Current Outstanding</small><strong :class="branchDetails.totals.current_outstanding > 0 ? 'text-danger' : 'text-success'">{{ currency }} {{ money(branchDetails.totals.current_outstanding) }}</strong></div>
        </div>

        <div class="branch-period-bar mb-3">
          <lucide-icon name="calendar" />
          <span>Report period:</span>
          <strong>{{ formatDisplayDate(branchDetails.start_date) }} to {{ formatDisplayDate(branchDetails.end_date) }}</strong>
        </div>

        <div class="branch-section mb-3">
          <div class="branch-section-heading">
            <div>
              <h5>Sales and Orders Created in This Period</h5>
              <small>Items, quantities, customers, sales staff, payments, and balances.</small>
            </div>
            <b-badge variant="primary">{{ branchDetails.transactions.length }} records</b-badge>
          </div>
          <div class="table-responsive">
          <table class="table table-hover mb-0 branch-detail-table">
            <thead>
              <tr>
                <th>Date / Time</th><th>Type / Order No.</th><th>Customer</th><th>Items Sold</th><th>Sold By</th>
                <th class="amount">Total</th><th class="amount">Paid to Date</th><th class="amount">Received in Period</th><th class="amount">Remaining</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in branchDetails.transactions" :key="row.id">
                <td class="nowrap">{{ formatDisplayDate(row.date) }}<br><small>{{ row.time }}</small></td>
                <td><b-badge :variant="row.transaction_type === 'Order' ? 'success' : 'primary'">{{ row.transaction_type }}</b-badge><br><strong>{{ row.order_number }}</strong><br><small>{{ row.status }} / {{ row.payment_status }}</small></td>
                <td><strong>{{ row.customer_name }}</strong><br><small>{{ row.customer_phone || '-' }}</small><br><small>{{ row.customer_address || '' }}</small></td>
                <td>
                  <div v-for="(item, itemIndex) in row.items" :key="row.id + '-item-' + itemIndex" class="item-line">
                    <span>{{ item.model }}</span><strong>x {{ money(item.quantity) }}</strong>
                  </div>
                  <small v-if="!row.items.length">No item details</small>
                  <div class="item-total">Total Qty: {{ money(row.quantity) }}</div>
                </td>
                <td>{{ row.sold_by }}</td>
                <td class="amount">{{ money(row.total) }}</td>
                <td class="amount">{{ money(row.paid_to_date) }}</td>
                <td class="amount">{{ money(row.received_in_period) }}</td>
                <td class="amount balance-cell">{{ money(row.remaining_balance) }}</td>
              </tr>
              <tr v-if="!branchDetails.transactions.length"><td colspan="9" class="empty-row">No sales or orders were created at this branch in the selected period.</td></tr>
            </tbody>
          </table>
          </div>
        </div>

        <div class="branch-section mb-0">
          <div class="branch-section-heading">
            <div>
              <h5>Customer Payments Received in This Period</h5>
              <small>Includes payments against older invoices, even when the original sale was made in a previous week or month.</small>
            </div>
            <b-badge variant="success">{{ branchDetails.receipts.length }} receipts</b-badge>
          </div>
          <div class="table-responsive">
          <table class="table table-hover mb-0 branch-detail-table">
            <thead>
              <tr>
                <th>Receipt Date / Time</th><th>Payment Type</th><th>Receipt / Order No.</th><th>Customer</th><th>Related Items</th>
                <th>Received By / Method</th><th class="amount">Received</th><th class="amount">Remaining After Receipt</th><th class="amount">Current Remaining</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in branchDetails.receipts" :key="row.id">
                <td class="nowrap">{{ formatDisplayDate(row.receipt_date) }}<br><small>{{ row.receipt_time }}</small></td>
                <td><b-badge :variant="receiptBadgeVariant(row.receipt_type_key)">{{ row.receipt_type }}</b-badge></td>
                <td><small>{{ row.receipt_reference || '-' }}</small><br><strong>{{ row.order_number }}</strong><br><small>Sale date: {{ formatDisplayDate(row.sale_date) }}</small></td>
                <td><strong>{{ row.customer_name }}</strong><br><small>{{ row.customer_phone || '-' }}</small><br><small>{{ row.customer_address || '' }}</small></td>
                <td>
                  <div v-for="(item, itemIndex) in row.items" :key="row.id + '-receipt-item-' + itemIndex" class="item-line">
                    <span>{{ item.model }}</span><strong>x {{ money(item.quantity) }}</strong>
                  </div>
                  <small v-if="!row.items.length">No item details</small>
                </td>
                <td>{{ row.received_by }}<br><small>{{ row.payment_method }}</small></td>
                <td class="amount received-cell">{{ money(row.amount) }}</td>
                <td class="amount balance-cell">{{ money(row.remaining_after_receipt) }}</td>
                <td class="amount balance-cell">{{ money(row.current_remaining) }}</td>
              </tr>
              <tr v-if="!branchDetails.receipts.length"><td colspan="9" class="empty-row">No customer payments were received at this branch in the selected period.</td></tr>
            </tbody>
          </table>
          </div>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Daily Reports" },
  data() {
    const today = new Date();
    const localDate = new Date(today.getTime() - today.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    return {
      loading: false,
      filters: { start_date: localDate, end_date: localDate, warehouse_id: null, provider_id: null },
      warehouses: [],
      suppliers: [],
      report: null,
      currency: "",
      canExport: false,
      branchDetailsLoading: false,
      branchDetails: null,
      selectedBranch: null
    };
  },
  computed: {
    warehouseOptions() {
      return [{ value: null, text: "All Permitted Branches" }].concat(
        this.warehouses.map(branch => ({ value: branch.id, text: branch.name }))
      );
    },
    supplierOptions() {
      return [{ value: null, text: "All Suppliers" }].concat(
        this.suppliers.map(supplier => ({ value: supplier.id, text: supplier.name }))
      );
    },
    displayDate() {
      const start = this.formatDisplayDate(this.report.start_date || this.report.date);
      const end = this.formatDisplayDate(this.report.end_date || this.report.date);
      return start === end ? start : `${start} to ${end}`;
    },
    branchDetailsTitle() {
      return this.selectedBranch
        ? `${this.selectedBranch.warehouse} - Sales, Orders and Customer Receipts`
        : "Branch Sales, Orders and Customer Receipts";
    }
  },
  created() {
    this.fetchReport();
  },
  methods: {
    localDateString(date) {
      return new Date(date.getTime() - date.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    },
    formatDisplayDate(value) {
      const parts = String(value || "").split("-");
      return parts.length === 3 ? `${parts[2]}-${parts[1]}-${parts[0]}` : value;
    },
    setDatePreset(preset) {
      const today = new Date();
      let start = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      let end = new Date(start);

      if (preset === "week") {
        const daysSinceMonday = (start.getDay() + 6) % 7;
        start.setDate(start.getDate() - daysSinceMonday);
      } else if (preset === "last_month") {
        start = new Date(today.getFullYear(), today.getMonth() - 1, 1);
        end = new Date(today.getFullYear(), today.getMonth(), 0);
      }

      this.filters.start_date = this.localDateString(start);
      this.filters.end_date = this.localDateString(end);
      this.fetchReport();
    },
    fetchReport() {
      if (!this.filters.start_date || !this.filters.end_date || this.filters.end_date < this.filters.start_date) {
        if (this.$bvToast) {
          this.$bvToast.toast("The To Date must be on or after the From Date.", {
            title: "Daily Reports",
            variant: "warning",
            solid: true
          });
        }
        return;
      }
      this.loading = true;
      NProgress.start();
      axios.get("report/daily", { params: this.filters })
        .then(response => {
          this.report = response.data.report;
          this.warehouses = response.data.warehouses || [];
          this.suppliers = response.data.suppliers || [];
          this.currency = response.data.currency || "";
          this.canExport = !!response.data.can_export;
        })
        .catch(error => {
          const message = error.response && error.response.status === 403
            ? "You do not have permission to view Daily Reports."
            : "The daily report could not be loaded.";
          if (this.$bvToast) this.$bvToast.toast(message, { title: "Daily Reports", variant: "danger", solid: true });
        })
        .finally(() => {
          this.loading = false;
          NProgress.done();
        });
    },
    money(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 });
    },
    amountClass(value) {
      return Number(value || 0) < 0 ? "text-danger" : Number(value || 0) > 0 ? "text-success" : "";
    },
    receiptBadgeVariant(type) {
      if (type === "advance") return "success";
      if (type === "previous_balance") return "warning";
      return "primary";
    },
    openBranchDetails(branch) {
      this.selectedBranch = branch;
      this.branchDetails = null;
      this.branchDetailsLoading = true;
      this.$bvModal.show("branch-details-modal");

      axios.get("report/daily/branch-details", {
        params: {
          start_date: this.filters.start_date,
          end_date: this.filters.end_date,
          warehouse_id: branch.warehouse_id
        }
      })
        .then(response => {
          this.branchDetails = response.data.details;
        })
        .catch(error => {
          const message = error.response && error.response.status === 403
            ? "You do not have permission to view this branch."
            : "The branch transaction details could not be loaded.";
          if (this.$bvToast) this.$bvToast.toast(message, { title: "Daily Reports", variant: "danger", solid: true });
          this.$bvModal.hide("branch-details-modal");
        })
        .finally(() => {
          this.branchDetailsLoading = false;
        });
    },
    printReport() {
      window.print();
    },
    exportCsv() {
      const rows = [
        ["Daily Report", this.report.start_date, this.report.end_date, this.report.day_name, this.report.scope, this.report.supplier_scope],
        [],
        ["Cash Available", "Amount"],
        ["Opening Register Balance", this.report.totals.opening_balance],
        ["Cash Received from Customers", this.report.totals.customer_receipts],
        ["Supplier Return Receipts", this.report.totals.supplier_return_receipts],
        ["Manual Register Cash In", this.report.totals.register_cash_in],
        ["Total Cash Available", this.report.totals.cash_available],
        [],
        ["Branch", "Gross Sales", "Returns", "Net Sales"],
        ...this.report.sales_by_branch.map(row => [row.warehouse, row.gross_sales, row.sale_returns, row.net_sales]),
        ["Total", this.report.totals.gross_sales, this.report.totals.sale_returns, this.report.totals.net_sales],
        [],
        ["Description", "Type", "Reference", "Branch", "Method", "Amount"],
        ...this.report.outflows.map(row => [row.description, row.type, row.reference || "", row.warehouse || "", row.payment_method, row.amount]),
        ["Total Expenses and Payments", "", "", "", "", this.report.totals.total_outflows],
        [],
        ["Summary", "Amount"],
        ["Calculated Closing Cash", this.report.totals.calculated_closing],
        ["Actual Closed Register Balance", this.report.totals.actual_register_closing],
        ["Cash Difference", this.report.totals.cash_difference],
        ["Customer Receivable", this.report.totals.customer_receivable],
        ["Supplier Payable", this.report.totals.supplier_payable],
        [],
        ["Payment Method", "Received", "Paid Out", "Net Movement"],
        ...this.report.payment_methods.map(row => [row.payment_method, row.inflow, row.outflow, row.net])
      ];
      const escape = value => {
        let text = String(value == null ? "" : value);
        if (/^[=+\-@]/.test(text)) text = `'${text}`;
        return `"${text.replace(/"/g, '""')}"`;
      };
      const csv = "\ufeff" + rows.map(row => row.map(escape).join(",")).join("\r\n");
      const link = document.createElement("a");
      link.href = URL.createObjectURL(new Blob([csv], { type: "text/csv;charset=utf-8" }));
      link.download = `daily-report-${this.report.start_date}-to-${this.report.end_date}.csv`;
      link.click();
      URL.revokeObjectURL(link.href);
    }
  }
};
</script>

<style scoped>
.daily-report-page { padding: 16px; }
.filter-card, .report-sheet { border: 1px solid #d5dde5; border-radius: 10px; background: #fff; }
.report-filter label { min-height: 21px; }
.quick-range { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.quick-range .btn { margin: 0; }
.report-actions { display: flex; align-items: flex-end; justify-content: flex-end; gap: 10px; }
.report-actions .btn { margin: 0; white-space: nowrap; }
.report-sheet { max-width: 1120px; margin: 0 auto; padding: 18px; color: #142b45; }
.report-table { width: 100%; border-collapse: collapse; table-layout: auto; }
.report-table th, .report-table td { border: 1px solid #9ba9b5; padding: 7px 9px; vertical-align: middle; }
.report-table thead th { background: #edf4f6; text-transform: uppercase; font-size: 12px; }
.report-heading th, .report-heading td { border-color: #183955; }
.report-title { font-size: 25px; letter-spacing: .5px; }
.branch-title { text-align: center; font-weight: 700; background: #f4f7f9; }
.section-title { margin-top: 14px; padding: 7px; background: #197f8c; color: white; text-align: center; font-size: 16px; font-weight: 700; letter-spacing: .4px; }
.serial { width: 62px; text-align: center; }
.amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
.total-row th, .total-row td { background: #e8f2f5; font-weight: 800; border-bottom: 2px solid #183955; }
.total-row td:first-child, .total-row th:first-child { text-align: center; }
.summary-table th { width: 75%; text-align: center; }
.strong-amount { font-size: 17px; font-weight: 800; }
.empty-row { text-align: center; color: #788896; padding: 15px !important; }
.scope-note { margin: 12px 0 0; color: #667785; font-size: 12px; }
.branch-link { display: inline-flex; align-items: center; gap: 6px; padding: 0; border: 0; background: transparent; color: #126b78; font-weight: 700; text-align: left; cursor: pointer; }
.branch-link:hover { color: #0b4e58; text-decoration: underline; }
.branch-link svg { width: 14px; height: 14px; }
.branch-summary { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 10px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #f8fafc; }
.branch-summary > div { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.branch-summary small { color: #6b7280; }
.branch-summary strong { color: #111827; font-size: 15px; }
.branch-period-bar { display: flex; align-items: center; gap: 7px; padding: 9px 12px; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; color: #374151; }
.branch-period-bar svg { width: 16px; height: 16px; color: #663399; }
.branch-section { overflow: hidden; border: 1px solid #e5e7eb; border-radius: 8px; background: #fff; }
.branch-section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 14px; border-bottom: 1px solid #e5e7eb; }
.branch-section-heading h5 { margin: 0; color: #111827; font-size: 15px; font-weight: 700; }
.branch-section-heading small { color: #6b7280; }
.branch-detail-table { min-width: 1280px; color: #374151; font-size: 12px; }
.branch-detail-table thead th { background: #f3f4f6; color: #374151; vertical-align: middle; white-space: nowrap; border-top: 0; }
.branch-detail-table td { vertical-align: middle; }
.branch-detail-table .nowrap { white-space: nowrap; }
.item-line { display: flex; justify-content: space-between; gap: 12px; min-width: 220px; padding: 2px 0; border-bottom: 1px dashed #dce4e9; }
.item-line:last-of-type { border-bottom: 0; }
.item-total { margin-top: 4px; color: #111827; font-weight: 700; }
.received-cell { color: #137447; font-weight: 800; }
.balance-cell { color: #9a3412; font-weight: 800; }
@media (max-width: 767px) {
  .daily-report-page { padding: 8px; }
  .report-actions { justify-content: flex-start; flex-wrap: wrap; }
  .report-sheet { padding: 8px; overflow-x: auto; }
  .report-table { min-width: 680px; }
  .branch-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .branch-section-heading { align-items: flex-start; }
}
@media print {
  .filter-card, .breadcrumb, .breadcumb, nav, header, aside { display: none !important; }
  .daily-report-page { padding: 0 !important; }
  .report-sheet { max-width: none; border: 0; padding: 0; }
  .section-title { background: #197f8c !important; color: #fff !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
  .report-table th, .report-table td { padding: 4px 6px; font-size: 10px; }
  .report-title { font-size: 20px !important; }
}
</style>

<style>
.branch-details-dialog {
  width: 96vw !important;
  max-width: 1600px !important;
}
.branch-details-dialog .modal-content {
  border-radius: 8px;
}
.branch-details-dialog .modal-body {
  padding: 16px;
}
@media print {
  body * { visibility: hidden !important; }
  #daily-report-sheet, #daily-report-sheet * { visibility: visible !important; }
  #daily-report-sheet { position: absolute; left: 0; top: 0; width: 100%; }
}
</style>
