<template>
  <div class="main-content pricing-level-page">
    <breadcumb :page="isEditing ? 'Edit Pricing Level' : 'Create Pricing Level'" folder="Pricing Level" />

    <b-card class="pricing-filter-card mb-4">
      <b-row align-v="end">
        <b-col lg="4" md="5">
          <b-form-group label="Brand *" label-for="pricing-brand">
            <v-select
              id="pricing-brand"
              v-model="selectedBrandId"
              :options="brands"
              :reduce="brand => brand.id"
              label="name"
              :loading="optionsLoading"
              :disabled="isEditing || optionsLoading || searching || submitting"
              placeholder="Choose Brand"
              @input="onBrandSelected"
            />
          </b-form-group>
        </b-col>

        <b-col lg="4" md="5">
          <b-form-group label="Category *" label-for="pricing-category">
            <v-select
              id="pricing-category"
              v-model="selectedCategoryId"
              :options="categories"
              :reduce="category => category.id"
              label="name"
              :loading="categoriesLoading"
              :disabled="isEditing || !selectedBrandId || categoriesLoading || searching || submitting"
              placeholder="Choose Category"
              @input="persistDraft"
            />
          </b-form-group>
        </b-col>

        <b-col v-if="!isEditing" lg="2" md="2">
          <b-button
            block
            variant="primary"
            class="mb-3"
            :disabled="!canSearch || searching || submitting"
            @click="searchProducts"
          >
            <span v-if="searching" class="spinner sm spinner-white mr-2"></span>
            <lucide-icon v-else name="search" />
            {{ searching ? "Searching..." : "Search" }}
          </b-button>
        </b-col>

        <b-col lg="2" class="text-lg-right">
          <router-link to="/app/pricing-levels/list" class="btn btn-outline-secondary mb-3">
            <lucide-icon name="list" /> All Pricing Levels
          </router-link>
        </b-col>
      </b-row>
    </b-card>

    <div v-if="searching" class="pricing-loading-card">
      <div class="spinner spinner-primary"></div>
    </div>

    <b-card v-else-if="hasSearched && !pricingRows.length" class="empty-pricing-state text-center">
      <lucide-icon name="package-x" />
      <h4>No products found</h4>
      <p class="text-muted mb-0">No products match the selected brand and category.</p>
    </b-card>

    <b-card v-else-if="pricingRows.length" class="pricing-results-card">
      <div class="results-heading">
        <div>
          <h4 class="mb-1">Products</h4>
          <span class="text-muted">
            {{ productCount }} product{{ productCount === 1 ? "" : "s" }} {{ isEditing ? "in this entry" : "found" }}
            <template v-if="variantRowCount"> | {{ variantRowCount }} variant rows</template>
          </span>
        </div>
        <b-button variant="primary" :disabled="!pricingRows.length || submitting" @click="savePricingLevels">
          <span v-if="submitting" class="spinner sm spinner-white mr-2"></span>
          <lucide-icon v-else name="save" />
          {{ submitting ? "Saving..." : (isEditing ? "Update Pricing Level" : "Create Pricing Level") }}
        </b-button>
      </div>

      <div class="table-responsive pricing-table-wrap">
        <table class="table table-bordered table-hover pricing-table mb-0">
          <thead>
            <tr>
              <th rowspan="2">Name</th>
              <th rowspan="2">Code</th>
              <th rowspan="2">Brand</th>
              <th rowspan="2">Category</th>
              <th colspan="7" class="pricing-group-heading">Pricing Level</th>
            </tr>
            <tr>
              <th>Company RB</th>
              <th>MRP</th>
              <th>Product Cost</th>
              <th>Fix Price</th>
              <th>Retail Price</th>
              <th>Whole Sale</th>
              <th>Minimum</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="row in pricingRows"
              :key="row.row_key"
              :class="{ 'pricing-row-dirty': isDirty(row.product_id) }"
            >
              <td class="product-name-cell">
                <strong>{{ row.name }}</strong>
                <small v-if="row.variant_name">
                  <span class="variant-badge">Variant</span> {{ row.variant_name }}
                </small>
              </td>
              <td>{{ row.code }}</td>
              <td>{{ row.brand || "N/D" }}</td>
              <td class="category-cell">{{ row.category || "N/D" }}</td>
              <td v-for="field in priceFields" :key="`${row.row_key}-${field}`" class="price-input-cell">
                <b-form-input
                  v-model.number="row[field]"
                  type="number"
                  min="0"
                  step="0.01"
                  :disabled="submitting"
                  @input="markDirty(row.product_id)"
                  @blur="persistDraft"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="results-footer">
        <span class="text-muted">
          <lucide-icon name="info" /> Changed rows are highlighted until saved.
        </span>
        <b-button variant="primary" :disabled="!pricingRows.length || submitting" @click="savePricingLevels">
          <span v-if="submitting" class="spinner sm spinner-white mr-2"></span>
          <lucide-icon v-else name="save" />
          {{ submitting ? "Saving..." : (isEditing ? "Update Pricing Level" : "Create Pricing Level") }}
        </b-button>
      </div>
    </b-card>

    <b-card v-else class="empty-pricing-state text-center">
      <lucide-icon name="sliders" />
      <h4>Choose a brand and category</h4>
      <p class="text-muted mb-0">Click Search to load all matching products and update their pricing levels.</p>
    </b-card>
  </div>
</template>

<script>
import { mapGetters } from "vuex";

export default {
  name: "CreatePricingLevel",
  metaInfo() {
    return { title: this.isEditing ? "Edit Pricing Level" : "Create Pricing Level" };
  },
  data() {
    return {
      brands: [],
      categories: [],
      selectedBrandId: null,
      selectedCategoryId: null,
      pricingRows: [],
      optionsLoading: false,
      categoriesLoading: false,
      searching: false,
      submitting: false,
      hasSearched: false,
      dirtyProducts: {},
      priceFields: [
        "company_rb_price",
        "mrp_price",
        "cost",
        "fix_price",
        "price",
        "wholesale_price",
        "min_price"
      ]
    };
  },
  computed: {
    ...mapGetters(["currentUser"]),
    editingEntryId() {
      return Number(this.$route.params.id || 0);
    },
    isEditing() {
      return this.editingEntryId > 0;
    },
    draftStorageKey() {
      const userId = this.currentUser && this.currentUser.id ? this.currentUser.id : "anonymous";
      const scope = this.isEditing ? `edit-${this.editingEntryId}` : "create";
      return `pricing-level-draft:${userId}:${scope}`;
    },
    canSearch() {
      return !!this.selectedBrandId && !!this.selectedCategoryId;
    },
    dirtyCount() {
      return Object.keys(this.dirtyProducts).filter(id => this.dirtyProducts[id]).length;
    },
    productCount() {
      return new Set(this.pricingRows.map(row => row.product_id)).size;
    },
    variantRowCount() {
      return this.pricingRows.filter(row => row.variant_id).length;
    }
  },
  methods: {
    makeToast(variant, message, title) {
      this.$root.$bvToast.toast(message, { title, variant, solid: true });
    },
    errorMessage(error, fallback) {
      const response = error && error.response && error.response.data;
      if (response && response.errors) {
        const first = Object.values(response.errors)[0];
        if (Array.isArray(first) && first.length) return first[0];
      }
      return response && response.message ? response.message : fallback;
    },
    numericValue(value) {
      const number = Number(value);
      return Number.isFinite(number) ? number : 0;
    },
    loadOptions(brandId) {
      const initialLoad = !brandId;
      this.optionsLoading = initialLoad;
      this.categoriesLoading = !initialLoad;

      return axios.get("pricing-level/options", {
        params: brandId ? { brand_id: brandId } : {}
      })
        .then(response => {
          if (Array.isArray(response.data.brands)) this.brands = response.data.brands;
          this.categories = Array.isArray(response.data.categories) ? response.data.categories : [];
        })
        .catch(error => {
          this.makeToast("danger", this.errorMessage(error, "Unable to load pricing filters."), this.$t("Failed"));
        })
        .finally(() => {
          this.optionsLoading = false;
          this.categoriesLoading = false;
        });
    },
    onBrandSelected(brandId) {
      this.selectedCategoryId = null;
      this.categories = [];
      this.pricingRows = [];
      this.dirtyProducts = {};
      this.hasSearched = false;
      this.persistDraft();
      if (brandId) this.loadOptions(brandId);
    },
    normalizeRows(products) {
      const rows = [];
      (products || []).forEach(product => {
        const common = {
          product_id: product.id,
          created_at: product.created_at,
          name: product.name,
          brand: product.brand,
          category: product.categories_display || product.category,
          product_type: product.product_type || product.type
        };

        const variants = Array.isArray(product.pricing_variants)
          ? product.pricing_variants
          : (Array.isArray(product.variants) ? product.variants : []);

        if (common.product_type === "is_variant" && variants.length) {
          variants.forEach(variant => {
            const row = Object.assign({}, common, {
              row_key: `product-${product.id}-variant-${variant.id}`,
              variant_id: variant.id,
              variant_name: variant.name,
              code: variant.code || product.code
            });
            this.priceFields.forEach(field => { row[field] = this.numericValue(variant[field]); });
            rows.push(row);
          });
          return;
        }

        const row = Object.assign({}, common, {
          row_key: `product-${product.id}`,
          variant_id: null,
          variant_name: "",
          code: product.code
        });
        this.priceFields.forEach(field => { row[field] = this.numericValue(product[field]); });
        rows.push(row);
      });
      return rows;
    },
    searchProducts() {
      if (!this.canSearch || this.searching) {
        if (!this.canSearch) {
          this.makeToast("warning", "Please select both a brand and category.", this.$t("Warning") || "Warning");
        }
        return;
      }

      this.searching = true;
      this.hasSearched = true;
      this.pricingRows = [];
      this.dirtyProducts = {};

      axios.get("products", {
        params: {
          page: 1,
          limit: -1,
          SortField: "name",
          SortType: "asc",
          brand_id: this.selectedBrandId,
          category_id: this.selectedCategoryId,
          pricing_level: 1
        }
      })
        .then(response => {
          const products = Array.isArray(response.data.products) ? response.data.products : [];
          this.pricingRows = this.normalizeRows(products);
          this.persistDraft();
        })
        .catch(error => {
          this.makeToast("danger", this.errorMessage(error, "Unable to load products."), this.$t("Failed"));
        })
        .finally(() => {
          this.searching = false;
        });
    },
    loadDirectProduct(productId) {
      this.searching = true;
      this.hasSearched = true;
      return axios.get(`products/${productId}/pricing-level`)
        .then(response => {
          const product = response.data.pricing;
          this.selectedBrandId = product.brand_id || null;
          this.selectedCategoryId = product.category_id || null;
          return this.loadOptions(this.selectedBrandId).then(() => {
            this.pricingRows = this.normalizeRows([product]);
            this.persistDraft();
          });
        })
        .catch(error => {
          this.makeToast("danger", this.errorMessage(error, "Unable to load product pricing."), this.$t("Failed"));
        })
        .finally(() => {
          this.searching = false;
        });
    },
    markDirty(productId) {
      this.$set(this.dirtyProducts, String(productId), true);
    },
    isDirty(productId) {
      return !!this.dirtyProducts[String(productId)];
    },
    persistDraft() {
      if (typeof window === "undefined" || !window.localStorage) return;
      const draft = {
        entry_id: this.editingEntryId || null,
        brand_id: this.selectedBrandId,
        category_id: this.selectedCategoryId,
        has_searched: this.hasSearched,
        rows: this.pricingRows,
        dirty_products: this.dirtyProducts,
        saved_at: new Date().toISOString()
      };
      window.localStorage.setItem(this.draftStorageKey, JSON.stringify(draft));
    },
    readDraft() {
      if (typeof window === "undefined" || !window.localStorage) return null;
      try {
        const value = window.localStorage.getItem(this.draftStorageKey);
        return value ? JSON.parse(value) : null;
      } catch (error) {
        return null;
      }
    },
    clearDraft() {
      if (typeof window !== "undefined" && window.localStorage) {
        window.localStorage.removeItem(this.draftStorageKey);
      }
    },
    restoreDraft(draft) {
      if (!draft || !Array.isArray(draft.rows) || !draft.rows.length) return Promise.resolve(false);
      if (this.isEditing && Number(draft.entry_id) !== this.editingEntryId) return Promise.resolve(false);

      this.selectedBrandId = draft.brand_id || null;
      this.selectedCategoryId = draft.category_id || null;
      this.pricingRows = draft.rows;
      this.dirtyProducts = draft.dirty_products || {};
      this.hasSearched = !!draft.has_searched || this.pricingRows.length > 0;
      return (this.selectedBrandId ? this.loadOptions(this.selectedBrandId) : Promise.resolve())
        .then(() => true);
    },
    loadEntry() {
      this.searching = true;
      this.hasSearched = true;
      return axios.get(`pricing-levels/${this.editingEntryId}`)
        .then(response => {
          const entry = response.data.entry || {};
          const products = Array.isArray(response.data.products) ? response.data.products : [];
          this.selectedBrandId = entry.brand_id || null;
          this.selectedCategoryId = entry.category_id || null;
          return this.loadOptions(this.selectedBrandId).then(() => {
            this.pricingRows = this.normalizeRows(products);
            this.dirtyProducts = {};
          });
        })
        .catch(error => {
          this.makeToast("danger", this.errorMessage(error, "Unable to load pricing level entry."), this.$t("Failed"));
          this.$router.push({ name: "pricing_levels_index" });
        })
        .finally(() => {
          this.searching = false;
        });
    },
    entryDetails() {
      return this.pricingRows.map(row => {
        const detail = {
          product_id: row.product_id,
          product_variant_id: row.variant_id || null
        };
        this.priceFields.forEach(field => { detail[field] = this.numericValue(row[field]); });
        return detail;
      });
    },
    savePricingLevels() {
      if (!this.pricingRows.length || this.submitting) return;
      this.persistDraft();
      const payload = {
        brand_id: this.selectedBrandId,
        category_id: this.selectedCategoryId,
        details: this.entryDetails()
      };
      const request = this.isEditing
        ? axios.put(`pricing-levels/${this.editingEntryId}`, payload)
        : axios.post("pricing-levels", payload);

      this.submitting = true;
      request
        .then(() => {
          this.dirtyProducts = {};
          this.clearDraft();
          this.makeToast("success", this.isEditing
            ? "Pricing level entry updated successfully."
            : "Pricing level entry created successfully.", this.$t("Success"));
          this.$router.push({ name: "pricing_levels_index" });
        })
        .catch(error => {
          this.makeToast("danger", this.errorMessage(error, "Unable to save pricing levels."), this.$t("Failed"));
        })
        .finally(() => {
          this.submitting = false;
        });
    }
  },
  created() {
    const directProductId = Number(this.$route.query.product || 0);
    const draft = this.readDraft();
    this.loadOptions().then(() => {
      if (this.isEditing) {
        this.loadEntry().then(() => {
          if (draft) this.restoreDraft(draft);
        });
      } else if (draft && Array.isArray(draft.rows) && draft.rows.length) {
        this.restoreDraft(draft);
      } else if (directProductId > 0) {
        this.loadDirectProduct(directProductId);
      }
    });
  }
};
</script>

<style scoped>
.pricing-filter-card,
.pricing-results-card,
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

.results-heading,
.results-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.results-heading {
  margin-bottom: 18px;
}

.results-footer {
  margin-top: 18px;
}

.pricing-table-wrap {
  border: 1px solid #e6e9ef;
  border-radius: 8px;
}

.pricing-table {
  min-width: 1750px;
}

.pricing-table thead th {
  vertical-align: middle;
  white-space: nowrap;
  background: #f7f8fa;
}

.pricing-group-heading {
  text-align: center;
  color: #fff;
  background: #7b3fb1 !important;
}

.pricing-table td {
  vertical-align: middle;
}

.product-name-cell {
  min-width: 230px;
}

.product-name-cell strong,
.product-name-cell small {
  display: block;
}

.product-name-cell small {
  margin-top: 5px;
  color: #72798a;
}

.variant-badge {
  display: inline-block;
  margin-right: 4px;
  padding: 2px 6px;
  border-radius: 9px;
  color: #6f38a1;
  background: #efe6f7;
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
}

.category-cell {
  min-width: 150px;
  white-space: pre-line;
}

.price-input-cell {
  min-width: 125px;
}

.price-input-cell input {
  min-width: 105px;
}

.pricing-row-dirty td {
  background: #fffaf0;
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
  .results-heading,
  .results-footer {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
