<template>
  <div class='main-content targets-page'>
    <div class='targets-hero'><div><h2>Further Discount Ledger</h2><p>Company and category balances: accrued from sales, posted, and still receivable.</p></div><router-link class='btn btn-outline-light' to='/app/targets/dashboard'>Target Dashboard</router-link></div>
    <section class='target-card target-section mb-3'>
      <div class='row align-items-end'><div class='form-group col-md-4'><label>Company</label><v-select v-model='filters.supplier_id' :options='options.suppliers' label='name' :reduce='item=>item.id' :clearable='true'/></div><div class='form-group col-md-4'><label>Category</label><v-select v-model='filters.category_id' :options='options.categories' label='name' :reduce='item=>item.id' :clearable='true'/></div><div class='form-group col-md-2'><label>Year</label><input v-model='filters.year' type='number' class='form-control'></div><div class='form-group col-md-2'><button class='btn target-purple-btn btn-block' @click='load'>Apply</button></div></div>
    </section>
    <div class='target-kpis'><div class='target-card target-kpi'><div><div class='target-kpi-label'>Discount Accrued</div><div class='target-kpi-value'>{{ money(data.summary.earned) }}</div></div></div><div class='target-card target-kpi'><div><div class='target-kpi-label'>Company Posted</div><div class='target-kpi-value'>{{ money(data.summary.posted) }}</div></div></div><div class='target-card target-kpi'><div><div class='target-kpi-label'>Outstanding / (Over-posted)</div><div class='target-kpi-value'>{{ money(data.summary.balance) }}</div></div></div></div>
    <section class='target-card target-section'>
      <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Company</th><th>Category / Product</th><th>Period</th><th>Target</th><th>Achieved</th><th>Further Discounts</th><th>Accrued</th><th>Posted</th><th>Balance</th><th>Status</th></tr></thead><tbody>
        <tr v-for='row in data.rows' :key='ledgerKey(row)'><td>{{ row.supplier }}</td><td><router-link :to='targetLink(row)'>{{ row.category }}</router-link><br><small>{{ row.target_name }}</small></td><td>{{ row.start_date }} - {{ row.end_date }}</td><td>{{ qty(row.target_quantity) }}</td><td>{{ qty(row.achieved_quantity) }}</td><td>{{ discountSummary(row.further_discounts) }}</td><td>{{ money(row.earned) }}</td><td>{{ money(row.posted) }}</td><td :class='balanceClass(row)'>{{ money(row.balance) }}</td><td><span class='target-status' :class='eligibilityClass(row)'>{{ row.eligible?'Accruing':'No Sales Yet' }}</span></td></tr>
        <tr v-if='!loading&&!data.rows.length'><td colspan='10' class='text-center text-muted p-4'>No further-discount target lines found.</td></tr>
      </tbody></table></div>
    </section>
  </div>
</template>
<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{vSelect},
  data(){return{loading:false,options:{suppliers:[],categories:[]},filters:{supplier_id:null,category_id:null,year:new Date().getFullYear()},data:{summary:{earned:0,posted:0,balance:0},rows:[]}}},
  async created(){try{const response=await axios.get('targets/options');this.options=response.data;await this.load()}catch(error){this.notify(error)}},
  methods:{
    async load(){this.loading=true;try{const response=await axios.get('targets/discount-ledger',{params:this.filters});this.data=response.data}catch(error){this.notify(error)}finally{this.loading=false}},
    money(value){return Number(value||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})},
    qty(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    discountSummary(discounts){return(discounts||[]).length?discounts.map(item=>item.label+': '+item.value+(item.type==='percentage'?'%':' fixed')).join(' | '):'None'},
    ledgerKey(row){return row.target_id+'-'+row.line_id},
    targetLink(row){return '/app/targets/'+row.target_id},
    balanceClass(row){return row.balance>0?'text-danger':'text-success'},
    eligibilityClass(row){return row.eligible?'achieved':'on-track'},
    notify(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Unable to load the discount ledger.',{variant:'danger'})}
  }
};
</script>
