<template>
  <div class='main-content targets-page'>
    <div class='targets-hero'>
      <div><h2>Target Management</h2><p>Track supplier targets, warehouse allocation and achievement.</p></div>
      <div class='targets-hero-actions no-print'>
        <router-link v-if='canCreate' class='target-hero-btn target-hero-btn-primary' to='/app/targets/create'>
          <lucide-icon name='plus' :size='20'/><span>Create Target</span>
        </router-link>
        <router-link v-if='canReports' class='target-hero-btn target-hero-btn-secondary' to='/app/targets/reports'>
          <lucide-icon name='file-text' :size='19'/><span>Reports</span>
        </router-link>
      </div>
    </div>
    <div class='target-card target-filters no-print'><div class='row'>
      <div class='col-md-3'><label>Supplier</label><v-select v-model='filters.supplier_id' :options='options.suppliers' label='name' :reduce='reduceId' placeholder='All suppliers'/></div>
      <div class='col-md-2'><label>Period</label><select v-model='filters.period_type' class='form-control'><option value=''>All periods</option><option value='annual'>Annual</option><option value='monthly'>Monthly</option></select></div>
      <div class='col-md-2'><label>Year</label><select v-model='filters.year' class='form-control'><option v-for='year in years' :key='year' :value='year'>{{ year }}</option></select></div>
      <div v-if='isMonthly' class='col-md-2'><label>Month</label><select v-model='filters.month' class='form-control'><option value=''>All</option><option v-for='m in 12' :key='m' :value='m'>{{ monthName(m) }}</option></select></div>
      <div class='col-md-3'><label>Warehouse</label><v-select v-model='filters.warehouse_id' :options='options.warehouses' label='name' :reduce='reduceId' placeholder='All warehouses'/></div>
    </div></div>
    <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
    <template v-else>
      <div class='target-kpis'><div v-for='card in cards' :key='card.label' class='target-card target-kpi'>
        <div class='target-kpi-icon' :class='card.tone'><lucide-icon :name='card.icon' :size='32' :stroke-width='2.15'/></div>
        <div class='flex-grow-1'>
          <div class='target-kpi-label'>{{ card.label }}</div>
          <div class='target-kpi-value'>{{ fmt(card.value) }}{{ card.percent ? '%' : ' Units' }}</div>
          <div class='target-progress-row'>
            <div class='target-progress' :class='card.tone'><span :style='{width:width(card.progress)}'></span></div>
            <small>{{ fmt(card.progress) }}%</small>
          </div>
        </div>
      </div></div>
      <div class='target-grid'>
        <section class='target-card target-section'><div class='target-section-title'><h3>{{ chartTitle }}</h3></div><achievement-chart :points='data.monthly'/></section>
        <section class='target-card target-section'><div class='target-section-title'><h3>Warehouse Performance</h3></div><div v-if='!data.warehouses.length' class='text-muted p-4'>No warehouse performance yet.</div><div v-for='row in data.warehouses' :key='row.id' class='mb-3'><div class='d-flex justify-content-between'><strong>{{ row.name }}</strong><span>{{ row.percentage }}%</span></div><small>{{ fmt(row.achieved) }} / {{ fmt(row.target) }} units</small><div class='target-progress mt-1'><span :style='{width:width(row.percentage)}'></span></div></div></section>
      </div>
      <section class='target-card target-section'><div class='target-section-title'><h3>Product / Category Performance</h3><div><router-link class='btn btn-sm btn-link' to='/app/targets/list'>View Details</router-link><button class='btn btn-sm btn-link' @click='printReport'>Print Report</button></div></div>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Name</th><th>Target</th><th>Achieved</th><th>Remaining</th><th>Achievement</th><th>Status</th></tr></thead><tbody><tr v-for='row in data.lines' :key='row.name'><td>{{ row.name }}</td><td>{{ fmt(row.target) }}</td><td>{{ fmt(row.achieved) }}</td><td>{{ fmt(row.remaining) }}</td><td>{{ row.percentage }}%<div class='target-progress'><span :style='{width:width(row.percentage)}'></span></div></td><td><span class='target-status' :class='rowClass(row.status)'>{{ row.status }}</span></td></tr><tr v-if='!data.lines.length'><td colspan='6' class='text-center text-muted'>No active target data for these filters.</td></tr></tbody></table></div>
      </section>
    </template>
  </div>
</template>
<script>
import AchievementChart from './AchievementChart.vue';
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{AchievementChart,vSelect},
  data(){return{
    loading:true,
    filters:{supplier_id:null,period_type:'annual',year:new Date().getFullYear(),month:'',warehouse_id:null},
    options:{suppliers:[],warehouses:[]},
    data:{summary:{target:0,achieved:0,remaining:0,percentage:0},monthly:[],warehouses:[],lines:[]}
  }},
  computed:{
    permissions(){return this.$store.getters.currentUserPermissions||[]},
    canCreate(){return this.permissions.includes('targets.create')},
    canReports(){return this.permissions.includes('targets.reports')},
    isMonthly(){return this.filters.period_type==='monthly'},
    chartTitle(){return this.isMonthly?'Weekly Achievement':'Monthly Achievement'},
    years(){const y=new Date().getFullYear();return Array.from({length:7},(_,i)=>y-3+i)},
    cards(){const s=this.data.summary;return[
      {label:this.filters.period_type==='monthly'?'Monthly Target':'Annual Target',value:s.target,progress:100,icon:'target-arrow',tone:'purple'},
      {label:'Achieved',value:s.achieved,progress:s.percentage,icon:'chart-no-axes-column',tone:'green'},
      {label:'Remaining',value:s.remaining,progress:Math.max(0,100-s.percentage),icon:'pie-chart',tone:'orange'},
      {label:'Achievement',value:s.percentage,progress:s.percentage,icon:'trophy',percent:true,tone:'purple'}
    ]},
  },
  watch:{filters:{deep:true,handler(){clearTimeout(this.timer);this.timer=setTimeout(this.load,250)}}},
  created(){this.loadOptions();this.load()},
  methods:{
    reduceId(option){return option.id},
    width(value){return Math.min(Number(value||0),100)+'%'},
    rowClass(value){return String(value||'').toLowerCase().replace(/\s+/g,'-')},
    fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    monthName(month){return new Date(2000,month-1,1).toLocaleString(undefined,{month:'long'})},
    params(){const params={};Object.keys(this.filters).forEach(key=>{if(this.filters[key]!==''&&this.filters[key]!==null)params[key]=this.filters[key]});return params},
    async loadOptions(){try{const response=await axios.get('targets/options');this.options=response.data}catch(error){this.notifyError(error)}},
    async load(){this.loading=true;try{const response=await axios.get('targets/dashboard',{params:this.params()});this.data=response.data}catch(error){this.notifyError(error)}finally{this.loading=false}},
    notifyError(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Could not load targets.',{variant:'danger'})},
    async printReport(){
      try{const response=await axios.get('targets/report/print',{params:this.params(),responseType:'blob'});window.open(URL.createObjectURL(response.data),'_blank')}
      catch(error){this.notifyError(error)}
    }
  }
};
</script>
