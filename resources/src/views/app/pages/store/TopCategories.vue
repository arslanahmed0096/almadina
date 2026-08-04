<template>
  <div class="main-content">
    <breadcumb page="Top Categories" :folder="$t('Store')" />

    <div v-if="isLoading" class="loading_page spinner spinner-primary mr-3"></div>

    <b-card v-else>
      <div class="d-flex justify-content-between align-items-start flex-wrap mb-4">
        <div>
          <h4 class="mb-1">Homepage Top Categories</h4>
          <p class="text-muted mb-0">
            Choose up to {{ maximum }} categories. Only these categories will appear in this homepage section.
          </p>
        </div>
        <b-button variant="primary" :disabled="isSaving || !selectedIds.length" @click="save">
          <span v-if="isSaving" class="spinner-border spinner-border-sm mr-1"></span>
          <lucide-icon v-else name="check" class="mr-1" />
          Save
        </b-button>
      </div>

      <b-form-group label="Select categories">
        <v-select
          v-model="selectedIds"
          :options="categories"
          :reduce="category => category.id"
          :selectable="category => selectedIds.includes(category.id) || selectedIds.length < maximum"
          label="name"
          multiple
          :close-on-select="false"
          placeholder="Choose homepage categories"
        >
          <template #option="category">
            <span>{{ category.name }}</span>
          </template>
        </v-select>
        <small class="text-muted">{{ selectedIds.length }}/{{ maximum }} selected</small>
      </b-form-group>

      <div class="selected-heading mt-4 mb-2">
        <strong>Display order</strong>
        <span class="text-muted small">Use the arrows to control the homepage order.</span>
      </div>

      <div v-if="selectedCategories.length" class="selected-list">
        <div v-for="(category, index) in selectedCategories" :key="category.id" class="selected-row">
          <span class="order-number">{{ index + 1 }}</span>
          <span class="category-name">{{ category.name }}</span>
          <div class="row-actions">
            <b-button
              size="sm"
              variant="outline-secondary"
              :disabled="index === 0"
              title="Move up"
              @click="move(index, -1)"
            >
              <lucide-icon name="arrow-up" />
            </b-button>
            <b-button
              size="sm"
              variant="outline-secondary"
              :disabled="index === selectedCategories.length - 1"
              title="Move down"
              @click="move(index, 1)"
            >
              <lucide-icon name="chevron-down" />
            </b-button>
            <b-button size="sm" variant="outline-danger" title="Remove" @click="remove(category.id)">
              <lucide-icon name="x" />
            </b-button>
          </div>
        </div>
      </div>

      <b-alert v-else show variant="light" class="border text-center text-muted">
        Select at least one category for the homepage.
      </b-alert>
    </b-card>
  </div>
</template>

<script>
export default {
  name: "StoreTopCategories",
  metaInfo: { title: "Top Categories" },
  data() {
    return {
      isLoading: true,
      isSaving: false,
      categories: [],
      selectedIds: [],
      maximum: 8
    };
  },
  computed: {
    selectedCategories() {
      return this.selectedIds
        .map(id => this.categories.find(category => Number(category.id) === Number(id)))
        .filter(Boolean);
    }
  },
  mounted() {
    this.fetch();
  },
  methods: {
    makeToast(variant, message, title) {
      this.$root.$bvToast.toast(message, {
        title,
        variant,
        solid: true
      });
    },
    async fetch() {
      this.isLoading = true;
      try {
        const response = await axios.get("/admin/store/top-categories");
        this.categories = Array.isArray(response.data.categories) ? response.data.categories : [];
        this.selectedIds = Array.isArray(response.data.selected_ids)
          ? response.data.selected_ids.map(Number)
          : [];
        this.maximum = Number(response.data.maximum || 8);
      } catch (error) {
        this.makeToast("danger", "Could not load top categories.", this.$t("Failed"));
      } finally {
        this.isLoading = false;
      }
    },
    move(index, direction) {
      const target = index + direction;
      if (target < 0 || target >= this.selectedIds.length) return;
      const reordered = this.selectedIds.slice();
      const item = reordered.splice(index, 1)[0];
      reordered.splice(target, 0, item);
      this.selectedIds = reordered;
    },
    remove(id) {
      this.selectedIds = this.selectedIds.filter(selectedId => Number(selectedId) !== Number(id));
    },
    validationMessage(error) {
      const errors = error && error.response && error.response.data && error.response.data.errors;
      if (errors && errors.top_category_ids && errors.top_category_ids.length) {
        return errors.top_category_ids[0];
      }
      return "Could not save top categories.";
    },
    async save() {
      if (!this.selectedIds.length) {
        this.makeToast("warning", "Select at least one category.", this.$t("Failed"));
        return;
      }
      this.isSaving = true;
      try {
        const response = await axios.put("/admin/store/top-categories", {
          top_category_ids: this.selectedIds
        });
        this.selectedIds = Array.isArray(response.data.selected_ids)
          ? response.data.selected_ids.map(Number)
          : this.selectedIds;
        this.makeToast("success", "Top categories saved successfully.", this.$t("Success"));
      } catch (error) {
        this.makeToast("danger", this.validationMessage(error), this.$t("Failed"));
      } finally {
        this.isSaving = false;
      }
    }
  }
};
</script>

<style scoped>
.selected-heading {
  display: flex;
  justify-content: space-between;
  gap: 1rem;
}

.selected-list {
  overflow: hidden;
  border: 1px solid #e4e7eb;
  border-radius: 8px;
}

.selected-row {
  display: flex;
  align-items: center;
  min-height: 58px;
  padding: 9px 12px;
  background: #fff;
  border-bottom: 1px solid #edf0f2;
}

.selected-row:last-child {
  border-bottom: 0;
}

.order-number {
  display: grid;
  flex: 0 0 32px;
  width: 32px;
  height: 32px;
  margin-right: 12px;
  place-items: center;
  color: #663399;
  background: #f2eafa;
  border-radius: 50%;
  font-weight: 600;
}

.category-name {
  flex: 1;
  font-weight: 600;
}

.row-actions {
  display: flex;
  gap: 6px;
}

.row-actions .btn {
  display: inline-grid;
  width: 34px;
  height: 34px;
  padding: 0;
  place-items: center;
}
</style>
