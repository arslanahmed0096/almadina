<template>
  <div class="main-content">
    <breadcumb page="All Pricing Levels" folder="Pricing Level" />
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <vue-good-table
      v-else
      mode="remote"
      :columns="columns"
      :rows="pricingLevels"
      :totalRows="totalRows"
      :search-options="{ enabled: true, placeholder: $t('Search_this_table') }"
      :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
      @on-page-change="onPageChange"
      @on-per-page-change="onPerPageChange"
      @on-sort-change="onSortChange"
      @on-search="onSearch"
      styleClass="table-hover tableOne vgt-table"
    >
      <div slot="table-actions" class="mt-2 mb-3">
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
          v-if="canManage"
          class="btn-sm btn btn-primary btn-icon m-1"
          to="/app/pricing-levels/create"
        >
          <lucide-icon name="plus" />
          <span class="ml-1">Create Pricing Level</span>
        </router-link>
      </div>

      <template slot="table-row" slot-scope="props">
        <span v-if="props.column.field === 'date'">{{ formatDate(props.row.date) }}</span>
        <span v-else-if="props.column.field === 'actions'">
          <router-link
            v-if="canManage"
            v-b-tooltip.hover
            title="Edit"
            :to="{ name: 'pricing_levels_edit', params: { id: props.row.id } }"
          >
            <lucide-icon class="text-25 text-success" name="pencil" />
          </router-link>
          <a
            v-if="canManage"
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
    canManage() {
      return this.currentUserPermissions && this.currentUserPermissions.includes("pricing_level");
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
    formatDate(value) {
      if (!value) return "-";
      const parts = String(value).split("-");
      const date = parts.length === 3
        ? new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]))
        : new Date(value);
      if (Number.isNaN(date.getTime())) return value;
      return date.toLocaleDateString("en-US", { month: "short", day: "numeric", year: "numeric" });
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
          this.pricingLevels = Array.isArray(response.data.pricing_levels) ? response.data.pricing_levels : [];
          this.totalRows = Number(response.data.totalRows || 0);
        })
        .finally(() => {
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
    onSearch({ searchTerm }) {
      this.search = searchTerm || "";
      this.updateParams({ page: 1 });
      this.getPricingLevels(1);
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
  created() {
    this.loadOptions();
    this.getPricingLevels(1);
  }
};
</script>
