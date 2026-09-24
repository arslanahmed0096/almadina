<template>
  <div class="main-content stock-valuation-report">
    <breadcumb page="Stock Valuation Report" :folder="$t('Reports')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <template v-else>
      <b-card class="mb-3 filter-card">
        <b-row>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Branch / Warehouse">
              <v-select
                v-model="warehouse_id"
                :reduce="option => option.value"
                :options="warehouseOptions"
                :clearable="false"
                @input="applyFilters"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Category">
              <v-select
                v-model="category_id"
                :reduce="option => option.value"
                :options="categoryOptions"
                :clearable="false"
                @input="applyFilters"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Brand">
              <v-select
                v-model="brand_id"
                :reduce="option => option.value"
                :options="brandOptions"
                :clearable="false"
                @input="applyFilters"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Stock Status">
              <v-select
                v-model="stock_status"
                :reduce="option => option.value"
                :options="stockStatusOptions"
                :clearable="false"
                @input="applyFilters"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Valuation Price">
              <v-select
                v-model="price_basis"
                :reduce="option => option.value"
                :options="priceBasisOptions"
                :clearable="false"
              />
            </b-form-group>
          </b-col>
          <b-col lg="2" md="4" sm="6">
            <b-form-group label="Sales Period">
              <date-range-picker
                v-model="dateRange"
                :startDate="startDate"
                :endDate="endDate"
                :locale-data="locale"
                opens="left"
                @update="Submit_filter_dateRange"
              >
                <template v-slot:input="picker">
                  {{ fmt(picker.startDate) }} - {{ fmt(picker.endDate) }}
                </template>
              </date-range-picker>
            </b-form-group>
          </b-col>
        </b-row>
        <small class="text-muted">
          Stock values are current. The date range applies to sold, transferred, adjusted, and customer history figures.
        </small>
      </b-card>

      <b-row class="summary-row">
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100">
            <span>Total Products</span>
            <strong>{{ summary.product_count || 0 }}</strong>
          </b-card>
        </b-col>
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100">
            <span>Branch Stock Lines</span>
            <strong>{{ summary.line_count || 0 }}</strong>
          </b-card>
        </b-col>
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100">
            <span>On-hand Quantity</span>
            <strong>{{ formatNumber(summary.current_quantity, 3) }}</strong>
          </b-card>
        </b-col>
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100 summary-primary">
            <span>Total Stock Amount (Cost)</span>
            <strong>{{ formatPrice(summary.cost_value) }}</strong>
          </b-card>
        </b-col>
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100">
            <span>{{ priceBasisLabel }} Stock Worth</span>
            <strong>{{ formatPrice(selectedSummaryWorth) }}</strong>
          </b-card>
        </b-col>
        <b-col xl="2" md="4" sm="6" class="mb-3">
          <b-card class="summary-card h-100">
            <span>Sold Qty / Amount</span>
            <strong>{{ formatNumber(summary.sold_quantity, 3) }}</strong>
            <small>{{ formatPrice(summary.sold_amount) }}</small>
          </b-card>
        </b-col>
      </b-row>

      <b-card class="mb-3">
        <h6 class="mb-3">Stock Worth by Every Price Category</h6>
        <div class="price-value-grid">
          <button
            v-for="type in price_types"
            :key="type.code"
            type="button"
            class="price-value-item"
            :class="{ active: price_basis === type.code }"
            @click="price_basis = type.code"
          >
            <span>{{ type.name }}</span>
            <strong>{{ formatPrice(summaryPriceValue(type.code)) }}</strong>
          </button>
        </div>
      </b-card>

      <b-card class="wrapper print-table-only">
        <vue-good-table
          mode="remote"
          :columns="columns"
          :totalRows="totalRows"
          :rows="reports"
          :isLoading.sync="tableLoading"
          @on-page-change="onPageChange"
          @on-per-page-change="onPerPageChange"
          @on-sort-change="onSortChange"
          @on-search="onSearch"
          :search-options="{ placeholder: 'Search model no, product or SKU', enabled: true }"
          :pagination-options="{
            enabled: true,
            mode: 'records',
            perPage: Number(limit),
            perPageDropdown: [10, 25, 50, 100],
            nextLabel: 'Next',
            prevLabel: 'Previous',
            rowsPerPageLabel: 'Rows per page'
          }"
          styleClass="tableOne table-hover vgt-table mt-3 valuation-table"
        >
          <div slot="table-actions" class="mb-2">
            <b-button @click="printTableOnly" size="sm" variant="outline-secondary ripple m-1">
              <lucide-icon name="printer" /> {{ $t('print') }}
            </b-button>
            <b-button @click="stock_report_PDF" size="sm" variant="outline-success ripple m-1">
              <lucide-icon name="file-text" /> PDF
            </b-button>
          </div>

          <template slot="table-row" slot-scope="props">
            <span v-if="props.column.field === 'current_quantity'">
              {{ formatQuantity(props.row.current_quantity, props.row.unit) }}
            </span>
            <span v-else-if="props.column.field === 'cost'">
              {{ formatPrice(props.row.cost) }}
            </span>
            <span v-else-if="props.column.field === 'selected_unit_price'">
              {{ formatPrice(selectedUnitPrice(props.row)) }}
            </span>
            <span v-else-if="props.column.field === 'cost_stock_value'">
              {{ formatPrice(props.row.cost_stock_value) }}
            </span>
            <span v-else-if="props.column.field === 'selected_stock_value'">
              {{ formatPrice(selectedStockValue(props.row)) }}
            </span>
            <span v-else-if="props.column.field === 'total_units_sold'">
              {{ formatQuantity(props.row.total_units_sold, props.row.unit) }}
            </span>
            <span v-else-if="props.column.field === 'sold_amount'">
              {{ formatPrice(props.row.sold_amount) }}
            </span>
            <span v-else-if="props.column.field === 'total_units_transferred'">
              {{ formatQuantity(props.row.total_units_transferred, props.row.unit) }}
            </span>
            <span v-else-if="props.column.field === 'total_units_adjusted'">
              {{ formatQuantity(props.row.total_units_adjusted, props.row.unit) }}
            </span>
            <span v-else-if="props.column.field === 'last_sale_date'">
              {{ props.row.last_sale_date || '—' }}
            </span>
            <span v-else-if="props.column.field === 'actions'">
              <b-button
                size="sm"
                variant="outline-primary"
                :disabled="!props.row.sale_count"
                @click="showSalesHistory(props.row)"
              >
                Sales ({{ props.row.sale_count || 0 }})
              </b-button>
            </span>
            <span v-else>{{ props.formattedRow[props.column.field] }}</span>
          </template>
        </vue-good-table>
      </b-card>
    </template>

    <b-modal id="valuation-sales-history" size="xl" title="Product Sales Detail" hide-footer>
      <div v-if="selectedRow" class="mb-3">
        <strong>{{ selectedRow.model_no }} — {{ selectedRow.product_name }}</strong>
        <div class="text-muted">
          {{ selectedRow.variant }} · {{ selectedRow.warehouse }} · {{ startDate }} to {{ endDate }}
        </div>
      </div>
      <div v-if="historyLoading" class="text-center p-5">
        <div class="spinner spinner-primary"></div>
      </div>
      <template v-else>
        <b-row class="mb-3">
          <b-col md="4"><strong>Invoices:</strong> {{ salesHistorySummary.sale_count || 0 }}</b-col>
          <b-col md="4"><strong>Quantity:</strong> {{ formatNumber(salesHistorySummary.quantity, 3) }}</b-col>
          <b-col md="4"><strong>Amount:</strong> {{ formatPrice(salesHistorySummary.amount) }}</b-col>
        </b-row>
        <b-alert v-if="historyTruncated" show variant="warning">
          Showing the latest 250 sale lines for this selection.
        </b-alert>
        <b-table
          small
          striped
          hover
          responsive
          show-empty
          :items="salesHistory"
          :fields="historyFields"
          empty-text="No completed sales in this period"
        >
          <template #cell(quantity)="data">
            {{ formatQuantity(data.item.quantity, data.item.unit) }}
          </template>
          <template #cell(unit_price)="data">{{ formatPrice(data.item.unit_price) }}</template>
          <template #cell(amount)="data">{{ formatPrice(data.item.amount) }}</template>
          <template #cell(price_type)="data">{{ data.item.price_type || 'retail' }}</template>
        </b-table>
      </template>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import jsPDF from "jspdf";
import autoTable from "jspdf-autotable";
import DateRangePicker from "vue2-daterange-picker";
import "vue2-daterange-picker/dist/vue2-daterange-picker.css";
import moment from "moment";
import { mapGetters } from "vuex";
import {
  formatPriceDisplay as formatPriceDisplayHelper,
  getPriceFormatSetting
} from "../../../../utils/priceFormat";

export default {
  metaInfo: { title: "Stock Valuation Report" },
  components: { DateRangePicker },

  data() {
    const end = moment().endOf("day");
    const start = moment().subtract(29, "days").startOf("day");
    return {
      isLoading: true,
      tableLoading: false,
      reports: [],
      totalRows: 0,
      summary: {
        product_count: 0,
        line_count: 0,
        current_quantity: 0,
        cost_value: 0,
        sold_quantity: 0,
        sold_amount: 0,
        price_values: {}
      },
      warehouses: [],
      categories: [],
      brands: [],
      price_types: [],
      warehouse_id: 0,
      category_id: 0,
      brand_id: 0,
      stock_status: "all",
      price_basis: "cost",
      search: "",
      limit: 10,
      serverParams: { sort: { field: "product_name", type: "asc" }, page: 1 },
      startDate: start.format("YYYY-MM-DD"),
      endDate: end.format("YYYY-MM-DD"),
      dateRange: { startDate: start.toDate(), endDate: end.toDate() },
      locale: {
        applyLabel: "Apply",
        cancelLabel: "Cancel",
        weekLabel: "W",
        customRangeLabel: "Custom Range",
        daysOfWeek: moment.weekdaysMin(),
        monthNames: moment.monthsShort(),
        firstDay: 1
      },
      price_format_key: null,
      selectedRow: null,
      historyLoading: false,
      historyTruncated: false,
      salesHistory: [],
      salesHistorySummary: { sale_count: 0, quantity: 0, amount: 0 },
      historyFields: [
        { key: "date", label: "Sale Date" },
        { key: "reference", label: "Invoice" },
        { key: "customer", label: "Purchased By" },
        { key: "quantity", label: "Quantity", class: "text-right" },
        { key: "unit_price", label: "Unit Price", class: "text-right" },
        { key: "price_type", label: "Price Type" },
        { key: "amount", label: "Amount", class: "text-right" }
      ],
      stockStatusOptions: [
        { label: "All Stock", value: "all" },
        { label: "In Stock", value: "in_stock" },
        { label: "Zero Stock", value: "zero" },
        { label: "Negative Stock", value: "negative" }
      ]
    };
  },

  computed: {
    ...mapGetters(["currentUser"]),
    warehouseOptions() {
      return [{ label: "All Branches", value: 0 }].concat(
        this.warehouses.map(item => ({ label: item.name, value: item.id }))
      );
    },
    categoryOptions() {
      return [{ label: "All Categories", value: 0 }].concat(
        this.categories.map(item => ({ label: item.name, value: item.id }))
      );
    },
    brandOptions() {
      return [{ label: "All Brands", value: 0 }].concat(
        this.brands.map(item => ({ label: item.name, value: item.id }))
      );
    },
    priceBasisOptions() {
      return this.price_types.map(item => ({ label: item.name, value: item.code }));
    },
    priceBasisLabel() {
      const type = this.price_types.find(item => item.code === this.price_basis);
      return type ? type.name : "Cost Price";
    },
    selectedSummaryWorth() {
      return Number((this.summary.price_values || {})[this.price_basis] || 0);
    },
    columns() {
      return [
        { label: "Model No", field: "model_no", tdClass: "text-left", thClass: "text-left" },
        { label: "Product", field: "product_name", tdClass: "text-left", thClass: "text-left" },
        { label: "Variant", field: "variant", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Category", field: "category", tdClass: "text-left", thClass: "text-left" },
        { label: "Brand", field: "brand", tdClass: "text-left", thClass: "text-left" },
        { label: "Branch", field: "warehouse", tdClass: "text-left", thClass: "text-left" },
        { label: "Remaining Qty", field: "current_quantity", tdClass: "text-right", thClass: "text-right" },
        { label: "Cost / Unit", field: "cost", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: `${this.priceBasisLabel} / Unit`, field: "selected_unit_price", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Total Stock Amount (Cost)", field: "cost_stock_value", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: `${this.priceBasisLabel} Stock Worth`, field: "selected_stock_value", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Qty Sold", field: "total_units_sold", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Sales Amount", field: "sold_amount", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Transferred Out", field: "total_units_transferred", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Net Adjustment", field: "total_units_adjusted", tdClass: "text-right", thClass: "text-right", sortable: false },
        { label: "Last Sold", field: "last_sale_date", tdClass: "text-left", thClass: "text-left", sortable: false },
        { label: "Customer / Dates", field: "actions", tdClass: "text-center", thClass: "text-center", sortable: false }
      ];
    }
  },

  methods: {
    formatPriceDisplay(number, decimals = 2) {
      const key = this.price_format_key || getPriceFormatSetting({ store: this.$store });
      if (key) this.price_format_key = key;
      return formatPriceDisplayHelper(Number(number || 0), decimals, key || null);
    },
    formatPrice(number) {
      const currency = (this.currentUser && this.currentUser.currency) || "";
      const value = this.formatPriceDisplay(number, 2);
      return currency ? `${currency} ${value}` : value;
    },
    formatNumber(number, decimals = 2) {
      return Number(number || 0).toLocaleString(undefined, {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals
      });
    },
    formatQuantity(number, unit) {
      return `${this.formatNumber(number, 3)} ${unit || "Pcs"}`;
    },
    fmt(date) {
      return moment(date).format("YYYY-MM-DD");
    },
    selectedUnitPrice(row) {
      return Number(row[this.price_basis] || 0);
    },
    selectedStockValue(row) {
      return Number(row[`${this.price_basis}_stock_value`] || 0);
    },
    summaryPriceValue(code) {
      return Number((this.summary.price_values || {})[code] || 0);
    },
    requestParams(page = this.serverParams.page, limit = this.limit) {
      return {
        page,
        limit,
        SortField: this.serverParams.sort.field,
        SortType: this.serverParams.sort.type,
        warehouse_id: this.warehouse_id || 0,
        category_id: this.category_id || 0,
        brand_id: this.brand_id || 0,
        stock_status: this.stock_status,
        search: this.search || "",
        date_from: this.startDate,
        date_to: this.endDate
      };
    },
    async Get_Stock_Inventory_Valuation(page = 1) {
      this.tableLoading = true;
      NProgress.start();
      try {
        const response = await axios.get("report/stock_inventory_valuation", {
          params: this.requestParams(page)
        });
        this.reports = response.data.reports || [];
        this.totalRows = Number(response.data.totalRows || 0);
        this.summary = response.data.summary || this.summary;
        this.warehouses = response.data.warehouses || [];
        this.categories = response.data.categories || [];
        this.brands = response.data.brands || [];
        this.price_types = response.data.price_types || [];
        if (!this.price_types.some(type => type.code === this.price_basis)) {
          this.price_basis = this.price_types.length ? this.price_types[0].code : "cost";
        }
        this.serverParams.page = page;
      } catch (error) {
        const message = error.response && error.response.data && error.response.data.message
          ? error.response.data.message
          : "Unable to load the stock valuation report.";
        this.$bvToast.toast(message, { title: "Stock Valuation Report", variant: "danger", solid: true });
      } finally {
        this.tableLoading = false;
        this.isLoading = false;
        NProgress.done();
      }
    },
    applyFilters() {
      this.serverParams.page = 1;
      this.Get_Stock_Inventory_Valuation(1);
    },
    Submit_filter_dateRange() {
      this.startDate = moment(this.dateRange.startDate).format("YYYY-MM-DD");
      this.endDate = moment(this.dateRange.endDate).format("YYYY-MM-DD");
      this.applyFilters();
    },
    onPageChange({ currentPage }) {
      this.Get_Stock_Inventory_Valuation(currentPage);
    },
    onPerPageChange({ currentPerPage }) {
      this.limit = Number(currentPerPage);
      this.applyFilters();
    },
    onSortChange(params) {
      if (!params || !params.length) return;
      this.serverParams.sort = { field: params[0].field, type: params[0].type };
      this.applyFilters();
    },
    onSearch({ searchTerm }) {
      this.search = searchTerm || "";
      this.applyFilters();
    },
    async showSalesHistory(row) {
      this.selectedRow = row;
      this.salesHistory = [];
      this.salesHistorySummary = { sale_count: 0, quantity: 0, amount: 0 };
      this.historyTruncated = false;
      this.historyLoading = true;
      this.$bvModal.show("valuation-sales-history");
      try {
        const response = await axios.get("report/stock_inventory_valuation/sales", {
          params: {
            product_id: row.product_id,
            product_variant_id: row.product_variant_id || "",
            warehouse_id: row.warehouse_id,
            date_from: this.startDate,
            date_to: this.endDate
          }
        });
        this.salesHistory = response.data.sales || [];
        this.salesHistorySummary = response.data.summary || this.salesHistorySummary;
        this.historyTruncated = !!response.data.is_truncated;
      } catch (error) {
        this.$bvToast.toast("Unable to load sale details.", {
          title: "Stock Valuation Report",
          variant: "danger",
          solid: true
        });
      } finally {
        this.historyLoading = false;
      }
    },
    async fetchAllRows() {
      const response = await axios.get("report/stock_inventory_valuation", {
        params: this.requestParams(1, -1)
      });
      return response.data.reports || [];
    },
    exportRows(rows) {
      return rows.map(row => ({
        model_no: row.model_no,
        product: row.product_name,
        variant: row.variant,
        category: row.category,
        brand: row.brand,
        branch: row.warehouse,
        remaining_qty: `${this.formatNumber(row.current_quantity, 3)} ${row.unit}`,
        cost_price: this.formatPriceDisplay(row.cost, 2),
        valuation_price: this.formatPriceDisplay(this.selectedUnitPrice(row), 2),
        cost_value: this.formatPriceDisplay(row.cost_stock_value, 2),
        stock_worth: this.formatPriceDisplay(this.selectedStockValue(row), 2),
        sold_qty: `${this.formatNumber(row.total_units_sold, 3)} ${row.unit}`,
        sales_amount: this.formatPriceDisplay(row.sold_amount, 2),
        transferred: `${this.formatNumber(row.total_units_transferred, 3)} ${row.unit}`,
        adjusted: `${this.formatNumber(row.total_units_adjusted, 3)} ${row.unit}`,
        last_sold: row.last_sale_date || "—"
      }));
    },
    async stock_report_PDF() {
      NProgress.start();
      try {
        const rows = this.exportRows(await this.fetchAllRows());
        const pdf = new jsPDF("l", "pt", "a4");
        pdf.setFontSize(16);
        pdf.text("Stock Valuation Report", 32, 32);
        pdf.setFontSize(9);
        pdf.text(`Sales period: ${this.startDate} to ${this.endDate} | Valuation: ${this.priceBasisLabel}`, 32, 49);
        autoTable(pdf, {
          startY: 62,
          body: rows,
          columns: [
            { header: "Model No", dataKey: "model_no" },
            { header: "Product", dataKey: "product" },
            { header: "Variant", dataKey: "variant" },
            { header: "Category", dataKey: "category" },
            { header: "Brand", dataKey: "brand" },
            { header: "Branch", dataKey: "branch" },
            { header: "Remaining Qty", dataKey: "remaining_qty" },
            { header: "Cost / Unit", dataKey: "cost_price" },
            { header: `${this.priceBasisLabel} / Unit`, dataKey: "valuation_price" },
            { header: "Cost Value", dataKey: "cost_value" },
            { header: "Stock Worth", dataKey: "stock_worth" },
            { header: "Qty Sold", dataKey: "sold_qty" },
            { header: "Sales Amount", dataKey: "sales_amount" },
            { header: "Transferred Out", dataKey: "transferred" },
            { header: "Net Adjustment", dataKey: "adjusted" },
            { header: "Last Sold", dataKey: "last_sold" }
          ],
          styles: { fontSize: 6, cellPadding: 2 },
          headStyles: { fillColor: [102, 51, 153] }
        });
        pdf.save("Stock_Valuation_Report.pdf");
      } catch (error) {
        this.$bvToast.toast("Unable to create the PDF report.", { title: "Stock Valuation Report", variant: "danger", solid: true });
      } finally {
        NProgress.done();
      }
    },
    escapeHtml(value) {
      return String(value == null ? "" : value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    },
    async printTableOnly() {
      NProgress.start();
      try {
        const rows = this.exportRows(await this.fetchAllRows());
        const headers = ["Model No", "Product", "Variant", "Category", "Brand", "Branch", "Remaining Qty", "Cost / Unit", `${this.priceBasisLabel} / Unit`, "Cost Value", "Stock Worth", "Qty Sold", "Sales Amount", "Transferred Out", "Net Adjustment", "Last Sold"];
        const keys = ["model_no", "product", "variant", "category", "brand", "branch", "remaining_qty", "cost_price", "valuation_price", "cost_value", "stock_worth", "sold_qty", "sales_amount", "transferred", "adjusted", "last_sold"];
        const head = headers.map(value => `<th>${this.escapeHtml(value)}</th>`).join("");
        const body = rows.map(row => `<tr>${keys.map(key => `<td>${this.escapeHtml(row[key])}</td>`).join("")}</tr>`).join("");
        const popup = window.open("", "_blank");
        if (!popup) return;
        popup.document.write(`<!doctype html><html><head><title>Stock Valuation Report</title><style>@page{size:A4 landscape;margin:8mm}body{font-family:Arial,sans-serif;font-size:9px}h2{margin:0 0 4px}p{margin:0 0 12px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #bbb;padding:4px;text-align:left}th{background:#eee}</style></head><body><h2>Stock Valuation Report</h2><p>Sales period: ${this.escapeHtml(this.startDate)} to ${this.escapeHtml(this.endDate)} | Valuation: ${this.escapeHtml(this.priceBasisLabel)}</p><table><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table></body></html>`);
        popup.document.close();
        popup.focus();
        setTimeout(() => { popup.print(); popup.close(); }, 300);
      } catch (error) {
        this.$bvToast.toast("Unable to prepare the printable report.", { title: "Stock Valuation Report", variant: "danger", solid: true });
      } finally {
        NProgress.done();
      }
    }
  },

  created() {
    this.Get_Stock_Inventory_Valuation(1);
  }
};
</script>

<style scoped>
.filter-card .form-group { margin-bottom: 0.75rem; }
.summary-card { border: 0; box-shadow: 0 2px 10px rgba(0, 0, 0, 0.06); }
.summary-card span { display: block; color: #6b7280; font-size: 12px; margin-bottom: 8px; }
.summary-card strong { display: block; color: #222; font-size: 19px; line-height: 1.2; }
.summary-card small { color: #6b7280; display: block; margin-top: 5px; }
.summary-primary { border-left: 4px solid #663399; }
.price-value-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
.price-value-item { background: #f8f9fa; border: 1px solid #e1e5ea; border-radius: 8px; cursor: pointer; padding: 12px; text-align: left; }
.price-value-item span { color: #6b7280; display: block; font-size: 12px; }
.price-value-item strong { display: block; font-size: 15px; margin-top: 5px; }
.price-value-item.active { background: #f4eff9; border-color: #663399; box-shadow: 0 0 0 1px #663399; }
.valuation-table { white-space: nowrap; }
@media (max-width: 767px) {
  .summary-card strong { font-size: 16px; }
}
</style>
