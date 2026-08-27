<template>
  <div class="main-content">
    <breadcumb :page="pageTitle" folder="Procurement" />

    <b-card v-if="viewing && gate">
      <div class="d-flex mb-3">
        <b-button v-if="['draft', 'pending_verification'].includes(gate.status) && can('gate_passes_confirm')" variant="success" class="mr-2" @click="confirm">Confirm receipt</b-button>
        <router-link
          v-if="['accepted', 'partially_accepted'].includes(gate.status) && can('Purchases_add')"
          class="btn btn-primary"
          :to="{ path: '/app/purchases/store', query: { gate_pass: gate.number } }"
        >Invoice</router-link>
        <a v-if="gate.attachment_path" class="btn btn-outline-secondary ml-2" :href="api + 'procurement/gate-passes/' + gate.id + '/attachment'">Attachment</a>
      </div>
      <b-row>
        <b-col md="6">
          <p><strong>Receipt type:</strong> {{ gate.purchase_order_id ? 'Purchase Order' : 'Direct receipt' }}</p>
          <p v-if="gate.purchase_order"><strong>Purchase Order:</strong> <router-link :to="'/app/procurement/purchase-orders/' + gate.purchase_order_id">{{ gate.purchase_order.number }}</router-link></p>
          <p><strong>Supplier:</strong> {{ gate.provider ? gate.provider.name : '-' }}</p>
          <p><strong>Supplier Gate Pass:</strong> {{ gate.supplier_gate_pass_number || '-' }}</p>
          <p><strong>Bilty:</strong> {{ gate.bilty_number || '-' }} &middot; <strong>Vehicle:</strong> {{ gate.vehicle_number || '-' }}</p>
        </b-col>
        <b-col md="6">
          <p><strong>Delivered:</strong> {{ gate.delivered_at }}</p>
          <p><strong>Destination:</strong> {{ gate.warehouse ? gate.warehouse.name : '-' }}</p>
          <span class="badge badge-light-primary">{{ label(gate.status) }}</span>
        </b-col>
      </b-row>
      <div class="table-responsive mt-3">
        <table class="table">
          <thead><tr><th>Product</th><th>Code</th><th>Qty</th></tr></thead>
          <tbody><tr v-for="item in gate.items" :key="item.id"><td>{{ displayProduct(item) }}</td><td>{{ item.sku || '-' }}</td><td>{{ formatQty(item.delivered_quantity) }}</td></tr></tbody>
        </table>
      </div>
    </b-card>

    <b-card v-else-if="ready">
      <b-form @submit.prevent="save">
        <b-form-group>
          <b-form-checkbox v-model="hasPurchaseOrder" switch @change="purchaseOrderModeChanged">Have Purchase Order</b-form-checkbox>
        </b-form-group>

        <template v-if="hasPurchaseOrder">
          <b-row>
            <b-col md="6">
              <b-form-group label="Supplier *">
                <v-select v-model="form.provider_id" :reduce="option => option.id" label="name" :options="meta.providers" placeholder="Choose supplier" @input="supplierChanged" />
              </b-form-group>
            </b-col>
            <b-col md="6">
              <b-form-group label="Purchase Order *">
                <v-select v-model="form.purchase_order_id" :reduce="option => option.id" label="label" :options="filteredPurchaseOrders" :disabled="!form.provider_id" placeholder="Choose purchase order" @input="purchaseOrderChanged" />
              </b-form-group>
            </b-col>
          </b-row>
          <b-alert v-if="loadingPurchaseOrder" show variant="light">Loading Purchase Order...</b-alert>
          <b-alert v-else-if="order" show variant="light">Receiving against <strong>{{ order.number }}</strong> from <strong>{{ order.provider.name }}</strong> into <strong>{{ order.warehouse.name }}</strong>.</b-alert>
          <b-alert v-else show variant="light">{{ form.provider_id ? 'Select an incomplete Purchase Order to load its receivable products.' : 'Select a supplier to see its incomplete Purchase Orders.' }}</b-alert>
        </template>

        <b-row v-else>
          <b-col md="6"><b-form-group label="Supplier *"><v-select v-model="form.provider_id" :reduce="option => option.id" label="name" :options="meta.providers" placeholder="Choose supplier" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Destination warehouse *"><v-select v-model="form.warehouse_id" :reduce="option => option.id" label="name" :options="meta.warehouses" placeholder="Choose warehouse" @input="warehouseChanged" /></b-form-group></b-col>
        </b-row>

        <b-row>
          <b-col md="3"><b-form-group label="Delivery date/time *"><b-form-input type="datetime-local" v-model="form.delivered_at" required /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Supplier Gate Pass"><b-form-input v-model="form.supplier_gate_pass_number" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Bilty / consignment"><b-form-input v-model="form.bilty_number" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Vehicle"><b-form-input v-model="form.vehicle_number" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Driver name"><b-form-input v-model="form.driver_name" /></b-form-group></b-col>
          <b-col md="3"><b-form-group label="Driver phone"><b-form-input v-model="form.driver_phone" /></b-form-group></b-col>
          <b-col md="6"><b-form-group label="Gate Pass image/PDF"><b-form-file @input="file = $event" accept="image/jpeg,image/png,application/pdf" /></b-form-group></b-col>
        </b-row>

        <template v-if="direct">
          <b-modal hide-footer id="gate_pass_product_scan" size="md" title="Barcode Scanner"><qrcode-scanner :qrbox="250" :fps="10" style="width:100%;height:calc(100vh - 56px)" @result="onScan" /></b-modal>
          <b-row><b-col md="12" class="mb-4">
            <h6>Add products</h6>
            <div class="autocomplete"><div class="input-with-icon">
              <img src="/assets_setup/scan.png" alt="Scan product" class="scan-icon" @click="showScanner">
              <input ref="product_autocomplete" class="autocomplete-input" :disabled="!form.warehouse_id || productsLoading" :placeholder="productSearchPlaceholder" :value="searchInput" @input="onSearchInput" @focus="handleFocus" @blur="focused = false" @keydown.enter.prevent="selectFirstResult">
            </div>
            <ul class="autocomplete-result-list" v-show="focused && productFilter.length">
              <li v-for="product in productFilter" :key="productKey(product)" class="autocomplete-result" @mousedown.prevent="selectProduct(product)">{{ resultLabel(product) }} <small class="text-muted ml-2">Stock: {{ product.qte }} {{ product.unitPurchase || '' }}</small></li>
            </ul></div>
            <small v-if="!form.warehouse_id" class="text-muted">Select a destination warehouse before adding products.</small>
            <small v-else-if="productsLoading" class="text-muted">Loading warehouse products...</small>
          </b-col></b-row>
        </template>

        <div class="table-responsive"><table class="table table-hover">
          <thead><tr><th>Product</th><th>Code</th><th v-if="!direct">Ordered Quantity</th><th>Receiving Quantity</th><th v-if="direct"></th></tr></thead>
          <tbody>
            <tr v-if="!lines.length"><td colspan="4">{{ direct ? 'No products added. Search or scan a product above.' : (order ? 'No receivable products remain.' : 'Select a Purchase Order to load products.') }}</td></tr>
            <tr v-for="(line, index) in lines" :key="line.line_key || line.purchase_order_item_id">
              <td>{{ line.product }} {{ line.model || '' }}</td><td>{{ line.sku || '-' }}</td>
              <td v-if="!direct">{{ formatQty(line.ordered) }}</td>
              <td><b-form-input type="number" step="1" min="0" v-model.number="line.delivered_quantity" /></td>
              <td v-if="direct"><b-button size="sm" variant="outline-danger" @click="lines.splice(index, 1)"><lucide-icon name="x" /></b-button></td>
            </tr>
          </tbody>
        </table></div>
        <b-form-group label="Notes"><b-form-textarea v-model="form.notes" /></b-form-group>
        <b-button type="submit" variant="primary" :disabled="saving">{{ saving ? 'Saving...' : 'Record and send for verification' }}</b-button>
      </b-form>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from 'vuex';

export default {
  data() {
    return {
      order: null, gate: null, lines: [], file: null, ready: false, saving: false, api: '/api/',
      hasPurchaseOrder: false, loadingPurchaseOrder: false,
      meta: { providers: [], warehouses: [], purchase_orders: [] }, productsLoading: false, warehouseProducts: [], productFilter: [],
      focused: false, searchInput: '', searchTimer: null, nextLineKey: 1,
      form: {
        purchase_order_id: null, provider_id: null, warehouse_id: null,
        delivered_at: new Date(Date.now() - new Date().getTimezoneOffset() * 60000).toISOString().slice(0, 16),
        supplier_gate_pass_number: '', bilty_number: '', vehicle_number: '', driver_name: '', driver_phone: '', notes: ''
      }
    };
  },
  computed: {
    ...mapGetters(['currentUserPermissions']),
    viewing() { return this.$route.name === 'procurement_gate_detail'; },
    direct() { return !this.hasPurchaseOrder; },
    filteredPurchaseOrders() {
      if (!this.form.provider_id) return [];
      return this.meta.purchase_orders.filter(order => Number(order.provider_id) === Number(this.form.provider_id));
    },
    pageTitle() {
      if (this.viewing) return this.gate ? this.gate.number : 'Gate Pass';
      return 'Add Gate Pass';
    },
    productSearchPlaceholder() {
      if (!this.form.warehouse_id) return 'Select destination warehouse first';
      if (this.productsLoading) return 'Loading products...';
      return 'Scan or search product by code, model, or name';
    }
  },
  mounted() {
    if (this.viewing) this.loadGate();
    else {
      const purchaseOrderId = this.$route.name === 'procurement_gate_create' ? Number(this.$route.params.id) : null;
      this.hasPurchaseOrder = Boolean(purchaseOrderId);
      this.form.purchase_order_id = purchaseOrderId;
      this.loadMetadata(purchaseOrderId);
    }
  },
  beforeDestroy() { if (this.searchTimer) clearTimeout(this.searchTimer); },
  methods: {
    can(permission) { return (this.currentUserPermissions || []).includes(permission); },
    label(value) { return String(value || '').replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase()); },
    displayProduct(item) {
      const source = item.purchase_order_item || item;
      return [source.product_name || ('Product #' + item.product_id), source.variant_name].filter(Boolean).join(' ');
    },
    formatQty(value) { return Number(value || 0).toFixed(0); },
    errorMessage(error, fallback) {
      const data = error && error.response && error.response.data;
      if (data && data.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
      }
      return (data && data.message) || fallback;
    },
    toast(message, variant = 'warning') { this.$root.$bvToast.toast(message, { title: 'Gate Pass', variant, solid: true }); },
    loadGate() {
      axios.get('procurement/gate-passes/' + this.$route.params.id).then(response => { this.gate = response.data.gate_pass; })
        .catch(error => this.toast(this.errorMessage(error, 'Could not load the Gate Pass.'), 'danger'));
    },
    applyMetadata(data) {
      this.meta = {
        providers: data.providers || [],
        warehouses: data.warehouses || [],
        purchase_orders: (data.purchase_orders || []).map(order => ({
          ...order,
          label: [order.number, order.provider && order.provider.name, order.warehouse && order.warehouse.name].filter(Boolean).join(' - ')
        }))
      };
      if (data.purchase_order) {
        this.order = data.purchase_order;
        this.form.provider_id = this.order.provider_id;
        this.form.warehouse_id = this.order.warehouse_id;
        this.lines = data.progress.lines.map(line => ({ ...line, delivered_quantity: 0 }));
      }
    },
    loadMetadata(purchaseOrderId = null) {
      axios.get('procurement/gate-passes-metadata', { params: purchaseOrderId ? { purchase_order_id: purchaseOrderId } : {} }).then(response => {
        this.applyMetadata(response.data);
        this.ready = true;
      }).catch(error => this.toast(this.errorMessage(error, 'Could not load the Gate Pass form.'), 'danger'));
    },
    purchaseOrderModeChanged(enabled) {
      this.hasPurchaseOrder = Boolean(enabled);
      this.order = null;
      this.lines = [];
      this.form.purchase_order_id = null;
      this.form.provider_id = null;
      this.form.warehouse_id = null;
      this.warehouseProducts = [];
      this.clearSearch();
    },
    supplierChanged(providerId) {
      if (this.order && Number(this.order.provider_id) === Number(providerId)) return;
      this.order = null;
      this.lines = [];
      this.form.purchase_order_id = null;
      this.form.warehouse_id = null;
    },
    purchaseOrderChanged(purchaseOrderId) {
      this.order = null;
      this.lines = [];
      this.form.warehouse_id = null;
      if (!purchaseOrderId) return;
      this.loadingPurchaseOrder = true;
      axios.get('procurement/gate-passes-metadata', { params: { purchase_order_id: purchaseOrderId } }).then(response => {
        this.applyMetadata(response.data);
      }).catch(error => {
        this.form.purchase_order_id = null;
        this.toast(this.errorMessage(error, 'Could not load the Purchase Order.'), 'danger');
      }).finally(() => { this.loadingPurchaseOrder = false; });
    },
    warehouseChanged(warehouseId) { this.lines = []; this.clearSearch(); this.loadWarehouseProducts(warehouseId); },
    loadWarehouseProducts(warehouseId) {
      this.warehouseProducts = [];
      if (!warehouseId) return;
      this.productsLoading = true;
      axios.get('get_Products_by_warehouse/' + warehouseId, { params: { stock: 0, product_service: 0, product_combo: 1, include_out_of_stock: 1 } })
        .then(response => { this.warehouseProducts = Array.isArray(response.data) ? response.data : []; })
        .catch(error => this.toast(this.errorMessage(error, 'Could not load products for this warehouse.'), 'danger'))
        .finally(() => { this.productsLoading = false; });
    },
    handleFocus() { this.focused = true; if (this.searchInput.length >= 2) this.searchProducts(true); },
    onSearchInput(event) { this.searchInput = event.target.value; this.searchProducts(); },
    searchProducts(immediate = false) {
      if (this.searchTimer) clearTimeout(this.searchTimer);
      const term = this.searchInput.trim().toLowerCase();
      if (term.length < 2 || !this.form.warehouse_id) { this.productFilter = []; return; }
      const runSearch = () => {
        const exact = this.warehouseProducts.filter(product => String(product.code || '').toLowerCase() === term || String(product.barcode || '').toLowerCase() === term);
        if (exact.length === 1) { this.selectProduct(exact[0]); return; }
        this.productFilter = this.warehouseProducts.filter(product => [product.name, product.code, product.barcode, product.Variant].filter(Boolean).join(' ').toLowerCase().includes(term)).slice(0, 50);
      };
      if (immediate) runSearch(); else this.searchTimer = setTimeout(runSearch, 300);
    },
    selectFirstResult() { if (this.productFilter.length) this.selectProduct(this.productFilter[0]); else this.searchProducts(true); },
    selectProduct(result) {
      const variantId = result.product_variant_id || null;
      const duplicate = this.lines.find(line => Number(line.product_id) === Number(result.id) && Number(line.product_variant_id || 0) === Number(variantId || 0));
      if (duplicate) { this.toast('This product/model is already added.'); this.clearSearch(); return; }
      axios.get('show_product_data/' + result.id + '/' + (variantId || 0) + '/' + this.form.warehouse_id).then(response => {
        const product = response.data;
        const modelMatch = String(product.name || result.name || '').match(/^\[([^\]]+)]/);
        this.lines.unshift({
          line_key: this.nextLineKey++, product_id: Number(result.id), product_variant_id: variantId ? Number(variantId) : null,
          unit_id: product.purchase_unit_id || product.unit_id || null,
          product: String(product.name || result.name || '').replace(/^\[[^\]]+]\s*/, ''), model: modelMatch ? modelMatch[1] : '',
          sku: result.code || product.code || '', delivered_quantity: 1
        });
        this.clearSearch();
        this.$nextTick(() => this.$refs.product_autocomplete && this.$refs.product_autocomplete.focus());
      }).catch(error => this.toast(this.errorMessage(error, 'Could not add this product.'), 'danger'));
    },
    productKey(product) { return String(product.id) + ':' + String(product.product_variant_id || 0); },
    resultLabel(product) { return String(product.code || '') + ' (' + String(product.name || '') + ')'; },
    showScanner() {
      if (!this.form.warehouse_id) { this.toast('Select a destination warehouse first.'); return; }
      this.$bvModal.show('gate_pass_product_scan');
    },
    onScan(decodedText) { this.searchInput = String(decodedText || ''); this.$bvModal.hide('gate_pass_product_scan'); this.searchProducts(true); },
    clearSearch() { this.searchInput = ''; this.productFilter = []; if (this.$refs.product_autocomplete) this.$refs.product_autocomplete.value = ''; },
    validateForm() {
      if (this.hasPurchaseOrder && !this.form.provider_id) return 'Select a supplier.';
      if (this.hasPurchaseOrder && !this.form.purchase_order_id) return 'Select a Purchase Order.';
      if (this.hasPurchaseOrder && !this.order) return 'Wait for the Purchase Order to finish loading.';
      if (this.direct && !this.form.provider_id) return 'Select a supplier.';
      if (this.direct && !this.form.warehouse_id) return 'Select a destination warehouse.';
      const items = this.lines.filter(line => Number(line.delivered_quantity) > 0);
      if (!items.length) return 'Add at least one product with a quantity greater than zero.';
      if (items.some(line => !Number.isInteger(Number(line.delivered_quantity)))) return 'Quantity must be a whole number.';
      if (this.direct && items.some(line => !line.unit_id)) return 'A purchase unit is missing for one of the selected products.';
      return null;
    },
    save() {
      const validationError = this.validateForm();
      if (validationError) { this.toast(validationError); return; }
      const items = this.lines.filter(line => Number(line.delivered_quantity) > 0).map(line => this.direct ? {
        product_id: line.product_id, product_variant_id: line.product_variant_id, unit_id: line.unit_id,
        delivered_quantity: line.delivered_quantity, accepted_quantity: line.delivered_quantity, rejected_quantity: 0
      } : {
        purchase_order_item_id: line.purchase_order_item_id, delivered_quantity: line.delivered_quantity,
        accepted_quantity: line.delivered_quantity, rejected_quantity: 0
      });
      const payload = { ...this.form, receipt_type: this.direct ? 'direct' : 'purchase_order', purchase_order_id: this.direct ? null : this.order.id, submit_for_verification: 1 };
      const formData = new FormData();
      Object.entries(payload).forEach(([key, value]) => { if (value !== null && value !== '') formData.append(key, value); });
      formData.append('items', JSON.stringify(items));
      if (this.file) formData.append('attachment', this.file);
      this.saving = true;
      axios.post('procurement/gate-passes', formData).then(() => { this.$router.push('/app/procurement/gate-passes'); })
        .catch(error => this.toast(this.errorMessage(error, 'Could not record the Gate Pass.'), 'danger')).finally(() => { this.saving = false; });
    },
    confirm() {
      axios.post('procurement/gate-passes/' + this.gate.id + '/confirm').then(this.loadGate)
        .catch(error => this.toast(this.errorMessage(error, 'Could not confirm the Gate Pass.'), 'danger'));
    }
  }
};
</script>

<style scoped>
.input-with-icon { display: flex; align-items: center; }
.scan-icon { width: 50px; height: 50px; margin-right: 8px; cursor: pointer; }
</style>
