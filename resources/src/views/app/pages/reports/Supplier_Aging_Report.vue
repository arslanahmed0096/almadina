<template>
  <div class='main-content supplier-aging-page'>
    <breadcumb page='Supplier Aging Report' :folder="$t('Reports')" />
    <b-card class='filter-card no-print mb-3'>
      <b-row class='align-items-end'>
        <b-col lg='2' md='4' class='mb-2'>
          <label>Report month</label>
          <b-form-input v-model='filters.month' type='month' @change='onMonthChange' />
        </b-col>
        <b-col lg='2' md='4' class='mb-2'>
          <label>Report date</label>
          <b-form-input v-model='filters.as_of' type='date' />
        </b-col>
        <b-col lg='2' md='4' class='mb-2'>
          <label>Overdue threshold</label>
          <b-input-group append='days'>
            <b-form-input v-model.number='filters.overdue_days' type='number' min='1' max='3650' />
          </b-input-group>
        </b-col>
        <b-col lg='2' md='4' class='mb-2'>
          <label>Supplier</label>
          <v-select v-model='filters.supplier_id' :options='suppliers' label='name' :reduce='item => item.id' placeholder='All suppliers' />
        </b-col>
        <b-col lg='2' md='4' class='mb-2'>
          <label>Branch</label>
          <v-select v-model='filters.warehouse_id' :options='branches' label='name' :reduce='item => item.id' placeholder='All branches' />
        </b-col>
        <b-col lg='2' md='4' class='mb-2 text-md-right'>
          <div class='action-grid'>
            <b-button size='sm' variant='primary' :disabled='loading || exporting' @click='loadReport'>
              <lucide-icon name='refresh-cw' class='mr-1' /> Load
            </b-button>
            <b-button size='sm' variant='outline-secondary' :disabled='loading || exporting || !report' @click='printReport'>
              <lucide-icon name='printer' class='mr-1' /> Print
            </b-button>
            <b-button size='sm' variant='danger' :disabled='loading || exporting || !report' @click='exportPdf'>
              <lucide-icon name='file-text' class='mr-1' /> PDF
            </b-button>
            <b-button size='sm' variant='success' :disabled='loading || exporting || !report' @click='exportExcel'>
              <lucide-icon name='table' class='mr-1' /> Excel
            </b-button>
          </div>
        </b-col>
      </b-row>
    </b-card>

    <div v-if='loading && !report' class='loading_page spinner spinner-primary mr-3'></div>
    <b-alert v-else-if='error' show variant='danger'>{{ error }}</b-alert>
    <div v-else-if='report' class='aging-print-area'>
      <h2 class='report-title'>
        Al-Madina Electronics Payment Plan for {{ report.month_name }}
        <span>(Dated: {{ displayDate(report.as_of) }})</span>
      </h2>
      <div class='report-meta'>Amounts in {{ report.currency }}</div>

      <div class='table-responsive'>
        <table class='aging-table summary-table'>
          <thead>
            <tr>
              <th>Supplier Name</th>
              <th class='amount-cell'>Total Balance</th>
              <th class='amount-cell'>Due Payments</th>
              <th class='amount-cell collection-heading'>{{ collectionHeading }}</th>
              <th class='amount-cell overdue-heading'>Pending Overdue Payment ({{ report.overdue_days }} &amp; Above Days)</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for='row in report.rows' :key='row.supplier_id'>
              <td class='supplier-name'>{{ row.supplier_name }}</td>
              <td class='amount-cell'>{{ money(row.total_balance) }}</td>
              <td class='amount-cell'>{{ money(row.due_payments) }}</td>
              <td class='amount-cell collection-value'>{{ money(row.month_collection) }}</td>
              <td class='amount-cell' :class='{ overdueValue: row.overdue_amount > 0 }'>{{ money(row.overdue_amount) }}</td>
            </tr>
            <tr v-if='!report.rows.length'>
              <td colspan='5' class='empty-cell'>No supplier balances or collections found for these filters.</td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <th>Total</th>
              <th class='amount-cell'>{{ money(report.totals.total_balance) }}</th>
              <th class='amount-cell'>{{ money(report.totals.due_payments) }}</th>
              <th class='amount-cell collection-value'>{{ money(report.totals.month_collection) }}</th>
              <th class='amount-cell overdue-value'>{{ money(report.totals.overdue_amount) }}</th>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class='detail-heading'>Outstanding Invoice Aging Detail</div>
      <div class='table-responsive'>
        <table class='aging-table detail-table'>
          <thead>
            <tr>
              <th>Supplier Name</th>
              <th>Invoice</th>
              <th class='text-center'>Days</th>
              <th class='amount-cell'>Amount</th>
              <th>Due Payment Status</th>
            </tr>
          </thead>
          <tbody v-for='supplier in detailRows' :key='supplier.supplier_id' class='supplier-detail-group'>
            <tr v-for='(item, index) in supplier.details' :key='item.purchase_id' :class='{ overdueRow: item.overdue_threshold_reached }'>
                <td v-if='index === 0' :rowspan='supplier.details.length' class='detail-supplier'>
                  {{ supplier.supplier_name }}
                  <small>{{ supplier.details.length }} open invoice{{ supplier.details.length === 1 ? '' : 's' }}</small>
                </td>
                <td>
                  <strong>{{ item.purchase_reference }}</strong>
                  <small>{{ displayDate(item.purchase_date) }}</small>
                </td>
                <td class='text-center age-cell'>
                  <strong>{{ item.invoice_age_days }} Days</strong>
                  <small v-if='item.days_overdue'>{{ item.days_overdue }} overdue</small>
                  <small v-else-if='item.days_until_due'>{{ item.days_until_due }} until due</small>
                  <small v-else>due today</small>
                </td>
                <td class='amount-cell detail-amount'>{{ money(item.outstanding) }}</td>
                <td class='status-cell'>
                  <span :class='{ overdueStatus: item.days_overdue > 0 }'>{{ item.status }}</span>
                  <small>Terms: {{ item.payment_terms_days }} days</small>
                </td>
            </tr>
          </tbody>
          <tbody v-if='!detailRows.length'>
            <tr><td colspan='5' class='empty-cell'>No outstanding supplier invoices found.</td></tr>
          </tbody>
          <tfoot v-if='detailRows.length'>
            <tr>
              <th colspan='3' class='text-right'>{{ report.month_name }} Total Outstanding Payment</th>
              <th class='amount-cell overdue-value'>{{ money(report.totals.total_balance) }}</th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>
</template>

<script>
import moment from 'moment';
import NProgress from 'nprogress';

export default {
  metaInfo: { title: 'Supplier Aging Report' },
  data() {
    return {
      loading: false,
      exporting: false,
      optionsLoaded: false,
      error: '',
      report: null,
      suppliers: [],
      branches: [],
      filters: {
        month: moment().format('YYYY-MM'),
        as_of: moment().format('YYYY-MM-DD'),
        overdue_days: 40,
        supplier_id: null,
        warehouse_id: null
      }
    };
  },
  computed: {
    collectionHeading() {
      const label = this.report ? moment(this.report.month, 'YYYY-MM').format('MMMM') : 'Month';
      return label + ' Collection';
    },
    detailRows() {
      if (!this.report || !Array.isArray(this.report.rows)) return [];
      return this.report.rows.filter(row => Array.isArray(row.details) && row.details.length);
    }
  },
  created() {
    this.loadReport();
  },
  methods: {
    onMonthChange() {
      const selected = moment(this.filters.month, 'YYYY-MM');
      this.filters.as_of = selected.isSame(moment(), 'month')
        ? moment().format('YYYY-MM-DD')
        : selected.endOf('month').format('YYYY-MM-DD');
    },
    filterParams(includeOptions) {
      const params = {
        month: this.filters.month,
        as_of: this.filters.as_of,
        overdue_days: this.filters.overdue_days,
        include_options: includeOptions ? 1 : 0
      };
      if (this.filters.supplier_id) params.supplier_id = this.filters.supplier_id;
      if (this.filters.warehouse_id) params.warehouse_id = this.filters.warehouse_id;
      return params;
    },
    async loadReport() {
      this.loading = true;
      this.error = '';
      NProgress.start();
      try {
        const params = this.filterParams(!this.optionsLoaded);
        const response = await axios.get('report/supplier_aging', { params });
        this.report = response.data;
        if (!this.optionsLoaded) {
          this.suppliers = response.data.suppliers || [];
          this.branches = response.data.branches || [];
          this.optionsLoaded = true;
        }
      } catch (error) {
        const data = error.response && error.response.data;
        this.error = (data && (data.message || data.error)) || 'Unable to load the supplier aging report.';
      } finally {
        this.loading = false;
        NProgress.done();
      }
    },
    money(value) {
      return Number(value || 0).toLocaleString(undefined, {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
      });
    },
    displayDate(value) {
      return value ? moment(value).format('DD-MMM-YYYY') : '-';
    },
    printReport() {
      window.print();
    },
    exportPdf() {
      return this.downloadExport('report/supplier_aging/pdf', 'pdf');
    },
    exportExcel() {
      return this.downloadExport('report/supplier_aging/excel', 'xlsx');
    },
    async downloadExport(endpoint, extension) {
      this.exporting = true;
      this.error = '';
      NProgress.start();
      try {
        const response = await axios.get(endpoint, {
          params: this.filterParams(false),
          responseType: 'blob'
        });
        const blob = new Blob([response.data], { type: response.headers['content-type'] });
        const link = document.createElement('a');
        link.href = window.URL.createObjectURL(blob);
        link.download = 'supplier-aging-' + this.filters.month + '-' + this.filters.as_of + '.' + extension;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.setTimeout(() => window.URL.revokeObjectURL(link.href), 1000);
      } catch (error) {
        this.error = 'Unable to export the supplier aging report.';
      } finally {
        this.exporting = false;
        NProgress.done();
      }
    }
  }
};
</script>

<style scoped>
.filter-card label {
  color: #5f6877;
  font-size: 12px;
  font-weight: 700;
  margin-bottom: 5px;
  text-transform: uppercase;
}

.action-grid {
  display: grid;
  gap: 5px;
  grid-template-columns: repeat(2, minmax(72px, 1fr));
}

.action-grid .btn {
  align-items: center;
  display: inline-flex;
  justify-content: center;
  white-space: nowrap;
}

.aging-print-area {
  background: #fff;
  color: #111;
  padding: 18px;
}

.report-title {
  color: #82bd4b;
  font-size: 25px;
  font-weight: 800;
  margin: 0;
  text-align: center;
}

.report-title span {
  white-space: nowrap;
}

.report-meta {
  color: #6c757d;
  font-size: 12px;
  margin: 4px 0 12px;
  text-align: right;
}

.aging-table {
  border-collapse: collapse;
  min-width: 850px;
  width: 100%;
}

.aging-table th,
.aging-table td {
  border: 1.5px solid #111;
  padding: 8px 10px;
  vertical-align: middle;
}

.aging-table th {
  background: #f6f7f8;
  font-size: 15px;
  font-weight: 800;
}

.summary-table .supplier-name,
.summary-table tfoot th:first-child {
  font-weight: 800;
}

.amount-cell {
  font-variant-numeric: tabular-nums;
  text-align: right;
  white-space: nowrap;
}

.collection-heading,
.collection-value {
  color: #82bd4b;
}

.overdue-heading,
.overdue-value,
.overdueValue,
.overdueRow .detail-amount,
.overdueStatus {
  color: #d21e1e;
  font-weight: 800;
}

.detail-heading {
  background: #f1f3f5;
  border: 1.5px solid #111;
  border-bottom: 0;
  font-size: 18px;
  font-weight: 800;
  margin-top: 20px;
  padding: 9px 10px;
}

.supplier-detail-group::after {
  content: '';
  display: table-row;
  height: 12px;
}

.detail-supplier {
  font-size: 17px;
  font-weight: 800;
  width: 24%;
}

.detail-table small,
.detail-supplier small,
.status-cell small,
.age-cell small {
  color: #6c757d;
  display: block;
  font-size: 11px;
  font-weight: 500;
  margin-top: 2px;
}

.age-cell strong,
.detail-amount {
  font-size: 16px;
}

.status-cell {
  font-size: 15px;
  font-weight: 700;
  text-align: center;
}

.empty-cell {
  color: #6c757d;
  padding: 24px !important;
  text-align: center;
}

@media (max-width: 767px) {
  .aging-print-area { padding: 10px; }
  .report-title { font-size: 19px; }
}

@media print {
  .no-print { display: none !important; }
  .supplier-aging-page { padding: 0 !important; }
  .aging-print-area { padding: 0; }
  .report-title { font-size: 20px; }
  .aging-table { min-width: 0; }
  .aging-table th,
  .aging-table td { padding: 5px 7px; }
}
</style>
