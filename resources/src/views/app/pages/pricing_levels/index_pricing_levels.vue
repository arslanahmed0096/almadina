<template>
  <div class="main-content">
    <breadcumb page="All Pricing Levels" folder="Pricing Level" />

    <div class="d-flex flex-wrap align-items-center justify-content-between mt-2 mb-3">
      <div class="pricing-level-search mb-2">
        <input
          v-model="search"
          type="text"
          class="form-control"
          :placeholder="$t('Search_this_table')"
          @input="onSearchInput($event.target.value)"
        >
      </div>
      <div class="mb-2">
        <b-button variant="outline-info m-1" size="sm" v-b-toggle.pricing-level-filter>
          <lucide-icon name="filter" /> {{ $t("Filter") }}
        </b-button>
        <b-button variant="outline-success m-1" size="sm" @click="pricingLevelPdf">
          <lucide-icon name="copy" /> PDF
        </b-button>
        <vue-excel-xlsx
          class="btn btn-sm btn-outline-danger ripple m-1"
          :data="exportRows"
          :columns="excelColumns"
          file-name="Pricing_Level_Entries"
          file-type="xlsx"
          sheet-name="Pricing Levels"
        >
          <lucide-icon name="file-spreadsheet" /> EXCEL
        </vue-excel-xlsx>
        <router-link
          v-if="canCreate"
          class="btn-sm btn btn-primary btn-icon m-1"
          to="/app/pricing-levels/create"
        >
          <lucide-icon name="plus" />
          <span class="ml-1">Create Pricing Level</span>
        </router-link>
      </div>
    </div>

    <vue-good-table
      mode="remote"
      :is-loading="isLoading"
      :columns="columns"
      :rows="pricingLevels"
      :totalRows="totalRows"
      :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
      @on-page-change="onPageChange"
      @on-per-page-change="onPerPageChange"
      @on-sort-change="onSortChange"
      styleClass="table-hover tableOne vgt-table"
    >
      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field === 'date'">{{ formatDate(props.row.date) }}</span>
        <span v-else-if="props.column.field === 'actions'">
          <a
            v-if="canView"
            v-b-tooltip.hover
            title="View pricing details"
            class="cursor-pointer"
            @click="openPricingView(props.row)"
          >
            <lucide-icon class="text-25 text-info" name="eye" />
          </a>
          <router-link
            v-if="canEdit"
            v-b-tooltip.hover
            title="Edit"
            class="ml-2"
            :to="{ name: 'pricing_levels_edit', params: { id: props.row.id } }"
          >
            <lucide-icon class="text-25 text-success" name="pencil" />
          </router-link>
          <a
            v-if="canDelete"
            v-b-tooltip.hover
            title="Delete"
            class="cursor-pointer ml-2"
            @click="removePricingLevel(props.row.id)"
          >
            <lucide-icon class="text-25 text-danger" name="x" />
          </a>
        </span>
        <span v-else>{{ props.formattedRow[props.column.field] }}</span>
      </template>
    </vue-good-table>

    <b-modal
      id="pricing-level-view-modal"
      size="xl"
      centered
      hide-footer
      hide-header
      modal-class="pricing-level-view-modal"
      body-class="pricing-level-view-modal-body"
    >
      <div class="pricing-view-shell">
        <header class="pricing-view-header">
          <div class="pricing-view-header__identity">
            <span class="pricing-view-header__icon"><lucide-icon name="tags" /></span>
            <div>
              <span class="pricing-view-header__eyebrow">PRODUCT PRICING</span>
              <h3>Pricing Level Details</h3>
              <p>Review the saved purchase prices, sale prices, and margin rules.</p>
            </div>
          </div>
          <button type="button" class="pricing-view-close" aria-label="Close" @click="$bvModal.hide('pricing-level-view-modal')">
            <lucide-icon name="x" />
          </button>
        </header>

        <div class="pricing-view-content">
          <div v-if="viewLoading" class="pricing-view-loading">
            <div class="spinner spinner-primary"></div>
          </div>
          <template v-else>
            <div class="pricing-view-summary">
              <div><span class="pricing-view-summary__icon"><lucide-icon name="tag" /></span><section><small>BRAND</small><strong>{{ viewEntry.brand || "N/D" }}</strong></section></div>
              <div><span class="pricing-view-summary__icon"><lucide-icon name="folder" /></span><section><small>CATEGORY</small><strong>{{ viewEntry.category || "N/D" }}</strong></section></div>
              <div><span class="pricing-view-summary__icon"><lucide-icon name="calendar" /></span><section><small>DATE</small><strong>{{ formatDate(viewEntry.date) }}</strong></section></div>
              <div><span class="pricing-view-summary__icon"><lucide-icon name="package" /></span><section><small>PRODUCTS</small><strong>{{ viewEntry.total_products || 0 }}</strong></section></div>
            </div>

            <section class="pricing-view-panel">
              <div class="pricing-view-panel__heading">
                <div><h5>Product pricing</h5><p>Only active products in this pricing level are shown.</p></div>
                <span>{{ viewRows.length }} pricing row{{ viewRows.length === 1 ? "" : "s" }}</span>
              </div>
              <div class="table-responsive pricing-view-table-wrap">
                <table class="table table-hover pricing-view-table mb-0">
                  <thead>
                    <tr>
                      <th>Product</th><th>Code</th><th>Purchase Price</th><th>Product Cost</th>
                      <th>Regular Price</th><th>Al-Madina Price</th><th>Wholesale</th><th>Minimum</th><th>Margins</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="row in viewRows" :key="row.key">
                      <td class="pricing-view-product"><strong>{{ row.name }}</strong><small v-if="row.variant"><span>VARIANT</span>{{ row.variant }}</small></td>
                      <td class="pricing-view-code">{{ row.code }}</td>
                      <td>{{ priceDisplay(row.purchase_price) }}</td>
                      <td>{{ priceDisplay(row.cost) }}</td>
                      <td>{{ priceDisplay(row.fix_price) }}</td>
                      <td>{{ priceDisplay(row.price) }}</td>
                      <td>{{ priceDisplay(row.wholesale_price) }}</td>
                      <td>{{ priceDisplay(row.min_price) }}</td>
                      <td class="pricing-margin-summary">
                        <template v-if="row.pricing_margins && row.pricing_margins.length">
                          <span v-for="(margin, index) in row.pricing_margins" :key="`${row.key}-margin-${index}`" class="pricing-margin-chip">
                            {{ margin.label || "Margin" }}: {{ margin.value }}{{ margin.type === "percentage" ? "%" : "" }}
                          </span>
                        </template>
                        <span v-else class="pricing-no-margin">No margins</span>
                      </td>
                    </tr>
                    <tr v-if="!viewRows.length"><td colspan="9" class="text-center text-muted py-5">No active product pricing details.</td></tr>
                  </tbody>
                </table>
              </div>
            </section>
          </template>
        </div>
      </div>
    </b-modal>

    <b-sidebar id="pricing-level-filter" :title="$t('Filter')" bg-variant="white" right shadow>
      <div class="px-3 py-2">
        <b-form-group :label="$t('date')">
          <b-form-input v-model="filters.date" type="date" />
        </b-form-group>

        <b-form-group :label="$t('Brand')">
          <v-select
            v-model="filters.brand_id"
            :reduce="brand => brand.id"
            :options="brands"
            label="name"
            placeholder="Choose Brand"
            @input="onFilterBrandSelected"
          />
        </b-form-group>

        <b-form-group :label="$t('Categorie')">
          <v-select
            v-model="filters.category_id"
            :reduce="category => category.id"
            :options="categories"
            label="name"
            :disabled="!filters.brand_id || categoriesLoading"
            :loading="categoriesLoading"
            placeholder="Choose Category"
          />
        </b-form-group>

        <b-button block size="sm" variant="primary" class="mb-2" @click="applyFilters">
          <lucide-icon name="filter" /> {{ $t("Filter") }}
        </b-button>
        <b-button block size="sm" variant="danger" @click="resetFilters">
          <lucide-icon name="power" /> {{ $t("Reset") }}
        </b-button>
      </div>
    </b-sidebar>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";

export default {
  name: "IndexPricingLevels",
  metaInfo: { title: "All Pricing Levels" },
  data() {
    return {
      pricingLevels: [],
      brands: [],
      categories: [],
      categoriesLoading: false,
      totalRows: 0,
      isLoading: true,
      search: "",
      searchTimer: null,
      requestSequence: 0,
      viewLoading: false,
      viewEntry: {},
      viewRows: [],
      filters: { date: "", brand_id: null, category_id: null },
      serverParams: {
        page: 1,
        perPage: 10,
        sort: { field: "id", type: "desc" }
      }
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canCreate() {
      return this.hasPermission("pricing_level_add");
    },
    canView() {
      return this.hasPermission("pricing_level_view");
    },
    canEdit() {
      return this.hasPermission("pricing_level_edit");
    },
    canDelete() {
      return this.hasPermission("pricing_level_delete");
    },
    columns() {
      return [
        { label: this.$t("date"), field: "date", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Brand"), field: "brand", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Categorie"), field: "category", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("TotalProducts"), field: "total_products", type: "number", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    },
    excelColumns() {
      return [
        { label: "Date", field: "date" },
        { label: "Brand", field: "brand" },
        { label: "Category", field: "category" },
        { label: "Total Products", field: "total_products" }
      ];
    },
    exportRows() {
      return this.pricingLevels.map(row => ({
        date: this.formatDate(row.date),
        brand: row.brand,
        category: row.category,
        total_products: row.total_products
      }));
    }
  },
  methods: {
    hasPermission(permission) {
      return this.currentUserPermissions && this.currentUserPermissions.includes(permission);
    },
    formatDate(value) {
      if (!value) return "-";
      const parts = String(value).split("-");
      const date = parts.length === 3
        ? new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]))
        : new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
    },
    priceDisplay(value) {
      return Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    marginSummary(margins) {
      if (!Array.isArray(margins) || !margins.length) return "No margins";
      return margins.map(margin => `${margin.label || "Margin"}: ${margin.value}${margin.type === "percentage" ? "%" : ""}`).join(" | ");
    },
    flattenPricingProducts(products) {
      const rows = [];
      (products || []).forEach(product => {
        if (Array.isArray(product.pricing_variants) && product.pricing_variants.length) {
          product.pricing_variants.forEach(variant => rows.push(Object.assign({}, variant, {
            key: `product-${product.id}-variant-${variant.id}`,
            name: product.name,
            variant: variant.name
          })));
        } else {
          rows.push(Object.assign({}, product, { key: `product-${product.id}`, variant: "" }));
        }
      });
      return rows.sort((left, right) => String(left.name || "").localeCompare(String(right.name || ""), undefined, { sensitivity: "base" }));
    },
    openPricingView(row) {
      this.viewEntry = Object.assign({}, row);
      this.viewRows = [];
      this.viewLoading = true;
      this.$bvModal.show("pricing-level-view-modal");
      axios.get(`pricing-levels/${row.id}`)
        .then(response => {
          this.viewEntry = response.data.entry || row;
          this.viewRows = this.flattenPricingProducts(response.data.products);
        })
        .catch(() => {
          this.$root.$bvToast.toast("Unable to load pricing details.", { title: this.$t("Failed"), variant: "danger", solid: true });
        })
        .finally(() => { this.viewLoading = false; });
    },
    loadOptions(brandId) {
      this.categoriesLoading = !!brandId;
      return axios.get("pricing-level/options", { params: brandId ? { brand_id: brandId } : {} })
        .then(response => {
          if (Array.isArray(response.data.brands)) this.brands = response.data.brands;
          this.categories = Array.isArray(response.data.categories) ? response.data.categories : [];
        })
        .finally(() => { this.categoriesLoading = false; });
    },
    onFilterBrandSelected(brandId) {
      this.filters.category_id = null;
      this.categories = [];
      if (brandId) this.loadOptions(brandId);
    },
    updateParams(values) {
      this.serverParams = Object.assign({}, this.serverParams, values);
    },
    getPricingLevels(page) {
      const requestId = ++this.requestSequence;
      this.isLoading = true;
      NProgress.start();
      axios.get("pricing-levels", {
        params: {
          page: page || this.serverParams.page,
          limit: this.serverParams.perPage,
          SortField: this.serverParams.sort.field,
          SortType: this.serverParams.sort.type,
          search: this.search,
          date: this.filters.date || "",
          brand_id: this.filters.brand_id || "",
          category_id: this.filters.category_id || ""
        }
      })
        .then(response => {
          if (requestId !== this.requestSequence) return;
          this.pricingLevels = Array.isArray(response.data.pricing_levels) ? response.data.pricing_levels : [];
          this.totalRows = Number(response.data.totalRows || 0);
        })
        .finally(() => {
          if (requestId !== this.requestSequence) return;
          this.isLoading = false;
          NProgress.done();
        });
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page === currentPage) return;
      this.updateParams({ page: currentPage });
      this.getPricingLevels(currentPage);
    },
    onPerPageChange({ currentPerPage }) {
      if (this.serverParams.perPage === currentPerPage) return;
      this.updateParams({ page: 1, perPage: currentPerPage });
      this.getPricingLevels(1);
    },
    onSortChange(params) {
      if (!params || !params.length) return;
      this.updateParams({ page: 1, sort: { field: params[0].field, type: params[0].type } });
      this.getPricingLevels(1);
    },
    onSearchInput(searchTerm) {
      const nextSearch = this.normalizeSearch(searchTerm);
      this.search = nextSearch;
      this.updateParams({ page: 1 });
      this.syncSearchQuery(nextSearch);
      this.scheduleSearch();
    },
    normalizeSearch(value) {
      return typeof value === "string" ? value : "";
    },
    scheduleSearch() {
      if (this.searchTimer) clearTimeout(this.searchTimer);
      this.searchTimer = setTimeout(() => {
        this.searchTimer = null;
        this.getPricingLevels(1);
      }, 350);
    },
    syncSearchQuery(search) {
      const currentSearch = this.normalizeSearch(this.$route.query.search);
      if (currentSearch === search) return;

      const query = Object.assign({}, this.$route.query);
      if (search) query.search = search;
      else delete query.search;

      this.$router.replace({ query }).catch(() => {});
    },
    applyFilters() {
      this.updateParams({ page: 1 });
      this.getPricingLevels(1);
      this.$root.$emit("bv::toggle::collapse", "pricing-level-filter");
    },
    resetFilters() {
      this.filters = { date: "", brand_id: null, category_id: null };
      this.categories = [];
      this.updateParams({ page: 1 });
      this.getPricingLevels(1);
      this.$root.$emit("bv::toggle::collapse", "pricing-level-filter");
    },
    pricingLevelPdf() {
      if (!this.exportRows.length) return;
      const pdf = new jsPDF("p", "pt");
      autoTable(pdf, {
        head: [["Date", "Brand", "Category", "Total Products"]],
        body: this.exportRows.map(row => [row.date, row.brand, row.category, row.total_products]),
        startY: 50,
        theme: "striped",
        headStyles: { fillColor: [113, 55, 159] }
      });
      pdf.save("Pricing_Level_Entries.pdf");
    },
    removePricingLevel(id) {
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
        if (!result.value) return;
        axios.delete(`pricing-levels/${id}`)
          .then(() => {
            this.$swal(this.$t("Delete_Deleted"), this.$t("Deleted_in_successfully"), "success");
            this.getPricingLevels(this.serverParams.page);
          })
          .catch(() => {
            this.$swal(this.$t("Delete_Failed"), this.$t("Delete_Therewassomethingwronge"), "warning");
          });
      });
    }
  },
  watch: {
    "$route.query.search"(value) {
      const nextSearch = this.normalizeSearch(value);
      if (nextSearch === this.search) return;

      this.search = nextSearch;
      this.updateParams({ page: 1 });
      this.scheduleSearch();
    }
  },
  created() {
    this.search = this.normalizeSearch(this.$route.query.search);
    this.loadOptions();
    this.getPricingLevels(1);
  },
  beforeDestroy() {
    if (this.searchTimer) clearTimeout(this.searchTimer);
  }
};
</script>

<style scoped>
.pricing-level-search {
  width: 250px;
  max-width: 100%;
}

.pricing-view-loading {
  min-height: 360px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.pricing-view-summary {
  display: grid;
  grid-template-columns: repeat(4, minmax(130px, 1fr));
  gap: 12px;
  margin-bottom: 20px;
}

.pricing-view-summary > div {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  padding: 15px;
  border: 1px solid #e2e8f0;
  border-radius: 12px;
  background: #fff;
  box-shadow: 0 5px 16px rgba(15, 23, 42, .04);
}

.pricing-view-summary small,
.pricing-view-summary strong,
.pricing-view-table td small {
  display: block;
}

.pricing-view-summary small,
.pricing-view-table td small {
  color: #64748b;
}

.pricing-view-table {
  min-width: 1240px;
}

.pricing-view-table th,
.pricing-view-table td {
  padding: 12px 10px;
  white-space: nowrap;
  vertical-align: middle;
}

.pricing-margin-summary {
  min-width: 235px;
  max-width: 300px;
  white-space: normal !important;
}

.pricing-view-shell { background: #f8fafc; }
.pricing-view-header {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 112px;
  padding: 24px 30px;
  overflow: hidden;
  color: #fff;
  background: linear-gradient(135deg, #71379f, #4f46e5);
}
.pricing-view-header::after {
  position: absolute;
  right: -45px;
  bottom: -85px;
  width: 210px;
  height: 210px;
  content: "";
  border-radius: 50%;
  background: rgba(255,255,255,.08);
}
.pricing-view-header__identity { position: relative; z-index: 1; display: flex; align-items: center; gap: 15px; }
.pricing-view-header__icon { display: flex; align-items: center; justify-content: center; width: 50px; height: 50px; border-radius: 13px; background: rgba(255,255,255,.16); }
.pricing-view-header__icon svg { width: 25px; height: 25px; }
.pricing-view-header__eyebrow { font-size: 10px; font-weight: 800; letter-spacing: 1.2px; opacity: .78; }
.pricing-view-header h3 { margin: 2px 0 3px; color: #fff; font-size: 22px; font-weight: 700; }
.pricing-view-header p { margin: 0; color: rgba(255,255,255,.78); font-size: 12px; }
.pricing-view-close { position: relative; z-index: 2; display: flex; align-items: center; justify-content: center; width: 38px; height: 38px; color: #fff; border: 1px solid rgba(255,255,255,.22); border-radius: 10px; background: rgba(255,255,255,.1); }
.pricing-view-close svg { width: 19px; height: 19px; }
.pricing-view-content { padding: 24px 28px 28px; }
.pricing-view-summary__icon { display: flex; flex: 0 0 38px; align-items: center; justify-content: center; width: 38px; height: 38px; color: #71379f; border-radius: 10px; background: #f0e7f8; }
.pricing-view-summary__icon svg { width: 18px; height: 18px; }
.pricing-view-summary section { min-width: 0; }
.pricing-view-summary strong { overflow: hidden; color: #1e293b; font-size: 14px; text-overflow: ellipsis; white-space: nowrap; }
.pricing-view-panel { overflow: hidden; border: 1px solid #e2e8f0; border-radius: 13px; background: #fff; box-shadow: 0 7px 20px rgba(15,23,42,.05); }
.pricing-view-panel__heading { display: flex; align-items: center; justify-content: space-between; gap: 15px; padding: 17px 20px; border-bottom: 1px solid #e8edf3; }
.pricing-view-panel__heading h5 { margin: 0; color: #1e293b; font-size: 16px; font-weight: 700; }
.pricing-view-panel__heading p { margin: 3px 0 0; color: #64748b; font-size: 11px; }
.pricing-view-panel__heading > span { padding: 5px 10px; color: #71379f; border-radius: 14px; background: #f0e7f8; font-size: 11px; font-weight: 700; }
.pricing-view-table-wrap { max-height: calc(100vh - 345px); }
.pricing-view-table thead th { position: sticky; top: 0; z-index: 1; color: #475569; border-top: 0; border-bottom: 1px solid #dfe6ee; background: #f8fafc; font-size: 11px; font-weight: 750; }
.pricing-view-table tbody td { color: #334155; border-top: 1px solid #edf1f5; font-size: 12px; }
.pricing-view-product { min-width: 220px; white-space: normal !important; }
.pricing-view-product strong { display: block; color: #1e293b; }
.pricing-view-product small { margin-top: 4px; }
.pricing-view-product small span { display: inline-block; padding: 2px 5px; margin-right: 5px; color: #71379f; border-radius: 7px; background: #f0e7f8; font-size: 9px; font-weight: 800; }
.pricing-view-code { color: #64748b !important; font-family: monospace; }
.pricing-margin-chip { display: inline-block; padding: 3px 7px; margin: 2px; color: #5f2d86; border: 1px solid #e3d5ef; border-radius: 9px; background: #faf7fd; font-size: 10px; }
.pricing-no-margin { color: #94a3b8; font-size: 11px; }

@media (max-width: 767px) {
  .pricing-view-summary { grid-template-columns: 1fr 1fr; }
  .pricing-view-header { padding: 20px; }
  .pricing-view-header p { display: none; }
  .pricing-view-content { padding: 16px; }
  .pricing-view-panel__heading { align-items: flex-start; flex-direction: column; }
}
</style>

<style>
/* BootstrapVue mounts modal wrappers under body, so these sizing rules must remain unscoped. */
.pricing-level-view-modal .modal-dialog {
  width: calc(100vw - 48px) !important;
  max-width: 1400px !important;
  margin: 24px auto !important;
}
.pricing-level-view-modal .modal-content {
  max-height: calc(100vh - 48px);
  overflow: hidden;
  border: 0 !important;
  border-radius: 18px !important;
  background: #f8fafc;
  box-shadow: 0 28px 70px -22px rgba(30,27,75,.48), 0 12px 28px -16px rgba(15,23,42,.35) !important;
}
.pricing-level-view-modal .pricing-level-view-modal-body {
  max-height: calc(100vh - 48px);
  overflow-y: auto;
  padding: 0 !important;
}
@media (max-width: 767px) {
  .pricing-level-view-modal .modal-dialog { width: calc(100vw - 20px) !important; margin: 10px auto !important; }
  .pricing-level-view-modal .modal-content,
  .pricing-level-view-modal .pricing-level-view-modal-body { max-height: calc(100vh - 20px); }
}
</style>
