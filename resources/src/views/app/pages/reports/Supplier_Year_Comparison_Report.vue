<template>
  <div class="main-content">
    <breadcumb page="Supplier-wise Sales & Payments" :folder="$t('Reports')" />

    <b-card class="mb-3 border-0 shadow-sm">
      <b-row align-v="end">
        <b-col lg="3" md="6" class="mb-2">
          <label class="font-weight-bold">Supplier</label>
          <v-select
            v-model="filters.supplier_id"
            :reduce="option => option.id"
            :options="suppliers"
            label="name"
            :clearable="true"
            placeholder="All Suppliers"
          />
        </b-col>
        <b-col lg="3" md="6" class="mb-2">
          <label class="font-weight-bold">Branch</label>
          <v-select
            v-model="filters.warehouse_id"
            :reduce="option => option.id"
            :options="branches"
            label="name"
            :clearable="true"
            placeholder="All Branches"
          />
        </b-col>
        <b-col lg="2" md="4" class="mb-2">
          <label class="font-weight-bold">First Year</label>
          <v-select v-model="filters.baseline_year" :options="years" :clearable="false" />
        </b-col>
        <b-col lg="2" md="4" class="mb-2">
          <label class="font-weight-bold">Compare With</label>
          <v-select v-model="filters.comparison_year" :options="years" :clearable="false" />
        </b-col>
        <b-col lg="2" md="4" class="mb-2 d-flex">
          <b-button variant="primary" class="mr-2 flex-grow-1" :disabled="loading" @click="loadReport">
            <lucide-icon class="mr-1" name="filter" /> Apply
          </b-button>
          <b-button variant="success" title="Export Excel" :disabled="loading || exporting" @click="exportExcel">
            <lucide-icon class="mr-1" name="file-spreadsheet" /> Excel
          </b-button>
        </b-col>
      </b-row>
    </b-card>

    <b-card class="border-0 shadow-sm report-card" body-class="p-0">
      <div class="report-title">
        SUPPLIER-WISE SALES &amp; PAYMENTS OVERVIEW&nbsp; | &nbsp;{{ report.baseline_year }} vs {{ report.comparison_year }}
      </div>
      <div class="report-subtitle">
        All figures in {{ report.currency || 'PKR' }} &nbsp;|&nbsp; Supplier: {{ supplierName }}
        &nbsp;|&nbsp; Branch: {{ branchName }} &nbsp;|&nbsp; Variance = Sales - Payments
      </div>

      <div v-if="loading" class="text-center py-5">
        <b-spinner variant="primary"></b-spinner>
      </div>

      <div v-else class="table-responsive">
        <table class="comparison-table">
          <thead>
            <tr>
              <th rowspan="2" class="month-column">Month</th>
              <th colspan="3">YEAR {{ report.baseline_year }}</th>
              <th colspan="3">YEAR {{ report.comparison_year }}</th>
              <th rowspan="2" class="growth-column">YoY Sales<br />Growth %</th>
            </tr>
            <tr>
              <th>Sales</th>
              <th>Payments</th>
              <th>Variance</th>
              <th>Sales</th>
              <th>Payments</th>
              <th>Variance</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in report.rows" :key="row.month_number">
              <td class="text-left">{{ row.month }}</td>
              <td>{{ amount(row.baseline_sales) }}</td>
              <td>{{ amount(row.baseline_payments) }}</td>
              <td>{{ amount(row.baseline_variance) }}</td>
              <td>{{ amount(row.comparison_sales) }}</td>
              <td>{{ amount(row.comparison_payments) }}</td>
              <td>{{ amount(row.comparison_variance) }}</td>
              <td :class="growthClass(row.growth)">{{ growth(row.growth) }}</td>
            </tr>
          </tbody>
          <tfoot v-if="report.totals">
            <tr>
              <th class="text-left">TOTAL</th>
              <th>{{ amount(report.totals.baseline_sales) }}</th>
              <th>{{ amount(report.totals.baseline_payments) }}</th>
              <th>{{ amount(report.totals.baseline_variance) }}</th>
              <th>{{ amount(report.totals.comparison_sales) }}</th>
              <th>{{ amount(report.totals.comparison_payments) }}</th>
              <th>{{ amount(report.totals.comparison_variance) }}</th>
              <th>{{ growth(report.totals.growth) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="report-note" v-if="report.comparison_through">
        Note: {{ report.comparison_year }} figures are available through {{ report.comparison_through }} only;
        totals and YoY growth reflect this partial period.
      </div>
      <div class="report-note" v-else>
        Note: Sales represent received supplier purchases. Payments use the supplier payment date.
      </div>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Supplier-wise Sales & Payments" },
  data() {
    const currentYear = new Date().getFullYear();
    return {
      loading: false,
      exporting: false,
      suppliers: [],
      branches: [],
      years: Array.from({ length: currentYear - 1999 }, (_, index) => currentYear - index),
      filters: {
        supplier_id: null,
        warehouse_id: null,
        baseline_year: currentYear - 1,
        comparison_year: currentYear
      },
      report: {
        rows: [],
        totals: null,
        baseline_year: currentYear - 1,
        comparison_year: currentYear,
        currency: "PKR",
        supplier: null,
        branch: null,
        comparison_through: null
      }
    };
  },
  computed: {
    supplierName() {
      return this.report.supplier ? this.report.supplier.name : "All Suppliers";
    },
    branchName() {
      return this.report.branch ? this.report.branch.name : "All Branches";
    }
  },
  created() {
    this.loadReport();
  },
  methods: {
    params() {
      return {
        baseline_year: this.filters.baseline_year,
        comparison_year: this.filters.comparison_year,
        supplier_id: this.filters.supplier_id || undefined,
        warehouse_id: this.filters.warehouse_id || undefined
      };
    },
    validateYears() {
      if (Number(this.filters.baseline_year) === Number(this.filters.comparison_year)) {
        this.$bvToast.toast("Please select two different years.", {
          title: "Invalid year comparison",
          variant: "warning",
          solid: true
        });
        return false;
      }
      return true;
    },
    loadReport() {
      if (!this.validateYears()) return;
      this.loading = true;
      NProgress.start();
      axios
        .get("report/supplier_year_comparison", { params: this.params() })
        .then(response => {
          this.report = response.data;
          this.suppliers = response.data.suppliers || [];
          this.branches = response.data.branches || [];
        })
        .catch(error => this.showError(error, "Unable to load supplier report."))
        .finally(() => {
          this.loading = false;
          NProgress.done();
        });
    },
    exportExcel() {
      if (!this.validateYears()) return;
      this.exporting = true;
      NProgress.start();
      axios
        .get("report/supplier_year_comparison_excel", {
          params: this.params(),
          responseType: "blob"
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute(
            "download",
            `supplier-wise-sales-payments-${this.filters.baseline_year}-vs-${this.filters.comparison_year}.xlsx`
          );
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.URL.revokeObjectURL(url);
        })
        .catch(error => this.showError(error, "Unable to export supplier report."))
        .finally(() => {
          this.exporting = false;
          NProgress.done();
        });
    },
    amount(value) {
      if (value === null || typeof value === "undefined") return "-";
      return Number(value).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
      });
    },
    growth(value) {
      if (value === null || typeof value === "undefined") return "N/A";
      return `${Number(value).toLocaleString(undefined, { maximumFractionDigits: 2 })}%`;
    },
    growthClass(value) {
      if (value === null || typeof value === "undefined") return "growth-neutral";
      return Number(value) < 0 ? "growth-negative" : "growth-positive";
    },
    showError(error, fallback) {
      const message = error.response && error.response.data && error.response.data.message
        ? error.response.data.message
        : fallback;
      this.$bvToast.toast(message, { title: "Error", variant: "danger", solid: true });
    }
  }
};
</script>

<style scoped>
.report-card {
  overflow: hidden;
}
.report-title {
  background: #244574;
  color: #fff;
  font-size: 20px;
  font-weight: 700;
  letter-spacing: 0.2px;
  padding: 8px 12px;
}
.report-subtitle,
.report-note {
  color: #747474;
  font-size: 12px;
  font-style: italic;
  padding: 5px 12px;
}
.comparison-table {
  border-collapse: collapse;
  min-width: 980px;
  width: 100%;
}
.comparison-table th,
.comparison-table td {
  border: 1px solid #b8c4d4;
  padding: 6px 8px;
  text-align: right;
  white-space: nowrap;
}
.comparison-table thead th {
  background: #244574;
  color: #fff;
  font-weight: 700;
  text-align: center;
  vertical-align: middle;
}
.comparison-table tbody tr:nth-child(odd) td:not(:last-child) {
  background: #eaf0fa;
}
.comparison-table tfoot th {
  background: #244574;
  color: #fff;
}
.month-column {
  min-width: 130px;
}
.growth-column {
  min-width: 140px;
}
.growth-positive {
  background: #e2f0d9;
}
.growth-negative {
  background: #fbe1e1;
}
.growth-neutral {
  background: #f3f4f6;
}
@media (max-width: 767px) {
  .report-title {
    font-size: 16px;
  }
}
</style>
