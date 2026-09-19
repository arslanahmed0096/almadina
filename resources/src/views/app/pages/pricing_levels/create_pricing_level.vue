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
              <th rowspan="2" class="product-name-heading">
                <button type="button" class="name-sort-button" @click="toggleProductSort">
                  Name <lucide-icon :name="productSortType === 'asc' ? 'arrow-up' : 'arrow-down'" />
                </button>
              </th>
              <th rowspan="2">Code</th>
              <th rowspan="2">Brand</th>
              <th rowspan="2">Category</th>
              <th rowspan="2">Purchase Price</th>
              <th colspan="7" class="pricing-group-heading">Pricing Level</th>
              <th rowspan="2" class="margin-icon-heading"><lucide-icon name="percent" /></th>
            </tr>
            <tr>
              <th>Company RB</th>
              <th>MRP</th>
              <th>Product Cost</th>
              <th>Regular Price</th>
              <th>Al-Madina Price</th>
              <th>Whole Sale</th>
              <th>Minimum</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in sortedPricingRows" :key="row.row_key" :class="{ 'pricing-row-dirty': isDirty(row.product_id) }">
              <td class="product-name-cell">
                <strong>{{ row.name }}</strong>
                <small v-if="row.variant_name">
                  <span class="variant-badge">Variant</span> {{ row.variant_name }}
                </small>
              </td>
              <td>{{ row.code }}</td>
              <td>{{ row.brand || "N/D" }}</td>
              <td class="category-cell">{{ row.category || "N/D" }}</td>
              <td class="purchase-price-cell">{{ wholeNumber(activePurchasePrice(row)) }}</td>
              <td v-for="field in priceFields" :key="`${row.row_key}-${field}`" class="price-input-cell">
                <b-form-input
                  v-model.number="row[field]"
                  type="number"
                  min="0"
                  step="0.01"
                  :disabled="submitting"
                  :readonly="isMarginAppliedField(row, field)"
                  @input="onPriceInput(row, field)"
                  @blur="persistDraft"
                />
              </td>
              <td class="margin-action-cell">
                <b-button
                  v-b-tooltip.hover
                  :title="row.pricing_margins.length ? `Edit ${row.pricing_margins.length} margin${row.pricing_margins.length === 1 ? '' : 's'}` : 'Add margins'"
                  class="margin-icon-button"
                  size="sm"
                  variant="outline-primary"
                  @click="openMarginModal(row)"
                >
                  <lucide-icon name="percent" />
                  <span v-if="row.pricing_margins.length" class="margin-count-badge">{{ row.pricing_margins.length }}</span>
                </b-button>
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

    <b-modal
      id="pricing-margin-modal"
      size="lg"
      centered
      hide-footer
      title="Purchase Price Margins"
      @hidden="closeMarginModal"
    >
      <template v-if="activeMarginRow">
        <div class="margin-modal-product">
          <div class="margin-modal-product__identity">
            <span class="margin-modal-product__icon"><lucide-icon name="package" /></span>
            <div>
              <small>SELECTED PRODUCT</small>
              <strong>{{ activeMarginRow.name }}</strong>
              <span v-if="activeMarginRow.variant_name">{{ activeMarginRow.variant_name }}</span>
              <span>{{ activeMarginRow.code }}</span>
            </div>
          </div>
          <div class="margin-modal-product__price">
            <small>PURCHASE PRICE</small>
            <strong>{{ wholeNumber(activePurchasePrice(activeMarginRow)) }}</strong>
          </div>
        </div>

        <div class="margin-modal-heading">
          <div>
            <h5>Margins</h5>
            <p>The first four rows update Minimum, Wholesale, Al-Madina, and Regular prices.</p>
          </div>
          <b-button
            size="sm"
            variant="outline-primary"
            :disabled="!Number(activePurchasePrice(activeMarginRow))"
            @click="addMargin"
          >
            <lucide-icon name="plus" /> Add margin
          </b-button>
        </div>

        <div v-if="!Number(activePurchasePrice(activeMarginRow))" class="alert alert-warning">
          Enter Product Cost in the pricing row first. It will become the Purchase Price when saved.
        </div>
        <div v-else-if="!marginDraft.length" class="margin-modal-empty">
          No margins added. Click Add margin to begin.
        </div>

        <b-row
          v-for="(margin, index) in marginDraft"
          :key="`modal-margin-${index}`"
          class="align-items-end margin-modal-line"
        >
          <b-col lg="2" md="6">
            <b-form-group label="Price Label">
              <b-form-input
                v-model="margin.label"
                type="text"
                maxlength="100"
                :readonly="index < 3"
                :placeholder="marginTierName(index, margin)"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="6">
            <b-form-group label="Margin Type">
              <b-form-select v-model="margin.type" :options="marginTypeOptions" />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4">
            <b-form-group label="Margin">
              <b-form-input v-model="margin.value" type="number" min="0" step="0.01" />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4">
            <b-form-group label="Profit">
              <b-form-input :value="marginProfit(activeMarginRow, margin)" readonly />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4">
            <b-form-group label="Applied Price">
              <b-form-input :value="marginPrice(activeMarginRow, margin)" readonly />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" class="mb-3 text-center">
            <b-button v-if="index >= 4" block variant="outline-danger" @click="removeMargin(index)">Remove</b-button>
          </b-col>
        </b-row>

        <div class="margin-modal-actions">
          <b-button variant="outline-secondary" @click="$bvModal.hide('pricing-margin-modal')">Cancel</b-button>
          <b-button variant="primary" @click="saveMarginModal">
            <lucide-icon name="check" /> Apply to Price Row
          </b-button>
        </div>
      </template>
    </b-modal>
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
      activeMarginRow: null,
      marginDraft: [],
      productSortType: "asc",
      marginTypeOptions: [
        { text: "%", value: "percentage" },
        { text: "Fixed amount", value: "fixed" }
      ],
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
    },
    sortedPricingRows() {
      const direction = this.productSortType === "desc" ? -1 : 1;
      return this.pricingRows.slice().sort((left, right) => {
        const byName = String(left.name || "").localeCompare(String(right.name || ""), undefined, { sensitivity: "base" });
        if (byName !== 0) return byName * direction;
        return String(left.variant_name || "").localeCompare(String(right.variant_name || ""), undefined, { sensitivity: "base" }) * direction;
      });
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
    normalizeMargins(margins) {
      return (Array.isArray(margins) ? margins : []).map((margin, index) => ({
        type: margin && margin.type === "fixed" ? "fixed" : "percentage",
        value: margin && margin.value !== undefined && margin.value !== null ? margin.value : "",
        label: index < 4
          ? ["Minimum Price", "Wholesale Price", "Al-Madina Price", "Regular Price"][index]
          : (margin && String(margin.label || "").trim()) || `Custom Price ${index + 1}`
      }));
    },
    withStandardMargins(margins) {
      const normalized = this.normalizeMargins(margins);
      while (normalized.length < 4) {
        const index = normalized.length;
        normalized.push({
          type: "percentage",
          value: "",
          label: ["Minimum Price", "Wholesale Price", "Al-Madina Price", "Regular Price"][index]
        });
      }
      return normalized;
    },
    wholeNumber(value) {
      return Math.round(this.numericValue(value));
    },
    marginTierName(index, margin = null) {
      return ["Minimum Price", "Wholesale Price", "Al-Madina Price", "Regular Price"][index]
        || (margin && margin.label)
        || `Custom Price ${index + 1}`;
    },
    openMarginModal(row) {
      this.activeMarginRow = row;
      this.marginDraft = this.withStandardMargins(row.pricing_margins);
      this.$bvModal.show("pricing-margin-modal");
    },
    closeMarginModal() {
      this.activeMarginRow = null;
      this.marginDraft = [];
    },
    addMargin() {
      if (!this.activeMarginRow || !(Number(this.activePurchasePrice(this.activeMarginRow)) > 0)) return;
      this.marginDraft.push({
        type: "percentage",
        value: "",
        label: `Custom Price ${this.marginDraft.length + 1}`
      });
    },
    removeMargin(index) {
      this.marginDraft.splice(index, 1);
    },
    marginProfit(row, margin) {
      const base = Number(this.activePurchasePrice(row));
      const amount = Number(margin.value);
      if (!Number.isFinite(base) || !Number.isFinite(amount) || margin.value === "") return "";
      return Math.round(margin.type === "percentage" ? base * amount / 100 : amount);
    },
    marginPrice(row, margin) {
      const base = Number(this.activePurchasePrice(row));
      const profit = Number(this.marginProfit(row, margin));
      if (!Number.isFinite(base) || !Number.isFinite(profit) || margin.value === "") return "";
      return Math.round(base + profit);
    },
    isMarginAppliedField(row, field) {
      const index = { min_price: 0, wholesale_price: 1, price: 2, fix_price: 3 }[field];
      return index !== undefined && row.pricing_margins.length > index;
    },
    applyMarginPrices(row) {
      const fields = ["min_price", "wholesale_price", "price", "fix_price"];
      row.pricing_margins.slice(0, fields.length).forEach((margin, index) => {
        const price = this.marginPrice(row, margin);
        if (price !== "") this.$set(row, fields[index], price);
      });
    },
    validateMarginList(row, margins) {
      if (!margins.length) return true;
      if (!(Number(this.activePurchasePrice(row)) > 0)) {
        this.makeToast("danger", `Purchase Price is required for ${row.name}.`, this.$t("Failed"));
        return false;
      }

      let previous = null;
      for (let index = 0; index < margins.length; index++) {
        const margin = margins[index];
        const value = Number(margin.value);
        const price = Number(this.marginPrice(row, margin));
        if (!["percentage", "fixed"].includes(margin.type) || margin.value === "" || !Number.isFinite(value) || value < 0) {
          this.makeToast("danger", `Enter a non-negative margin for ${row.name}.`, this.$t("Failed"));
          return false;
        }
        if (index >= 4 && !String(margin.label || "").trim()) {
          this.makeToast("danger", `Enter a custom price label for ${row.name}.`, this.$t("Failed"));
          return false;
        }
        if (previous !== null && price <= previous) {
          this.makeToast("danger", `Each next margin price for ${row.name} must be higher.`, this.$t("Failed"));
          return false;
        }
        previous = price;
      }
      return true;
    },
    saveMarginModal() {
      if (!this.activeMarginRow || !this.validateMarginList(this.activeMarginRow, this.marginDraft)) return;
      this.$set(this.activeMarginRow, "pricing_margins", this.normalizeMargins(this.marginDraft));
      this.applyMarginPrices(this.activeMarginRow);
      this.markDirty(this.activeMarginRow.product_id);
      this.persistDraft();
      this.$bvModal.hide("pricing-margin-modal");
    },
    validatePricingMargins() {
      for (const row of this.pricingRows) {
        const margins = row.pricing_margins || [];
        if (!this.validateMarginList(row, margins)) {
          this.openMarginModal(row);
          return false;
        }
      }
      return true;
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
              code: variant.code || product.code,
              purchase_price: this.numericValue(variant.purchase_price),
              purchase_price_tracks_cost: ["none", "cost"].includes(variant.purchase_price_source),
              pricing_margins: this.normalizeMargins(variant.pricing_margins)
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
          code: product.code,
          purchase_price: this.numericValue(product.purchase_price),
          purchase_price_tracks_cost: ["none", "cost"].includes(product.purchase_price_source),
          pricing_margins: this.normalizeMargins(product.pricing_margins)
        });
        this.priceFields.forEach(field => { row[field] = this.numericValue(product[field]); });
        rows.push(row);
      });
      return rows;
    },
    activePurchasePrice(row) {
      return this.numericValue(row && row.purchase_price);
    },
    onPriceInput(row, field) {
      if (field === "cost" && (row.purchase_price_tracks_cost || !(Number(row.purchase_price) > 0))) {
        this.$set(row, "purchase_price", this.numericValue(row.cost));
        this.$set(row, "purchase_price_tracks_cost", true);
      }
      this.markDirty(row.product_id);
    },
    toggleProductSort() {
      this.productSortType = this.productSortType === "asc" ? "desc" : "asc";
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
      if (!this.isEditing) return;
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
      if (!this.isEditing) return null;
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
      this.pricingRows = draft.rows.map(row => Object.assign({}, row, {
        purchase_price: this.numericValue(row.purchase_price),
        pricing_margins: this.normalizeMargins(row.pricing_margins)
      }));
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
          product_variant_id: row.variant_id || null,
          pricing_margins: (row.pricing_margins || []).map(margin => ({
            type: margin.type,
            value: this.numericValue(margin.value),
            label: margin.label
          }))
        };
        this.priceFields.forEach(field => { detail[field] = this.numericValue(row[field]); });
        return detail;
      });
    },
    savePricingLevels() {
      if (!this.pricingRows.length || this.submitting) return;
      if (!this.validatePricingMargins()) return;
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
    if (!this.isEditing) this.clearDraft();
    const draft = this.readDraft();
    this.loadOptions().then(() => {
      if (this.isEditing) {
        this.loadEntry().then(() => {
          if (draft) this.restoreDraft(draft);
        });
      } else if (directProductId > 0) {
        this.loadDirectProduct(directProductId);
      } else if (draft && Array.isArray(draft.rows) && draft.rows.length) {
        this.restoreDraft(draft);
      }
    });
  },
  beforeRouteLeave(to, from, next) {
    if (!this.isEditing) {
      this.clearDraft();
      this.selectedBrandId = null;
      this.selectedCategoryId = null;
      this.categories = [];
      this.pricingRows = [];
      this.dirtyProducts = {};
      this.hasSearched = false;
    }
    next();
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
  min-width: 1450px;
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
  position: sticky;
  left: 0;
  z-index: 2;
  width: 180px;
  min-width: 180px;
  max-width: 180px;
  background: #fff;
  white-space: normal;
}

.product-name-heading {
  position: sticky;
  left: 0;
  z-index: 4;
  width: 180px;
  min-width: 180px;
  background: #f7f8fa !important;
}

.name-sort-button {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 0;
  color: inherit;
  font: inherit;
  font-weight: 700;
  border: 0;
  background: transparent;
}

.name-sort-button svg {
  width: 14px;
  height: 14px;
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
  min-width: 115px;
  max-width: 145px;
  white-space: pre-line;
}

.price-input-cell {
  width: 98px;
  min-width: 98px;
}

.price-input-cell input {
  min-width: 78px;
  padding-right: 6px;
  padding-left: 6px;
}

.purchase-price-cell {
  min-width: 105px;
  font-weight: 700;
  color: #5f2d86;
  background: #f7f1fb;
}

.margin-icon-heading,
.margin-action-cell {
  width: 72px;
  min-width: 72px;
  text-align: center;
}

.margin-icon-button {
  position: relative;
  width: 42px;
  height: 36px;
  padding: 5px;
}

.margin-count-badge {
  position: absolute;
  top: -8px;
  right: -8px;
  min-width: 19px;
  height: 19px;
  padding: 0 5px;
  border-radius: 10px;
  color: #fff;
  background: #7b3fb1;
  font-size: 11px;
  font-weight: 700;
  line-height: 19px;
}

.margin-modal-product {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 16px;
  margin-bottom: 20px;
  border: 1px solid #e8def0;
  border-radius: 10px;
  background: #faf7fd;
}

.margin-modal-product__identity {
  display: flex;
  align-items: center;
  gap: 12px;
}

.margin-modal-product__identity strong,
.margin-modal-product__identity span,
.margin-modal-product__identity small,
.margin-modal-product__price small,
.margin-modal-product__price strong {
  display: block;
}

.margin-modal-product__identity small,
.margin-modal-product__price small {
  color: #72798a;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: .5px;
}

.margin-modal-product__icon {
  display: flex !important;
  align-items: center;
  justify-content: center;
  width: 42px;
  height: 42px;
  border-radius: 8px;
  color: #fff;
  background: #7b3fb1;
}

.margin-modal-product__price {
  min-width: 125px;
  text-align: right;
}

.margin-modal-product__price strong {
  color: #5f2d86;
  font-size: 22px;
}

.margin-modal-heading,
.margin-modal-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.margin-modal-heading h5,
.margin-modal-heading p {
  margin: 0;
}

.margin-modal-heading p {
  margin-top: 3px;
  color: #72798a;
  font-size: 12px;
}

.margin-modal-empty {
  padding: 18px;
  margin-top: 16px;
  color: #72798a;
  text-align: center;
  border: 1px dashed #d9c8e8;
  border-radius: 8px;
  background: #faf7fd;
}

.margin-modal-line {
  padding-top: 14px;
  margin-top: 14px;
  border-top: 1px solid #eadff2;
}

.margin-modal-actions {
  justify-content: flex-end;
  padding-top: 18px;
  margin-top: 8px;
  border-top: 1px solid #e6e9ef;
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

  .margin-modal-product,
  .margin-modal-heading,
  .margin-modal-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .margin-modal-product__price {
    text-align: left;
  }
}
</style>
