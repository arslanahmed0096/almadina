<template>
  <div class='main-content targets-page'>
    <div class='targets-hero'><div><h2>Target Reports</h2><p>Supplier, annual, monthly, warehouse and product target performance.</p></div><router-link class='btn btn-outline-light' to='/app/targets/dashboard'>Dashboard</router-link></div>
    <div class='target-card target-filters no-print'><div class='row'>
      <div class='col-md-3'><label>Supplier</label><v-select v-model='filters.supplier_id' :options='options.suppliers' label='name' :reduce='reduceId' placeholder='All suppliers'/></div>
      <div class='col-md-2'><label>Period</label><select v-model='filters.period_type' class='form-control'><option value=''>All</option><option value='annual'>Annual</option><option value='monthly'>Monthly</option></select></div>
      <div class='col-md-2'><label>Year</label><input v-model.number='filters.year' type='number' class='form-control'></div>
      <div class='col-md-2'><label>Warehouse</label><v-select v-model='filters.warehouse_id' :options='options.warehouses' label='name' :reduce='reduceId' placeholder='All'/></div>
      <div class='col-md-3 d-flex align-items-end'><button class='btn target-purple-btn mr-2' @click='load'>Preview</button><button class='btn btn-outline-secondary mr-2' @click='printReport'>Print</button><button v-if='canExport' class='btn btn-outline-primary' @click='downloadPdf'>PDF</button></div>
      <div class='col-md-2 mt-2'><label>Month</label><select v-model='filters.month' class='form-control'><option value=''>All</option><option v-for='month in 12' :key='month' :value='month'>{{ month }}</option></select></div>
      <div class='col-md-2 mt-2'><label>Status</label><select v-model='filters.status' class='form-control'><option value=''>All</option><option value='active'>Active</option><option value='completed'>Completed</option></select></div>
      <div class='col-md-4 mt-2'><label>Product</label><v-select v-model='filters.product_id' :options='options.products' label='name' :reduce='reduceId' placeholder='All products'/></div>
      <div class='col-md-4 mt-2'><label>Category</label><v-select v-model='filters.category_id' :options='options.categories' label='name' :reduce='reduceId' placeholder='All categories'/></div>
    </div></div>
    <section class='target-card target-section'>
      <div class='target-section-title'><h3>Target versus Achievement</h3><button v-if='canExport' class='btn btn-sm btn-success no-print' @click='downloadExcel'>Export Excel</button></div>
      <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
      <div v-else class='target-table-wrap'><table class='target-table'><thead><tr><th>Supplier</th><th>Target</th><th>Period</th><th>Target Units</th><th>Achieved</th><th>Remaining</th><th>%</th><th>Status</th></tr></thead><tbody><tr v-for='row in data.targets' :key='row.id'><td>{{ row.supplier }}</td><td>{{ row.target_name }}</td><td>{{ row.start_date }} - {{ row.end_date }}</td><td>{{ fmt(row.metrics.target) }}</td><td>{{ fmt(row.metrics.achieved) }}</td><td>{{ fmt(row.metrics.remaining) }}</td><td>{{ row.metrics.percentage }}%</td><td><span class='target-status' :class='statusClass(row.metrics.status)'>{{ row.metrics.status }}</span></td></tr><tr v-if='!data.targets.length'><td colspan='8' class='text-center text-muted'>No report data found.</td></tr></tbody></table></div>
    </section>
  </div>
</template>
<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{vSelect},
  data(){return{loading:false,options:{suppliers:[],warehouses:[],products:[],categories:[]},filters:{supplier_id:null,period_type:'',year:new Date().getFullYear(),month:'',warehouse_id:null,status:'',product_id:null,category_id:null},data:{targets:[]}}},
  computed:{permissions(){return this.$store.getters.currentUserPermissions||[]},canExport(){return this.permissions.includes('targets.export')}},
  async created(){try{const response=await axios.get('targets/options');this.options=response.data;await this.load()}catch(error){this.notify(error)}},
  methods:{
    reduceId(option){return option.id},fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    statusClass(value){return String(value||'').toLowerCase().replace(/\s+/g,'-')},
    params(){const params={};Object.keys(this.filters).forEach(key=>{if(this.filters[key]!==''&&this.filters[key]!==null)params[key]=this.filters[key]});return params},
    query(){return new URLSearchParams(this.params()).toString()},
    async load(){this.loading=true;try{const response=await axios.get('targets/report',{params:this.params()});this.data=response.data}catch(error){this.notify(error)}finally{this.loading=false}},
    async blob(path,filename,preview){
      try{
        const response=await axios.get(path,{params:this.params(),responseType:'blob'});
        const url=URL.createObjectURL(response.data);
        if(preview)window.open(url,'_blank');else{const link=document.createElement('a');link.href=url;link.download=filename;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000)}
      }catch(error){this.notify(error)}
    },
    printReport(){this.blob('targets/report/print','supplier-target-report.html',true)},
    downloadPdf(){this.blob('targets/report/pdf','supplier-target-report.pdf',false)},
    downloadExcel(){this.blob('targets/report/excel','supplier-target-report.xlsx',false)},
    notify(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Unable to build the report.',{variant:'danger'})}
  }
};
</script>
