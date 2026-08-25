<template>
  <div class="main-content">
    <breadcumb :page="editing ? 'Edit Purchase Order' : 'New Purchase Order'" folder="Procurement" />

    <b-card v-if="ready">
      <b-form @submit.prevent="save">
        <b-row>
          <b-col md="3">
            <b-form-group label="PO date *">
              <b-form-input type="date" v-model="form.order_date" required />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group label="Expected delivery">
              <b-form-input type="date" v-model="form.expected_delivery_date" />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group label="Supplier *">
              <v-select
                v-model="form.provider_id"
                :reduce="option => option.id"
                label="name"
                :options="meta.providers"
                placeholder="Choose supplier"
              />
            </b-form-group>
          </b-col>
          <b-col md="3">
            <b-form-group label="Destination *">
              <v-select
                v-model="form.warehouse_id"
                :reduce="option => option.id"
                label="name"
                :options="meta.warehouses"
                placeholder="Choose destination"
                @input="warehouseChanged"
              />
            </b-form-group>
          </b-col>
        </b-row>

        <b-modal hide-footer id="po_product_scan" size="md" title="Barcode Scanner">
          <qrcode-scanner
            :qrbox="250"
            :fps="10"
            style="width: 100%; height: calc(100vh - 56px);"
            @result="onScan"
          />
        </b-modal>

        <b-row>
          <b-col md="12" class="mb-4">
            <h6>Product</h6>
            <div id="po-product-autocomplete" class="autocomplete">
              <div class="input-with-icon">
                <img
                  src="/assets_setup/scan.png"
                  alt="Scan product"
                  class="scan-icon"
                  @click="showScanner"
                >
                <input
                  ref="product_autocomplete"
                  class="autocomplete-input"
                  :disabled="!form.warehouse_id || productsLoading"
                  :placeholder="productSearchPlaceholder"
                  :value="searchInput"
                  @input="onSearchInput"
                  @focus="handleFocus"
                  @blur="handleBlur"
                  @keydown.enter.prevent="selectFirstResult"
                >
              </div>
              <ul class="autocomplete-result-list" v-show="focused && productFilter.length">
                <li
                  v-for="product in productFilter"
                  :key="productKey(product)"
                  class="autocomplete-result"
                  @mousedown.prevent="selectProduct(product)"
                >
                  {{ resultLabel(product) }}
                  <small class="text-muted ml-2">Stock: {{ product.qte }} {{ product.unitPurchase || '' }}</small>
                </li>
              </ul>
            </div>
            <small v-if="!form.warehouse_id" class="text-muted">Select a destination warehouse before adding products.</small>
            <small v-else-if="productsLoading" class="text-muted">Loading warehouse products…</small>
          </b-col>
        </b-row>

        <div class="table-responsive">
          <table class="table table-hover">
            <thead class="bg-gray-300">
              <tr>
                <th>#</th>
                <th>Code</th>
                <th>Product</th>
                <th>Model</th>
                <th>Unit</th>
                <th style="min-width: 145px">Qty</th>
                <th style="min-width: 130px">Unit price</th>
                <th style="min-width: 120px">Discount</th>
                <th style="min-width: 170px">Tax</th>
                <th class="text-center"><i class="fa fa-trash"></i></th>
              </tr>
            </thead>
            <tbody>
              <tr v-if="form.items.length === 0">
                <td colspan="10">No products added. Search or scan a product above.</td>
              </tr>
              <tr
                v-for="(line, index) in form.items"
                :key="line.line_key"
                :ref="'po_line_' + line.line_key"
                :class="{ 'po-row-highlight': highlightedLineKey === line.line_key }"
              >
                <td>{{ index + 1 }}</td>
                <td>{{ line.sku || '—' }}</td>
                <td>{{ line.product_name || productName(line) }}</td>
                <td>{{ line.variant_name || variantName(line) || '—' }}</td>
                <td>{{ line.unit_name || unitName(line) || '—' }}</td>
                <td>
                  <b-input-group>
                    <b-input-group-prepend>
                      <b-button size="sm" variant="primary" @click="decrement(line)">−</b-button>
                    </b-input-group-prepend>
                    <b-form-input
                      type="number"
                      step="0.000001"
                      min="0.000001"
                      v-model.number="line.quantity"
                      required
                    />
                    <b-input-group-append>
                      <b-button size="sm" variant="primary" @click="increment(line)">+</b-button>
                    </b-input-group-append>
                  </b-input-group>
                </td>
                <td>
                  <b-form-input type="number" step="0.01" min="0" v-model.number="line.unit_price" />
                </td>
                <td>
                  <b-form-input type="number" step="0.01" min="0" v-model.number="line.discount" />
                </td>
                <td>
                  <v-select
                    v-model="line.tax_id"
                    :reduce="option => option.id"
                    label="name"
                    :options="meta.taxes"
                    placeholder="No tax"
                  />
                </td>
                <td class="text-center">
                  <b-button variant="outline-danger" size="sm" @click="removeLine(index)">
                    <lucide-icon name="x" />
                  </b-button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <b-row class="mt-3">
          <b-col md="6">
            <b-form-group label="Notes">
              <b-form-textarea v-model="form.notes" rows="4" />
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Terms and conditions">
              <b-form-textarea v-model="form.terms" rows="4" />
            </b-form-group>
          </b-col>
        </b-row>

        <b-button type="submit" variant="primary" :disabled="saving">
          {{ saving ? 'Saving…' : 'Save draft' }}
        </b-button>
      </b-form>
    </b-card>
  </div>
</template>

<script>
export default {
  data() {
    return {
      ready: false,
      saving: false,
      productsLoading: false,
      focused: false,
      searchTimer: null,
      searchInput: '',
      productFilter: [],
      warehouseProducts: [],
      highlightedLineKey: null,
      highlightTimer: null,
      nextLineKey: 1,
      meta: { providers: [], warehouses: [], products: [], taxes: [] },
      form: {
        order_date: new Date().toISOString().slice(0, 10),
        expected_delivery_date: null,
        provider_id: null,
        warehouse_id: null,
        notes: '',
        terms: '',
        items: []
      }
    };
  },

  computed: {
    editing() {
      return !!this.$route.params.id;
    },

    productSearchPlaceholder() {
      if (!this.form.warehouse_id) return 'Select destination warehouse first';
      if (this.productsLoading) return 'Loading products…';
      return 'Scan or search product by code, model, or name';
    }
  },

  mounted() {
    Promise.all([
      axios.get('procurement/metadata'),
      this.editing
        ? axios.get('procurement/purchase-orders/' + this.$route.params.id)
        : Promise.resolve(null)
    ]).then(([metadataResponse, orderResponse]) => {
      this.meta = metadataResponse.data;

      if (orderResponse) {
        const order = orderResponse.data.purchase_order;
        this.form = {
          order_date: order.order_date.slice(0, 10),
          expected_delivery_date: order.expected_delivery_date && order.expected_delivery_date.slice(0, 10),
          provider_id: order.provider_id,
          warehouse_id: order.warehouse_id,
          notes: order.notes,
          terms: order.terms,
          items: order.items.map(item => ({
            line_key: this.newLineKey(),
            product_id: item.product_id,
            product_variant_id: item.product_variant_id,
            unit_id: item.unit_id,
            product_name: item.product_name,
            variant_name: item.variant_name,
            sku: item.sku,
            unit_name: item.unit_name,
            quantity: Number(item.ordered_quantity),
            unit_price: Number(item.unit_price),
            discount: Number(item.discount),
            discount_method: item.discount_method,
            tax_id: item.tax_id
          }))
        };
      }

      this.ready = true;
      if (this.form.warehouse_id) this.loadWarehouseProducts(this.form.warehouse_id);
    }).catch(error => {
      this.toast(this.errorMessage(error, 'Could not load the Purchase Order form.'), 'danger');
    });
  },

  beforeDestroy() {
    if (this.searchTimer) clearTimeout(this.searchTimer);
    if (this.highlightTimer) clearTimeout(this.highlightTimer);
  },

  methods: {
    newLineKey() {
      return this.nextLineKey++;
    },

    warehouseChanged(warehouseId) {
      this.clearSearch();
      this.loadWarehouseProducts(warehouseId);
    },

    loadWarehouseProducts(warehouseId) {
      this.warehouseProducts = [];
      if (!warehouseId) return;

      this.productsLoading = true;
      axios.get('get_Products_by_warehouse/' + warehouseId, {
        params: {
          stock: 0,
          product_service: 0,
          product_combo: 1,
          include_out_of_stock: 1
        }
      }).then(response => {
        this.warehouseProducts = Array.isArray(response.data) ? response.data : [];
      }).catch(error => {
        this.toast(this.errorMessage(error, 'Could not load products for this warehouse.'), 'danger');
      }).finally(() => {
        this.productsLoading = false;
      });
    },

    handleFocus() {
      this.focused = true;
      if (this.searchInput.length >= 2) this.searchProducts(true);
    },

    handleBlur() {
      this.focused = false;
    },

    onSearchInput(event) {
      this.searchInput = event.target.value;
      this.searchProducts();
    },

    searchProducts(immediate = false) {
      if (this.searchTimer) clearTimeout(this.searchTimer);
      const term = this.searchInput.trim().toLowerCase();

      if (term.length < 2 || !this.form.warehouse_id) {
        this.productFilter = [];
        return;
      }

      const runSearch = () => {
        const exact = this.warehouseProducts.filter(product =>
          String(product.code || '').toLowerCase() === term ||
          String(product.barcode || '').toLowerCase() === term
        );

        if (exact.length === 1) {
          this.selectProduct(exact[0]);
          return;
        }

        this.productFilter = this.warehouseProducts.filter(product => {
          const haystack = [product.name, product.code, product.barcode, product.Variant]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
          return haystack.includes(term);
        }).slice(0, 50);
      };

      if (immediate) runSearch();
      else this.searchTimer = setTimeout(runSearch, 300);
    },

    selectFirstResult() {
      if (this.productFilter.length) this.selectProduct(this.productFilter[0]);
      else this.searchProducts(true);
    },

    selectProduct(result) {
      const variantId = result.product_variant_id || null;
      const existing = this.form.items.find(line =>
        Number(line.product_id) === Number(result.id) &&
        Number(line.product_variant_id || 0) === Number(variantId || 0)
      );

      if (existing) {
        this.toast('This product/model is already added.', 'warning');
        this.highlightLine(existing.line_key);
        this.clearSearch();
        return;
      }

      axios.get('show_product_data/' + result.id + '/' + (variantId || 0) + '/' + this.form.warehouse_id)
        .then(response => {
          const product = response.data;
          const metadataProduct = this.meta.products.find(item => Number(item.id) === Number(result.id));
          const metadataVariant = metadataProduct && Array.isArray(metadataProduct.variants)
            ? metadataProduct.variants.find(item => Number(item.id) === Number(variantId))
            : null;
          const line = {
            line_key: this.newLineKey(),
            product_id: Number(result.id),
            product_variant_id: variantId ? Number(variantId) : null,
            unit_id: product.purchase_unit_id || product.unit_id || null,
            product_name: metadataProduct ? metadataProduct.name : product.name,
            variant_name: metadataVariant ? metadataVariant.name : this.modelFromResult(result),
            sku: result.code || product.code,
            unit_name: product.unitPurchase || product.unit || '',
            quantity: 1,
            unit_price: Number(product.Unit_cost || 0),
            discount: 0,
            discount_method: 'fixed',
            tax_id: null
          };

          this.form.items.unshift(line);
          this.highlightLine(line.line_key);
          this.clearSearch();
          this.$nextTick(() => this.$refs.product_autocomplete && this.$refs.product_autocomplete.focus());
        }).catch(error => {
          this.toast(this.errorMessage(error, 'Could not add this product.'), 'danger');
        });
    },

    modelFromResult(result) {
      if (!result.product_variant_id) return '';
      const match = String(result.name || '').match(/^\[([^\]]+)]/);
      return match ? match[1] : '';
    },

    productKey(product) {
      return String(product.id) + ':' + String(product.product_variant_id || 0);
    },

    resultLabel(product) {
      return String(product.code || '') + ' (' + String(product.name || '') + ')';
    },

    productName(line) {
      const product = this.meta.products.find(item => Number(item.id) === Number(line.product_id));
      return product ? product.name : 'Product #' + line.product_id;
    },

    variantName(line) {
      if (!line.product_variant_id) return '';
      const product = this.meta.products.find(item => Number(item.id) === Number(line.product_id));
      const variant = product && Array.isArray(product.variants)
        ? product.variants.find(item => Number(item.id) === Number(line.product_variant_id))
        : null;
      return variant ? variant.name : '';
    },

    unitName(line) {
      const product = this.meta.products.find(item => Number(item.id) === Number(line.product_id));
      return product && product.unit_purchase
        ? (product.unit_purchase.ShortName || product.unit_purchase.name)
        : '';
    },

    increment(line) {
      line.quantity = Number(line.quantity || 0) + 1;
    },

    decrement(line) {
      const quantity = Number(line.quantity || 0);
      if (quantity > 1) line.quantity = quantity - 1;
    },

    removeLine(index) {
      this.form.items.splice(index, 1);
    },

    highlightLine(lineKey) {
      this.highlightedLineKey = lineKey;
      if (this.highlightTimer) clearTimeout(this.highlightTimer);
      this.$nextTick(() => {
        let row = this.$refs['po_line_' + lineKey];
        if (Array.isArray(row)) row = row[0];
        if (row && typeof row.scrollIntoView === 'function') {
          row.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
      });
      this.highlightTimer = setTimeout(() => {
        this.highlightedLineKey = null;
      }, 1800);
    },

    showScanner() {
      if (!this.form.warehouse_id) {
        this.toast('Select a destination warehouse first.', 'warning');
        return;
      }
      this.$bvModal.show('po_product_scan');
    },

    onScan(decodedText) {
      this.searchInput = String(decodedText || '');
      if (this.$refs.product_autocomplete) this.$refs.product_autocomplete.value = this.searchInput;
      this.$bvModal.hide('po_product_scan');
      this.searchProducts(true);
    },

    clearSearch() {
      this.searchInput = '';
      this.productFilter = [];
      if (this.$refs.product_autocomplete) this.$refs.product_autocomplete.value = '';
    },

    validateForm() {
      if (!this.form.provider_id) return 'Select a supplier.';
      if (!this.form.warehouse_id) return 'Select a destination warehouse.';
      if (!this.form.items.length) return 'Add at least one product.';
      return null;
    },

    save() {
      const validationError = this.validateForm();
      if (validationError) {
        this.toast(validationError, 'warning');
        return;
      }

      this.saving = true;
      const request = this.editing
        ? axios.put('procurement/purchase-orders/' + this.$route.params.id, this.form)
        : axios.post('procurement/purchase-orders', this.form);

      request.then(response => {
        this.$router.push('/app/procurement/purchase-orders/' + response.data.purchase_order.id);
      }).catch(error => {
        this.toast(this.errorMessage(error, 'Could not save the Purchase Order.'), 'danger');
      }).finally(() => {
        this.saving = false;
      });
    },

    errorMessage(error, fallback) {
      const response = error && error.response && error.response.data;
      if (response && response.errors) {
        const first = Object.values(response.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
      }
      return (response && response.message) || fallback;
    },

    toast(message, variant) {
      this.$root.$bvToast.toast(message, {
        title: variant === 'danger' ? 'Error' : 'Purchase Order',
        variant,
        solid: true
      });
    }
  }
};
</script>

<style scoped>
.input-with-icon {
  display: flex;
  align-items: center;
}

.scan-icon {
  width: 50px;
  height: 50px;
  margin-right: 8px;
  cursor: pointer;
}

.po-row-highlight > td {
  animation: po-row-pulse 1.8s ease-out;
}

@keyframes po-row-pulse {
  0%, 35% {
    background-color: rgba(102, 84, 241, 0.22);
    box-shadow: inset 0 2px 0 rgba(102, 84, 241, 0.55), inset 0 -2px 0 rgba(102, 84, 241, 0.55);
  }
  100% {
    background-color: transparent;
    box-shadow: none;
  }
}
</style>
