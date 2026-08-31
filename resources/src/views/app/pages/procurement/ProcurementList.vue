<template>
  <div class="main-content">
    <breadcumb :page="title" folder="Procurement" />
    <b-card>
      <div class="d-flex flex-wrap justify-content-between mb-3">
        <b-form-input v-model="search" @keyup.enter="load(1)" class="mr-2 mb-2" style="max-width:360px" placeholder="Search references, supplier, product, vehicle or bilty" />
        <router-link v-if="kind === 'orders' && can('purchase_orders_create')" class="btn btn-primary mb-2" to="/app/procurement/purchase-orders/create"><lucide-icon name="plus" /> New Purchase Order</router-link>
        <router-link v-if="kind === 'gates' && can('gate_passes_create')" class="btn btn-primary mb-2" to="/app/procurement/gate-passes/create"><lucide-icon name="plus" /> Add Gate Pass</router-link>
      </div>
      <div class="table-responsive">
        <table class="table table-hover">
          <thead><tr><th v-for="column in columns" :key="column.key">{{ column.label }}</th><th>Actions</th></tr></thead>
          <tbody>
            <tr v-for="row in rows" :key="row.id">
              <td v-for="column in columns" :key="column.key"><span v-if="column.key === 'status'" class="badge badge-light-primary">{{ label(value(row, column.key)) }}</span><span v-else>{{ value(row, column.key) }}</span></td>
              <td>
                <router-link class="btn btn-sm btn-outline-primary" :to="detailUrl(row)"><lucide-icon name="eye" /> View</router-link>
                <router-link
                  v-if="kind === 'gates' && can('Purchases_add') && ['accepted', 'partially_accepted'].includes(row.status)"
                  class="btn btn-sm btn-outline-success ml-1"
                  :to="{ path: '/app/purchases/store', query: { gate_pass: row.number } }"
                ><lucide-icon name="file-plus" /> Invoice</router-link>
              </td>
            </tr>
            <tr v-if="!rows.length"><td :colspan="columns.length + 1" class="text-center text-muted py-5">No records found</td></tr>
          </tbody>
        </table>
      </div>
      <b-pagination v-model="page" :total-rows="total" :per-page="limit" @change="load" />
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from 'vuex';

export default {
  data: () => ({ rows: [], total: 0, page: 1, limit: 20, search: '' }),
  computed: {
    ...mapGetters(['currentUserPermissions']),
    kind() { return this.$route.meta.kind; },
    title() { return { orders: 'Purchase Orders', gates: 'Gate Passes', invoices: 'Supplier Invoices' }[this.kind]; },
    columns() {
      return {
        orders: [
          { key: 'number', label: 'PO Number' }, { key: 'order_date', label: 'Date' }, { key: 'provider.name', label: 'Supplier' },
          { key: 'warehouse.name', label: 'Destination' }, { key: 'progress.ordered', label: 'Ordered' }, { key: 'progress.received', label: 'Received' },
          { key: 'progress.remaining', label: 'Remaining' }, { key: 'progress.invoiced', label: 'Invoiced' }, { key: 'status', label: 'Status' }
        ],
        gates: [
          { key: 'number', label: 'Gate Pass' }, { key: 'supplier_gate_pass_number', label: 'Supplier GP' }, { key: 'purchase_order.number', label: 'PO Number' },
          { key: 'provider.name', label: 'Supplier' }, { key: 'delivered_at', label: 'Delivery' }, { key: 'vehicle_number', label: 'Vehicle' },
          { key: 'bilty_number', label: 'Bilty' }, { key: 'accepted_quantity', label: 'Accepted' }, { key: 'status', label: 'Status' }
        ],
        invoices: [
          { key: 'supplier_invoice_number', label: 'Supplier Invoice' }, { key: 'provider.name', label: 'Supplier' }, { key: 'purchase_order.number', label: 'PO Number' },
          { key: 'gate_pass.number', label: 'Gate Pass' }, { key: 'invoice_date', label: 'Invoice Date' }, { key: 'tax_type', label: 'Tax Type' },
          { key: 'grand_total', label: 'Amount' }, { key: 'status', label: 'Status' }, { key: 'purchase.payment_statut', label: 'Payment' }
        ]
      }[this.kind];
    }
  },
  watch: { kind() { this.load(1); } },
  mounted() { this.load(1); },
  methods: {
    can(permission) { return (this.currentUserPermissions || []).includes(permission); },
    endpoint() { return { orders: 'purchase-orders', gates: 'gate-passes', invoices: 'supplier-invoices' }[this.kind]; },
    load(page = 1) {
      this.page = page;
      axios.get('procurement/' + this.endpoint(), { params: { page, limit: this.limit, search: this.search } }).then(response => {
        this.rows = response.data.data;
        this.total = response.data.total;
      });
    },
    value(row, key) {
      const result = key.split('.').reduce((value, part) => value && value[part], row);
      if (key === 'purchase_order.number' && !result) return 'Direct receipt';
      if (['order_date', 'delivered_at', 'invoice_date'].includes(key) && result) {
        const [year, month, day] = String(result).split('T')[0].split('-');
        if (year && month && day) return `${day}-${month}-${year}`;
      }
      return result ?? '-';
    },
    label(value) { return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase()); },
    detailUrl(row) {
      if (this.kind === 'orders') return '/app/procurement/purchase-orders/' + row.id;
      if (this.kind === 'gates') return '/app/procurement/gate-passes/' + row.id;
      return '/app/procurement/supplier-invoices/' + row.id;
    }
  }
};
</script>
