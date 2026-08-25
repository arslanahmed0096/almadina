<template>
  <div class="main-content">
    <breadcumb page="Tax Report" :folder="$t('Reports')" />
    <b-card class="mb-3">
      <b-row>
        <b-col md="2"><b-form-group label="From"><b-form-input v-model="filters.from" type="date" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="To"><b-form-input v-model="filters.to" type="date" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Tax code"><b-form-input v-model.trim="filters.tax_code" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Transaction"><b-form-select v-model="filters.transaction_type" :options="transactions" /></b-form-group></b-col>
        <b-col md="2"><b-form-group label="Behavior"><b-form-select v-model="filters.behavior" :options="behaviors" /></b-form-group></b-col>
        <b-col md="2" class="d-flex align-items-end pb-3"><b-button variant="primary" @click="load">Apply Filters</b-button></b-col>
      </b-row>
    </b-card>
    <b-row class="mb-3">
      <b-col md="3" v-for="card in cards" :key="card.label"><b-card><div class="text-muted">{{ card.label }}</div><h4>{{ card.value }}</h4></b-card></b-col>
    </b-row>
    <b-card>
      <b-table responsive striped hover :busy="loading" :items="rows" :fields="fields" show-empty>
        <template #cell(tax)="p"><strong>{{ p.item.tax_code }}</strong><br><small>{{ p.item.tax_name }}</small></template>
        <template #cell(transaction)="p">{{ title(p.item.transaction_type) }} #{{ p.item.transaction_id }}</template>
        <template #cell(tax_amount)="p"><span :class="p.item.is_reversal || p.item.behavior === 'deductive' ? 'text-danger' : 'text-success'">{{ p.item.is_reversal ? '-' : (p.item.behavior === 'deductive' ? '-' : '') }}{{ money(p.item.tax_amount) }}</span></template>
      </b-table>
      <b-pagination v-model="page" :total-rows="totalRows" :per-page="limit" @change="load" />
    </b-card>
  </div>
</template>
<script>
export default {
  metaInfo: { title: 'Tax Report' },
  data() { return {
    loading: false, rows: [], totalRows: 0, page: 1, limit: 20,
    filters: { from: '', to: '', tax_code: '', transaction_type: '', behavior: '' },
    summary: { taxable_amount: 0, additive_taxes: 0, deductible_taxes: 0, reversed_taxes: 0, net_tax: 0 },
    transactions: [{ value: '', text: 'All transactions' }, ...['purchase', 'sale_invoice', 'pos', 'sale_return', 'purchase_return'].map(x => ({ value: x, text: this.title(x) }))],
    behaviors: [{ value: '', text: 'All behaviors' }, ...['additive', 'deductive', 'inclusive'].map(x => ({ value: x, text: this.title(x) }))],
    fields: [
      { key: 'date', label: 'Date' }, { key: 'reference', label: 'Reference' }, { key: 'branch', label: 'Branch' }, { key: 'transaction', label: 'Transaction' }, { key: 'party', label: 'Customer / supplier' }, { key: 'tax', label: 'Tax' },
      { key: 'price_type_name', label: 'Applied price' }, { key: 'taxable_base', label: 'Taxable base' },
      { key: 'rate', label: 'Rate / value' }, { key: 'behavior', label: 'Behavior' }, { key: 'tax_amount', label: 'Tax amount' }, { key: 'transaction_status', label: 'Status' }
    ]
  }; },
  computed: { cards() { return [
    { label: 'Taxable amount', value: this.money(this.summary.taxable_amount) }, { label: 'Additive / GST', value: this.money(this.summary.additive_taxes) },
    { label: 'Deductive / WHT', value: this.money(this.summary.deductive_taxes) }, { label: 'Reversed / Net tax', value: `${this.money(this.summary.reversed_taxes)} / ${this.money(this.summary.net_tax)}` }
  ]; } },
  created() { this.load(); },
  methods: {
    title(value) { return String(value || '').replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()); },
    money(value) { return Number(value || 0).toFixed(2); },
    async load() { this.loading = true; try { const { data } = await axios.get('tax-report', { params: { ...this.filters, page: this.page, limit: this.limit } }); this.rows = data.rows; this.totalRows = data.totalRows; this.summary = data.summary; } finally { this.loading = false; } }
  }
};
</script>
