<template>
  <div class="main-content purchase-discounts-page">
    <breadcumb page="Purchase Discounts" :folder="$t('Purchases')" />

    <b-card class="mb-3 discount-filter-card">
      <b-row class="align-items-end">
        <b-col lg="5" md="12">
          <b-form-group label="Search discounts">
            <b-form-input
              v-model.trim="filters.search"
              placeholder="Search by discount name"
              debounce="350"
              @input="applyFilters"
              @keyup.enter="applyFilters"
            />
          </b-form-group>
        </b-col>
        <b-col lg="3" md="6">
          <b-form-group label="Discount category">
            <b-form-select v-model="filters.category" :options="categoryFilterOptions" @change="applyFilters" />
          </b-form-group>
        </b-col>
        <b-col lg="2" md="6">
          <b-form-group label="Status">
            <b-form-select v-model="filters.status" :options="statusFilterOptions" @change="applyFilters" />
          </b-form-group>
        </b-col>
        <b-col lg="2" class="d-flex justify-content-lg-end pb-3">
          <b-button variant="outline-secondary" class="mr-2" @click="resetFilters">
            <lucide-icon name="rotate-ccw" /> Reset
          </b-button>
          <b-button v-if="can('purchase_discounts.create')" variant="primary" @click="openCreate">
            <lucide-icon name="plus" /> Add
          </b-button>
        </b-col>
      </b-row>
    </b-card>

    <b-card>
      <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
      <vue-good-table
        v-else
        mode="remote"
        :columns="columns"
        :rows="discounts"
        :totalRows="totalRows"
        :pagination-options="{ enabled: true, mode: 'records', nextLabel: 'next', prevLabel: 'prev' }"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        styleClass="table-hover tableOne vgt-table"
      >
        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field === 'category_label'">
            <b-badge :variant="props.row.category === 'on_invoice' ? 'primary' : 'info'">
              {{ props.row.category_label }}
            </b-badge>
          </span>
          <span v-else-if="props.column.field === 'status_label'">
            <b-badge :variant="props.row.status === 'active' ? 'success' : 'secondary'">
              {{ props.row.status_label }}
            </b-badge>
          </span>
          <span v-else-if="props.column.field === 'description'" class="discount-description">
            {{ props.row.description || '—' }}
          </span>
          <span v-else-if="props.column.field === 'actions'" class="discount-actions">
            <b-button size="sm" variant="outline-info" class="mr-1" title="View" v-b-tooltip.hover @click="viewDiscount(props.row)">
              <lucide-icon name="eye" />
            </b-button>
            <b-button v-if="can('purchase_discounts.update')" size="sm" variant="outline-success" class="mr-1" title="Edit" v-b-tooltip.hover @click="openEdit(props.row)">
              <lucide-icon name="pencil" />
            </b-button>
            <b-button v-if="can('purchase_discounts.delete')" size="sm" variant="outline-danger" title="Delete" v-b-tooltip.hover @click="remove(props.row)">
              <lucide-icon name="trash-2" />
            </b-button>
          </span>
          <span v-else>{{ props.formattedRow[props.column.field] }}</span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal id="purchase-discount-form" hide-footer size="lg" :title="editing ? 'Edit Purchase Discount' : 'Create Purchase Discount'">
      <b-alert v-if="formError" show variant="danger">{{ formError }}</b-alert>
      <b-form @submit.prevent="save">
        <b-row>
          <b-col md="7">
            <b-form-group label="Discount Name *">
              <b-form-input v-model.trim="form.name" required maxlength="120" placeholder="Enter discount name" />
            </b-form-group>
          </b-col>
          <b-col md="5">
            <b-form-group label="Discount Category *">
              <b-form-select v-model="form.category" :options="categoryOptions" required />
            </b-form-group>
          </b-col>
          <b-col md="12">
            <b-form-group label="Description">
              <b-form-textarea v-model="form.description" rows="4" maxlength="2000" placeholder="Optional description" />
            </b-form-group>
          </b-col>
          <b-col md="6">
            <b-form-group label="Status *">
              <b-form-radio-group v-model="form.status" :options="statusOptions" buttons button-variant="outline-primary" required />
            </b-form-group>
          </b-col>
        </b-row>
        <div class="d-flex justify-content-end mt-3">
          <b-button variant="outline-secondary" class="mr-2" type="button" @click="$bvModal.hide('purchase-discount-form')">Cancel</b-button>
          <b-button variant="primary" type="submit" :disabled="saving">
            <span v-if="saving" class="spinner sm spinner-white mr-2"></span>
            <lucide-icon v-else name="check" />
            {{ saving ? 'Saving...' : 'Save Discount' }}
          </b-button>
        </div>
      </b-form>
    </b-modal>

    <b-modal id="purchase-discount-view" hide-footer size="md" title="Purchase Discount Details">
      <div v-if="selected" class="discount-view">
        <div class="discount-view__heading">
          <div>
            <small>DISCOUNT NAME</small>
            <h4>{{ selected.name }}</h4>
          </div>
          <b-badge :variant="selected.status === 'active' ? 'success' : 'secondary'">{{ selected.status_label }}</b-badge>
        </div>
        <b-table-simple bordered small responsive>
          <b-tbody>
            <b-tr><b-th>ID</b-th><b-td>{{ selected.id }}</b-td></b-tr>
            <b-tr><b-th>Category</b-th><b-td>{{ selected.category_label }}</b-td></b-tr>
            <b-tr><b-th>Description</b-th><b-td>{{ selected.description || 'No description' }}</b-td></b-tr>
            <b-tr><b-th>Created At</b-th><b-td>{{ selected.created_at }}</b-td></b-tr>
            <b-tr><b-th>Transaction Usage</b-th><b-td>{{ selected.is_used ? 'Used by purchase transactions' : 'Not used' }}</b-td></b-tr>
          </b-tbody>
        </b-table-simple>
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from "nprogress";
import { mapGetters } from "vuex";

export default {
  metaInfo: { title: "Purchase Discounts" },
  data() {
    return {
      loading: false,
      saving: false,
      editing: false,
      formError: "",
      selected: null,
      discounts: [],
      totalRows: 0,
      categories: [
        { value: "on_invoice", text: "On Invoice Discount" },
        { value: "further_discount", text: "Further Discount" }
      ],
      filters: { search: "", category: "", status: "" },
      serverParams: { page: 1, perPage: 10, sort: { field: "id", type: "desc" } },
      form: {}
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    columns() {
      return [
        { label: "ID", field: "id", type: "number", width: "70px" },
        { label: "Discount Name", field: "name" },
        { label: "Discount Category", field: "category_label", sortFn: null },
        { label: "Description", field: "description", sortable: false },
        { label: "Status", field: "status_label" },
        { label: "Created At", field: "created_at" },
        { label: "Actions", field: "actions", sortable: false, tdClass: "text-nowrap" }
      ];
    },
    categoryOptions() {
      return [{ value: "", text: "Select category", disabled: true }].concat(this.categories);
    },
    categoryFilterOptions() {
      return [{ value: "", text: "All categories" }].concat(this.categories);
    },
    statusOptions() {
      return [{ value: "active", text: "Active" }, { value: "inactive", text: "Inactive" }];
    },
    statusFilterOptions() {
      return [{ value: "", text: "All statuses" }].concat(this.statusOptions);
    }
  },
  created() {
    this.loadDiscounts();
  },
  methods: {
    can(permission) {
      return (this.currentUserPermissions || []).includes(permission);
    },
    blankForm() {
      return { id: null, name: "", category: "", description: "", status: "active" };
    },
    errorMessage(error, fallback) {
      const response = error && error.response && error.response.data;
      if (response && response.errors) return Object.values(response.errors).flat().join(" ");
      return (response && response.message) || fallback;
    },
    toast(message, variant = "success") {
      this.$root.$bvToast.toast(message, { title: "Purchase Discounts", variant, solid: true });
    },
    async loadDiscounts() {
      this.loading = true;
      NProgress.start();
      try {
        const { data } = await axios.get("purchase-discounts", {
          params: {
            ...this.filters,
            page: this.serverParams.page,
            limit: this.serverParams.perPage,
            SortField: this.serverParams.sort.field,
            SortType: this.serverParams.sort.type
          }
        });
        this.discounts = data.discounts || [];
        this.totalRows = Number(data.totalRows) || 0;
        if (Array.isArray(data.categories) && data.categories.length) this.categories = data.categories;
      } catch (error) {
        this.toast(this.errorMessage(error, "Unable to load purchase discounts."), "danger");
      } finally {
        this.loading = false;
        NProgress.done();
      }
    },
    applyFilters() {
      this.serverParams.page = 1;
      this.loadDiscounts();
    },
    resetFilters() {
      this.filters = { search: "", category: "", status: "" };
      this.applyFilters();
    },
    onPageChange(params) {
      this.serverParams.page = params.currentPage;
      this.loadDiscounts();
    },
    onPerPageChange(params) {
      this.serverParams.perPage = params.currentPerPage;
      this.serverParams.page = 1;
      this.loadDiscounts();
    },
    onSortChange(params) {
      if (!params || !params.length) return;
      const fieldMap = { category_label: "category", status_label: "status" };
      this.serverParams.sort = {
        field: fieldMap[params[0].field] || params[0].field,
        type: params[0].type
      };
      this.loadDiscounts();
    },
    openCreate() {
      this.editing = false;
      this.formError = "";
      this.form = this.blankForm();
      this.$bvModal.show("purchase-discount-form");
    },
    openEdit(discount) {
      this.editing = true;
      this.formError = "";
      this.form = {
        id: discount.id,
        name: discount.name,
        category: discount.category,
        description: discount.description || "",
        status: discount.status
      };
      this.$bvModal.show("purchase-discount-form");
    },
    viewDiscount(discount) {
      this.selected = discount;
      this.$bvModal.show("purchase-discount-view");
    },
    async save() {
      this.saving = true;
      this.formError = "";
      try {
        const method = this.editing ? "put" : "post";
        const url = this.editing ? `purchase-discounts/${this.form.id}` : "purchase-discounts";
        const { data } = await axios[method](url, this.form);
        this.$bvModal.hide("purchase-discount-form");
        this.toast(data.message || "Purchase discount saved successfully.");
        await this.loadDiscounts();
      } catch (error) {
        this.formError = this.errorMessage(error, "Unable to save purchase discount.");
      } finally {
        this.saving = false;
      }
    },
    async remove(discount) {
      if (discount.is_used) {
        this.toast("This discount is already used by a purchase transaction. Set it to inactive instead.", "warning");
        return;
      }
      const answer = await this.$swal({
        title: "Delete this purchase discount?",
        text: "This action is only allowed for discounts that have not been used.",
        type: "warning",
        icon: "warning",
        showCancelButton: true,
        confirmButtonText: "Delete"
      });
      if (!answer.value && !answer.isConfirmed) return;
      try {
        const { data } = await axios.delete(`purchase-discounts/${discount.id}`);
        this.toast(data.message || "Purchase discount deleted successfully.");
        await this.loadDiscounts();
      } catch (error) {
        this.toast(this.errorMessage(error, "Unable to delete purchase discount."), "danger");
      }
    }
  }
};
</script>

<style scoped>
.discount-filter-card .form-group { margin-bottom: 0; }
.discount-description { display: inline-block; max-width: 320px; white-space: normal; }
.discount-actions { display: inline-flex; align-items: center; }
.discount-view__heading { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 1rem; }
.discount-view__heading small { color: #64748b; font-weight: 700; letter-spacing: .06em; }
.discount-view__heading h4 { margin: .25rem 0 0; }
@media (max-width: 991.98px) { .discount-filter-card .form-group { margin-bottom: 1rem; } }
</style>
