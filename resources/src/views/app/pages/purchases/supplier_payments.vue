<template>
  <div class="main-content">
    <breadcumb page="Supplier Payments" :folder="$t('Purchases')" />

    <b-card class="mb-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
          <h4 class="mb-1">New Supplier Payment</h4>
          <small class="text-muted">The amount is allocated to the oldest outstanding supplier invoices first.</small>
        </div>
        <b-badge variant="primary">FIFO Allocation</b-badge>
      </div>

      <b-row>
        <b-col md="4">
          <b-form-group label="Supplier *">
            <v-select v-model="form.provider_id" :reduce="row => row.id" label="name" :options="providers" @input="loadOutstanding" />
          </b-form-group>
        </b-col>
        <b-col md="2">
          <b-form-group label="Payment Date *">
            <b-form-input v-model="form.payment_date" type="date" />
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group label="Payment Method *">
            <v-select v-model="form.payment_method_id" :reduce="row => row.id" label="name" :options="paymentMethods" />
          </b-form-group>
        </b-col>
        <b-col md="3">
          <b-form-group label="Amount *">
            <b-form-input v-model.number="form.amount" type="number" min="0.01" step="0.01" />
            <small class="text-muted">Outstanding: {{ money(totalOutstanding) }}</small>
          </b-form-group>
        </b-col>
      </b-row>

      <b-row v-if="requiresAccount">
        <b-col md="4">
          <b-form-group label="Account Paid From *">
            <v-select v-model="form.account_id" :reduce="row => row.id" :get-option-label="accountLabel" :options="compatibleAccounts" />
          </b-form-group>
        </b-col>
        <b-col md="4">
          <b-form-group label="Bank Name">
            <b-form-input v-model.trim="form.bank_name" maxlength="191" />
          </b-form-group>
        </b-col>
        <b-col md="4">
          <b-form-group label="Bank Account Number">
            <b-form-input v-model.trim="form.bank_account_number" maxlength="191" />
          </b-form-group>
        </b-col>
      </b-row>

      <b-row v-if="isCheque">
        <b-col md="3"><b-form-group label="Cheque Number *"><b-form-input v-model.trim="form.cheque_number" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Cheque Date *"><b-form-input v-model="form.cheque_date" type="date" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Cheque Status"><b-form-select v-model="form.cheque_status" :options="chequeStatuses" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Clearance Date"><b-form-input v-model="form.clearance_date" type="date" /></b-form-group></b-col>
      </b-row>

      <b-row v-if="isBank && !isCheque">
        <b-col md="6"><b-form-group label="Transaction Reference"><b-form-input v-model.trim="form.transaction_reference" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Transfer Date"><b-form-input v-model="form.transfer_date" type="date" /></b-form-group></b-col>
      </b-row>

      <b-form-group label="Notes">
        <b-form-textarea v-model.trim="form.notes" rows="2" />
      </b-form-group>

      <div class="allocation-preview mb-3">
        <div class="d-flex justify-content-between align-items-center p-3 border-bottom">
          <strong>Allocation Preview</strong>
          <span>{{ preview.length }} invoice(s) · Allocated {{ money(previewAllocated) }}</span>
        </div>
        <div class="table-responsive">
          <table class="table table-sm mb-0">
            <thead><tr><th>Invoice</th><th>Date</th><th>Branch</th><th class="text-right">Outstanding</th><th class="text-right">This Payment</th><th class="text-right">Remaining</th></tr></thead>
            <tbody>
              <tr v-for="row in preview" :key="row.id">
                <td>{{ row.reference }}</td><td>{{ row.date }}</td><td>{{ row.warehouse || '-' }}</td>
                <td class="text-right">{{ money(row.outstanding) }}</td><td class="text-right text-success font-weight-bold">{{ money(row.allocated) }}</td><td class="text-right">{{ money(row.remaining) }}</td>
              </tr>
              <tr v-if="!preview.length"><td colspan="6" class="text-center text-muted py-3">Select a supplier and enter an amount to preview FIFO allocation.</td></tr>
            </tbody>
          </table>
        </div>
      </div>

      <b-alert v-if="Number(form.amount) > totalOutstanding && totalOutstanding >= 0" show variant="danger">
        Payment cannot exceed the supplier's outstanding balance.
      </b-alert>
      <b-button variant="primary" :disabled="saving || !canSubmit" @click="savePayment">
        <span v-if="saving" class="spinner sm spinner-white mr-2"></span>
        <lucide-icon v-else name="check" class="mr-1" /> Save Supplier Payment
      </b-button>
    </b-card>

    <b-card>
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h4 class="mb-0">Supplier Payment History</h4>
        <b-button size="sm" variant="outline-primary" @click="loadPayments"><lucide-icon name="refresh-cw" class="mr-1" /> Refresh</b-button>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th>Reference / Date</th><th>Supplier</th><th>Method / Account</th><th>Bank / Cheque Details</th><th class="text-right">Amount</th><th>Invoices Covered</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <tr v-for="payment in payments" :key="payment.id">
              <td><strong>{{ payment.reference }}</strong><br><small>{{ payment.payment_date }}</small></td>
              <td>{{ payment.provider ? payment.provider.name : '-' }}</td>
              <td>{{ payment.payment_method ? payment.payment_method.name : '-' }}<br><small>{{ payment.account ? accountLabel(payment.account) : '-' }}</small></td>
              <td>
                <span v-if="payment.cheque_number">Cheque: {{ payment.cheque_number }} · {{ payment.cheque_date }} · {{ payment.cheque_status }}</span>
                <span v-else-if="payment.transaction_reference">Transfer: {{ payment.transaction_reference }}</span>
                <span v-else>{{ payment.bank_name || '-' }} {{ payment.bank_account_number || '' }}</span>
              </td>
              <td class="text-right font-weight-bold">{{ money(payment.amount) }}</td>
              <td>
                <div v-for="allocation in payment.allocations" :key="allocation.id">
                  {{ allocation.purchase ? allocation.purchase.Ref : '-' }}: {{ money(allocation.montant) }}
                </div>
              </td>
              <td><b-badge :variant="payment.status === 'cancelled' ? 'danger' : 'success'">{{ payment.status }}</b-badge></td>
              <td>
                <b-button v-if="payment.status !== 'cancelled' && currentUserPermissions.includes('payment_purchases_delete')" size="sm" variant="outline-danger" @click="cancelPayment(payment)">Cancel</b-button>
              </td>
            </tr>
            <tr v-if="!payments.length"><td colspan="8" class="text-center text-muted py-4">No supplier payments found.</td></tr>
          </tbody>
        </table>
      </div>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Supplier Payments" },
  data() {
    const now = new Date();
    const today = new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
    return {
      providers: [], paymentMethods: [], accounts: [], invoices: [], payments: [],
      saving: false,
      chequeStatuses: ["pending", "cleared", "bounced", "cancelled"],
      form: {
        provider_id: null, payment_date: today, amount: null, payment_method_id: null, account_id: null,
        bank_name: "", bank_account_number: "", transaction_reference: "", transfer_date: today,
        cheque_number: "", cheque_date: today, cheque_status: "pending", clearance_date: "", notes: ""
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    selectedMethod() { return this.paymentMethods.find(row => row.id === this.form.payment_method_id) || null; },
    methodName() { return this.selectedMethod ? String(this.selectedMethod.name).toLowerCase() : ""; },
    isCheque() { return this.methodName.includes("cheque") || this.methodName.includes("check"); },
    isBank() { return this.methodName.includes("bank") || this.isCheque || Number(this.form.payment_method_id) === 6; },
    requiresAccount() { return this.isBank || this.methodName.includes("easypaisa") || this.methodName.includes("easy paisa"); },
    compatibleAccounts() {
      if (this.methodName.includes("easypaisa") || this.methodName.includes("easy paisa")) return this.accounts.filter(row => row.account_type === "easypaisa");
      if (this.isBank) return this.accounts.filter(row => row.account_type === "bank");
      return this.accounts;
    },
    totalOutstanding() { return this.invoices.reduce((sum, row) => sum + Number(row.outstanding || 0), 0); },
    preview() {
      let remaining = Math.max(0, Number(this.form.amount || 0));
      return this.invoices.map(row => {
        const allocated = Math.min(remaining, Number(row.outstanding || 0));
        remaining = Math.max(0, remaining - allocated);
        return Object.assign({}, row, { allocated, remaining: Number(row.outstanding || 0) - allocated });
      }).filter(row => row.allocated > 0);
    },
    previewAllocated() { return this.preview.reduce((sum, row) => sum + row.allocated, 0); },
    canSubmit() {
      if (!this.form.provider_id || !this.form.payment_date || !this.form.payment_method_id || Number(this.form.amount) <= 0 || Number(this.form.amount) > this.totalOutstanding) return false;
      if (this.requiresAccount && !this.form.account_id) return false;
      return !this.isCheque || Boolean(this.form.cheque_number && this.form.cheque_date);
    }
  },
  created() { this.loadPayments(); },
  methods: {
    money(value) { return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    accountLabel(row) { return [row.account_name, row.account_num].filter(Boolean).join(" - "); },
    loadPayments() {
      NProgress.start();
      axios.get("supplier-payments", { params: { limit: 100 } }).then(response => {
        this.payments = response.data.payments.data || [];
        this.providers = response.data.providers || [];
        this.paymentMethods = response.data.payment_methods || [];
        this.accounts = response.data.accounts || [];
      }).catch(error => this.showError(error, "Supplier payments could not be loaded.")).finally(() => NProgress.done());
    },
    loadOutstanding() {
      this.invoices = [];
      if (!this.form.provider_id) return;
      axios.get("supplier-payments/outstanding", { params: { provider_id: this.form.provider_id } })
        .then(response => { this.invoices = response.data.invoices || []; })
        .catch(error => this.showError(error, "Outstanding invoices could not be loaded."));
    },
    savePayment() {
      if (!this.canSubmit || this.saving) return;
      this.saving = true;
      NProgress.start();
      axios.post("supplier-payments", this.form).then(() => {
        this.$bvToast.toast("Supplier payment saved and allocated to the oldest invoices.", { title: "Success", variant: "success", solid: true });
        const providerId = this.form.provider_id;
        this.form.amount = null; this.form.notes = ""; this.form.cheque_number = ""; this.form.transaction_reference = "";
        this.loadPayments(); this.form.provider_id = providerId; this.loadOutstanding();
      }).catch(error => this.showError(error, "Supplier payment could not be saved.")).finally(() => { this.saving = false; NProgress.done(); });
    },
    cancelPayment(payment) {
      this.$bvModal.msgBoxConfirm("Cancel this complete supplier payment and reverse all invoice allocations?", { title: "Cancel Supplier Payment", okVariant: "danger", okTitle: "Cancel Payment" })
        .then(confirmed => {
          if (!confirmed) return;
          axios.delete("supplier-payments/" + payment.id).then(() => {
            this.$bvToast.toast("Supplier payment cancelled and allocations reversed.", { title: "Success", variant: "success", solid: true });
            this.loadPayments();
            if (this.form.provider_id === payment.provider_id) this.loadOutstanding();
          }).catch(error => this.showError(error, "Supplier payment could not be cancelled."));
        });
    },
    showError(error, fallback) {
      const errors = error.response && error.response.data && error.response.data.errors;
      const message = errors ? Object.values(errors).reduce((all, rows) => all.concat(rows), []).join(" ") : fallback;
      if (this.$bvToast) this.$bvToast.toast(message, { title: "Failed", variant: "danger", solid: true });
    }
  }
};
</script>

<style scoped>
.allocation-preview { border: 1px solid #dfe5ec; border-radius: 8px; overflow: hidden; }
.allocation-preview thead th { background: #f5f6fa; white-space: nowrap; }
.table td { vertical-align: middle; }
</style>
