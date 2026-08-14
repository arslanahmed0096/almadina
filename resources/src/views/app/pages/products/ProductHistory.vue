<template>
  <div class="main-content product-history-page">
    <breadcumb page="Product History" folder="Products" />

    <div v-if="loading && !product.id" class="history-loading">
      <b-spinner variant="primary" />
      <div class="mt-2 text-muted">Loading complete product history...</div>
    </div>

    <template v-else>
      <b-card class="product-hero mb-3">
        <div class="d-flex flex-wrap align-items-center">
          <img :src="'/images/products/' + (product.image || 'no-image.png')" class="product-image mr-3" alt="Product" />
          <div class="product-main mr-auto">
            <div class="d-flex align-items-center flex-wrap">
              <h3 class="mb-1 mr-2">{{ product.name }}</h3>
              <b-badge :variant="product.is_active ? 'success' : 'danger'">{{ product.is_active ? 'Active' : 'Inactive' }}</b-badge>
            </div>
            <div class="text-muted">
              <strong>{{ product.code }}</strong>
              <span v-if="product.brand"> · {{ product.brand }}</span>
              <span v-if="product.category"> · {{ product.category }}</span>
              <span v-if="product.type"> · {{ product.type }}</span>
            </div>
          </div>
          <div class="hero-prices">
            <div><span>Cost</span><strong>{{ money(product.cost) }}</strong></div>
            <div><span>Sale price</span><strong>{{ money(product.price) }}</strong></div>
            <div><span>Fix price</span><strong>{{ money(product.fix_price) }}</strong></div>
          </div>
        </div>
      </b-card>

      <b-row class="mb-3">
        <b-col v-for="card in summaryCards" :key="card.label" xl="2" md="4" sm="6" class="mb-2">
          <b-card class="summary-card h-100">
            <div class="summary-label">{{ card.label }}</div>
            <div class="summary-value" :class="card.className">{{ card.value }}</div>
          </b-card>
        </b-col>
      </b-row>

      <b-card class="mb-3 stock-card" no-body>
        <b-card-header class="d-flex align-items-center justify-content-between">
          <div>
            <strong>Current Stock by Warehouse</strong>
            <div class="small text-muted">Current inventory available to your warehouse access</div>
          </div>
          <b-button size="sm" variant="outline-primary" @click="showStock = !showStock">
            {{ showStock ? 'Hide' : 'Show' }} stock
          </b-button>
        </b-card-header>
        <b-collapse v-model="showStock">
          <b-table :items="stock" :fields="stockFields" responsive small striped class="mb-0">
            <template #cell(variant_name)="{ item }">
              <span v-if="item.variant_name">{{ item.variant_name }} <small class="text-muted">{{ item.variant_code }}</small></span>
              <span v-else class="text-muted">Base product</span>
            </template>
            <template #cell(quantity)="{ item }"><strong>{{ number(item.quantity) }} {{ product.unit }}</strong></template>
          </b-table>
          <div v-if="!stock.length" class="empty-state">No warehouse stock record exists for this product.</div>
        </b-collapse>
      </b-card>

      <b-card class="history-card">
        <div class="filters mb-3">
          <b-row>
            <b-col lg="4" class="mb-2">
              <label>Search history</label>
              <b-input-group>
                <b-form-input v-model.trim="filters.search" placeholder="Reference, customer, supplier, warehouse, user..." @keyup.enter="applyFilters" />
                <b-input-group-append><b-button variant="primary" @click="applyFilters">Search</b-button></b-input-group-append>
              </b-input-group>
            </b-col>
            <b-col lg="3" md="4" class="mb-2">
              <label>Activity type</label>
              <b-form-select v-model="filters.type" :options="typeOptions" @change="applyFilters" />
            </b-col>
            <b-col lg="2" md="4" class="mb-2">
              <label>From</label>
              <b-form-input v-model="filters.from" type="date" @change="applyFilters" />
            </b-col>
            <b-col lg="2" md="4" class="mb-2">
              <label>To</label>
              <b-form-input v-model="filters.to" type="date" @change="applyFilters" />
            </b-col>
            <b-col lg="1" class="mb-2 d-flex align-items-end">
              <b-button block variant="outline-secondary" @click="resetFilters">Reset</b-button>
            </b-col>
          </b-row>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <div class="text-muted">{{ totalRows }} history record{{ totalRows === 1 ? '' : 's' }}</div>
          <b-form-select v-model="limit" :options="limitOptions" size="sm" class="limit-select" @change="changeLimit" />
        </div>

        <div v-if="loading" class="history-loading compact"><b-spinner variant="primary" /></div>
        <b-table
          v-else
          :items="history"
          :fields="historyFields"
          responsive
          hover
          striped
          small
          class="history-table"
          show-empty
          empty-text="No product history matches these filters."
        >
          <template #cell(event_label)="{ item }">
            <b-badge :variant="eventBadge(item.event_type)" class="event-badge">{{ item.event_label }}</b-badge>
          </template>
          <template #cell(occurred_at)="{ item }">
            <div class="text-nowrap">{{ formatDate(item.occurred_at) }}</div>
          </template>
          <template #cell(reference)="{ item }">
            <a v-if="item.link" :href="item.link" target="_blank" rel="noopener noreferrer" class="font-weight-bold">
              {{ item.reference || '-' }} <span aria-hidden="true">↗</span>
            </a>
            <strong v-else>{{ item.reference || '-' }}</strong>
          </template>
          <template #cell(details)="{ item }">
            <div v-if="item.variant_name" class="font-weight-bold">Variant: {{ item.variant_name }}</div>
            <div v-if="item.detail">{{ item.detail }}</div>
            <div v-if="item.notes" class="small text-muted text-wrap">{{ item.notes }}</div>
            <div v-if="item.event_type === 'pricing'" class="pricing-detail">
              <span>Cost: {{ money(item.unit_cost) }}</span>
              <span>Price: {{ money(item.unit_price) }}</span>
              <span>Fix: {{ money(item.pricing.fix_price) }}</span>
              <span>MRP: {{ money(item.pricing.mrp_price) }}</span>
              <span>Company RB: {{ money(item.pricing.company_rb_price) }}</span>
              <span>Wholesale: {{ money(item.pricing.wholesale_price) }}</span>
              <span>Minimum: {{ money(item.pricing.min_price) }}</span>
            </div>
          </template>
          <template #cell(party_name)="{ item }">
            <div v-if="item.party_name"><strong>{{ item.party_name }}</strong><div class="small text-muted text-capitalize">{{ item.party_type }}</div></div>
            <span v-else class="text-muted">-</span>
          </template>
          <template #cell(warehouse_name)="{ item }">
            <div>{{ item.warehouse_name || '-' }}</div>
            <div v-if="item.destination_warehouse_name" class="small text-primary">To: {{ item.destination_warehouse_name }}</div>
          </template>
          <template #cell(quantity)="{ item }">
            <span v-if="item.quantity !== null">{{ number(item.quantity) }} {{ product.unit }}</span>
            <span v-else class="text-muted">-</span>
          </template>
          <template #cell(stock_effect)="{ item }">
            <span v-if="item.event_type === 'transfer'" class="text-primary font-weight-bold">Transfer</span>
            <span v-else-if="Number(item.stock_effect) > 0" class="text-success font-weight-bold">+{{ number(item.stock_effect) }}</span>
            <span v-else-if="Number(item.stock_effect) < 0" class="text-danger font-weight-bold">{{ number(item.stock_effect) }}</span>
            <span v-else class="text-muted">No change</span>
          </template>
          <template #cell(value)="{ item }">
            <div v-if="item.total !== null">Total: <strong>{{ money(item.total) }}</strong></div>
            <div v-if="item.unit_cost !== null && item.event_type !== 'pricing'" class="small text-muted">Cost: {{ money(item.unit_cost) }}</div>
            <div v-if="item.unit_price !== null && item.event_type !== 'pricing'" class="small text-muted">Price: {{ money(item.unit_price) }}</div>
            <span v-if="item.total === null && item.unit_cost === null && item.unit_price === null" class="text-muted">-</span>
          </template>
          <template #cell(status)="{ item }"><b-badge :variant="statusBadge(item.status)">{{ item.status || '-' }}</b-badge></template>
          <template #cell(performed_by)="{ item }">{{ item.performed_by || '-' }}</template>
        </b-table>

        <div v-if="totalRows > limit" class="d-flex justify-content-end mt-3">
          <b-pagination v-model="page" :total-rows="totalRows" :per-page="limit" @change="changePage" />
        </div>
      </b-card>
    </template>
  </div>
</template>

<script>
import axios from 'axios'
import NProgress from 'nprogress'
import { formatPriceDisplay, getPriceFormatSetting } from '../../../../utils/priceFormat'

export default {
  name: 'ProductHistory',
  props: { id: [String, Number] },
  metaInfo() { return { title: this.product.name ? `${this.product.name} - Product History` : 'Product History' } },
  data() {
    return {
      loading: true,
      showStock: true,
      product: {},
      summary: {},
      stock: [],
      history: [],
      types: [],
      totalRows: 0,
      page: 1,
      limit: 25,
      filters: { search: '', type: '', from: '', to: '' },
      limitOptions: [
        { value: 10, text: '10 per page' },
        { value: 25, text: '25 per page' },
        { value: 50, text: '50 per page' },
        { value: 100, text: '100 per page' }
      ],
      stockFields: [
        { key: 'warehouse_name', label: 'Warehouse' },
        { key: 'variant_name', label: 'Variant' },
        { key: 'quantity', label: 'Current Quantity', class: 'text-right' }
      ],
      historyFields: [
        { key: 'occurred_at', label: 'Date / Time' },
        { key: 'event_label', label: 'Activity' },
        { key: 'reference', label: 'Reference' },
        { key: 'details', label: 'Details' },
        { key: 'party_name', label: 'Customer / Supplier' },
        { key: 'warehouse_name', label: 'Warehouse' },
        { key: 'quantity', label: 'Quantity', class: 'text-right' },
        { key: 'stock_effect', label: 'Stock Effect', class: 'text-right' },
        { key: 'value', label: 'Value', class: 'text-right' },
        { key: 'status', label: 'Status' },
        { key: 'performed_by', label: 'Recorded By' }
      ]
    }
  },
  computed: {
    typeOptions() {
      return [{ value: '', text: 'All activity' }].concat(this.types.map(type => ({ value: type.value, text: type.label })))
    },
    summaryCards() {
      return [
        { label: 'Current Stock', value: `${this.number(this.summary.current_stock)} ${this.product.unit || ''}`, className: 'text-primary' },
        { label: 'History Events', value: this.summary.history_events || 0, className: '' },
        { label: 'Purchases', value: this.summary.purchases || 0, className: 'text-success' },
        { label: 'Sales', value: this.summary.sales || 0, className: 'text-info' },
        { label: 'Returns', value: this.summary.returns || 0, className: 'text-warning' },
        { label: 'Price Changes', value: this.summary.pricing_changes || 0, className: 'text-purple' }
      ]
    }
  },
  created() { this.fetchHistory() },
  methods: {
    async fetchHistory() {
      this.loading = true
      NProgress.start()
      try {
        const { data } = await axios.get(`/api/products/${this.id}/history`, {
          params: {
            page: this.page,
            limit: this.limit,
            search: this.filters.search || '',
            type: this.filters.type || '',
            from: this.filters.from || '',
            to: this.filters.to || ''
          }
        })
        this.product = data.product || {}
        this.summary = data.summary || {}
        this.stock = data.stock || []
        this.history = data.history || []
        this.types = data.types || []
        this.totalRows = Number(data.totalRows || 0)
      } catch (error) {
        const message = error.response && error.response.data && error.response.data.message
          ? error.response.data.message
          : 'Failed to load product history.'
        this.$bvToast.toast(message, { title: 'Product History', variant: 'danger', solid: true })
      } finally {
        this.loading = false
        NProgress.done()
      }
    },
    applyFilters() { this.page = 1; this.fetchHistory() },
    resetFilters() { this.filters = { search: '', type: '', from: '', to: '' }; this.page = 1; this.fetchHistory() },
    changePage(page) { this.page = page; this.fetchHistory() },
    changeLimit() { this.page = 1; this.fetchHistory() },
    number(value) {
      const amount = Number(value || 0)
      return amount.toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 2 })
    },
    money(value) {
      if (value === null || value === undefined || value === '') return '-'
      try {
        return formatPriceDisplay(value, 2, getPriceFormatSetting({ store: this.$store }))
      } catch (error) {
        return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      }
    },
    formatDate(value) {
      if (!value) return '-'
      const normalized = String(value).replace(' ', 'T')
      const date = new Date(normalized)
      return Number.isNaN(date.getTime()) ? value : date.toLocaleString()
    },
    eventBadge(type) {
      return {
        purchase: 'success', purchase_return: 'danger', sale: 'primary', sale_return: 'warning',
        shipment: 'info', opening_stock: 'success', stock_adjustment: 'secondary', transfer: 'info',
        damage: 'danger', pricing: 'dark', quotation: 'light', service_job: 'primary', product_created: 'secondary'
      }[type] || 'secondary'
    },
    statusBadge(status) {
      const value = String(status || '').toLowerCase()
      if (['completed', 'received', 'approved', 'paid', 'active', 'delivered', 'shipped', 'add', 'updated'].includes(value)) return 'success'
      if (['pending', 'ordered', 'partial', 'packed'].includes(value)) return 'warning'
      if (['cancelled', 'canceled', 'rejected', 'inactive', 'remove'].includes(value)) return 'danger'
      return 'secondary'
    }
  }
}
</script>

<style scoped>
.product-history-page { padding-bottom: 32px; }
.history-loading { min-height: 320px; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.history-loading.compact { min-height: 180px; }
.product-hero, .summary-card, .stock-card, .history-card { border: 1px solid #e7eaf0; box-shadow: 0 5px 18px rgba(31, 41, 55, .05); }
.product-image { width: 74px; height: 74px; border-radius: 10px; border: 1px solid #e5e7eb; object-fit: contain; background: #fff; }
.product-main h3 { font-size: 21px; color: #172033; }
.hero-prices { display: flex; flex-wrap: wrap; gap: 10px; }
.hero-prices > div { min-width: 115px; padding: 9px 12px; border-radius: 8px; background: #f7f8fb; }
.hero-prices span { display: block; color: #7b8190; font-size: 11px; }
.hero-prices strong { display: block; color: #272c36; margin-top: 2px; }
.summary-card ::v-deep .card-body { padding: 14px; }
.summary-label { color: #777f90; font-size: 12px; }
.summary-value { color: #1e293b; font-size: 21px; font-weight: 700; margin-top: 4px; }
.text-purple { color: #6f42c1; }
.stock-card ::v-deep .card-header { background: #fff; border-bottom: 1px solid #edf0f4; }
.empty-state { padding: 28px; text-align: center; color: #7b8190; }
.filters label { color: #525b6b; font-size: 12px; font-weight: 600; margin-bottom: 5px; }
.limit-select { width: 135px; }
.event-badge { white-space: normal; line-height: 1.35; }
.pricing-detail { display: flex; flex-wrap: wrap; gap: 4px 10px; margin-top: 5px; max-width: 380px; }
.pricing-detail span { background: #f1f3f7; border-radius: 4px; padding: 2px 5px; font-size: 11px; white-space: nowrap; }
::v-deep .history-table th { white-space: nowrap; background: #f8f9fb; color: #444b58; }
::v-deep .history-table td { vertical-align: top; }
::v-deep .product-history-btn { gap: 3px; }
@media (max-width: 767px) {
  .hero-prices { margin-top: 14px; width: 100%; }
  .hero-prices > div { flex: 1; }
}
</style>
