<template>
  <div class="main-content">
    <breadcumb page="Stock Transfer Ledger" folder="Transfers" />

    <b-card class="mb-3 ledger-filter-card">
      <b-row align-v="end">
        <b-col md="2"><b-form-group label="From"><b-form-input v-model="filters.date_from" type="date" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="To"><b-form-input v-model="filters.date_to" type="date" /></b-form-group></b-col>
        <b-col md="3"><b-form-group label="Company"><v-select v-model="filters.company_id" :options="meta.companies" label="name" :reduce="o => o.id" placeholder="All companies" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Payment status"><b-form-select v-model="filters.payment_status" :options="paymentStatuses" /></b-form-group></b-col>
        <b-col md="3" class="mb-3 text-md-right">
          <b-button variant="primary" class="mr-1" :disabled="loading" @click="loadAll"><lucide-icon name="refresh-cw" /> Load</b-button>
          <b-dropdown right variant="outline-success" text="Export" :disabled="exporting">
            <b-dropdown-item @click="download('excel')">Excel</b-dropdown-item>
            <b-dropdown-item @click="download('pdf')">PDF</b-dropdown-item>
          </b-dropdown>
        </b-col>
      </b-row>
      <b-row class="mt-3">
        <b-col md="6"><b-form-group label="Source warehouse"><v-select class="filter-warehouse-select" v-model="filters.source_warehouse_id" :options="meta.warehouses" label="name" :reduce="o => o.id" placeholder="All source warehouses" /></b-form-group></b-col>
        <b-col md="6"><b-form-group label="Destination warehouse"><v-select class="filter-warehouse-select" v-model="filters.destination_warehouse_id" :options="meta.warehouses" label="name" :reduce="o => o.id" placeholder="All destination warehouses" /></b-form-group></b-col>
      </b-row>
    </b-card>

    <b-alert v-if="error" show variant="danger" dismissible @dismissed="error = ''">{{ error }}</b-alert>
    <div v-if="loading && !loaded" class="loading_page spinner spinner-primary"></div>

    <template v-else>
      <b-row class="mb-3">
        <b-col md="3" v-for="card in summaryCards" :key="card.label" class="mb-2">
          <b-card :class="'summary-card border-left-' + card.variant">
            <small class="text-muted text-uppercase">{{ card.label }}</small>
            <div class="h4 mb-0">{{ money(card.value) }}</div>
          </b-card>
        </b-col>
      </b-row>

      <b-tabs card content-class="pt-3" v-model="activeTab">
        <b-tab title="Transfers">
          <div class="table-responsive">
            <table class="table table-hover ledger-table">
              <thead><tr><th>Date</th><th>Transfer #</th><th>From</th><th>To</th><th>Company</th><th class="num">Quantity</th><th class="num">Transfer Value</th><th class="num">Paid</th><th class="num">Outstanding</th><th>Status</th><th></th></tr></thead>
              <tbody>
                <tr v-for="row in transfers" :key="row.id">
                  <td>{{ date(row.date) }}</td><td><strong>{{ row.reference }}</strong></td><td class="warehouse-cell">{{ row.from_warehouse }}</td><td class="warehouse-cell">{{ row.to_warehouse }}</td><td>{{ row.company || '-' }}</td>
                  <td class="num">{{ qty(row.quantity) }}</td><td class="num">{{ money(row.transfer_value) }}</td><td class="num text-success">{{ money(row.paid_amount) }}</td><td class="num text-danger font-weight-bold">{{ money(row.outstanding_balance) }}</td>
                  <td><b-badge :variant="statusVariant(row.payment_status)">{{ label(row.payment_status) }}</b-badge></td>
                  <td><b-button size="sm" variant="outline-primary" @click="showTransfer(row.id)"><lucide-icon name="eye" /></b-button></td>
                </tr>
                <tr v-if="!transfers.length"><td colspan="11" class="text-center text-muted py-4">No stock transfers match these filters.</td></tr>
              </tbody>
            </table>
          </div>
          <b-pagination v-if="pagination.total > pagination.per_page" v-model="pagination.page" :total-rows="pagination.total" :per-page="pagination.per_page" @change="loadTransfers" />
        </b-tab>

        <b-tab title="Company / Warehouse Ledger">
          <b-row class="mb-3">
            <b-col md="3"><v-select v-model="ledgerFilters.company_id" :options="meta.companies" label="name" :reduce="o => o.id" placeholder="Select company" @input="loadLedger" /></b-col>
            <b-col md="3"><v-select v-model="ledgerFilters.warehouse_id" :options="externalWarehouses" label="name" :reduce="o => o.id" placeholder="All company warehouses" @input="loadLedger" /></b-col>
            <b-col md="6" class="text-right"><span class="mr-3">Opening: <strong>{{ money(ledger.opening_balance) }}</strong></span>Closing: <strong>{{ money(ledger.closing_balance) }}</strong></b-col>
          </b-row>
          <div class="table-responsive"><table class="table table-striped ledger-table">
            <thead><tr><th>Date</th><th>Transaction</th><th>Reference</th><th>Company</th><th>Warehouse</th><th class="num">Debit</th><th class="num">Credit</th><th class="num">Balance</th><th>Notes</th></tr></thead>
            <tbody><tr v-for="row in ledger.rows" :key="row.id"><td>{{ date(row.date) }}</td><td>{{ label(row.type) }}</td><td>{{ row.reference }}</td><td>{{ row.company }}</td><td>{{ row.warehouse || '-' }}</td><td class="num">{{ money(row.debit) }}</td><td class="num text-success">{{ money(row.credit) }}</td><td class="num font-weight-bold" :class="row.balance > 0 ? 'text-danger' : 'text-success'">{{ money(row.balance) }}</td><td>{{ row.notes || '-' }}</td></tr><tr v-if="!ledger.rows.length"><td colspan="9" class="text-center text-muted py-4">No ledger entries found.</td></tr></tbody>
          </table></div>
        </b-tab>

        <b-tab title="Payments & Advances">
          <div class="d-flex justify-content-between mb-3"><div class="text-muted">Unallocated amounts remain available as advances and are applied once only.</div><b-button variant="success" @click="openPayment"><lucide-icon name="plus" /> Receive Payment</b-button></div>
          <div class="table-responsive"><table class="table table-hover ledger-table">
            <thead><tr><th>Date</th><th>Reference</th><th>Company</th><th>Warehouse</th><th>Method</th><th class="num">Amount</th><th class="num">Allocated</th><th class="num">Available Advance</th><th>Received By</th></tr></thead>
            <tbody><tr v-for="row in payments" :key="row.id"><td>{{ date(row.payment_date) }}</td><td><strong>{{ row.reference }}</strong><small class="d-block text-muted">{{ row.external_reference }}</small></td><td>{{ row.company && row.company.name }}</td><td>{{ row.warehouse ? row.warehouse.name : 'Company level' }}</td><td>{{ row.payment_method && row.payment_method.name }}</td><td class="num">{{ money(row.amount) }}</td><td class="num">{{ money(row.allocated_amount) }}</td><td class="num text-success font-weight-bold">{{ money(row.unallocated_amount) }}</td><td>{{ row.receiver && row.receiver.username }}</td></tr><tr v-if="!payments.length"><td colspan="9" class="text-center text-muted py-4">No payments recorded.</td></tr></tbody>
          </table></div>
        </b-tab>

        <b-tab title="Summary">
          <b-row><b-col lg="6" class="mb-3"><h5>Company balances</h5><table class="table table-sm"><thead><tr><th>Company</th><th class="num">Transferred</th><th class="num">Outstanding</th><th class="num">Advance</th></tr></thead><tbody><tr v-for="row in summary.companies" :key="row.company_id"><td>{{ row.company }}</td><td class="num">{{ money(row.transfer_value) }}</td><td class="num text-danger">{{ money(row.outstanding) }}</td><td class="num text-success">{{ money(row.available_advance) }}</td></tr></tbody></table></b-col>
          <b-col lg="6"><h5>Warehouse balances</h5><table class="table table-sm"><thead><tr><th>Warehouse</th><th>Company</th><th class="num">Transferred</th><th class="num">Outstanding</th></tr></thead><tbody><tr v-for="row in summary.warehouses" :key="row.warehouse_id"><td>{{ row.warehouse }}</td><td>{{ row.company }}</td><td class="num">{{ money(row.transfer_value) }}</td><td class="num text-danger">{{ money(row.outstanding) }}</td></tr></tbody></table></b-col></b-row>
        </b-tab>

        <b-tab title="Companies & Warehouses">
          <div class="d-flex justify-content-between mb-3"><div class="text-muted">Assign each third-party warehouse to one external company.</div><b-button variant="primary" @click="openCompany"><lucide-icon name="plus" /> Add Company</b-button></div>
          <b-row><b-col lg="5"><h5>External companies</h5><table class="table table-sm"><thead><tr><th>Name</th><th>Code</th><th class="num">Opening</th><th>Warehouses</th></tr></thead><tbody><tr v-for="company in meta.companies" :key="company.id"><td>{{ company.name }}</td><td>{{ company.code || '-' }}</td><td class="num">{{ money(company.opening_balance) }}</td><td>{{ (company.warehouses || []).length }}</td></tr></tbody></table></b-col>
          <b-col lg="7"><h5>Warehouse ownership</h5><table class="table table-sm"><thead><tr><th>Warehouse</th><th>Type</th><th>External company</th><th></th></tr></thead><tbody><tr v-for="warehouse in setupWarehouses" :key="warehouse.id"><td>{{ warehouse.name }}</td><td><b-form-select v-model="warehouse.ownership_type" :options="ownershipOptions" size="sm" /></td><td><v-select v-if="warehouse.ownership_type === 'external'" v-model="warehouse.stock_transfer_company_id" :options="meta.companies" label="name" :reduce="o => o.id" :clearable="false" /></td><td><b-button size="sm" variant="outline-primary" @click="saveWarehouse(warehouse)">Save</b-button></td></tr></tbody></table></b-col></b-row>
        </b-tab>
      </b-tabs>
    </template>

    <b-modal id="payment-modal" title="Receive Stock Transfer Payment / Advance" hide-footer size="lg">
      <b-form @submit.prevent="savePayment"><b-row>
        <b-col md="6"><b-form-group label="Company *"><v-select v-model="payment.stock_transfer_company_id" :options="meta.companies" label="name" :reduce="o => o.id" @input="payment.warehouse_id = null; payment.transfer_id = null" /></b-form-group></b-col>
        <b-col md="6"><b-form-group label="Warehouse (optional)"><v-select v-model="payment.warehouse_id" :options="paymentWarehouses" label="name" :reduce="o => o.id" placeholder="Company-level payment" /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Payment date *"><b-form-input v-model="payment.payment_date" type="date" required /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Amount *"><b-form-input v-model.number="payment.amount" type="number" min="0.01" step="0.01" required /></b-form-group></b-col>
        <b-col md="4"><b-form-group label="Method *"><v-select v-model="payment.payment_method_id" :options="meta.payment_methods" label="name" :reduce="o => o.id" /></b-form-group></b-col>
        <b-col md="6"><b-form-group label="Account"><v-select v-model="payment.account_id" :options="meta.accounts" label="account_name" :reduce="o => o.id" placeholder="Required for bank/Easypaisa" /></b-form-group></b-col>
        <b-col md="6"><b-form-group label="External reference"><b-form-input v-model="payment.external_reference" /></b-form-group></b-col>
        <b-col md="12"><b-form-group label="Specific transfer (optional)"><v-select v-model="payment.transfer_id" :options="paymentTransfers" :get-option-label="o => o.Ref + ' — outstanding ' + money(o.outstanding_balance)" :reduce="o => o.id" placeholder="Auto-allocate oldest balances; leave unallocated as advance" /></b-form-group></b-col>
        <b-col md="12"><b-form-group label="Remarks"><b-form-textarea v-model="payment.remarks" rows="2" /></b-form-group></b-col>
      </b-row><div class="text-right"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('payment-modal')">Cancel</b-button><b-button type="submit" variant="success" :disabled="saving">Save Payment</b-button></div></b-form>
    </b-modal>

    <b-modal id="company-modal" title="Add External Company" hide-footer>
      <b-form @submit.prevent="saveCompany"><b-form-group label="Company name *"><b-form-input v-model="company.name" required /></b-form-group><b-form-group label="Code"><b-form-input v-model="company.code" /></b-form-group><b-form-group label="Opening balance"><b-form-input v-model.number="company.opening_balance" type="number" step="0.01" /><small>Positive = company owes us; negative = available credit.</small></b-form-group><b-form-group label="Phone"><b-form-input v-model="company.phone" /></b-form-group><b-form-group label="Notes"><b-form-textarea v-model="company.notes" /></b-form-group><div class="text-right"><b-button type="submit" variant="primary" :disabled="saving">Save Company</b-button></div></b-form>
    </b-modal>

    <b-modal id="transfer-financial-modal" title="Transfer Financial Details" size="lg" dialog-class="stock-transfer-financial-dialog" body-class="stock-transfer-financial-body" centered scrollable hide-footer>
      <div v-if="selectedTransfer">
        <div class="financial-modal-heading">
          <div><small>Transfer reference</small><strong>{{ selectedTransfer.reference }}</strong></div>
          <div><small>Company</small><strong>{{ selectedTransfer.company || 'Internal transfer' }}</strong></div>
          <b-badge :variant="statusVariant(selectedTransfer.payment_status)">{{ label(selectedTransfer.payment_status) }}</b-badge>
        </div>
        <b-row class="financial-modal-summary">
          <b-col md="3" sm="6" class="mb-3"><div class="financial-stat"><small>Transfer value</small><strong>{{ money(selectedTransfer.transfer_value) }}</strong></div></b-col>
          <b-col md="3" sm="6" class="mb-3"><div class="financial-stat"><small>Previous outstanding</small><strong>{{ money(selectedTransfer.previous_outstanding) }}</strong></div></b-col>
          <b-col md="3" sm="6" class="mb-3"><div class="financial-stat success"><small>Payments / advances</small><strong>{{ money(selectedTransfer.paid_amount) }}</strong></div></b-col>
          <b-col md="3" sm="6" class="mb-3"><div class="financial-stat danger"><small>Remaining</small><strong>{{ money(selectedTransfer.outstanding_balance) }}</strong></div></b-col>
        </b-row>
        <h6 class="font-weight-bold mb-3">Transferred Products</h6>
        <div class="table-responsive financial-products-table">
          <table class="table table-bordered table-hover mb-0">
            <thead><tr><th>Product</th><th>Code</th><th class="num">Quantity</th><th class="num">Transfer price</th><th class="num">Total</th></tr></thead>
            <tbody>
              <tr v-for="item in selectedTransfer.items" :key="item.id"><td class="product-cell">{{ item.product && item.product.name }}</td><td class="code-cell">{{ item.product && item.product.code }}</td><td class="num">{{ qty(item.quantity) }}</td><td class="num">{{ money(item.unit_transfer_price) }}</td><td class="num font-weight-bold">{{ money(item.transfer_total) }}</td></tr>
              <tr v-if="!selectedTransfer.items || !selectedTransfer.items.length"><td colspan="5" class="text-center text-muted py-4">No transfer items found.</td></tr>
            </tbody>
          </table>
        </div>
      </div>
    </b-modal>
  </div>
</template>

<script>
import moment from 'moment';
import NProgress from 'nprogress';

export default {
  metaInfo: { title: 'Stock Transfer Ledger' },
  data() { return {
    loading: false, loaded: false, saving: false, exporting: false, error: '', activeTab: 0,
    filters: { date_from: moment().startOf('month').format('YYYY-MM-DD'), date_to: moment().format('YYYY-MM-DD'), company_id: null, source_warehouse_id: null, destination_warehouse_id: null, payment_status: null },
    ledgerFilters: { company_id: null, warehouse_id: null },
    paymentStatuses: [{ value: null, text: 'All statuses' }, { value: 'unpaid', text: 'Unpaid' }, { value: 'partially_paid', text: 'Partially Paid' }, { value: 'paid', text: 'Paid' }],
    ownershipOptions: [{ value: 'internal', text: 'Internal' }, { value: 'external', text: 'External' }],
    meta: { companies: [], warehouses: [], payment_methods: [], accounts: [], open_transfers: [] },
    setupWarehouses: [], transfers: [], payments: [], ledger: { rows: [], opening_balance: 0, closing_balance: 0 },
    summary: { totals: {}, companies: [], warehouses: [] }, pagination: { page: 1, per_page: 25, total: 0 },
    payment: {}, company: {}, selectedTransfer: null
  }; },
  computed: {
    summaryCards() { return [
      { label: 'Transferred stock value', value: this.summary.totals.transfer_value, variant: 'primary' },
      { label: 'Payments received', value: this.summary.totals.payments_received, variant: 'success' },
      { label: 'Outstanding receivables', value: this.summary.totals.outstanding_receivables, variant: 'danger' },
      { label: 'Available advances', value: this.summary.totals.available_advances, variant: 'warning' }
    ]; },
    externalWarehouses() { return this.meta.warehouses.filter(w => w.ownership_type === 'external' && (!this.ledgerFilters.company_id || Number(w.stock_transfer_company_id) === Number(this.ledgerFilters.company_id))); },
    paymentWarehouses() { return this.meta.warehouses.filter(w => w.ownership_type === 'external' && Number(w.stock_transfer_company_id) === Number(this.payment.stock_transfer_company_id)); },
    paymentTransfers() { return this.meta.open_transfers.filter(t => Number(t.stock_transfer_company_id) === Number(this.payment.stock_transfer_company_id)); }
  },
  created() { this.loadAll(); },
  methods: {
    params() { const p = { ...this.filters }; Object.keys(p).forEach(k => (p[k] === null || p[k] === '') && delete p[k]); return p; },
    async loadAll() {
      this.loading = true;
      this.error = '';
      NProgress.start();
      const warnings = [];
      try {
        await this.loadMeta();
        const sections = [
          ['Transfer history', () => this.loadTransfers(1)],
          ['Financial summary', () => this.loadSummary()],
          ['Company ledger', () => this.loadLedger()],
          ['Payments', () => this.loadPayments()]
        ];
        for (const section of sections) {
          try {
            await section[1]();
          } catch (error) {
            warnings.push(section[0] + ': ' + this.errorMessage(error, 'unable to load'));
          }
        }
        this.loaded = true;
        this.error = warnings.join(' | ');
      } catch (error) {
        this.error = 'Setup data: ' + this.errorMessage(error, 'unable to load');
      } finally {
        this.loading = false;
        NProgress.done();
      }
    },
    async loadMeta() { const { data } = await axios.get('stock-transfer-ledger/meta'); this.meta = data; this.setupWarehouses = (data.warehouses || []).map(w => ({ ...w })); },
    async loadTransfers(page) { if (typeof page === 'number') this.pagination.page = page; const { data } = await axios.get('stock-transfer-ledger', { params: { ...this.params(), page: this.pagination.page, limit: this.pagination.per_page } }); this.transfers = data.data || []; this.pagination.total = data.total || 0; },
    async loadSummary() { const { data } = await axios.get('stock-transfer-ledger/summary', { params: this.params() }); this.summary = data; },
    async loadLedger() { const params = { date_from: this.filters.date_from, date_to: this.filters.date_to, ...this.ledgerFilters }; Object.keys(params).forEach(k => !params[k] && delete params[k]); const { data } = await axios.get('stock-transfer-ledger/entries', { params }); this.ledger = data; },
    async loadPayments() { const { data } = await axios.get('stock-transfer-ledger/payments', { params: this.params() }); this.payments = data.payments || []; },
    openPayment() { this.payment = { idempotency_key: this.uuid(), payment_date: moment().format('YYYY-MM-DD'), amount: null, stock_transfer_company_id: this.filters.company_id, warehouse_id: null, payment_method_id: null, account_id: null, transfer_id: null, external_reference: '', remarks: '', auto_allocate: true }; this.$bvModal.show('payment-modal'); },
    async savePayment() { this.saving = true; try { await axios.post('stock-transfer-ledger/payments', this.payment); this.$bvModal.hide('payment-modal'); this.$bvToast.toast('Payment posted and allocated successfully.', { title: 'Success', variant: 'success' }); await this.loadAll(); } catch (e) { this.handleError(e, 'Unable to save payment.'); } finally { this.saving = false; } },
    openCompany() { this.company = { name: '', code: '', phone: '', opening_balance: 0, is_active: true, notes: '' }; this.$bvModal.show('company-modal'); },
    async saveCompany() { this.saving = true; try { await axios.post('stock-transfer-ledger/companies', this.company); this.$bvModal.hide('company-modal'); await this.loadMeta(); } catch (e) { this.handleError(e, 'Unable to save company.'); } finally { this.saving = false; } },
    async saveWarehouse(warehouse) { try { await axios.put('stock-transfer-ledger/warehouses/' + warehouse.id + '/ownership', { ownership_type: warehouse.ownership_type, stock_transfer_company_id: warehouse.ownership_type === 'external' ? warehouse.stock_transfer_company_id : null }); this.$bvToast.toast('Warehouse ownership updated.', { title: 'Success', variant: 'success' }); await this.loadMeta(); } catch (e) { this.handleError(e, 'Unable to update warehouse ownership.'); } },
    async showTransfer(id) { try { const { data } = await axios.get('stock-transfer-ledger/' + id); this.selectedTransfer = data.transfer; this.$bvModal.show('transfer-financial-modal'); } catch (e) { this.handleError(e, 'Unable to load transfer details.'); } },
    async download(kind) { this.exporting = true; try { const exportType = this.activeTab === 1 ? 'ledger' : 'transfers'; const params = { ...this.params(), ...this.ledgerFilters, export_type: exportType }; const response = await axios.get('stock-transfer-ledger/export/' + kind, { params, responseType: 'blob' }); const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([response.data])); link.download = 'stock-transfer-' + exportType + '.' + (kind === 'excel' ? 'xlsx' : 'pdf'); document.body.appendChild(link); link.click(); link.remove(); setTimeout(() => URL.revokeObjectURL(link.href), 1000); } catch (e) { this.handleError(e, 'Unable to export report.'); } finally { this.exporting = false; } },
    errorMessage(error, fallback) { const data = error.response && error.response.data; const errors = data && data.errors; const status = error.response && error.response.status; const message = errors ? Object.values(errors).reduce((all, values) => all.concat(values), [])[0] : ((data && data.message) || error.message || fallback); return (status ? 'HTTP ' + status + ' — ' : '') + message; },
    handleError(error, fallback) { this.error = this.errorMessage(error, fallback); },
    money(value) { return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
    qty(value) { return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 4 }); },
    date(value) { return value ? moment(value).format('DD-MMM-YYYY') : '-'; },
    label(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
    statusVariant(value) { return value === 'paid' ? 'success' : value === 'partially_paid' ? 'warning' : value === 'not_applicable' ? 'secondary' : 'danger'; },
    uuid() { return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16); }); }
  }
};
</script>

<style scoped>
.ledger-filter-card .form-group{margin-bottom:0}.summary-card{box-shadow:0 2px 8px rgba(0,0,0,.06)}.border-left-primary{border-left:4px solid #663399}.border-left-success{border-left:4px solid #28a745}.border-left-danger{border-left:4px solid #dc3545}.border-left-warning{border-left:4px solid #ffc107}.ledger-table{font-size:12px}.ledger-table th{white-space:nowrap;background:#f6f7f9}.warehouse-cell{min-width:180px;white-space:normal}.num{text-align:right;white-space:nowrap}
::v-deep .filter-warehouse-select .vs__dropdown-toggle{min-height:42px;height:auto}::v-deep .filter-warehouse-select .vs__selected{white-space:normal;line-height:1.35;margin:5px 2px;max-width:calc(100% - 34px)}
.financial-modal-heading{display:flex;align-items:center;gap:32px;padding-bottom:16px;margin-bottom:18px;border-bottom:1px solid #e4e7ed}.financial-modal-heading>div{display:flex;flex-direction:column;min-width:180px}.financial-modal-heading small,.financial-stat small{color:#718096;text-transform:uppercase;font-size:10px;letter-spacing:.4px}.financial-modal-heading strong{font-size:14px;color:#2d3748}.financial-modal-heading .badge{margin-left:auto}.financial-stat{height:100%;padding:15px 16px;border:1px solid #e4e7ed;border-left:4px solid #663399;border-radius:6px;background:#fff}.financial-stat strong{display:block;margin-top:5px;font-size:19px;color:#2d3748}.financial-stat.success{border-left-color:#28a745}.financial-stat.success strong{color:#28a745}.financial-stat.danger{border-left-color:#dc3545}.financial-stat.danger strong{color:#dc3545}.financial-products-table th{background:#f6f7f9;white-space:nowrap}.financial-products-table .product-cell{min-width:260px;white-space:normal}.financial-products-table .code-cell{min-width:180px;white-space:normal;word-break:break-word}
</style>

<style>
.stock-transfer-financial-dialog{width:calc(100vw - 64px);max-width:1180px!important;margin:1.75rem auto}.stock-transfer-financial-body{padding:24px 28px}.stock-transfer-financial-dialog .modal-header{padding:16px 28px;border-bottom:1px solid #e4e7ed}.stock-transfer-financial-dialog .modal-title{font-size:18px;font-weight:600;color:#2d3748}@media(max-width:767px){.stock-transfer-financial-dialog{width:calc(100vw - 20px);margin:.75rem auto}.stock-transfer-financial-body{padding:18px}.financial-modal-heading{align-items:flex-start;flex-direction:column;gap:10px}.financial-modal-heading .badge{margin-left:0}}
</style>
