<template>
  <div class="main-content">
    <breadcumb :page="$t('Brand')" :folder="$t('Products')"/>

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-card class="wrapper" v-if="!isLoading">
      <vue-good-table
        mode="remote"
        :columns="columns"
        :totalRows="totalRows"
        :rows="brands"
        @on-page-change="onPageChange"
        @on-per-page-change="onPerPageChange"
        @on-sort-change="onSortChange"
        @on-search="onSearch"
        :search-options="{
        enabled: true,
        placeholder: $t('Search_this_table'),  
      }"
        :select-options="{ 
          enabled: true ,
          clearSelectionText: '',
        }"
        @on-selected-rows-change="selectionChanged"
        :pagination-options="{
        enabled: true,
        mode: 'records',
        nextLabel: 'next',
        prevLabel: 'prev',
      }"
        styleClass="table-hover tableOne vgt-table"
      >
        <div slot="selected-row-actions">
          <button class="btn btn-danger btn-sm" @click="delete_by_selected()">{{$t('Del')}}</button>
        </div>
        <div slot="table-actions" class="mt-2 mb-3">
          <b-button @click="New_Brand()" class="btn-rounded" variant="btn btn-primary btn-icon m-1">
            <lucide-icon name="plus" />
            {{$t('Add')}}
          </b-button>
          <b-button @click="Show_import_brands()" class="btn-rounded" variant="btn btn-outline-info m-1">
            <lucide-icon name="upload" />
            {{ $t('Import') || 'Import' }}
          </b-button>
          <b-form-select
            v-model="nameSort"
            :options="nameSortOptions"
            class="d-inline-block m-1"
            style="width: 180px;"
            @change="applyNameSort"
          ></b-form-select>
        </div>

        <template slot="table-row" slot-scope="props">
          <span v-if="props.column.field == 'actions'">
            <a @click="Edit_Brand(props.row)" title="Edit" v-b-tooltip.hover>
              <lucide-icon class="text-25 text-success" name="pencil" />
            </a>
            <a title="Delete" v-b-tooltip.hover @click="Delete_Brand(props.row.id)">
              <lucide-icon class="text-25 text-danger" name="x" />
            </a>
          </span>
          <span v-else-if="props.column.field == 'image'">
            <b-img
              thumbnail
              height="50"
              width="50"
              fluid
              :src="'/images/brands/' + props.row.image"
              alt="image"
            ></b-img>
          </span>
        </template>
      </vue-good-table>
    </b-card>

    <b-modal ok-only ok-title="Cancel" size="lg" id="importBrands" :title="$t('Import') + ' ' + $t('Brand')">
      <b-form @submit.prevent="Submit_import_brands" enctype="multipart/form-data">
        <b-row>
          <b-col md="7" sm="12" class="mb-3">
            <b-form-group :label="($t('Import') || 'Import') + ' ' + ($t('Brand') || 'Brand') + ' XLS/XLSX'">
              <input
                ref="brandsImportFile"
                type="file"
                accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                @change="onImportFileSelected"
                class="form-control"
              >
            </b-form-group>

            <div v-if="import_brands" class="alert alert-info py-2">
              <strong>{{ import_brands.name }}</strong>
              <div class="small mb-0">{{ formatBytes(import_brands.size) }}</div>
            </div>

            <b-alert v-if="importErrors.length" show variant="danger" class="mt-2">
              <div class="font-weight-bold mb-1">Import failed</div>
              <ul class="mb-0 pl-3">
                <li v-for="(err, idx) in importErrors" :key="'brand-import-err-' + idx">{{ err }}</li>
              </ul>
            </b-alert>

            <b-alert v-if="importWarnings.length" show variant="warning" class="mt-2">
              <div class="font-weight-bold mb-1">Warnings</div>
              <ul class="mb-0 pl-3">
                <li v-for="(warning, idx) in importWarnings" :key="'brand-import-warning-' + idx">{{ warning }}</li>
              </ul>
            </b-alert>
          </b-col>

          <b-col md="5" sm="12" class="mb-3">
            <div class="border rounded p-3 bg-light">
              <h6 class="mb-2">Expected columns</h6>
              <p class="small text-muted mb-2">
                Use one row per brand. Only <strong>name</strong> is required. Existing brand names will be updated.
              </p>
              <div class="mb-2">
                <span class="badge badge-success mr-1">name</span>
                <span class="badge badge-secondary">description</span>
              </div>
              <table class="table table-sm table-bordered mb-0">
                <thead class="thead-light">
                  <tr>
                    <th>name</th>
                    <th>description</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>Apple</td>
                    <td>Premium electronics brand</td>
                  </tr>
                  <tr>
                    <td>Samsung</td>
                    <td>Phones, displays, and appliances</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </b-col>

          <b-col md="12" sm="12" class="d-flex flex-wrap align-items-center">
            <b-button type="submit" variant="primary" :disabled="ImportProcessing || !import_brands" class="mr-2 mb-2">
              <span v-if="!ImportProcessing">
                <lucide-icon class="mr-1" name="upload" />
                {{ $t("submit") }}
              </span>
              <span v-else class="d-inline-flex align-items-center">
                <span class="spinner sm spinner-white mr-2"></span>Processing...
              </span>
            </b-button>

            <b-button variant="outline-secondary" class="mb-2" :disabled="ImportProcessing" @click="clearImportSelection()">
              {{ $t('Reset') || 'Reset' }}
            </b-button>
          </b-col>
        </b-row>
      </b-form>
    </b-modal>

    <validation-observer ref="Create_brand">
      <b-modal hide-footer size="md" id="New_brand" :title="editmode?$t('Edit'):$t('Add')">
        <b-form @submit.prevent="Submit_Brand" enctype="multipart/form-data">
          <b-row>
            <!-- Brand Name -->
            <b-col md="12">
              <validation-provider
                name="Brand Name"
                :rules="{ required: true , min:3 , max:20}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('BrandName') + ' ' + '*'">
                  <b-form-input
                    :placeholder="$t('Enter_Name_Brand')"
                    :state="getValidationState(validationContext)"
                    aria-describedby="Name-feedback"
                    label="Name"
                    v-model="brand.name"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Name-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Brand Description -->
            <b-col md="12">
              <validation-provider
                name="Brand Description"
                :rules="{ max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('BrandDescription')">
                  <b-form-textarea
                    rows="3"
                    :placeholder="$t('Enter_Description_Brand')"
                    :state="getValidationState(validationContext)"
                    aria-describedby="Description-feedback"
                    label="Description"
                    v-model="brand.description"
                  ></b-form-textarea>
                  <b-form-invalid-feedback
                    id="Description-feedback"
                  >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- -Brand Image -->
            <b-col md="12">
              <validation-provider name="Image" ref="Image" rules="mimes:image/*|size:200">
                <b-form-group slot-scope="{validate, valid, errors }" :label="$t('BrandImage')">
                  <input
                    :state="errors[0] ? false : (valid ? true : null)"
                    :class="{'is-invalid': !!errors.length}"
                    @change="onImageSelected"
                    label="Choose Image"
                    type="file"
                  >
                  <b-form-invalid-feedback id="Image-feedback">{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <b-col md="12" class="mt-3">
              <b-button variant="primary" type="submit"  :disabled="SubmitProcessing"><lucide-icon class="me-2 font-weight-bold" name="check" /> {{$t('submit')}}</b-button>
                <div v-once class="typo__p" v-if="SubmitProcessing">
                  <div class="spinner sm spinner-primary mt-3"></div>
                </div>
            </b-col>

          </b-row>
        </b-form>
      </b-modal>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Brand"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing:false,
      ImportProcessing:false,
      serverParams: {
        columnFilters: {},
        sort: {
          field: "id",
          type: "desc"
        },
        page: 1,
        perPage: 10
      },
      selectedIds: [],
      totalRows: "",
      search: "",
      data: new FormData(),
      editmode: false,
      brands: [],
      limit: "10",
      import_brands: null,
      importErrors: [],
      importWarnings: [],
      nameSort: "az",
      brand: {
        id: "",
        name: "",
        description: "",
        image: ""
      }
    };
  },
  computed: {
    nameSortOptions() {
      return [
        { value: "az", text: "Brand Name: A-Z" },
        { value: "za", text: "Brand Name: Z-A" }
      ];
    },
    columns() {
      return [
        {
          label: this.$t("BrandImage"),
          field: "image",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("BrandName"),
          field: "name",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("BrandDescription"),
          field: "description",
          tdClass: "text-left",
          thClass: "text-left"
        },
        {
          label: this.$t("Action"),
          field: "actions",
          tdClass: "text-left",
          thClass: "text-left",
          sortable: false
        }
      ];
    }
  },

  methods: {
    //---- update Params Table
    updateParams(newProps) {
      this.serverParams = Object.assign({}, this.serverParams, newProps);
    },

    //---- Event Page Change
    onPageChange({ currentPage }) {
      if (this.serverParams.page !== currentPage) {
        this.updateParams({ page: currentPage });
        this.Get_Brands(currentPage);
      }
    },

    //---- Event Per Page Change
    onPerPageChange({ currentPerPage }) {
      if (this.limit !== currentPerPage) {
        this.limit = currentPerPage;
        this.updateParams({ page: 1, perPage: currentPerPage });
        this.Get_Brands(1);
      }
    },

    //---- Event on Sort Change
    onSortChange(params) {
      this.updateParams({
        sort: {
          type: params[0].type,
          field: params[0].field
        }
      });

      if (params[0].field === "name") {
        this.nameSort = params[0].type === "asc" ? "az" : "za";
      }

      this.Get_Brands(this.serverParams.page);
    },

    applyNameSort() {
      this.updateParams({
        page: 1,
        sort: {
          field: "name",
          type: this.nameSort === "za" ? "desc" : "asc"
        }
      });
      this.Get_Brands(1);
    },

    //---- Event Select Rows
    selectionChanged({ selectedRows }) {
      this.selectedIds = [];
      selectedRows.forEach((row, index) => {
        this.selectedIds.push(row.id);
      });
    },

    //---- Event on Search

    onSearch(value) {
      this.search = value.searchTerm;
      this.Get_Brands(this.serverParams.page);
    },

    //---- Validation State Form

    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------------- Submit Validation Create & Edit Brand
    Submit_Brand() {
      this.$refs.Create_brand.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          if (!this.editmode) {
            this.Create_Brand();
          } else {
            this.Update_Brand();
          }
        }
      });
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },

    //------------------------------ Event Upload Image -------------------------------\\
    async onImageSelected(e) {
      const { valid } = await this.$refs.Image.validate(e);

      if (valid) {
        this.brand.image = e.target.files[0];
      } else {
        this.brand.image = "";
      }
    },

    onImportFileSelected(e) {
      this.importErrors = [];
      this.importWarnings = [];
      const file = e && e.target && e.target.files ? e.target.files[0] : null;

      if (!file) {
        this.import_brands = null;
        return;
      }

      const extension = (file.name || "").split(".").pop().toLowerCase();
      const errors = [];

      if (["xls", "xlsx"].indexOf(extension) === -1) {
        errors.push("Unsupported file type. Please upload an .xlsx or .xls file.");
      }

      if (file.size > 20 * 1024 * 1024) {
        errors.push("File is too large. Please upload a file under the 20MB limit.");
      }

      if (errors.length) {
        this.importErrors = errors;
        this.import_brands = null;
        if (this.$refs.brandsImportFile) {
          this.$refs.brandsImportFile.value = "";
        }
        return;
      }

      this.import_brands = file;
    },

    Show_import_brands() {
      this.resetImportState();
      this.$bvModal.show("importBrands");
    },

    clearImportSelection() {
      this.import_brands = null;
      this.importErrors = [];
      this.importWarnings = [];
      if (this.$refs.brandsImportFile) {
        this.$refs.brandsImportFile.value = "";
      }
    },

    resetImportState() {
      this.ImportProcessing = false;
      this.clearImportSelection();
    },

    formatBytes(bytes) {
      if (!bytes || bytes <= 0) return "0 B";
      const k = 1024;
      const sizes = ["B", "KB", "MB", "GB"];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return (bytes / Math.pow(k, i)).toFixed(2) + " " + sizes[i];
    },

    onlyErrorsArray(data) {
      if (!data || !data.errors) return [];
      const value = data.errors;
      const out = [];

      if (Array.isArray(value)) {
        value.forEach(entry => {
          if (entry) out.push(String(entry));
        });
      } else if (typeof value === "object") {
        Object.keys(value).forEach(key => {
          const item = value[key];
          if (Array.isArray(item)) {
            item.forEach(entry => {
              if (entry) out.push(String(entry));
            });
          } else if (item) {
            out.push(String(item));
          }
        });
      } else if (typeof value === "string") {
        out.push(value);
      }

      return [...new Set(out.map(entry => entry.trim()).filter(Boolean))];
    },

    Submit_import_brands() {
      if (!this.import_brands) {
        this.importErrors = ["Please choose a file to import."];
        return;
      }

      this.ImportProcessing = true;
      this.importErrors = [];
      this.importWarnings = [];
      NProgress.start();
      NProgress.set(0.1);

      const data = new FormData();
      data.append("brands", this.import_brands);

      axios
        .post("brands/import", data, {
          headers: {
            "Content-Type": "multipart/form-data",
            Accept: "application/json"
          },
          validateStatus: () => true
        })
        .then(response => {
          const payload = response.data || {};

          if (response.status === 422 || payload.status === false) {
            const errors = this.onlyErrorsArray(payload);
            this.importErrors = errors.length
              ? errors
              : [payload.message || "Please fix the import file and try again."];
            this.makeToast("danger", "Check the import errors and try again.", this.$t("Failed"));
            return;
          }

          this.importWarnings = Array.isArray(payload.warnings) ? payload.warnings : [];
          this.makeToast(
            "success",
            (payload.imported || 0) + " brands imported successfully.",
            this.$t("Success")
          );
          Fire.$emit("Event_Brand");
          this.resetImportState();
          this.$bvModal.hide("importBrands");
        })
        .catch(() => {
          this.importErrors = ["An error occurred while importing brands."];
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        })
        .finally(() => {
          this.ImportProcessing = false;
          NProgress.done();
        });
    },

    //------------------------------ Modal (create Brand) -------------------------------\\
    New_Brand() {
      this.reset_Form();
      this.editmode = false;
      this.$bvModal.show("New_brand");
    },

    //------------------------------ Modal (Update Brand) -------------------------------\\
    Edit_Brand(brand) {
      this.Get_Brands(this.serverParams.page);
      this.reset_Form();
      this.brand = brand;
      this.editmode = true;
      this.$bvModal.show("New_brand");
    },

    //---------------------------------------- Get All brands-----------------\\
    Get_Brands(page) {
      // Start the progress bar.
      NProgress.start();
      NProgress.set(0.1);
      axios
        .get(
          "brands?page=" +
            page +
            "&SortField=" +
            this.serverParams.sort.field +
            "&SortType=" +
            this.serverParams.sort.type +
            "&search=" +
            this.search +
            "&limit=" +
            this.limit
        )
        .then(response => {
          this.brands = response.data.brands;
          this.totalRows = response.data.totalRows;

          // Complete the animation of theprogress bar.
          NProgress.done();
          this.isLoading = false;
        })
        .catch(response => {
          // Complete the animation of theprogress bar.
          NProgress.done();
          setTimeout(() => {
            this.isLoading = false;
          }, 500);
        });
    },

    //---------------------------------------- Create new brand-----------------\\
    Create_Brand() {
      var self = this;
      self.SubmitProcessing = true;
      self.data.append("name", self.brand.name);
      self.data.append("description", self.brand.description);
      self.data.append("image", self.brand.image);
      axios
        .post("brands", self.data)
        .then(response => {
          self.SubmitProcessing = false;
          Fire.$emit("Event_Brand");

          this.makeToast(
            "success",
            this.$t("Successfully_Created"),
            this.$t("Success")
          );
        })
        .catch(error => {
           self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //---------------------------------------- Update Brand-----------------\\
    Update_Brand() {
      var self = this;
       self.SubmitProcessing = true;
      self.data.append("name", self.brand.name);
      self.data.append("description", self.brand.description);
      self.data.append("image", self.brand.image);
      self.data.append("_method", "put");

      axios
        .post("brands/" + self.brand.id, self.data)
        .then(response => {
           self.SubmitProcessing = false;
          Fire.$emit("Event_Brand");

          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
        })
        .catch(error => {
           self.SubmitProcessing = false;
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
        });
    },

    //---------------------------------------- Reset Form -----------------\\
    reset_Form() {
      this.brand = {
        id: "",
        name: "",
        description: "",
        image: ""
      };
      this.data = new FormData();
    },

    //---------------------------------------- Delete Brand -----------------\\
    Delete_Brand(id) {
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
          axios
            .delete("brands/" + id)
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Brand");
            })
            .catch(() => {
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    },

    //---- Delete brands by selection

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
          // Start the progress bar.
          NProgress.start();
          NProgress.set(0.1);
          axios
            .post("brands/delete/by_selection", {
              selectedIds: this.selectedIds
            })
            .then(() => {
              this.$swal(
                this.$t("Delete_Deleted"),
                this.$t("Deleted_in_successfully"),
                "success"
              );

              Fire.$emit("Delete_Brand");
            })
            .catch(() => {
              // Complete the animation of theprogress bar.
              setTimeout(() => NProgress.done(), 500);
              this.$swal(
                this.$t("Delete_Failed"),
                this.$t("Delete_Therewassomethingwronge"),
                "warning"
              );
            });
        }
      });
    }
  }, //end Methods
  created: function() {
    this.Get_Brands(1);

    Fire.$on("Event_Brand", () => {
      setTimeout(() => {
        this.Get_Brands(this.serverParams.page);
        this.$bvModal.hide("New_brand");
      }, 500);
    });

    Fire.$on("Delete_Brand", () => {
      setTimeout(() => {
        this.Get_Brands(this.serverParams.page);
      }, 500);
    });
  }
};
</script>
