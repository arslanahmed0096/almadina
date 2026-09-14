<template>
  <div class='main-content targets-page'>
    <div class='targets-hero'><div><h2>Manage Targets</h2><p>Create, resume, activate and monitor supplier targets.</p></div><router-link v-if='canCreate' class='btn btn-light' to='/app/targets/create'>+ Create Target</router-link></div>
    <div class='target-card target-filters no-print'><div class='row'>
      <div class='col-md-3'><label>Search</label><input v-model='filters.search' class='form-control' placeholder='Target name'></div>
      <div class='col-md-3'><label>Supplier</label><v-select v-model='filters.supplier_id' :options='options.suppliers' label='name' :reduce='reduceId' placeholder='All suppliers'/></div>
      <div class='col-md-2'><label>Period</label><select v-model='filters.period_type' class='form-control'><option value=''>All</option><option value='annual'>Annual</option><option value='monthly'>Monthly</option></select></div>
      <div class='col-md-2'><label>Status</label><select v-model='filters.status' class='form-control'><option value=''>All</option><option v-for='status in statuses' :key='status' :value='status'>{{ status }}</option></select></div>
      <div class='col-md-2'><label>Warehouse</label><v-select v-model='filters.warehouse_id' :options='options.warehouses' label='name' :reduce='reduceId' placeholder='All'/></div>
    </div></div>
    <div class='target-card target-section'>
      <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
      <div v-else class='target-table-wrap'><table class='target-table'><thead><tr><th>Target</th><th>Supplier</th><th>Period</th><th>Target / Allocated</th><th>Achievement</th><th>Status</th><th>Created by</th><th class='no-print'>Actions</th></tr></thead><tbody>
        <tr v-for='target in rows' :key='target.id'><td><strong>{{ target.target_name }}</strong><br><small>{{ target.start_date }} - {{ target.end_date }}</small></td><td>{{ target.supplier }}</td><td>{{ title(target.period_type) }}</td><td>{{ fmt(target.total_target) }} / {{ fmt(target.allocated_total) }}</td><td>{{ target.metrics ? fmt(target.metrics.achieved) : 0 }} ({{ target.metrics ? target.metrics.percentage : 0 }}%)<div class='target-progress'><span :style='{width:width(target.metrics ? target.metrics.percentage : 0)}'></span></div></td><td><span class='target-status' :class='rowClass(target.status)'>{{ title(target.status) }}</span></td><td>{{ target.created_by || '-' }}</td><td class='no-print'><router-link class='btn btn-sm btn-link' :to='viewLink(target)'>View</router-link><router-link v-if='editable(target)' class='btn btn-sm btn-link' :to='editLink(target)'>{{ target.status==='draft' ? 'Continue' : 'Edit' }}</router-link><button v-if='activatable(target)' class='btn btn-sm btn-link text-success' @click='activate(target)'>Activate</button><button v-if='cancellable(target)' class='btn btn-sm btn-link text-warning' @click='cancel(target)'>Cancel</button><button v-if='deletable(target)' class='btn btn-sm btn-link text-danger' @click='remove(target)'>Delete</button></td></tr>
        <tr v-if='!rows.length'><td colspan='8' class='text-center text-muted p-4'>No supplier targets matched these filters.</td></tr>
      </tbody></table></div>
      <div v-if='meta.last_page>1' class='d-flex justify-content-between align-items-center mt-3 no-print'><span>Page {{ meta.current_page }} of {{ meta.last_page }}</span><div><button class='btn btn-sm btn-light mr-2' :disabled='meta.current_page<=1' @click='page(meta.current_page-1)'>Previous</button><button class='btn btn-sm btn-light' :disabled='meta.current_page>=meta.last_page' @click='page(meta.current_page+1)'>Next</button></div></div>
    </div>
  </div>
</template>
<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{vSelect},
  data(){return{
    loading:true,rows:[],meta:{current_page:1,last_page:1},options:{suppliers:[],warehouses:[]},
    statuses:['draft','active','completed','cancelled'],
    filters:{search:'',supplier_id:null,period_type:'',status:'',warehouse_id:null,page:1}
  }},
  computed:{
    permissions(){return this.$store.getters.currentUserPermissions||[]},
    canCreate(){return this.permissions.includes('targets.create')}
  },
  watch:{filters:{deep:true,handler(){clearTimeout(this.timer);this.timer=setTimeout(this.load,300)}}},
  async created(){
    Object.keys(this.filters).forEach(key=>{if(this.$route.query[key]!==undefined)this.filters[key]=this.$route.query[key]});
    try{const response=await axios.get('targets/options');this.options=response.data}catch(error){this.notify(error)}
    await this.load();
  },
  methods:{
    reduceId(option){return option.id},
    has(permission){return this.permissions.includes(permission)},
    title(value){value=String(value||'').replace(/_/g,' ');return value.charAt(0).toUpperCase()+value.slice(1)},
    fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    width(value){return Math.min(Number(value||0),100)+'%'},
    rowClass(value){return String(value||'').toLowerCase()},
    viewLink(target){return '/app/targets/'+target.id},
    editLink(target){return '/app/targets/edit/'+target.id+'?step='+(target.lines.length?target.allocations.length?3:2:1)},
    editable(target){return this.has('targets.edit')&&!['completed','cancelled'].includes(target.status)},
    activatable(target){return target.status==='draft'&&this.has('targets.activate')},
    cancellable(target){return ['draft','active'].includes(target.status)&&this.has('targets.cancel')},
    deletable(target){return target.status==='draft'&&this.has('targets.delete')},
    params(){const result={};Object.keys(this.filters).forEach(key=>{if(this.filters[key]!==''&&this.filters[key]!==null)result[key]=this.filters[key]});return result},
    async load(){
      this.loading=true;
      try{
        const params=this.params();this.$router.replace({query:params}).catch(()=>{});
        const response=await axios.get('targets',{params});this.rows=response.data.data||[];
        this.meta={current_page:response.data.current_page||1,last_page:response.data.last_page||1};
      }catch(error){this.notify(error)}finally{this.loading=false}
    },
    page(number){this.filters.page=number},
    async confirm(title,text){return (await this.$swal({title,text,icon:'warning',showCancelButton:true,confirmButtonColor:'#6f2dbd'})).isConfirmed},
    async activate(target){if(!await this.confirm('Activate this target?','Only complete allocations can be activated.'))return;try{const response=await axios.post('targets/'+target.id+'/activate');this.success(response);this.load()}catch(error){this.notify(error)}},
    async cancel(target){if(!await this.confirm('Cancel this target?','Cancelled targets stop contributing to active progress.'))return;try{const response=await axios.post('targets/'+target.id+'/cancel');this.success(response);this.load()}catch(error){this.notify(error)}},
    async remove(target){if(!await this.confirm('Delete this draft?','This action removes the draft and its wizard data.'))return;try{const response=await axios.delete('targets/'+target.id);this.success(response);this.load()}catch(error){this.notify(error)}},
    success(response){this.$bvToast.toast(response.data.message,{variant:'success'})},
    notify(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Unable to load targets.',{variant:'danger'})}
  }
};
</script>
