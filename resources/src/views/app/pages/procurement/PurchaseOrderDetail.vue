<template>
  <div class="main-content">
    <breadcumb :page="order ? order.number : 'Purchase Order'" folder="Procurement" />
    <div v-if="order">
      <div class="d-flex flex-wrap mb-3">
        <b-button v-if="order.status === 'draft' && can('purchase_orders_issue')" variant="success" class="mr-2" @click="issue">Issue PO</b-button>
        <router-link v-if="order.status === 'draft' && can('purchase_orders_edit_draft')" class="btn btn-outline-primary mr-2" :to="'/app/procurement/purchase-orders/' + order.id + '/edit'">Edit</router-link>
        <b-button class="mr-2" variant="outline-secondary" @click="downloadPdf">Download PDF</b-button>
        <router-link v-if="order.status !== 'draft' && order.status !== 'cancelled' && can('gate_passes_create')" class="btn btn-primary" :to="'/app/procurement/purchase-orders/' + order.id + '/gate-pass'">Record Gate Pass</router-link>
      </div>

      <b-row>
        <b-col md="4"><b-card><h5>{{ order.provider.name }}</h5><p>{{ order.supplier_contact_snapshot }}</p><p><strong>Destination:</strong> {{ order.warehouse.name }}</p><span class="badge badge-light-primary">{{ label(order.status) }}</span></b-card></b-col>
        <b-col md="8"><b-row><b-col cols="6" md="3" v-for="card in cards" :key="card.key"><b-card class="text-center mb-2"><div class="text-muted">{{ card.label }}</div><h4>{{ progress.totals[card.key] }}</h4></b-card></b-col></b-row></b-col>
      </b-row>

      <b-card class="mt-3" title="Quantity progress">
        <div class="table-responsive"><table class="table table-sm"><thead><tr><th>Product</th><th>Model</th><th>Ordered</th><th>Received</th><th>Rejected</th><th>Remaining</th><th>Invoiced</th><th>Not invoiced</th><th>Posted</th></tr></thead><tbody><tr v-for="line in progress.lines" :key="line.purchase_order_item_id"><td>{{ line.product }}</td><td>{{ line.model || '—' }}</td><td>{{ line.ordered }}</td><td>{{ line.received }}</td><td>{{ line.rejected }}</td><td>{{ line.remaining }}</td><td>{{ line.invoiced }}</td><td>{{ line.not_invoiced }}</td><td>{{ line.purchased }}</td></tr></tbody></table></div>
      </b-card>

      <b-row>
        <b-col md="6"><b-card class="mt-3" title="Gate Passes"><div v-for="gatePass in order.gate_passes" :key="gatePass.id"><router-link :to="'/app/procurement/gate-passes/' + gatePass.id">{{ gatePass.number }}</router-link> · {{ label(gatePass.status) }}</div><div v-if="!order.gate_passes.length" class="text-muted">No Gate Passes recorded.</div></b-card></b-col>
        <b-col md="6"><b-card class="mt-3" title="Invoices & Purchases"><div v-for="purchase in order.purchases" :key="'purchase-' + purchase.id"><router-link :to="'/app/purchases/detail/' + purchase.id">{{ purchase.Ref }}</router-link> · {{ label(purchase.purchase_source || 'purchase') }}</div><div v-for="invoice in order.supplier_invoices" :key="'legacy-invoice-' + invoice.id"><router-link :to="'/app/procurement/supplier-invoices/' + invoice.id">{{ invoice.supplier_invoice_number }}</router-link> · {{ label(invoice.status) }} <small>(historical)</small></div><div v-if="!order.purchases.length && !order.supplier_invoices.length" class="text-muted">No invoices recorded.</div></b-card></b-col>
      </b-row>

      <b-card class="mt-3" title="Timeline">
        <div class="border-left pl-3 py-2" v-for="audit in order.audits" :key="audit.id"><strong>{{ label(audit.action) }}</strong> · {{ audit.reference }}<br><small>{{ audit.created_at }} · {{ audit.user ? (audit.user.firstname + ' ' + audit.user.lastname) : 'System' }}</small><p v-if="audit.notes">{{ audit.notes }}</p><details v-if="audit.old_values || audit.new_values"><summary class="text-primary" style="cursor: pointer;">View recorded changes</summary><pre class="bg-light p-2 mt-2" style="white-space: pre-wrap;">{{ auditChanges(audit) }}</pre></details></div>
        <div v-if="!order.audits.length" class="text-muted">No history recorded.</div>
      </b-card>
    </div>
  </div>
</template>

<script>
import { mapGetters } from 'vuex';

export default {
  data: () => ({ order: null, progress: { totals: {}, lines: [] }, api: '/api/' }),
  computed: {
    ...mapGetters(['currentUserPermissions']),
    cards() {
      return [
        { key: 'ordered', label: 'Ordered' }, { key: 'received', label: 'Received' },
        { key: 'remaining', label: 'Remaining' }, { key: 'invoiced', label: 'Invoiced' },
        { key: 'not_invoiced', label: 'Uninvoiced' }, { key: 'purchased', label: 'Posted' },
        { key: 'order_value', label: 'PO value' }, { key: 'invoiced_value', label: 'Invoice value' }
      ];
    }
  },
  mounted() { this.load(); },
  methods: {
    can(permission) { return (this.currentUserPermissions || []).includes(permission); },
    label(value) { return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, character => character.toUpperCase()); },
    auditChanges(audit) { return JSON.stringify({ before: audit.old_values || null, after: audit.new_values || null }, null, 2); },
    load() { axios.get('procurement/purchase-orders/' + this.$route.params.id).then(response => { this.order = response.data.purchase_order; this.progress = response.data.progress; }); },
    issue() { axios.post('procurement/purchase-orders/' + this.order.id + '/issue').then(this.load); },
    downloadPdf() {
      axios.get('procurement/purchase-orders/' + this.order.id + '/pdf', { responseType: 'blob' }).then(response => {
        const url = URL.createObjectURL(response.data);
        const link = document.createElement('a');
        link.href = url;
        link.download = this.order.number + '.pdf';
        link.click();
        URL.revokeObjectURL(url);
      });
    }
  }
};
</script>
