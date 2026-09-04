<template>
  <div class="main-content">
    <breadcumb :page="$t('Edit')" :folder="$t('Users')"/>
    
    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>
    
    <validation-observer ref="Create_User" v-if="!isLoading">
      <b-card>
        <b-form @submit.prevent="Submit_User" enctype="multipart/form-data">
          <b-row>
            <!-- First name -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Firstname"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Firstname') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Firstname-feedback"
                    label="Firstname"
                    v-model="user.firstname"
                    :placeholder="$t('Firstname')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Firstname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Last name -->
            <b-col md="6" sm="12">
              <validation-provider
                name="lastname"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('lastname') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="lastname-feedback"
                    label="lastname"
                    v-model="user.lastname"
                    :placeholder="$t('lastname')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="lastname-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Username -->
            <b-col md="6" sm="12">
              <validation-provider
                name="username"
                :rules="{ required: true , min:3 , max:30}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('username') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="username-feedback"
                    label="username"
                    v-model="user.username"
                    :placeholder="$t('username')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="username-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Phone -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Phone"
                :rules="{ required: true}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Phone') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Phone-feedback"
                    label="Phone"
                    v-model="user.phone"
                    :placeholder="$t('Phone')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Phone-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Email -->
            <b-col md="6" sm="12">
              <validation-provider
                name="Email"
                :rules="{ required: true}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Email') + ' ' + '*'">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Email-feedback"
                    label="Email"
                    v-model="user.email"
                    :placeholder="$t('Email')"
                  ></b-form-input>
                  <b-form-invalid-feedback id="Email-feedback">{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                  <b-alert
                    show
                    variant="danger"
                    class="error mt-1"
                    v-if="email_exist !=''"
                  >{{email_exist}}</b-alert>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- role -->
            <b-col md="6" sm="12" class="mb-3">
              <validation-provider name="role" :rules="{ required: true}">
                <b-form-group slot-scope="{ valid, errors }" :label="$t('RoleName') + ' ' + '*'">
                  <v-select
                    :class="{'is-invalid': !!errors.length}"
                    :state="errors[0] ? false : (valid ? true : null)"
                    v-model="user.role_id"
                    :reduce="label => label.value"
                    :placeholder="$t('PleaseSelect')"
                    :options="roles.map(roles => ({label: roles.name, value: roles.id}))"
                  />
                  <b-form-invalid-feedback>{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Avatar -->
            <b-col md="6" sm="12" class="mb-3">
              <validation-provider name="Avatar" ref="Avatar" rules="mimes:image/*|size:200">
                <b-form-group slot-scope="{validate, valid, errors }" :label="$t('UserImage')">
                  <input
                    :state="errors[0] ? false : (valid ? true : null)"
                    :class="{'is-invalid': !!errors.length}"
                    @change="onFileSelected"
                    label="Choose Avatar"
                    type="file"
                  >
                  <b-form-invalid-feedback id="Avatar-feedback">{{ errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- New Password -->
            <b-col md="6" class="mb-3">
              <validation-provider
                name="New password"
                :rules="{min:6}"
                v-slot="validationContext"
              >
                <b-form-group :label="$t('Newpassword')">
                  <b-form-input
                    :state="getValidationState(validationContext)"
                    aria-describedby="Nawpassword-feedback"
                    :placeholder="$t('LeaveBlank')"
                    label="New password"
                    type="password"
                    v-model="user.NewPassword"
                  ></b-form-input>
                  <b-form-invalid-feedback
                    id="Nawpassword-feedback"
                  >{{ validationContext.errors[0] }}</b-form-invalid-feedback>
                </b-form-group>
              </validation-provider>
            </b-col>

            <!-- Record View -->
            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2">
                  <h6 class="mb-0">{{$t('ShowAll')}}</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <div class="psx-form-check">
                    <input type="checkbox" v-model="user.record_view" class="psx-checkbox psx-form-check-input" id="record_view">
                    <label class="psx-form-check-label" for="record_view">
                      <span class="font-weight-normal">{{$t('ShowAll')}} <lucide-icon class="text-info font-weight-bold" name="help-circle" v-b-tooltip.hover.bottom title="Allow user to view all records, not just their own" /></span>
                    </label>
                  </div>
                  <small class="text-muted d-block mt-2">Allow user to view all records, not just their own</small>
                </b-card-body>
              </b-card>
            </b-col>

            <!-- Access Warehouses -->
            <b-col md="6" sm="12" class="mb-3">
              <b-card class="h-100">
                <b-card-header class="pb-2">
                  <h6 class="mb-0">{{$t('Assigned_warehouses')}}</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <div class="psx-form-check mb-3">
                    <input type="checkbox" v-model="user.is_all_warehouses" class="psx-checkbox psx-form-check-input" id="is_all_warehouses">
                    <label class="psx-form-check-label" for="is_all_warehouses">
                      <span class="font-weight-normal">{{$t('All_Warehouses')}} <lucide-icon class="text-info font-weight-bold" name="help-circle" v-b-tooltip.hover.bottom title="If 'All Warehouses' Selected , User Can access all data for the selected Warehouses" /></span>
                    </label>
                  </div>
                  
                  <b-form-group :label="$t('Some_warehouses')" class="mb-0" v-if="!user.is_all_warehouses">
                    <v-select
                      multiple
                      v-model="assigned_warehouses"
                      @input="Selected_Warehouse"
                      :reduce="label => label.value"
                      :placeholder="$t('PleaseSelect')"
                      :options="warehouses.map(warehouses => ({label: warehouses.name, value: warehouses.id}))"
                    />
                  </b-form-group>
                </b-card-body>
              </b-card>
            </b-col>

            <!-- Custom Permission Overrides -->
            <b-col md="12" sm="12" class="mb-3">
              <b-card>
                <b-card-header class="pb-2">
                  <h6 class="mb-0">Custom Permissions</h6>
                </b-card-header>
                <b-card-body class="pt-3">
                  <p class="text-muted mb-3">
                    Leave both unchecked to use the selected role. Deny removes access even when the role allows it.
                  </p>
                  <div class="permission-overrides">
                    <div
                      v-for="group in permissionGroups"
                      :key="group.name"
                      class="permission-group mb-3"
                    >
                      <div class="permission-group-title">{{ group.name }}</div>
                      <div class="permission-row permission-row-head">
                        <span>Permission</span>
                        <span class="text-center">Allow</span>
                        <span class="text-center">Deny</span>
                      </div>
                      <div
                        v-for="permission in group.permissions"
                        :key="permission.id"
                        class="permission-row"
                      >
                        <span class="permission-name">{{ permission.label }}</span>
                        <label class="checkbox checkbox-outline-primary justify-content-center mb-0">
                          <input
                            type="checkbox"
                            :checked="isPermissionAllowed(permission.id)"
                            @change="togglePermissionOverride(permission.id, 'allow')"
                          >
                          <span class="checkmark"></span>
                        </label>
                        <label class="checkbox checkbox-outline-danger justify-content-center mb-0">
                          <input
                            type="checkbox"
                            :checked="isPermissionDenied(permission.id)"
                            @change="togglePermissionOverride(permission.id, 'deny')"
                          >
                          <span class="checkmark"></span>
                        </label>
                      </div>
                    </div>
                  </div>
                </b-card-body>
              </b-card>
            </b-col>

            <b-col md="12" class="mt-3">
                <b-button variant="primary" type="submit" :disabled="SubmitProcessing"><lucide-icon class="me-2 font-weight-bold" name="check" /> {{$t('submit')}}</b-button>
                <b-button variant="secondary" class="ml-2" @click="$router.push({ name: 'Users' })">{{$t('Cancel')}}</b-button>
                  <div v-once class="typo__p" v-if="SubmitProcessing">
                    <div class="spinner sm spinner-primary mt-3"></div>
                  </div>
            </b-col>

          </b-row>
        </b-form>
      </b-card>
    </validation-observer>
  </div>
</template>

<script>
import NProgress from "nprogress";

export default {
  metaInfo: {
    title: "Edit User"
  },
  data() {
    return {
      isLoading: true,
      SubmitProcessing: false,
      email_exist: "",
      roles: [],
      warehouses: [],
      permissions: [],
      data: new FormData(),
      user: {
        id: "",
        firstname: "",
        lastname: "",
        username: "",
        NewPassword: null,
        email: "",
        phone: "",
        statut: "",
        role_id: "",
        avatar: "",
        is_all_warehouses: 1,
        record_view: false,
      },
      assigned_warehouses: [],
      allowed_permission_ids: [],
      denied_permission_ids: [],
    };
  },

  computed: {
    permissionGroups() {
      const groups = {};
      (this.permissions || []).forEach(permission => {
        const label = permission.label || this.formatPermissionName(permission.name);
        const groupName = this.formatPermissionGroup(permission.name);
        if (!groups[groupName]) groups[groupName] = [];
        groups[groupName].push({
          id: permission.id,
          label,
          name: permission.name,
        });
      });

      return Object.keys(groups)
        .sort()
        .map(name => ({
          name,
          permissions: groups[name].sort((a, b) => a.label.localeCompare(b.label)),
        }));
    },
  },

  methods: {
    //------------- Submit Validation Edit User
    Submit_User() {
      this.$refs.Create_User.validate().then(success => {
        if (!success) {
          this.makeToast(
            "danger",
            this.$t("Please_fill_the_form_correctly"),
            this.$t("Failed")
          );
        } else {
          this.Update_User();
        }
      });
    },

    //----------------------- Update User ---------------------------\\
    Update_User() {
      var self = this;
      self.SubmitProcessing = true;
      self.data = new FormData();
      self.data.append("firstname", self.user.firstname);
      self.data.append("lastname", self.user.lastname);
      self.data.append("username", self.user.username);
      self.data.append("email", self.user.email);
      self.data.append("NewPassword", self.user.NewPassword);
      self.data.append("phone", self.user.phone);
      self.data.append("role", self.user.role_id);
      self.data.append("statut", self.user.statut);
      self.data.append("is_all_warehouses", self.user.is_all_warehouses);
      self.data.append("record_view", self.user.record_view ? 1 : 0);
      self.data.append("avatar", self.user.avatar);
      self.data.append("allowed_permission_ids", JSON.stringify(self.allowed_permission_ids || []));
      self.data.append("denied_permission_ids", JSON.stringify(self.denied_permission_ids || []));

      // append array assigned_warehouses
      if (self.assigned_warehouses.length) {
        for (var i = 0; i < self.assigned_warehouses.length; i++) {
          self.data.append("assigned_to[" + i + "]", self.assigned_warehouses[i]);
        }
      } else {
        self.data.append("assigned_to", []);
      }
      self.data.append("_method", "put");

      axios
        .post("users/" + this.user.id, self.data)
        .then(response => {
          this.makeToast(
            "success",
            this.$t("Successfully_Updated"),
            this.$t("Success")
          );
          self.SubmitProcessing = false;
          // Redirect to users list after successful update
          this.$router.push({ name: 'Users' });
        })
        .catch(error => {
          if (error.response && error.response.data && error.response.data.errors && error.response.data.errors.email) {
            self.email_exist = error.response.data.errors.email[0];
          }
          this.makeToast("danger", this.$t("InvalidData"), this.$t("Failed"));
          self.SubmitProcessing = false;
        });
    },

    //----------------------------------- Get User Data ---------------------------\\
    Get_User() {
      NProgress.start();
      NProgress.set(0.1);
      let id = this.$route.params.id;
      axios
        .get("users/" + id + "/edit")
        .then(response => {
          this.user = response.data.user;
          this.roles = response.data.roles;
          this.warehouses = response.data.warehouses;
          this.assigned_warehouses = response.data.assigned_warehouses || [];
          this.permissions = response.data.permissions || [];
          this.allowed_permission_ids = (response.data.permission_overrides && response.data.permission_overrides.allow) ? response.data.permission_overrides.allow : [];
          this.denied_permission_ids = (response.data.permission_overrides && response.data.permission_overrides.deny) ? response.data.permission_overrides.deny : [];
          this.user.NewPassword = null;
          NProgress.done();
          this.isLoading = false;
        })
        .catch(error => {
          NProgress.done();
          this.makeToast("danger", this.$t("Failed_to_load_user"), this.$t("Failed"));
          setTimeout(() => {
            this.isLoading = false;
            this.$router.push({ name: 'Users' });
          }, 500);
        });
    },

    isPermissionAllowed(permissionId) {
      return (this.allowed_permission_ids || []).includes(permissionId);
    },

    isPermissionDenied(permissionId) {
      return (this.denied_permission_ids || []).includes(permissionId);
    },

    togglePermissionOverride(permissionId, type) {
      const source = type === 'allow' ? 'allowed_permission_ids' : 'denied_permission_ids';
      const opposite = type === 'allow' ? 'denied_permission_ids' : 'allowed_permission_ids';
      const selected = this[source].includes(permissionId);

      this[source] = selected
        ? this[source].filter(id => id !== permissionId)
        : this[source].concat(permissionId);

      if (!selected) {
        this[opposite] = this[opposite].filter(id => id !== permissionId);
      }
    },

    formatPermissionGroup(name) {
      if (String(name || '').startsWith('daily_reports_')) return 'Reports';
      const first = String(name || '').split('_')[0] || 'Other';
      return this.formatPermissionName(first);
    },

    formatPermissionName(name) {
      return String(name || '')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, c => c.toUpperCase());
    },

    Selected_Warehouse(value) {
      if (!value.length) {
        this.assigned_warehouses = [];
      }
    },

    //------------------------------ Event Upload Avatar -------------------------------\\
    async onFileSelected(e) {
      const { valid } = await this.$refs.Avatar.validate(e);

      if (valid) {
        this.user.avatar = e.target.files[0];
      } else {
        this.user.avatar = "";
      }
    },

    //------ Event Validation State
    getValidationState({ dirty, validated, valid = null }) {
      return dirty || validated ? valid : null;
    },

    //------ Toast
    makeToast(variant, msg, title) {
      this.$root.$bvToast.toast(msg, {
        title: title,
        variant: variant,
        solid: true
      });
    },
  },

  //----------------------------- Created function-------------------
  created: function() {
    this.Get_User();
  }
};
</script>

<style scoped>
.permission-overrides {
  max-height: 520px;
  overflow: auto;
  border: 1px solid #e9ecef;
  border-radius: 4px;
  background: #fff;
}

.permission-group {
  border-bottom: 1px solid #edf0f2;
}

.permission-group:last-child {
  border-bottom: 0;
  margin-bottom: 0 !important;
}

.permission-group-title {
  position: sticky;
  top: 0;
  z-index: 1;
  padding: 9px 12px;
  font-weight: 700;
  color: #2f365f;
  background: #f8f9fa;
  border-bottom: 1px solid #e9ecef;
}

.permission-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 86px 86px;
  align-items: center;
  gap: 8px;
  min-height: 38px;
  padding: 7px 12px;
  border-bottom: 1px solid #f1f3f5;
}

.permission-row:last-child {
  border-bottom: 0;
}

.permission-row-head {
  min-height: 32px;
  color: #6c757d;
  background: #fbfbfc;
  font-size: 12px;
  font-weight: 700;
  text-transform: uppercase;
}

.permission-name {
  min-width: 0;
  color: #212529;
  overflow-wrap: anywhere;
}

@media (max-width: 575.98px) {
  .permission-row {
    grid-template-columns: minmax(0, 1fr) 64px 64px;
    padding: 7px 8px;
  }
}
</style>
