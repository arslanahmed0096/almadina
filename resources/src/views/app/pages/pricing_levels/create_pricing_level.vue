<template>
  <div class="main-content pricing-level-page">
    <breadcumb page="Create Pricing Level" folder="Pricing Level" />

    <b-card class="pricing-selector-card mb-4">
      <b-row align-v="end">
        <b-col lg="9" md="8">
          <b-form-group label="Select Product" label-for="pricing-product">
            <v-select
              id="pricing-product"
              v-model="selectedProductId"
              :options="productOptions"
              :reduce="product => product.id"
              label="display"
              :loading="productsLoading"
              :disabled="productsLoading || submitting"
              placeholder="Search by product name or code"
              @input="onProductSelected"
            >
              <template slot="option" slot-scope="option">
                <div class="product-option">
                  <strong>{{ option.name }}</strong>
                  <small>{{ option.code }} · {{ option.type }}</small>
                </div>
              </template>
            </v-select>
          </b-form-group>
        </b-col>
        <b-col lg="3" md="4" class="text-md-right">
          <router-link to="/app/pricing-levels/list" class="btn btn-outline-secondary mb-3">
            <lucide-icon name="list" /> All Pricing Levels
          </router-link>
        </b-col>
      </b-row>
    </b-card>

    <div v-if="pricingLoading" class="pricing-loading-card">
      <div class="spinner spinner-primary"></div>
    </div>

    <b-card v-else-if="pricingForm.id" class="pricing-form-card">
      <div class="selected-product mb-4">
        <div class="selected-product-main">
          <span class="selected-product-icon"><lucide-icon name="package" /></span>
          <div>
            <small>SELECTED PRODUCT</small>
            <h4>{{ pricingForm.name }}</h4>
          </div>
        </div>
        <span class="product-code">{{ pricingForm.code }}</span>
      </div>

      <b-form @submit.prevent="submitPricingLevel">
        <template v-if="pricingForm.type !== 'is_variant'">
          <section class="price-section purchase-section">
            <h5><lucide-icon name="shopping-cart" /> Purchase Pricing</h5>
            <b-row>
              <b-col md="4">
                <b-form-group label="Company RB Price">
                  <b-form-input v-model.number="pricingForm.company_rb_price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group label="MRP Price">
                  <b-form-input v-model.number="pricingForm.mrp_price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
              <b-col md="4">
                <b-form-group label="Product Cost">
                  <b-form-input v-model.number="pricingForm.cost" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
            </b-row>
          </section>

          <section class="price-section sale-section">
            <h5><lucide-icon name="dollar-sign" /> Sale Pricing</h5>
            <b-row>
              <b-col lg="3" md="6">
                <b-form-group label="Fix Price">
                  <b-form-input v-model.number="pricingForm.fix_price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
              <b-col lg="3" md="6">
                <b-form-group label="Retail Price (Almadina Price)">
                  <b-form-input v-model.number="pricingForm.price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
              <b-col lg="3" md="6">
                <b-form-group label="Whole Sale Price">
                  <b-form-input v-model.number="pricingForm.wholesale_price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
              <b-col lg="3" md="6">
                <b-form-group label="Minimum Price">
                  <b-form-input v-model.number="pricingForm.min_price" type="number" min="0" step="0.01" required />
                </b-form-group>
              </b-col>
            </b-row>
          </section>
        </template>

        <div v-else class="table-responsive variant-pricing-table">
          <table class="table table-bordered table-hover mb-0">
            <thead>
              <tr>
                <th>Variant</th>
                <th>Company RB</th>
                <th>MRP</th>
                <th>Product Cost</th>
                <th>Fix Price</th>
                <th>Retail (Almadina)</th>
                <th>Whole Sale</th>
                <th>Minimum</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="variant in pricingForm.variants" :key="variant.id">
                <td class="variant-name">
                  <strong>{{ variant.name }}</strong>
                  <small>{{ variant.code }}</small>
                </td>
                <td><b-form-input v-model.number="variant.company_rb_price" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.mrp_price" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.cost" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.fix_price" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.price" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.wholesale_price" type="number" min="0" step="0.01" required /></td>
                <td><b-form-input v-model.number="variant.min_price" type="number" min="0" step="0.01" required /></td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="form-actions mt-4">
          <span class="text-muted"><lucide-icon name="info" /> Prices are applied to the selected product when saved.</span>
          <div>
            <router-link to="/app/pricing-levels/list" class="btn btn-outline-secondary mr-2">Cancel</router-link>
            <b-button variant="primary" type="submit" :disabled="submitting">
              <span v-if="submitting" class="spinner sm spinner-white mr-2"></span>
              <lucide-icon v-else name="check" />
              {{ submitting ? "Saving..." : "Save Pricing Level" }}
            </b-button>
          </div>
        </div>
      </b-form>
    </b-card>

    <b-card v-else class="empty-pricing-state text-center">
      <lucide-icon name="tag" />
      <h4>Select a product to create its pricing level</h4>
      <p class="text-muted mb-0">You can configure purchase, retail, wholesale, and minimum prices here.</p>
    </b-card>
  </div>
</template>

<script>
export default {
  name: "CreatePricingLevel",
  metaInfo: { title: "Create Pricing Level" },
  data() {
    return {
      productsLoading: false,
      pricingLoading: false,
      submitting: false,
      selectedProductId: null,
      productOptions: [],
      pricingForm: this.emptyPricingForm()
    };
  },
  methods: {
    emptyPricingForm() {
      return {
        id: null,
        name: "",
        code: "",
        type: "",
        company_rb_price: 0,
        mrp_price: 0,
        cost: 0,
        fix_price: 0,
        price: 0,
        wholesale_price: 0,
        min_price: 0,
        variants: []
      };
    },
    normalizePricing(pricing) {
      const numericFields = [
        "company_rb_price", "mrp_price", "cost", "fix_price",
        "price", "wholesale_price", "min_price"
      ];
      const normalized = Object.assign(this.emptyPricingForm(), pricing || {});
      numericFields.forEach(field => {
        const value = Number(normalized[field]);
        normalized[field] = Number.isFinite(value) ? value : 0;
      });
      normalized.variants = (normalized.variants || []).map(variant => {
        const row = Object.assign({}, variant);
        numericFields.forEach(field => {
          const value = Number(row[field]);
          row[field] = Number.isFinite(value) ? value : 0;
        });
        return row;
      });
      return normalized;
    },
    makeToast(variant, message, title) {
      this.$root.$bvToast.toast(message, { title, variant, solid: true });
    },
    loadProducts() {
      this.productsLoading = true;
      return axios.get("products", {
        params: { page: 1, limit: -1, SortField: "name", SortType: "asc", pricing_level: 1 }
      })
        .then(response => {
          const products = Array.isArray(response.data.products) ? response.data.products : [];
          this.productOptions = products.map(product => ({
            id: product.id,
            name: product.name,
            code: product.code,
            type: product.type,
            display: `${product.name} — ${product.code}`
          }));
        })
        .catch(error => {
          const message = error.response && error.response.data && error.response.data.message
            ? error.response.data.message
            : "Unable to load products.";
          this.makeToast("danger", message, this.$t("Failed"));
        })
        .finally(() => {
          this.productsLoading = false;
        });
    },
    onProductSelected(productId) {
      if (!productId) {
        this.pricingForm = this.emptyPricingForm();
        return;
      }
      this.loadPricing(productId);
    },
    loadPricing(productId) {
      this.pricingLoading = true;
      axios.get(`products/${productId}/pricing-level`)
        .then(response => {
          this.pricingForm = this.normalizePricing(response.data.pricing);
        })
        .catch(error => {
          this.selectedProductId = null;
          this.pricingForm = this.emptyPricingForm();
          const message = error.response && error.response.data && error.response.data.message
            ? error.response.data.message
            : "Unable to load product pricing.";
          this.makeToast("danger", message, this.$t("Failed"));
        })
        .finally(() => {
          this.pricingLoading = false;
        });
    },
    submitPricingLevel() {
      if (!this.pricingForm.id || this.submitting) return;
      const fields = [
        "company_rb_price", "mrp_price", "cost", "fix_price",
        "price", "wholesale_price", "min_price"
      ];
      let payload;

      if (this.pricingForm.type === "is_variant") {
        payload = {
          variants: this.pricingForm.variants.map(variant => {
            const row = { id: variant.id };
            fields.forEach(field => { row[field] = variant[field]; });
            return row;
          })
        };
      } else {
        payload = {};
        fields.forEach(field => { payload[field] = this.pricingForm[field]; });
      }

      this.submitting = true;
      axios.put(`products/${this.pricingForm.id}/pricing-level`, payload)
        .then(() => {
          this.makeToast("success", "Pricing level saved successfully.", this.$t("Success"));
          this.$router.push({ name: "pricing_levels_index" });
        })
        .catch(error => {
          const errors = error.response && error.response.data ? error.response.data.errors || {} : {};
          const first = Object.values(errors)[0];
          const message = Array.isArray(first)
            ? first[0]
            : (error.response && error.response.data && error.response.data.message
              ? error.response.data.message
              : "Unable to save pricing level.");
          this.makeToast("danger", message, this.$t("Failed"));
        })
        .finally(() => {
          this.submitting = false;
        });
    }
  },
  created() {
    this.loadProducts().then(() => {
      const productId = Number(this.$route.query.product || 0);
      if (productId > 0) {
        this.selectedProductId = productId;
        this.loadPricing(productId);
      }
    });
  }
};
</script>

<style scoped>
.pricing-selector-card,
.pricing-form-card,
.empty-pricing-state,
.pricing-loading-card {
  border: 0;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(35, 31, 53, 0.07);
}

.pricing-loading-card {
  min-height: 220px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #fff;
}

.product-option {
  display: flex;
  flex-direction: column;
  padding: 2px 0;
}

.product-option small {
  color: #7b8494;
}

.selected-product {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 20px;
  border: 1px solid #ebe7f2;
  border-radius: 10px;
  background: #faf9fc;
}

.selected-product-main {
  display: flex;
  align-items: center;
  gap: 14px;
}

.selected-product-icon {
  width: 44px;
  height: 44px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: 10px;
  color: #fff;
  background: #7b3fb1;
}

.selected-product small {
  color: #8a8493;
  font-weight: 600;
  letter-spacing: 0.05em;
}

.selected-product h4 {
  margin: 3px 0 0;
}

.product-code {
  padding: 7px 12px;
  border-radius: 20px;
  color: #6d359f;
  background: #efe6f7;
  font-weight: 600;
}

.price-section {
  margin-bottom: 20px;
  padding: 20px;
  border-radius: 10px;
}

.price-section h5 {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 18px;
}

.purchase-section {
  border: 1px solid #dce8f7;
  background: #f7faff;
}

.sale-section {
  border: 1px solid #e8def2;
  background: #fcf9ff;
}

.variant-pricing-table {
  border-radius: 8px;
}

.variant-pricing-table table {
  min-width: 1200px;
}

.variant-name strong,
.variant-name small {
  display: block;
}

.variant-name small {
  margin-top: 3px;
  color: #8a8493;
}

.form-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.empty-pricing-state {
  padding: 60px 20px;
}

.empty-pricing-state > svg {
  width: 52px;
  height: 52px;
  margin-bottom: 18px;
  color: #7b3fb1;
}

@media (max-width: 767px) {
  .selected-product,
  .form-actions {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
