<template>
  <div class="main-content">
    <breadcumb page="Credit Limit Policy" folder="Policies" />
    <div v-if="loading" class="loading_page spinner spinner-primary mr-3"></div>
    <b-row v-else>
      <b-col lg="3" class="mb-3">
        <b-card no-body class="policy-nav-card">
          <div class="policy-nav-title">Policies</div>
          <router-link to="/app/settings/policies/credit-limit" class="policy-nav-link active">
            <lucide-icon name="shield" />
            <span>Credit Limit Policy</span>
          </router-link>
        </b-card>
      </b-col>
      <b-col lg="9">
        <b-card>
          <div class="d-flex align-items-start justify-content-between flex-wrap mb-4">
            <div>
              <h3 class="mb-2">Credit Limit Policy</h3>
              <p class="text-muted mb-0">
                Determines how many days a customer may keep a credit invoice unpaid. An overdue
                outstanding invoice blocks further credit purchases and applicable shipments.
              </p>
            </div>
            <b-badge :variant="policy.is_active ? 'success' : 'secondary'" class="mt-2 px-3 py-2">
              {{ policy.is_active ? 'Active' : 'Inactive' }}
            </b-badge>
          </div>

          <b-form @submit.prevent="save">
            <b-form-group label="Allowed Credit Days" description="This value is saved as a snapshot on each new credit invoice.">
              <b-form-select
                v-model.number="policy.allowed_credit_days"
                :options="dayOptions"
                :disabled="!canUpdate || saving"
                required
              />
            </b-form-group>
            <b-form-group label="Policy Status">
              <b-form-checkbox v-model="policy.is_active" switch :disabled="!canUpdate || saving">
                Allow eligible credit transactions
              </b-form-checkbox>
              <small class="text-muted">When inactive, new credit transactions are blocked; cash sales remain available.</small>
            </b-form-group>
            <div class="text-right">
              <b-button v-if="canUpdate" type="submit" variant="primary" :disabled="saving">
                <span v-if="saving" class="spinner-border spinner-border-sm mr-1"></span>
                Save Policy
              </b-button>
              <small v-else class="text-muted">You have read-only access to this policy.</small>
            </div>
          </b-form>
        </b-card>
      </b-col>
    </b-row>
  </div>
</template>

<script>
import { mapGetters } from "vuex";
import NProgress from "nprogress";

export default {
  metaInfo: { title: "Credit Limit Policy" },
  data() {
    return {
      loading: true,
      saving: false,
      policy: { allowed_credit_days: 30, is_active: true },
      dayOptions: [5, 10, 15, 20, 25, 30].map(value => ({ value, text: `${value} days` }))
    };
  },
  computed: {
    ...mapGetters(["currentUserPermissions"]),
    canUpdate() {
      const permissions = this.currentUserPermissions || [];
      return permissions.includes("policies.update") || permissions.includes("setting_system");
    }
  },
  created() {
    this.load();
  },
  methods: {
    load() {
      NProgress.start();
      axios.get("policies/credit-limit")
        .then(response => { this.policy = response.data.policy; })
        .catch(error => {
          this.$root.$bvToast.toast((error.response && error.response.data.message) || "Unable to load policy.", { title: "Error", variant: "danger", solid: true });
        })
        .finally(() => { this.loading = false; NProgress.done(); });
    },
    save() {
      this.saving = true;
      axios.put("policies/credit-limit", {
        allowed_credit_days: this.policy.allowed_credit_days,
        is_active: !!this.policy.is_active
      }).then(response => {
        this.policy = response.data.policy;
        this.$root.$bvToast.toast(response.data.message, { title: "Success", variant: "success", solid: true });
      }).catch(error => {
        const errors = error.response && error.response.data && error.response.data.errors;
        const message = errors ? Object.values(errors)[0][0] : "Unable to save policy.";
        this.$root.$bvToast.toast(message, { title: "Error", variant: "danger", solid: true });
      }).finally(() => { this.saving = false; });
    }
  }
};
</script>

<style scoped>
.policy-nav-card { overflow: hidden; }
.policy-nav-title { padding: 1rem 1.25rem; font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid #edf0f4; }
.policy-nav-link { display: flex; align-items: center; gap: .65rem; padding: .9rem 1.25rem; color: #4b5563; }
.policy-nav-link.active { color: #663399; background: rgba(102, 51, 153, .08); border-left: 3px solid #663399; }
@media (max-width: 991.98px) { .policy-nav-card { margin-bottom: .5rem; } }
</style>
