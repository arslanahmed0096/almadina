<template>
  <div class="main-content pricing-level-list">
    <breadcumb page="All Pricing Levels" folder="Pricing Level" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <vue-good-table
      v-else
      mode="remote"
      :columns="columns"
      :rows="products"
      :totalRows="totalRows"
      :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
      :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
      @on-page-change="onPageChange"
      @on-per-page-change="onPerPageChange"
      @on-sort-change="onSortChange"
      @on-search="onSearch"
      styleClass="tableOne vgt-table pricing-level-table"
    >
      <div slot="table-actions" class="pricing-table-actions mt-2 mb-3 d-flex flex-wrap align-items-center justify-content-between">
        <div class="d-flex flex-wrap align-items-center">
          <b-form-select
            v-model="nameSort"
            :options="nameSortOptions"
            size="sm"
            class="pricing-sort m-1"
            @change="applyNameSort"
          />

          <b-button class="m-1" size="sm" variant="outline-info" v-b-toggle.pricing-level-filter>
            <lucide-icon name="filter" />
            {{ $t("Filter") }}
          </b-button>

          <b-button class="m-1" size="sm" variant="outline-success" @click="pricingLevelPdf">
            <lucide-icon name="copy" /> PDF
          </b-button>

          <vue-excel-xlsx
            class="btn btn-sm btn-outline-danger m-1"
            :data="exportRows"
            :columns="excelColumns"
            file-name="pricing_levels"
            file-type="xlsx"
            sheet-name="Pricing Levels"
          >
            <lucide-icon name="file-spreadsheet" /> EXCEL
          </vue-excel-xlsx>
        </div>

        <div>
          <router-link
            v-if="canEdit"
            to="/app/pricing-levels/create"
            class="btn btn-sm btn-primary m-1"
          >
            <lucide-icon name="plus" />
            Create Pricing Level
          </router-link>
        </div>
      </div>

      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field === 'name'" class="pricing-text-cell">
          {{ props.row.name }}
        </span>

        <span v-else-if="props.column.field === 'category'" class="pricing-text-cell">
          {{ props.row.categories_display || props.row.category || 'N/D' }}
        </span>

        <span v-else-if="isPriceField(props.column.field)" class="pricing-price-cell">
          {{ pricingValues(props.row, props.column.field) }}
        </span>

        <span v-else-if="props.column.field === 'created_at' || props.column.field === 'pricing_updated_at'" class="pricing-date-cell">
          {{ formatDate(props.row[props.column.field]) }}
        </span>

        <span v-else-if="props.column.field === 'actions'">
          <router-link
            v-if="canEdit"
            v-b-tooltip.hover
            title="Edit Pricing Level"
            :to="{ name: 'pricing_levels_create', query: { product: props.row.id } }"
            class="btn btn-sm btn-outline-success"
          >
            <lucide-icon name="pencil" />
          </router-link>
          <span v-else class="text-muted">—</span>
        </span>

        <span v-else>{{ props.formattedRow[props.column.field] }}</span>
      </template>
    </vue-good-table>

    <b-sidebar id="pricing-level-filter" :title="$t('Filter')" bg-variant="white" right shadow>
      <div class="px-3 py-2">
        <b-form-group :label="$t('CodeProduct')">
          <b-form-input v-model="filters.code" :placeholder="$t('SearchByCode')" />
        </b-form-group>

        <b-form-group :label="$t('ProductName')">
          <b-form-input v-model="filters.name" :placeholder="$t('SearchByName')" />
        </b-form-group>

        <b-form-group :label="$t('Categorie')">
          <v-select
            v-model="filters.category"
            :reduce="option => option.value"
            :options="categories.map(category => ({ label: category.name, value: category.id }))"
            :placeholder="$t('Choose_Category')"
          />
        </b-form-group>

        <b-form-group :label="$t('Brand')">
          <v-select
            v-model="filters.brand"
            :reduce="option => option.value"
            :options="brands.map(brand => ({ label: brand.name, value: brand.id }))"
            :placeholder="$t('Choose_Brand')"
          />
        </b-form-group>

        <b-button block size="sm" variant="primary" class="mb-2" @click="applyFilters">
          <lucide-icon name="filter" /> {{ $t("Filter") }}
        </b-button>
        <b-button block size="sm" variant="outline-danger" @click="resetFilters">
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
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  name: "IndexPricingLevels",
  metaInfo: { title: "All Pricing Levels" },
  data() {
    return {
      products: [],
      categories: [],
      brands: [],
      totalRows: 0,
      isLoading: true,
      search: "",
      nameSort: "az",
      priceFormatKey: null,
      filters: {
        code: "",
        name: "",
        category: "",
        brand: ""
      },
      serverParams: {
        page: 1,
        perPage: 10,
        sort: { field: "name", type: "asc" }
      },
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
    ...mapGetters(["currentUserPermissions", "currentUser"]),
    canEdit() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("pricing_level");
    },
    currency() {
      return this.currentUser && this.currentUser.currency ? this.currentUser.currency : "";
    },
    nameSortOptions() {
      return [
        { value: "az", text: "Product Name: A-Z" },
        { value: "za", text: "Product Name: Z-A" }
      ];
    },
    excelColumns() {
      return [
        { label: "Created Date", field: "created_at" },
        { label: "Updated Date", field: "pricing_updated_at" },
        { label: this.$t("Name_product"), field: "name" },
        { label: this.$t("Code"), field: "code" },
        { label: this.$t("Brand"), field: "brand" },
        { label: this.$t("Categorie"), field: "category" },
        { label: "Company RB Price", field: "company_rb_price" },
        { label: "MRP Price", field: "mrp_price" },
        { label: "Product Cost", field: "cost" },
        { label: "Fix Price", field: "fix_price" },
        { label: "Retail Price", field: "price" },
        { label: "Whole Sale Price", field: "wholesale_price" },
        { label: "Minimum Price", field: "min_price" }
      ];
    },
    exportRows() {
      return this.products.map(product => ({
        created_at: this.formatDate(product.created_at),
        pricing_updated_at: this.formatDate(product.pricing_updated_at),
        name: product.name,
        code: product.code,
        brand: product.brand,
        category: product.categories_display || product.category || "N/D",
        company_rb_price: this.pricingValues(product, "company_rb_price"),
        mrp_price: this.pricingValues(product, "mrp_price"),
        cost: this.pricingValues(product, "cost"),
        fix_price: this.pricingValues(product, "fix_price"),
        price: this.pricingValues(product, "price"),
        wholesale_price: this.pricingValues(product, "wholesale_price"),
        min_price: this.pricingValues(product, "min_price")
      }));
    },
    columns() {
      return [
        { label: "Created Date", field: "created_at", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Updated Date", field: "pricing_updated_at", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Name_product"), field: "name", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Code"), field: "code", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Brand"), field: "brand", tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Categorie"), field: "category", tdClass: "text-left", thClass: "text-left" },
        { label: "Company RB Price", field: "company_rb_price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "MRP Price", field: "mrp_price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Product Cost", field: "cost", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Fix Price", field: "fix_price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Retail Price", field: "price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Whole Sale Price", field: "wholesale_price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: "Minimum Price", field: "min_price", sortable: false, tdClass: "text-left", thClass: "text-left" },
        { label: this.$t("Action"), field: "actions", sortable: false, tdClass: "text-left", thClass: "text-left" }
      ];
    }
  },
  methods: {
    isPriceField(field) {
      return this.priceFields.includes(field);
    },
    formatDate(value) {
      if (!value) return "—";
      const date = new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleString(undefined, {
        year: "numeric",
        month: "short",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit"
      });
    },
    formatPrice(value) {
      const numeric = Number(value);
      if (!Number.isFinite(numeric)) return "—";

      try {
        const key = this.priceFormatKey || getPriceFormatSetting({ store: this.$store });
        if (key) this.priceFormatKey = key;
        const formatted = formatPriceDisplayHelper(numeric, 2, key);
        return this.currency ? `${this.currency} ${formatted}` : formatted;
      } catch (error) {
        const formatted = numeric.toFixed(2);
        return this.currency ? `${this.currency} ${formatted}` : formatted;
      }
    },
    pricingValues(row, field) {
      const variants = Array.isArray(row.pricing_variants) ? row.pricing_variants : [];
      if (row.product_type === "is_variant" && variants.length) {
        return variants.map(variant => this.formatPrice(variant[field])).join("\n");
      }
      return this.formatPrice(row[field]);
    },
    pricingLevelPdf() {
      if (!this.exportRows.length) {
        this.$root.$bvToast.toast("No pricing levels to export.", {
          title: this.$t("Warning") || "Warning",
          variant: "warning",
          solid: true
        });
        return;
      }

      const pdf = new jsPDF("landscape", "pt", "a3");
      const headers = this.excelColumns.map(column => column.label);
      const body = this.exportRows.map(row => this.excelColumns.map(column => row[column.field]));
      autoTable(pdf, {
        head: [headers],
        body,
        startY: 45,
        theme: "striped",
        styles: { fontSize: 7, cellPadding: 3, overflow: "linebreak" },
        headStyles: { fillColor: [113, 55, 159] },
        margin: { left: 25, right: 25 }
      });
      pdf.save("pricing_levels.pdf");
    },
    applyFilters() {
      this.updateParams({ page: 1 });
      this.getProducts(1);
      this.$root.$emit("bv::toggle::collapse", "pricing-level-filter");
    },
    resetFilters() {
      this.filters = { code: "", name: "", category: "", brand: "" };
      this.updateParams({ page: 1 });
      this.getProducts(1);
      this.$root.$emit("bv::toggle::collapse", "pricing-level-filter");
    },
    updateParams(values) {
      this.serverParams = Object.assign({}, this.serverParams, values);
    },
    getProducts(page) {
      this.isLoading = true;
      NProgress.start();
      const params = {
        page: page || this.serverParams.page,
        limit: this.serverParams.perPage,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        search: this.search,
        code: this.filters.code || "",
        name: this.filters.name || "",
        category_id: this.filters.category || "",
        brand_id: this.filters.brand || "",
        pricing_level: 1
      };

      axios.get("products", { params })
        .then(response => {
          this.products = Array.isArray(response.data.products) ? response.data.products : [];
          this.categories = Array.isArray(response.data.categories) ? response.data.categories : [];
          this.brands = Array.isArray(response.data.brands) ? response.data.brands : [];
          this.totalRows = Number(response.data.totalRows || 0);
        })
        .catch(error => {
          const message = error.response && error.response.data && error.response.data.message
            ? error.response.data.message
            : "Unable to load pricing levels.";
          this.$root.$bvToast.toast(message, { title: this.$t("Failed"), variant: "danger", solid: true });
        })
        .finally(() => {
          this.isLoading = false;
          NProgress.done();
        });
    },
    onPageChange({ currentPage }) {
      if (this.serverParams.page === currentPage) return;
      this.updateParams({ page: currentPage });
      this.getProducts(currentPage);
    },
    onPerPageChange({ currentPerPage }) {
      if (this.serverParams.perPage === currentPerPage) return;
      this.updateParams({ page: 1, perPage: currentPerPage });
      this.getProducts(1);
    },
    onSortChange(params) {
      if (!params || !params.length) return;
      const selected = params[0];
      const field = selected.field === "brand"
        ? "brand_id"
        : selected.field === "category" ? "category_id" : selected.field;
      this.updateParams({ sort: { field, type: selected.type }, page: 1 });
      if (selected.field === "name") this.nameSort = selected.type === "desc" ? "za" : "az";
      this.getProducts(1);
    },
    onSearch({ searchTerm }) {
      this.search = searchTerm || "";
      this.updateParams({ page: 1 });
      this.getProducts(1);
    },
    applyNameSort() {
      this.updateParams({
        page: 1,
        sort: { field: "name", type: this.nameSort === "za" ? "desc" : "asc" }
      });
      this.getProducts(1);
    }
  },
  created() {
    this.getProducts(1);
  }
};
</script>

<style scoped>
.pricing-table-actions {
  gap: 0.5rem;
  min-width: 260px;
}

.pricing-sort {
  width: 190px;
}

.pricing-text-cell,
.pricing-price-cell,
.pricing-date-cell {
  white-space: pre-line;
}

.pricing-date-cell {
  display: inline-block;
  min-width: 130px;
}

.pricing-price-cell {
  display: inline-block;
  min-width: 92px;
  line-height: 1.65;
}

.pricing-level-list ::v-deep .vgt-responsive {
  overflow-x: auto;
}

.pricing-level-list ::v-deep .pricing-level-table {
  min-width: 1580px;
}
</style>
