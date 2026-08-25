<template>
  <div class="main-content tax-management-page">
    <breadcumb page="Tax Management" :folder="$t('Settings')" />

    <b-card v-if="can('taxes.update')" class="mb-3" title="Default Taxes">
      <b-row>
        <b-col md="3" v-for="type in ['purchase', 'sale_invoice', 'pos']" :key="type">
          <b-form-group :label="title(type)">
            <b-form-select v-model="defaults[type]" :options="defaultOptions(type)" />
          </b-form-group>
        </b-col>
        <b-col md="3" class="d-flex align-items-end pb-3"><b-button variant="outline-primary" @click="saveDefaults">Save Defaults</b-button></b-col>
      </b-row>
    </b-card>

    <b-card class="mb-3">
      <b-row>
        <b-col md="3"><b-form-group label="Search"><b-form-input v-model="filters.search" debounce="350" @input="loadTaxes" placeholder="Name, code or description" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Status"><b-form-select v-model="filters.status" :options="statusOptions" @change="loadTaxes" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Transaction"><b-form-select v-model="filters.transaction_type" :options="transactionFilterOptions" @change="loadTaxes" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Behavior"><b-form-select v-model="filters.behavior" :options="behaviorFilterOptions" @change="loadTaxes" /></b-form-group></b-col>
        <b-col md="3" class="d-flex align-items-end justify-content-end pb-3">
          <b-button v-if="can('taxes.create')" variant="primary" @click="openCreate"><lucide-icon name="plus" /> Create Tax</b-button>
        </b-col>
      </b-row>
    </b-card>

    <b-card>
      <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
      <div v-else class="table-responsive">
        <b-table :items="taxes" :fields="fields" striped hover show-empty>
          <template #cell(rate)="p"><strong>{{ rateLabel(p.item) }}</strong></template>
          <template #cell(behavior)="p"><b-badge :variant="behaviorVariant(p.item.behavior)">{{ title(p.item.behavior) }}</b-badge></template>
          <template #cell(transaction_types)="p"><span class="compact-list">{{ p.item.transaction_types.map(title).join(', ') }}</span></template>
          <template #cell(price_types)="p"><span class="compact-list">{{ (p.item.price_types || []).map(x => x.name).join(', ') }}</span></template>
          <template #cell(effective)="p">{{ effectiveLabel(p.item) }}</template>
          <template #cell(is_active)="p"><b-badge :variant="p.item.is_active ? 'success' : 'secondary'">{{ p.item.is_active ? 'Active' : 'Inactive' }}</b-badge></template>
          <template #cell(actions)="p">
            <b-button size="sm" variant="outline-info" class="mr-1" @click="viewTax(p.item)"><lucide-icon name="eye" /></b-button>
            <b-button v-if="can('taxes.update')" size="sm" variant="outline-success" class="mr-1" @click="openEdit(p.item)"><lucide-icon name="pencil" /></b-button>
            <b-button v-if="can('taxes.activate')" size="sm" variant="outline-warning" class="mr-1" @click="toggle(p.item)"><lucide-icon :name="p.item.is_active ? 'pause' : 'play'" /></b-button>
            <b-button v-if="can('taxes.delete') && !p.item.is_used" size="sm" variant="outline-danger" @click="remove(p.item)"><lucide-icon name="trash-2" /></b-button>
          </template>
        </b-table>
      </div>
      <b-pagination v-if="totalRows > perPage" v-model="page" :total-rows="totalRows" :per-page="perPage" @change="loadTaxes" />
    </b-card>

    <b-modal
      id="tax-form"
      size="xl"
      dialog-class="tax-management-edit-modal"
      hide-footer
      :title="editing ? 'Edit Tax' : 'Create Tax'"
    >
      <b-alert v-if="formError" show variant="danger">{{ formError }}</b-alert>
      <b-form @submit.prevent="save">
        <h5>Basic Details</h5>
        <b-row>
          <b-col md="4"><b-form-group label="Tax name *"><b-form-input v-model.trim="form.name" required maxlength="120" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Tax code *"><b-form-input v-model.trim="form.code" required maxlength="40" @input="form.code = form.code.toUpperCase()" /></b-form-group></b-col>
          <b-col md="2"><b-form-group label="Status"><b-form-checkbox v-model="form.is_active" switch>Active</b-form-checkbox></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Description"><b-form-textarea v-model="form.description" rows="2" /></b-form-group></b-col>
        </b-row>
        <hr><h5>Calculation</h5>
        <b-row>
          <b-col md="3"><b-form-group label="Calculation type *"><b-form-select v-model="form.calculation_type" :options="calculationOptions" required /></b-form-group></b-col>
          <b-col md="3"><b-form-group :label="form.calculation_type === 'percentage' ? 'Rate (%) *' : 'Fixed amount *'"><b-form-input v-model="form.rate" type="number" min="0" :max="form.calculation_type === 'percentage' ? 100 : null" step="0.000001" required /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Behavior *"><b-form-select v-model="form.behavior" :options="behaviorOptions" required /></b-form-group></b-col>
          <b-col md="2"><b-form-group label="Priority"><b-form-input v-model.number="form.priority" type="number" min="0" max="65535" /></b-form-group></b-col>
          <b-col md="1"><b-form-group label="Compound"><b-form-checkbox v-model="form.is_compound" switch /></b-form-group></b-col>
        </b-row>
        <hr><h5>Applicability</h5>
        <b-row>
          <b-col md="6"><b-form-group label="Transaction types *"><b-form-checkbox-group v-model="form.transaction_types" :options="transactionOptions" stacked /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Existing price types *"><b-form-checkbox-group v-model="form.price_type_ids" :options="priceTypeOptions" stacked /></b-form-group></b-col>
          <b-col md="12"><b-form-group label="Branches (leave empty for all accessible branches)"><b-form-checkbox-group v-model="form.warehouse_ids" :options="warehouseOptions" /></b-form-group></b-col>
        </b-row>
        <hr><h5>Effective Dates</h5>
        <b-row>
          <b-col md="4"><b-form-group label="Start date"><b-form-input v-model="form.effective_start_date" type="date" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="End date"><b-form-input v-model="form.effective_end_date" type="date" /></b-form-group></b-col>
        </b-row>
        <div class="d-flex justify-content-end"><b-button variant="secondary" class="mr-2" @click="$bvModal.hide('tax-form')">Cancel</b-button><b-button variant="primary" type="submit" :disabled="saving">{{ saving ? 'Saving…' : 'Save Tax' }}</b-button></div>
      </b-form>
    </b-modal>

    <b-modal id="tax-view" size="lg" hide-footer title="Tax Details">
      <div v-if="selected">
        <h4>{{ selected.name }} <b-badge variant="dark">{{ selected.code }}</b-badge></h4>
        <p class="text-muted">{{ selected.description || 'No description' }}</p>
        <b-table-simple bordered small><b-tbody>
          <b-tr><b-th>Value</b-th><b-td>{{ rateLabel(selected) }} ({{ title(selected.calculation_type) }})</b-td><b-th>Behavior</b-th><b-td>{{ title(selected.behavior) }}</b-td></b-tr>
          <b-tr><b-th>Transactions</b-th><b-td>{{ selected.transaction_types.map(title).join(', ') }}</b-td><b-th>Price types</b-th><b-td>{{ (selected.price_types || []).map(x => x.name).join(', ') }}</b-td></b-tr>
          <b-tr><b-th>Priority</b-th><b-td>{{ selected.priority }}</b-td><b-th>Compound</b-th><b-td>{{ selected.is_compound ? 'Yes' : 'No' }}</b-td></b-tr>
          <b-tr><b-th>Effective</b-th><b-td>{{ effectiveLabel(selected) }}</b-td><b-th>Status</b-th><b-td>{{ selected.is_active ? 'Active' : 'Inactive' }}</b-td></b-tr>
        </b-tbody></b-table-simple>
        <h6>Calculation sequence</h6><p>Priority {{ selected.priority }} · {{ selected.is_compound ? 'Includes earlier additive taxes in its base' : 'Uses the original taxable base' }} · Deductive taxes never increase a later base.</p>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from 'nprogress';
import { mapGetters } from 'vuex';

export default {
  metaInfo: { title: 'Tax Management' },
  data() { return {
    loading: false, saving: false, editing: false, selected: null, formError: '', taxes: [], defaults: { purchase: null, sale_invoice: null, pos: null }, metadata: { price_types: [], warehouses: [], transaction_types: [] }, totalRows: 0, page: 1, perPage: 10,
    filters: { search: '', status: '', transaction_type: '', behavior: '' },
    fields: [
      { key: 'name', label: 'Name' }, { key: 'code', label: 'Code' }, { key: 'rate', label: 'Rate / value' },
      { key: 'calculation_type', label: 'Calculation' }, { key: 'behavior', label: 'Behavior' },
      { key: 'transaction_types', label: 'Transactions' }, { key: 'price_types', label: 'Applied prices' },
      { key: 'effective', label: 'Effective period' }, { key: 'priority', label: 'Priority' },
      { key: 'is_active', label: 'Status' }, { key: 'actions', label: 'Actions' }
    ],
    form: {}
  }; },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    statusOptions() { return [{ value: '', text: 'All statuses' }, { value: 'active', text: 'Active' }, { value: 'inactive', text: 'Inactive' }]; },
    transactionFilterOptions() { return [{ value: '', text: 'All transactions' }].concat(this.transactionOptions); },
    behaviorFilterOptions() { return [{ value: '', text: 'All behaviors' }].concat(this.behaviorOptions); },
    transactionOptions() { return (this.metadata.transaction_types || []).map(x => ({ value: x, text: this.title(x) })); },
    priceTypeOptions() { return (this.metadata.price_types || []).map(x => ({ value: x.id, text: x.name })); },
    warehouseOptions() { return (this.metadata.warehouses || []).map(x => ({ value: x.id, text: x.name })); },
    behaviorOptions() { return ['additive', 'deductive', 'inclusive'].map(x => ({ value: x, text: this.title(x) })); },
    calculationOptions() { return ['percentage', 'fixed'].map(x => ({ value: x, text: this.title(x) })); }
  },
  created() { this.loadMetadata(); this.loadTaxes(); this.loadDefaults(); },
  methods: {
    can(permission) { return (this.currentUserPermissions || []).includes(permission); },
    title(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
    rateLabel(tax) { return tax.calculation_type === 'percentage' ? `${Number(tax.rate)}%` : Number(tax.rate).toFixed(2); },
    behaviorVariant(value) { return value === 'deductive' ? 'danger' : value === 'inclusive' ? 'info' : 'success'; },
    defaultOptions(type) { return [{ value: null, text: 'No default' }].concat(this.taxes.filter(t => t.is_active && t.transaction_types.includes(type)).map(t => ({ value: t.id, text: `${t.code} — ${this.rateLabel(t)}` }))); },
    effectiveLabel(tax) { return `${tax.effective_start_date || 'Any time'} — ${tax.effective_end_date || 'No end'}`; },
    blank() { return { name: '', code: '', description: '', calculation_type: 'percentage', rate: 0, behavior: 'additive', transaction_types: [], price_type_ids: [], warehouse_ids: [], effective_start_date: '', effective_end_date: '', priority: 100, is_compound: false, is_active: true }; },
    async loadMetadata() { const { data } = await axios.get('taxes/metadata'); this.metadata = data; },
    async loadDefaults() { const { data } = await axios.get('taxes/defaults'); (data.defaults || []).forEach(item => { this.$set(this.defaults, item.transaction_type, item.tax_id); }); },
    async saveDefaults() { await axios.put('taxes/defaults', { defaults: this.defaults }); this.$root.$bvToast.toast('Default taxes updated.', { title: 'Tax Management', variant: 'success', solid: true }); },
    async loadTaxes() { this.loading = true; NProgress.start(); try { const { data } = await axios.get('taxes', { params: { ...this.filters, page: this.page, limit: this.perPage } }); this.taxes = data.taxes; this.totalRows = data.totalRows; } finally { this.loading = false; NProgress.done(); } },
    openCreate() { this.editing = false; this.formError = ''; this.form = this.blank(); this.$bvModal.show('tax-form'); },
    openEdit(tax) { this.editing = true; this.formError = ''; this.form = { ...this.blank(), ...tax, transaction_types: [...tax.transaction_types], price_type_ids: [...tax.price_type_ids], warehouse_ids: [...tax.warehouse_ids], effective_start_date: tax.effective_start_date ? tax.effective_start_date.slice(0, 10) : '', effective_end_date: tax.effective_end_date ? tax.effective_end_date.slice(0, 10) : '' }; this.$bvModal.show('tax-form'); },
    viewTax(tax) { this.selected = tax; this.$bvModal.show('tax-view'); },
    async save() { this.saving = true; this.formError = ''; try { const method = this.editing ? 'put' : 'post'; const url = this.editing ? `taxes/${this.form.id}` : 'taxes'; await axios[method](url, this.form); this.$bvModal.hide('tax-form'); await this.loadTaxes(); this.$root.$bvToast.toast('Tax saved successfully.', { title: 'Tax Management', variant: 'success', solid: true }); } catch (e) { const errors = e.response && e.response.data && e.response.data.errors; this.formError = errors ? Object.values(errors).flat().join(' ') : ((e.response && e.response.data && e.response.data.message) || 'Unable to save tax.'); } finally { this.saving = false; } },
    async toggle(tax) { await axios.patch(`taxes/${tax.id}/toggle`); await this.loadTaxes(); },
    async remove(tax) { const answer = await this.$swal({ title: 'Delete this unused tax?', text: 'Used taxes can only be deactivated.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete' }); if (!answer.isConfirmed && !answer.value) return; try { await axios.delete(`taxes/${tax.id}`); await this.loadTaxes(); } catch (e) { this.$root.$bvToast.toast((e.response && e.response.data && e.response.data.message) || 'Unable to delete tax.', { title: 'Tax Management', variant: 'danger', solid: true }); } }
  }
};
</script>

<style scoped>
.tax-management-page h5 { color: #4b5563; margin-bottom: 1rem; }
.compact-list { white-space: normal; min-width: 120px; display: inline-block; }
</style>

<style>
.tax-management-edit-modal {
  width: calc(100vw - 48px);
  max-width: 1440px !important;
}

@media (max-width: 767.98px) {
  .tax-management-edit-modal {
    width: calc(100vw - 16px);
    margin: 8px auto;
  }
}
</style>
