<template>
  <div class="main-content">
    <breadcumb page="Branch Performance Comparison" :folder="$t('Reports')" />

    <b-card class="mb-3 border-0 shadow-sm">
      <b-row align-v="end">
        <b-col lg="3" md="4" class="mb-2">
          <label class="font-weight-bold">First Year</label>
          <v-select v-model="filters.baseline_year" :options="years" :clearable="false" />
        </b-col>
        <b-col lg="3" md="4" class="mb-2">
          <label class="font-weight-bold">Compare With</label>
          <v-select v-model="filters.comparison_year" :options="years" :clearable="false" />
        </b-col>
        <b-col lg="3" md="4" class="mb-2">
          <label class="font-weight-bold">Report View</label>
          <v-select
            v-model="filters.view"
            :reduce="option => option.value"
            :options="viewOptions"
            label="label"
            :clearable="false"
          />
        </b-col>
        <b-col lg="3" md="12" class="mb-2 d-flex">
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
        BRANCH PERFORMANCE COMPARISON&nbsp; | &nbsp;{{ report.baseline_year }} vs {{ report.comparison_year }}
      </div>
      <div class="report-subtitle">
        All figures in {{ report.currency || 'PKR' }} &nbsp;•&nbsp; Completed sales only &nbsp;•&nbsp;
        Difference = {{ report.comparison_year }} Total Sale - {{ report.baseline_year }} Total Sale
      </div>

      <div v-if="loading" class="text-center py-5">
        <b-spinner variant="primary"></b-spinner>
      </div>

      <div v-else class="table-responsive">
        <div v-if="showAnnual">
          <div class="section-title">1. ANNUAL SALES TOTAL BY BRANCH</div>
          <table class="comparison-table">
          <thead>
            <tr>
              <th class="text-left">Branch</th>
              <th>{{ report.baseline_year }} Total Sale</th>
              <th>{{ report.comparison_year }} Total Sale</th>
              <th>Difference</th>
              <th>% Change</th>
              <th>Trend</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in report.rows" :key="row.warehouse_id">
              <td class="text-left">{{ row.branch }}</td>
              <td>{{ amount(row.baseline_total) }}</td>
              <td>{{ amount(row.comparison_total) }}</td>
              <td :class="differenceFillClass(row.difference)">{{ signedAmount(row.difference) }}</td>
              <td :class="changeClass(row.change_percent)">{{ percent(row.change_percent) }}</td>
              <td :class="trendClass(row.trend)">{{ trend(row.trend) }}</td>
            </tr>
            <tr v-if="!report.rows.length">
              <td colspan="6" class="text-center text-muted py-4">No accessible branches found.</td>
            </tr>
          </tbody>
          <tfoot v-if="report.totals">
            <tr>
              <th class="text-left">GRAND TOTAL</th>
              <th>{{ amount(report.totals.baseline_total) }}</th>
              <th>{{ amount(report.totals.comparison_total) }}</th>
              <th>{{ signedAmount(report.totals.difference) }}</th>
              <th>{{ percent(report.totals.change_percent) }}</th>
              <th>{{ trend(report.totals.trend) }}</th>
            </tr>
          </tfoot>
          </table>
        </div>

        <div v-if="showMonthly">
          <div class="section-title" :class="{ 'monthly-section-title': showAnnual }">
            {{ showAnnual ? '2.' : '1.' }} MONTH-WISE BRANCH COMPARISON
          </div>
          <table class="comparison-table monthly-table">
          <thead>
            <tr>
              <th>Month</th>
              <th class="text-left">Branch</th>
              <th>{{ report.baseline_year }} Sale</th>
              <th>{{ report.comparison_year }} Sale</th>
              <th>Difference</th>
              <th>% Change</th>
            </tr>
          </thead>
          <tbody>
            <template v-for="month in report.months">
              <tr v-for="(row, branchIndex) in month.rows" :key="`${month.month_number}-${row.warehouse_id}`">
                <td v-if="branchIndex === 0" :rowspan="month.rows.length" class="month-cell">{{ month.month }}</td>
                <td class="text-left">{{ row.branch }}</td>
                <td>{{ amount(row.baseline_total) }}</td>
                <td>{{ amount(row.comparison_total) }}</td>
                <td :class="differenceFillClass(row.difference)">{{ signedAmount(row.difference) }}</td>
                <td>{{ percent(row.change_percent) }}</td>
              </tr>
            </template>
            <tr v-if="!report.rows.length">
              <td colspan="6" class="text-center text-muted py-4">No accessible branches found.</td>
            </tr>
          </tbody>
          </table>
        </div>
      </div>

      <div class="report-note" v-if="report.comparison_through">
        Note: {{ report.comparison_year }} figures are available through {{ report.comparison_through }} only.
      </div>
    </b-card>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Branch Performance Comparison" },
  data() {
    const currentYear = new Date().getFullYear();
    return {
      loading: false,
      exporting: false,
      years: Array.from({ length: currentYear - 1999 }, (_, index) => currentYear - index),
      viewOptions: [
        { value: "annual", label: "Annual Summary" },
        { value: "monthly", label: "Month-wise Detail" },
        { value: "both", label: "Annual + Month-wise" }
      ],
      filters: {
        baseline_year: currentYear - 1,
        comparison_year: currentYear,
        view: "both"
      },
      report: {
        rows: [],
        months: [],
        totals: null,
        baseline_year: currentYear - 1,
        comparison_year: currentYear,
        currency: "PKR",
        comparison_through: null
      }
    };
  },
  computed: {
    showAnnual() {
      return this.filters.view === "annual" || this.filters.view === "both";
    },
    showMonthly() {
      return this.filters.view === "monthly" || this.filters.view === "both";
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
        view: this.filters.view
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
        .get("report/branch_year_comparison", { params: this.params() })
        .then(response => {
          this.report = response.data;
        })
        .catch(error => this.showError(error, "Unable to load branch comparison report."))
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
        .get("report/branch_year_comparison_excel", {
          params: this.params(),
          responseType: "blob"
        })
        .then(response => {
          const url = window.URL.createObjectURL(new Blob([response.data]));
          const link = document.createElement("a");
          link.href = url;
          link.setAttribute(
            "download",
            `branch-performance-${this.filters.view}-${this.filters.baseline_year}-vs-${this.filters.comparison_year}.xlsx`
          );
          document.body.appendChild(link);
          link.click();
          link.remove();
          window.URL.revokeObjectURL(url);
        })
        .catch(error => this.showError(error, "Unable to export branch comparison report."))
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
    signedAmount(value) {
      const number = Number(value || 0);
      const formatted = Math.abs(number).toLocaleString(undefined, {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
      });
      return number < 0 ? `(${formatted})` : formatted;
    },
    percent(value) {
      if (value === null || typeof value === "undefined") return "N/A";
      return `${Number(value).toLocaleString(undefined, { minimumFractionDigits: 1, maximumFractionDigits: 1 })}%`;
    },
    trend(value) {
      if (value === "Up") return "▲ Up";
      if (value === "Down") return "▼ Down";
      return "— Flat";
    },
    differenceClass(value) {
      return Number(value) < 0 ? "difference-negative" : "";
    },
    differenceFillClass(value) {
      if (Number(value) < 0) return "difference-negative difference-down";
      if (Number(value) > 0) return "difference-up";
      return "difference-flat";
    },
    changeClass(value) {
      if (value === null || typeof value === "undefined") return "change-neutral";
      return Number(value) < 0 ? "change-negative" : "change-positive";
    },
    trendClass(value) {
      if (value === "Up") return "trend-up";
      if (value === "Down") return "trend-down";
      return "trend-flat";
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
.report-title,
.section-title {
  background: #244574;
  color: #fff;
  font-weight: 700;
  letter-spacing: 0.2px;
  padding: 8px 12px;
}
.monthly-section-title {
  margin-top: 24px;
}
.report-title {
  font-size: 20px;
}
.section-title {
  font-size: 16px;
  margin-top: 18px;
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
  min-width: 820px;
  width: 100%;
}
.comparison-table th,
.comparison-table td {
  border: 1px solid #b8c4d4;
  padding: 7px 9px;
  text-align: right;
  white-space: nowrap;
}
.comparison-table thead th {
  background: #244574;
  color: #fff;
  text-align: center;
}
.comparison-table tbody tr:nth-child(odd) td:not(:last-child) {
  background: #eaf0fa;
}
.comparison-table tfoot th {
  background: #244574;
  color: #fff;
}
.difference-negative {
  color: #9f1239;
}
.difference-up {
  background: #e2f0d9;
}
.difference-down {
  background: #fbe1e1;
}
.difference-flat {
  background: #f3f4f6;
}
.month-cell {
  background: #fff !important;
  text-align: left !important;
  vertical-align: middle;
}
.change-positive,
.trend-up {
  background: #e2f0d9;
}
.change-negative,
.trend-down {
  background: #fbe1e1;
}
.change-neutral,
.trend-flat {
  background: #f3f4f6;
}
@media (max-width: 767px) {
  .report-title {
    font-size: 16px;
  }
}
</style>
