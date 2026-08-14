<template>
  <div class="main-content">
    <breadcumb :page="$t('productsList')" :folder="$t('Products')"/>
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <div v-else>
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="products"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :select-options="{ enabled: true, clearSelectionText: '' }"
        @on-selected-rows-change="selectionChanged"
        :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        styleClass="tableOne vgt-table"
      >
        <!-- selected actions -->
        <div slot="selected-row-actions" v-if="can('products_delete')">
          <button class="btn btn-sm btn-outline-danger" @click="delete_by_selected()">
            <lucide-icon name="trash-2" /> {{$t('Del')}}
          </button>
        </div>

        <!-- table actions -->
        <div slot="table-actions" class="table-actions-wrapper mt-2 mb-3 d-flex flex-wrap align-items-center justify-content-between">
          <div class="table-actions-left d-flex flex-wrap align-items-center">
            <b-form-select
              v-model="nameSort"
              :options="nameSortOptions"
              size="sm"
              class="sort-select m-1"
              @change="applyNameSort"
            />

            <b-button class="m-1" size="sm" variant="outline-info" v-b-toggle.sidebar-right>
              <lucide-icon name="filter" />
              {{ $t("Filter") }}
            </b-button>

            <b-button class="m-1" @click="Product_PDF()" size="sm" variant="outline-success">
              <lucide-icon name="copy" /> PDF
            </b-button>

            <vue-excel-xlsx
              class="btn btn-sm btn-outline-danger m-1"
              :data="products"
              :columns="excelColumns"
              :file-name="'products'"
              :file-type="'xlsx'"
              :sheet-name="'products'"
            >
              <lucide-icon name="file-spreadsheet" /> EXCEL
            </vue-excel-xlsx>

            <b-button class="m-1" @click="exportAllProducts" :disabled="isExportingAll" size="sm" variant="outline-danger">
              <lucide-icon name="download" /> {{ $t('Export_All') || 'Export All' }}
            </b-button>

            <router-link
              v-if="currentUserPermissions && currentUserPermissions.includes('product_import')"
              :to="{ name: 'import_products' }"
              class="btn btn-sm btn-outline-info m-1"
            >
              <lucide-icon name="download" />
              {{ $t("import_products") }}
            </router-link>
          </div>

          <div class="table-actions-right d-flex flex-wrap align-items-center">
            <router-link
              class="btn btn-sm btn-primary m-1"
              v-if="currentUserPermissions && currentUserPermissions.includes('products_add')"
              to="/app/products/store"
            >
              <lucide-icon name="plus" />
              {{$t('Add')}}
            </router-link>
          </div>
        </div>

        <!-- SAFE rendering: never v-html for user text -->
        <template slot="table-row" slot-scope="props">
          <!-- actions -->
          <span v-if="props.column.field === 'actions'" class="action-cell">
            <router-link
              v-if="can('products_view')"
              v-b-tooltip.hover
              title="View"
              :to="{ name:'detail_product', params: { id: props.row.id} }"
              class="btn btn-sm btn-outline-info action-btn"
            >
              <lucide-icon class="text-info" name="eye" />
            </router-link>

            <router-link
              v-if="can('products_view')"
              v-b-tooltip.hover
              title="History"
              :to="{ name:'product_history', params: { id: props.row.id } }"
              target="_blank"
              rel="noopener noreferrer"
              class="btn btn-sm btn-outline-secondary action-btn"
            >
              <lucide-icon name="history" />
            </router-link>

            <b-button
              v-if="can('pricing_level_add')"
              v-b-tooltip.hover
              title="Pricing Level"
              class="btn btn-sm btn-outline-primary action-btn"
              @click="openPricingLevel(props.row)"
            >
              <svg class="action-price-icon" viewBox="0 0 24 24" aria-hidden="true">
                <path d="M20.59 13.41 11 3.83V3H4v7h.83l9.58 9.59a2 2 0 0 0 2.82 0l3.36-3.36a2 2 0 0 0 0-2.82Z"></path>
                <circle cx="7.5" cy="6.5" r="1.25"></circle>
              </svg>
            </b-button>

            <router-link
              v-if="can('products_edit')"
              v-b-tooltip.hover
              title="Edit"
              :to="{ name:'edit_product', params: { id: props.row.id } }"
              class="btn btn-sm btn-outline-success action-btn"
            >
              <lucide-icon class="text-success" name="pencil" />
            </router-link>

            <a
              v-if="can('products_add')"
              @click="Duplicate_Product(props.row.id)"
              v-b-tooltip.hover
              title="Duplicate"
              class="btn btn-sm btn-outline-warning action-btn"
            >
              <lucide-icon class="text-warning" name="copy" />
            </a>

            <a
              v-if="can('products_delete')"
              @click="Remove_Product(props.row.id)"
              v-b-tooltip.hover
              title="Delete"
              class="btn btn-sm btn-outline-danger action-btn"
            >
              <lucide-icon class="text-danger" name="x" />
            </a>
          </span>

          <!-- image (own slot, no html column flag) -->
          <span v-else-if="props.column.field === 'image'">
            <b-img
              thumbnail
              height="50"
              width="50"
              fluid
              :src="'/images/products/' + props.row.image"
              alt="image"
            />
          </span>

          <!-- multi-line text rendered safely -->
          <span v-else-if="props.column.field === 'name'" class="pre">{{ props.row.name }}</span>
          <span v-else-if="props.column.field === 'category'" class="pre">{{ props.row.categories_display || props.row.category }}</span>
          <span v-else-if="props.column.field === 'is_active'">
            <b-badge :variant="props.row.is_active ? 'success' : 'danger'">
              {{ props.row.is_active ? ($t('Active') || 'Active') : ($t('Inactive') || 'Inactive') }}
            </b-badge>
          </span>
          <span
            v-else-if="props.column.field === 'cost'"
            :class="{'pre': props.row.type === 'Variable'}"
          >
            {{ props.row.type === 'Variable' 
              ? formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', firstLine(props.row.cost), 2)
              : formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', props.row.cost, 2) }}
          </span>
          <span
            v-else-if="props.column.field === 'price'"
            :class="{'pre': props.row.type === 'Variable'}"
          >
            {{ props.row.type === 'Variable' 
              ? formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', firstLine(props.row.price), 2)
              : formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', props.row.price, 2) }}
          </span>
          <span
            v-else-if="props.column.field === 'fix_price'"
            :class="{'pre': props.row.type === 'Variable'}"
          >
            {{ props.row.type === 'Variable'
              ? formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', firstLine(props.row.fix_price), 2)
              : formatPriceWithSymbol(currentUser && currentUser.currency ? currentUser.currency : '', props.row.fix_price, 2) }}
          </span>

          <!-- default -->
          <span v-else>
            {{ props.formattedRow[props.column.field] }}
          </span>
        </template>
      </vue-good-table>

      <b-modal
        id="PricingLevelModal"
        size="xl"
        hide-footer
        hide-header
        centered
        modal-class="pricing-level-modal"
        body-class="pricing-level-modal-body"
      >
        <div class="pricing-level-shell">
          <header class="pricing-level-header">
            <div class="pricing-level-header__identity">
              <span class="pricing-level-header__icon">
                <svg viewBox="0 0 24 24" aria-hidden="true">
                  <path d="M20.59 13.41 11 3.83V3H4v7h.83l9.58 9.59a2 2 0 0 0 2.82 0l3.36-3.36a2 2 0 0 0 0-2.82Z"></path>
                  <circle cx="7.5" cy="6.5" r="1.25"></circle>
                </svg>
              </span>
              <div>
                <span class="pricing-level-header__eyebrow">Product pricing</span>
                <h3>Pricing Level</h3>
                <p>Review and update every purchase and sale price in one place.</p>
              </div>
            </div>
            <button type="button" class="pricing-level-close" aria-label="Close" @click="$bvModal.hide('PricingLevelModal')">
              <svg viewBox="0 0 24 24" aria-hidden="true">
                <path d="M18 6 6 18M6 6l12 12"></path>
              </svg>
            </button>
          </header>

          <div class="pricing-level-content">
            <div v-if="pricingLoading" class="pricing-level-loading">
              <div class="spinner spinner-primary"></div>
            </div>

            <b-form v-else @submit.prevent="submitPricingLevel">
              <div class="pricing-level-product mb-4">
                <div class="pricing-level-product__main">
                  <span class="pricing-level-product__avatar">
                    <lucide-icon name="package" />
                  </span>
                  <div>
                    <small>SELECTED PRODUCT</small>
                    <strong>{{ pricingForm.name }}</strong>
                  </div>
                </div>
                <span class="pricing-level-product__code">{{ pricingForm.code }}</span>
              </div>

          <template v-if="pricingForm.type !== 'is_variant'">
            <section class="pricing-level-section pricing-level-section--purchase">
              <h6><lucide-icon name="shopping-cart" /> Purchase Pricing</h6>
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

            <section class="pricing-level-section pricing-level-section--sale">
              <h6><lucide-icon name="dollar-sign" /> Sale Pricing</h6>
              <b-row>
                <b-col md="6" lg="3">
                  <b-form-group label="Fix Price">
                    <b-form-input v-model.number="pricingForm.fix_price" type="number" min="0" step="0.01" required />
                  </b-form-group>
                </b-col>
                <b-col md="6" lg="3">
                  <b-form-group label="Retail Price (Almadina Price)">
                    <b-form-input v-model.number="pricingForm.price" type="number" min="0" step="0.01" required />
                  </b-form-group>
                </b-col>
                <b-col md="6" lg="3">
                  <b-form-group label="Whole Sale Price">
                    <b-form-input v-model.number="pricingForm.wholesale_price" type="number" min="0" step="0.01" required />
                  </b-form-group>
                </b-col>
                <b-col md="6" lg="3">
                  <b-form-group label="Minimum Price">
                    <b-form-input v-model.number="pricingForm.min_price" type="number" min="0" step="0.01" required />
                  </b-form-group>
                </b-col>
              </b-row>
            </section>
          </template>

          <div v-else class="table-responsive pricing-level-variants">
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
                  <td class="variant-identity">
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

              <div class="pricing-level-actions mt-4">
                <span class="pricing-level-actions__hint">
                  <lucide-icon name="info" /> Changes apply immediately after saving.
                </span>
                <div>
                  <b-button class="pricing-level-cancel" variant="outline-secondary" type="button" @click="$bvModal.hide('PricingLevelModal')">
                    {{ $t('Cancel') || 'Cancel' }}
                  </b-button>
                  <b-button class="pricing-level-submit" variant="primary" type="submit" :disabled="pricingSubmitting">
                    <span v-if="pricingSubmitting" class="spinner sm spinner-white mr-2"></span>
                    <lucide-icon v-else name="check" />
                    {{ pricingSubmitting ? 'Updating...' : 'Update Pricing' }}
                  </b-button>
                </div>
              </div>
            </b-form>
          </div>
        </div>
      </b-modal>

      <!-- Filter sidebar -->
      <b-sidebar id="sidebar-right" :title="$t('Filter')" bg-variant="white" right shadow>
        <div class="px-3 py-2">
          <b-row>
            <b-col md="12">
              <b-form-group :label="$t('CodeProduct')">
                <b-form-input :placeholder="$t('SearchByCode')" v-model="Filter_code" />
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('ProductName')">
                <b-form-input :placeholder="$t('SearchByName')" v-model="Filter_name" />
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Categorie')">
                <v-select
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Category')"
                  v-model="Filter_category"
                  :options="categories.map(c => ({ label: c.name, value: c.id }))"
                />
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('Brand')">
                <v-select
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Brand')"
                  v-model="Filter_brand"
                  :options="brands.map(b => ({ label: b.name, value: b.id }))"
                />
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-form-group :label="$t('warehouse')">
                <v-select
                  :reduce="label => label.value"
                  :placeholder="$t('Choose_Warehouse')"
                  v-model="Filter_warehouse"
                  :options="warehouses.map(w => ({ label: w.name, value: w.id }))"
                />
              </b-form-group>
            </b-col>

            <b-col v-if="canViewInactiveProducts" md="12">
              <b-form-group :label="$t('Status') || 'Status'">
                <b-form-select v-model="Filter_status">
                  <option value="">All statuses</option>
                  <option value="1">{{ $t('Active') || 'Active' }}</option>
                  <option value="0">{{ $t('Inactive') || 'Inactive' }}</option>
                </b-form-select>
              </b-form-group>
            </b-col>

            <b-col md="12">
              <b-button @click="Get_Products(serverParams.page)" variant="primary m-1" size="sm" block>
                <lucide-icon name="filter" /> {{ $t("Filter") }}
              </b-button>
            </b-col>

            <b-col md="6" sm="12">
              <b-button @click="Reset_Filter()" variant="danger m-1" size="sm" block>
                <lucide-icon name="power" /> {{ $t("Reset") }}
              </b-button>
            </b-col>
          </b-row>
        </div>
      </b-sidebar>

      <!-- Import modal (unchanged except safer handling) -->
      <b-modal ok-only ok-title="Cancel" size="md" id="importProducts" :title="$t('import_products')">
        <b-form @submit.prevent="Submit_import" enctype="multipart/form-data">
          <b-row>
            <b-col md="12" sm="12" class="mb-3">
              <b-form-group>
                <input type="file" @change="onFileSelected">
                <b-form-invalid-feedback id="File-feedback" class="d-block">
                  File must be in xlsx format
                </b-form-invalid-feedback>
              </b-form-group>
            </b-col>

            <b-col md="6" sm="12">
              <b-button type="submit" variant="primary" :disabled="ImportProcessing" size="sm" block>
                {{ $t("submit") }}
              </b-button>
              <div v-once class="typo__p" v-if="ImportProcessing">
                <div class="spinner sm spinner-primary mt-3"></div>
              </div>
            </b-col>

            <b-col md="6" sm="12">
              <a :href="'/import/exemples/import_products.xlsx'" class="btn btn-info btn-sm btn-block">
                {{ $t("Download_exemple") }}
              </a>
            </b-col>

            <!-- import instructions table kept -->
            <!-- ... (your existing instructions table) ... -->
          </b-row>
        </b-form>
      </b-modal>
    </div>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import * as XLSX from "xlsx";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  metaInfo: { title: "Products" },
  data() {
    return {
      serverParams: { sort: { field: "name", type: "asc" }, page: 1, perPage: 10 },
      selectedIds: [],
      ImportProcessing: false,
      data: new FormData(),
      import_products: "",
      search: "",
      totalRows: "",
      isLoading: true,
      limit: "10",
      Filter_brand: "",
      Filter_code: "",
      Filter_name: "",
      Filter_category: "",
      Filter_warehouse: "",
      Filter_status: "",
      canViewInactiveProducts: false,
      categories: [],
      subcategories: [],
      brands: [],
      products: [],
      warehouses: [],
      nameSort: "az",
      isExportingAll: false,
      pricingLoading: false,
      pricingSubmitting: false,
      pricingForm: {
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
      },
      // Optional price format key for frontend display (loaded from system settings/localStorage)
      price_format_key: null
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    nameSortOptions() {
      return [
        { value: "az", text: "Product Name: A-Z" },
        { value: "za", text: "Product Name: Z-A" }
      ];
    },
    columns() {
      const columns = [
        { label: this.$t("image"), field: "image", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("type"), field: "type", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Name_product"), field: "name", tdClass: "text-left pre", thClass: "text-left" },
        { label: this.$t("Code"), field: "code", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Brand"), field: "brand", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Categorie"), field: "category", tdClass: "text-left pre", thClass: "text-left" }
      ];
      if (this.can("products_cost_view")) {
        columns.push({ label: this.$t("Cost"), field: "cost", tdClass: "text-left pre", thClass: "text-left" });
      }
      columns.push(
        { label: this.$t("Price"), field: "price", tdClass: "text-left pre", thClass: "text-left" },
        { label: this.$t("FixPrice"), field: "fix_price", tdClass: "text-left pre", thClass: "text-left" },
        { label: this.$t("Unit"), field: "unit", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Quantity"), field: "quantity", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Status") || "Status", field: "is_active", tdClass: "text-center", thClass: "text-center" },
        { label: this.$t("Action"), field: "actions", tdClass: "text-left", thClass: "text-left", sortable: false }
      );
      return columns;
    },
    excelColumns() {
      const columns = [
        { label: this.$t("type"), field: "type" },
        { label: this.$t("Name_product"), field: "name" },
        { label: this.$t("Code"), field: "code" },
        { label: this.$t("Categorie"), field: "categories_display" }
      ];
      if (this.can("products_cost_view")) {
        columns.push({ label: this.$t("Cost"), field: "cost" });
      }
      columns.push(
        { label: this.$t("Price"), field: "price" },
        { label: this.$t("FixPrice"), field: "fix_price" },
        { label: this.$t("Unit"), field: "unit" },
        { label: this.$t("Quantity"), field: "quantity" },
        { label: this.$t("Status") || "Status", field: "status" }
      );
      return columns;
    }
  },
  methods: {
    can(p) { return this.currentUserPermissions && this.currentUserPermissions.includes(p); },

    normalizePricing(pricing) {
      const numericFields = [
        "company_rb_price", "mrp_price", "cost", "fix_price",
        "price", "wholesale_price", "min_price"
      ];
      const normalized = Object.assign({ variants: [] }, pricing || {});
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

    openPricingLevel(row) {
      this.pricingLoading = true;
      this.pricingSubmitting = false;
      this.pricingForm = this.normalizePricing({
        id: row.id,
        name: row.name,
        code: row.code,
        type: row.product_type,
        variants: []
      });
      this.$bvModal.show("PricingLevelModal");

      axios.get(`products/${row.id}/pricing-level`)
        .then(response => {
          this.pricingForm = this.normalizePricing(response.data.pricing);
        })
        .catch(error => {
          this.$bvModal.hide("PricingLevelModal");
          const message = error.response?.data?.message || "Unable to load product pricing.";
          this.makeToast("danger", message, this.$t("Failed"));
        })
        .finally(() => {
          this.pricingLoading = false;
        });
    },

    submitPricingLevel() {
      if (!this.pricingForm.id || this.pricingSubmitting) return;

      this.pricingSubmitting = true;
      const fields = [
        "company_rb_price", "mrp_price", "cost", "fix_price",
        "price", "wholesale_price", "min_price"
      ];
      let details;
      if (this.pricingForm.type === "is_variant") {
        details = this.pricingForm.variants.map(variant => {
            const row = {
              product_id: this.pricingForm.id,
              product_variant_id: variant.id
            };
            fields.forEach(field => { row[field] = variant[field]; });
            return row;
          });
      } else {
        const row = {
          product_id: this.pricingForm.id,
          product_variant_id: null
        };
        fields.forEach(field => { row[field] = this.pricingForm[field]; });
        details = [row];
      }

      const payload = {
        brand_id: this.pricingForm.brand_id,
        category_id: this.pricingForm.category_id,
        details
      };

      axios.post("pricing-levels", payload)
        .then(() => {
          this.$bvModal.hide("PricingLevelModal");
          this.makeToast("success", "Pricing level entry created successfully.", this.$t("Success"));
          this.Get_Products(this.serverParams.page);
        })
        .catch(error => {
          const errors = error.response?.data?.errors || {};
          const first = Object.values(errors)[0];
          const message = Array.isArray(first) ? first[0] : (error.response?.data?.message || "Unable to create pricing level entry.");
          this.makeToast("danger", message, this.$t("Failed"));
        })
        .finally(() => {
          this.pricingSubmitting = false;
        });
    },

    // Return first line of a possibly multi-line string
    firstLine(val) {
      if (val === null || val === undefined) return '';
      return String(val).split('\n')[0];
    },

    //------------------------------Formetted Numbers -------------------------\\
    formatNumber(number, dec) {
      if (number === null || number === undefined || number === '') return '0.00';
      const value = (typeof number === "string"
        ? number
        : number.toString()
      ).split(".");
      if (dec <= 0) return value[0];
      let formated = value[1] || "";
      if (formated.length > dec)
        return `${value[0]}.${formated.substr(0, dec)}`;
      while (formated.length < dec) formated += "0";
      return `${value[0]}.${formated}`;
    },

    // Price formatting for display only (does NOT affect calculations or stored values)
    // Uses the global/system price_format setting when available; otherwise falls back
    // to the existing formatNumber helper to preserve current behavior.
    formatPriceDisplay(number, dec) {
      try {
        const decimals = Number.isInteger(dec) ? dec : 2;
        const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
        if (key) {
          this.price_format_key = key;
        }
        return formatPriceDisplayHelper(number, decimals, key);
      } catch (e) {
        return this.formatNumber(number, dec);
      }
    },

    formatPriceWithSymbol(symbol, number, dec) {
      const safeSymbol = symbol || "";
      const value = this.formatPriceDisplay(number, dec);
      return safeSymbol ? `${safeSymbol} ${value}` : value;
    },

    Product_PDF() {
      const pdf = new jsPDF("p", "pt");
      const fontPath = "/fonts/Vazirmatn-Bold.ttf";
      try {
        pdf.addFont(fontPath, "Vazirmatn", "normal");
        pdf.addFont(fontPath, "Vazirmatn", "bold");
      } catch(e) { /* ignore if already added */ }
      pdf.setFont("Vazirmatn", "normal");

      const headers = [
        this.$t("type"),
        this.$t("Name_product"),
        this.$t("Code"),
        this.$t("Categorie")
      ];
      if (this.can("products_cost_view")) headers.push(this.$t("Cost"));
      headers.push(this.$t("Price"), this.$t("FixPrice"), this.$t("Unit"), this.$t("Quantity"), this.$t("Status") || "Status");

      const products_pdf = JSON.parse(JSON.stringify(this.products));
      products_pdf.forEach(item => {
        item.name  = String(item.name || '').replace(/\r?\n/g, '\n');
        item.cost  = String(item.cost || '').replace(/\r?\n/g, '\n');
        item.price = String(item.price || '').replace(/\r?\n/g, '\n');
        item.fix_price = String(item.fix_price || '').replace(/\r?\n/g, '\n');
        item.categories_display = String(item.categories_display || '').replace(/\r?\n/g, '\n');
      });

      const body = products_pdf.map(p => {
        const row = [p.type, p.name, p.code, p.categories_display || p.category];
        if (this.can("products_cost_view")) row.push(p.cost);
        row.push(p.price, p.fix_price, p.unit, p.quantity, p.status);
        return row;
      });

      const marginX = 40;
      const rtl =
        (this.$i18n && ['ar','fa','ur','he'].includes(this.$i18n.locale)) ||
        (typeof document !== 'undefined' && document.documentElement.dir === 'rtl');

      autoTable(pdf, {
        head: [headers],
        body,
        startY: 110,
        theme: 'striped',
        margin: { left: marginX, right: marginX },
        styles: { font: 'Vazirmatn', fontSize: 9, cellPadding: 4, halign: rtl ? 'right' : 'left', textColor: 33 },
        headStyles: { font: 'Vazirmatn', fontStyle: 'bold', fillColor: [63,81,181], textColor: 255 },
        alternateRowStyles: { fillColor: [245,247,250] },
        columnStyles: this.can("products_cost_view")
          ? { 4: { halign: 'right' }, 5: { halign: 'right' }, 6: { halign: 'right' }, 8: { halign: 'right' } }
          : { 4: { halign: 'right' }, 5: { halign: 'right' }, 7: { halign: 'right' } },
        didDrawPage: (d) => {
          const pageW = pdf.internal.pageSize.getWidth();
          const pageH = pdf.internal.pageSize.getHeight();

          // Header banner
          pdf.setFillColor(63,81,181);
          pdf.rect(0, 0, pageW, 60, 'F');

          // Title
          pdf.setTextColor(255);
          pdf.setFont('Vazirmatn', 'bold');
          pdf.setFontSize(16);
          const title = this.$t('productsList') || 'Product List';
          rtl ? pdf.text(title, pageW - marginX, 38, { align: 'right' })
              : pdf.text(title, marginX, 38);

          

          // Reset text color
          pdf.setTextColor(33);

          // Footer page numbers
          pdf.setFontSize(8);
          const pn = `${d.pageNumber} / ${pdf.internal.getNumberOfPages()}`;
          rtl ? pdf.text(pn, marginX, pageH - 14, { align: 'left' })
              : pdf.text(pn, pageW - marginX, pageH - 14, { align: 'right' });
        }
      });

      pdf.save("Product_List.pdf");
    },

    Show_import_products() { this.$bvModal.show("importProducts"); },

    onFileSelected(e) {
      this.import_products = "";
      const file = e.target.files?.[0];
      if (!file) return;
      if (file.size >= 1048576) {
        this.makeToast("danger", this.$t("file_size_must_be_less_than_1_mega"), this.$t("Failed"));
        return;
      }
      this.import_products = file;
    },

    Submit_import() {
      NProgress.start(); NProgress.set(0.1);
      this.ImportProcessing = true;
      const fd = new FormData();
      fd.append("products", this.import_products);

      axios.post("products/import/csv", fd)
        .then(response => {
          this.ImportProcessing = false;
          NProgress.done();
          if (response.data.status === true) {
            this.makeToast("success", this.$t("Successfully_Imported"), this.$t("Success"));
            Fire.$emit("Event_import");
          } else {
            this.makeToast("danger", response.data.message || this.$t("Import_failed"), this.$t("Failed"));
          }
        })
        .catch(error => {
          this.ImportProcessing = false;
          NProgress.done();
          if (error.response && error.response.status === 422) {
            const firstError = Object.values(error.response.data.errors || { _: [this.$t('InvalidData')] })[0][0];
            this.makeToast("danger", firstError, this.$t("Failed"));
          } else {
            const message = error.response?.data?.message || this.$t("Please_follow_the_import_instructions");
            this.makeToast("danger", message, this.$t("Failed"));
          }
        });
    },

    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, { title, variant, solid: true });
    },

    updateParams(newProps) { this.serverParams = Object.assign({}, this.serverParams, newProps); },

    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Products(currentPage);
      }
    },

    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Products(1);
      }
    },

    selectionChanged({ selectedRows }) {
      this.selectedIds = selectedRows.map(r => r.id);
    },

    onSortChange(params) {
      const f = params[0]?.field;
      const field = (f === "brand") ? "brand_id" : (f === "category") ? "category_id" : f;
      this.updateParams({ sort: { type: params[0].type, field } });
      if (f === "name") {
        this.nameSort = params[0].type === "asc" ? "az" : "za";
      }
      this.Get_Products(this.serverParams.page);
    },

    applyNameSort() {
      this.updateParams({
        page: 1,
        sort: {
          field: "name",
          type: this.nameSort === "za" ? "desc" : "asc"
        }
      });
      this.Get_Products(1);
    },

    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Products(this.serverParams.page);
    },

    async exportAllProducts() {
      if (this.isExportingAll) return;
      this.isExportingAll = true;
      NProgress.start();
      NProgress.set(0.1);

      try {
        this.setToStrings();
        const params = new URLSearchParams({
          page: '1',
          limit: '-1',
          code: this.Filter_code || '',
          name: this.Filter_name || '',
          category_id: this.Filter_category || '',
          brand_id: this.Filter_brand || '',
          warehouse_id: this.Filter_warehouse || '',
          status: this.canViewInactiveProducts ? (this.Filter_status || '') : '',
          SortField: this.serverParams.sort.field || 'name',
          SortType: this.serverParams.sort.type || 'asc',
          search: this.search || ''
        }).toString();

        const response = await axios.get(`products?${params}`);
        const rows = Array.isArray(response.data.products) ? response.data.products : [];

        if (!rows.length) {
          this.makeToast('warning', this.$t('NoDataToExport') || 'No products to export', this.$t('Warning') || 'Warning');
          return;
        }

        const exportData = rows.map(row => {
          const newRow = {};
          this.excelColumns.forEach(col => {
            newRow[col.label] = row[col.field] !== undefined && row[col.field] !== null ? row[col.field] : '';
          });
          return newRow;
        });

        const worksheet = XLSX.utils.json_to_sheet(exportData);
        const workbook = XLSX.utils.book_new();
        XLSX.utils.book_append_sheet(workbook, worksheet, this.$t('productsList') || 'products');

        const timestamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
        const filename = `products_all_${timestamp}.xlsx`;
        XLSX.writeFile(workbook, filename);
      } catch (error) {
        console.error('Export all products failed:', error);
        this.makeToast('danger', this.$t('Export_Failed') || 'Export failed. Please try again.', this.$t('Failed') || 'Failed');
      } finally {
        this.isExportingAll = false;
        NProgress.done();
      }
    },

    Reset_Filter() {
      this.search = "";
      this.Filter_brand = "";
      this.Filter_code = "";
      this.Filter_name = "";
      this.Filter_category = "";
      this.Filter_warehouse = "";
      this.Filter_status = "";
      this.Get_Products(this.serverParams.page);
    },

    setToStrings() {
      if (this.Filter_category === null) this.Filter_category = "";
      if (this.Filter_brand === null) this.Filter_brand = "";
      if (this.Filter_warehouse === null) this.Filter_warehouse = "";
    },

    Get_Products(page) {
      NProgress.start(); NProgress.set(0.1);
      this.setToStrings();

      axios.get(
        "products?page=" + page +
        "&code=" + encodeURIComponent(this.Filter_code || "") +
        "&name=" + encodeURIComponent(this.Filter_name || "") +
        "&category_id=" + encodeURIComponent(this.Filter_category || "") +
        "&brand_id=" + encodeURIComponent(this.Filter_brand || "") +
        "&warehouse_id=" + encodeURIComponent(this.Filter_warehouse || "") +
        "&status=" + encodeURIComponent(this.canViewInactiveProducts ? (this.Filter_status || "") : "") +
        "&SortField=" + encodeURIComponent(this.serverParams.sort.field) +
        "&SortType=" + encodeURIComponent(this.serverParams.sort.type) +
        "&search=" + encodeURIComponent(this.search || "") +
        "&limit=" + encodeURIComponent(this.limit)
      )
      .then(response => {
        this.products   = response.data.products;
        this.warehouses = response.data.warehouses;
        this.categories = response.data.categories;
        this.subcategories = response.data.subcategories || [];
        this.brands     = response.data.brands;
        this.canViewInactiveProducts = !!response.data.can_view_inactive_products;
        this.totalRows  = response.data.totalRows;
        NProgress.done(); this.isLoading = false;
      })
      .catch(() => {
        NProgress.done();
        setTimeout(() => { this.isLoading = false; }, 500);
      });
    },

    Remove_Product(id) {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start(); NProgress.set(0.1);
          axios.delete("products/" + id)
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              Fire.$emit("Delete_Product");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete.Therewassomethingwronge"), "warning");
            });
        }
      });
    },

    Duplicate_Product(id) {
      this.$swal({
        title: this.$t("Confirm"),
        text: this.$t("Are_you_sure_you_want_to_duplicate_this_product"),
        type: "question",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Cancel"),
        confirmButtonText: this.$t("Yes")
      }).then(result => {
        if (result.value) {
          // Navigate to the create page with duplicate param to prefill data
          this.$router.push({ name: "store_product", query: { duplicate: id } });
        }
      });
    },

    delete_by_selected() {
      this.$swal({
        title: this.$t("Delete_Title"),
        text: this.$t("Delete_Text"),
        type: "warning",
        showCancelButton: true,
        confirmButtonColor: "#3085d6",
        cancelButtonColor: "#d33",
        cancelButtonText: this.$t("Delete_cancelButtonText"),
        confirmButtonText: this.$t("Delete_confirmButtonText")
      }).then(result => {
        if (result.value) {
          NProgress.start(); NProgress.set(0.1);
          axios.post("products/delete/by_selection", { selectedIds: this.selectedIds })
            .then(() => {
              this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
              Fire.$emit("Delete_Product");
            })
            .catch(() => {
              setTimeout(() => NProgress.done(), 500);
              this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
            });
        }
      });
    }
  },

  created() {
    this.Get_Products(1);

    Fire.$on("Delete_Product", () => {
      this.Get_Products(this.serverParams.page);
      setTimeout(() => NProgress.done(), 500);
    });

    Fire.$on("Event_import", () => {
      setTimeout(() => {
        this.Get_Products(this.serverParams.page);
        this.$bvModal.hide("importProducts");
      }, 500);
    });
  }
};
</script>

<style scoped>
.pre { white-space: pre-line; }
.sort-select { width: 190px; min-width: 180px; vertical-align: middle; }
.table-actions-wrapper {
  gap: 0.5rem;
}
.table-actions-left,
.table-actions-right {
  gap: 0.5rem;
}
.table-actions-right {
  margin-left: auto;
}
.table-actions-wrapper .btn,
.table-actions-wrapper .btn-group,
.table-actions-wrapper .b-form-select {
  white-space: nowrap;
}

.action-cell {
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 0.3rem;
}
.action-price-icon {
  width: 15px;
  height: 15px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.9;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.pricing-level-shell {
  color: #0f172a;
  background: #f8fafc;
}
.pricing-level-header {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  min-height: 112px;
  padding: 1.5rem 1.75rem;
  overflow: hidden;
  background:
    radial-gradient(circle at 85% 10%, rgba(167, 139, 250, 0.34), transparent 38%),
    linear-gradient(135deg, #6d28d9 0%, #7c3aed 48%, #4f46e5 100%);
  color: #fff;
}
.pricing-level-header::after {
  content: "";
  position: absolute;
  right: 12%;
  bottom: -70px;
  width: 210px;
  height: 210px;
  border: 28px solid rgba(255, 255, 255, 0.06);
  border-radius: 50%;
}
.pricing-level-header__identity {
  position: relative;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 1rem;
}
.pricing-level-header__icon {
  display: grid;
  place-items: center;
  width: 54px;
  height: 54px;
  flex: 0 0 54px;
  border: 1px solid rgba(255, 255, 255, 0.3);
  border-radius: 15px;
  background: rgba(255, 255, 255, 0.16);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.2);
}
.pricing-level-header__icon svg {
  width: 26px;
  height: 26px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.8;
  stroke-linecap: round;
  stroke-linejoin: round;
}
.pricing-level-header__eyebrow {
  display: block;
  margin-bottom: 0.2rem;
  color: #ddd6fe;
  font-size: 0.7rem;
  font-weight: 800;
  letter-spacing: 0.1em;
  text-transform: uppercase;
}
.pricing-level-header h3 {
  margin: 0;
  color: #fff;
  font-size: 1.45rem;
  font-weight: 750;
  letter-spacing: -0.02em;
}
.pricing-level-header p {
  margin: 0.25rem 0 0;
  color: rgba(255, 255, 255, 0.78);
  font-size: 0.86rem;
}
.pricing-level-close {
  position: relative;
  z-index: 2;
  display: grid;
  place-items: center;
  width: 38px;
  height: 38px;
  padding: 0;
  border: 1px solid rgba(255, 255, 255, 0.24);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.12);
  color: #fff;
  cursor: pointer;
  transition: 0.18s ease;
}
.pricing-level-close:hover {
  background: rgba(255, 255, 255, 0.22);
  transform: translateY(-1px);
}
.pricing-level-close svg {
  width: 19px;
  height: 19px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
}
.pricing-level-content {
  padding: 1.5rem 1.75rem 1.25rem;
}
.pricing-level-loading {
  min-height: 360px;
  display: grid;
  place-items: center;
}
.pricing-level-product {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 1.1rem;
  border: 1px solid #e0e7ff;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
}
.pricing-level-product__main {
  display: flex;
  align-items: center;
  gap: 0.75rem;
}
.pricing-level-product__main > div {
  display: flex;
  flex-direction: column;
}
.pricing-level-product__avatar {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  padding: 0 !important;
  border-radius: 10px !important;
  background: #eef2ff !important;
  color: #5b21b6 !important;
  font-family: inherit !important;
}
.pricing-level-product__avatar svg {
  width: 19px;
  height: 19px;
}
.pricing-level-product small,
.variant-identity small {
  color: #64748b;
  font-size: 0.7rem;
  letter-spacing: 0.05em;
}
.pricing-level-product__code {
  border-radius: 6px;
  padding: 0.3rem 0.55rem;
  background: #eef2ff;
  color: #4338ca;
  font-family: monospace;
}
.pricing-level-section {
  position: relative;
  margin-bottom: 1.1rem;
  padding: 1.15rem 1.15rem 0.35rem;
  overflow: hidden;
  border: 1px solid #e5e7eb;
  border-radius: 14px;
  background: #fff;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.035);
}
.pricing-level-section::before {
  content: "";
  position: absolute;
  inset: 0 auto 0 0;
  width: 4px;
  background: #f59e0b;
}
.pricing-level-section--sale {
  border-color: #d1fae5;
  background: linear-gradient(135deg, #fff 60%, #f0fdf4 100%);
}
.pricing-level-section--sale::before {
  background: #10b981;
}
.pricing-level-section h6 {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin-bottom: 1rem;
  color: #92400e;
  font-weight: 700;
}
.pricing-level-section--sale h6 {
  color: #065f46;
}
.pricing-level-section h6 svg {
  width: 17px;
  height: 17px;
}
.pricing-level-variants th {
  min-width: 135px;
  white-space: nowrap;
  background: #f8fafc;
  font-size: 0.77rem;
}
.pricing-level-variants th:first-child {
  min-width: 170px;
}
.pricing-level-variants td {
  vertical-align: middle;
}
.variant-identity strong,
.variant-identity small {
  display: block;
}
.pricing-level-actions {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.6rem;
  margin: 0 -1.75rem -1.25rem;
  padding: 1rem 1.75rem;
  border-top: 1px solid #e5e7eb;
  background: #fff;
}
.pricing-level-actions .btn {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
}
.pricing-level-actions > div {
  display: flex;
  gap: 0.6rem;
}
.pricing-level-actions__hint {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  color: #64748b;
  font-size: 0.78rem;
}
.pricing-level-actions__hint svg {
  width: 15px;
  height: 15px;
  color: #6366f1;
}
.pricing-level-cancel,
.pricing-level-submit {
  min-height: 40px;
  padding: 0.55rem 1rem;
  border-radius: 9px;
  font-weight: 650;
}
.pricing-level-submit {
  border-color: transparent !important;
  background: linear-gradient(135deg, #7c3aed, #4f46e5) !important;
  box-shadow: 0 7px 18px rgba(99, 102, 241, 0.24);
}
@media (max-width: 767px) {
  .pricing-level-header,
  .pricing-level-content {
    padding-left: 1rem;
    padding-right: 1rem;
  }
  .pricing-level-header p,
  .pricing-level-actions__hint {
    display: none;
  }
  .pricing-level-product,
  .pricing-level-actions {
    align-items: stretch;
    flex-direction: column;
  }
  .pricing-level-actions {
    margin-left: -1rem;
    margin-right: -1rem;
    padding-left: 1rem;
    padding-right: 1rem;
  }
  .pricing-level-actions > div,
  .pricing-level-actions .btn {
    width: 100%;
  }
  .pricing-level-actions .btn {
    justify-content: center;
  }
}
</style>

<style>
/* BootstrapVue teleports modal wrappers to body, so wrapper sizing must be unscoped. */
.pricing-level-modal .modal-dialog {
  width: calc(100vw - 48px) !important;
  max-width: 1400px !important;
  margin: 24px auto !important;
}
.pricing-level-modal .modal-content {
  max-height: calc(100vh - 48px);
  overflow: hidden;
  border: 0 !important;
  border-radius: 18px !important;
  background: #f8fafc;
  box-shadow: 0 28px 70px -22px rgba(30, 27, 75, 0.48), 0 12px 28px -16px rgba(15, 23, 42, 0.35) !important;
}
.pricing-level-modal .pricing-level-modal-body {
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  padding: 0 !important;
}
.pricing-level-modal .form-group label {
  margin-bottom: 0.45rem;
  color: #334155;
  font-size: 0.78rem;
  font-weight: 650;
}
.pricing-level-modal .form-control {
  height: 42px;
  border: 1px solid #dbe2ea;
  border-radius: 9px;
  background: #fff;
  color: #0f172a;
  font-size: 0.86rem;
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}
.pricing-level-modal .form-control:focus {
  border-color: #8b5cf6;
  box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.12);
}
.pricing-level-modal .modal-backdrop {
  background: #1e1b4b;
}
@media (max-width: 767px) {
  .pricing-level-modal .modal-dialog {
    width: calc(100vw - 20px) !important;
    margin: 10px auto !important;
  }
  .pricing-level-modal .modal-content,
  .pricing-level-modal .pricing-level-modal-body {
    max-height: calc(100vh - 20px);
  }
}
</style>
