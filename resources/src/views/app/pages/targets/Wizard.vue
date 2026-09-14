<template>
  <div class='main-content targets-page'>
    <div class='targets-hero'><div><h2>{{ targetId ? 'Continue Target' : 'Create Target' }}</h2><p>Define supplier targets and distribute them to warehouses.</p></div><router-link class='btn btn-outline-light' to='/app/targets/list'>Cancel</router-link></div>
    <div class='wizard-steps no-print'>
      <button v-for='item in steps' :key='item.number' class='wizard-step' :class='{active:step===item.number,done:maxStep>item.number}' :disabled='item.number>maxStep' @click='goStep(item.number)'><strong>{{ item.number }}. {{ item.label }}</strong><br><small>{{ item.help }}</small></button>
    </div>
    <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
    <section v-else class='target-card wizard-body'>
      <form v-if='step===1' @submit.prevent='saveDetails(true)'>
        <h3>Target Details</h3><div class='row'>
          <div class='form-group col-md-6'><label>Supplier *</label><v-select v-model='form.supplier_id' :options='options.suppliers' label='name' :reduce='reduceId'/><small class='text-danger'>{{ error('supplier_id') }}</small></div>
          <div class='form-group col-md-6'><label>Target Name *</label><input v-model.trim='form.target_name' class='form-control'><small class='text-danger'>{{ error('target_name') }}</small></div>
          <div class='form-group col-md-4'><label>Target Period *</label><select v-model='form.period_type' class='form-control' @change='periodChanged'><option value='annual'>Annual</option><option value='monthly'>Monthly</option></select></div>
          <div class='form-group col-md-4'><label>Start Date *</label><input v-model='form.start_date' type='date' class='form-control' @change='periodChanged'><small class='text-danger'>{{ error('start_date') }}</small></div>
          <div class='form-group col-md-4'><label>End Date *</label><input v-model='form.end_date' type='date' class='form-control'><small class='text-danger'>{{ error('end_date') }}</small></div>
          <div class='form-group col-md-4'><label>Measurement</label><input value='Quantity (Units)' class='form-control' disabled></div>
          <div class='form-group col-md-8'><label>Description / Notes</label><textarea v-model='form.description' rows='3' class='form-control'></textarea></div>
        </div><div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='saveDetails(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving'>Save &amp; Continue</button></div>
      </form>
      <form v-if='step===2' @submit.prevent='saveLines(true)'>
        <div class='target-section-title'><div><h3>Product &amp; Category Targets</h3><small>Select only items attributed to {{ supplierName }}.</small></div><button type='button' class='btn btn-sm btn-outline-primary' @click='addLine'>+ Add Row</button></div>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Type</th><th>Product / Category</th><th>Target Quantity</th><th>Unit</th><th></th></tr></thead><tbody>
          <tr v-for='(line,index) in lines' :key='line.key'><td><select v-model='line.type' class='form-control' @change='line.targetable_id=null'><option value='product'>Product</option><option value='category'>Category</option></select></td><td><v-select v-model='line.targetable_id' :options='lineOptions(line)' label='name' :reduce='reduceId'/></td><td><input v-model.number='line.target_quantity' type='number' min='0.001' step='0.001' class='form-control'></td><td><v-select v-model='line.unit_id' :options='options.units' label='ShortName' :reduce='reduceId'/></td><td><button type='button' class='btn btn-link text-danger' @click='removeLine(index)'>Remove</button></td></tr>
        </tbody></table></div>
        <div class='allocation-total my-3'>{{ lines.length }} target lines &nbsp; | &nbsp; Total supplier target: {{ fmt(totalTarget) }} units</div>
        <div v-if='error(lines)' class='alert alert-danger'>{{ error('lines') }}</div>
        <div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='step=1'>Back</button><div><button type='button' class='btn btn-outline-secondary mr-2' @click='saveLines(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving'>Save &amp; Continue</button></div></div>
      </form>
      <form v-if='step===3' @submit.prevent='activate'>
        <div class='target-section-title'><div><h3>Warehouse Allocation</h3><small>Allocate the complete supplier target within your warehouse scope.</small></div><div><button type='button' class='btn btn-sm btn-outline-primary mr-2' @click='distribute'>Distribute Equally</button><button type='button' class='btn btn-sm btn-outline-secondary' @click='reset'>Reset</button></div></div>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Warehouse</th><th>Allocated Quantity</th><th>Share</th><th>Validation</th></tr></thead><tbody><tr v-for='allocation in allocations' :key='allocation.warehouse_id'><td>{{ allocation.warehouse }}</td><td><input v-model.number='allocation.allocated_quantity' type='number' min='0' step='0.001' class='form-control'></td><td>{{ share(allocation.allocated_quantity) }}%</td><td><span class='target-status' :class='{behind:allocation.allocated_quantity<0}'>{{ allocation.allocated_quantity>=0 ? 'Valid' : 'Invalid' }}</span></td></tr></tbody></table></div>
        <div class='row my-3'><div class='col-md-3'><div class='allocation-total'>Target: {{ fmt(totalTarget) }}</div></div><div class='col-md-3'><div class='allocation-total'>Allocated: {{ fmt(allocatedTotal) }}</div></div><div class='col-md-3'><div class='allocation-total'>Unallocated: {{ fmt(Math.max(totalTarget-allocatedTotal,0)) }}</div></div><div class='col-md-3'><div class='allocation-total'>Allocation: {{ allocationPercent }}%</div></div></div>
        <div v-if='error(allocations)' class='alert alert-danger'>{{ error('allocations') }}</div>
        <div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='step=2'>Back</button><div><button type='button' class='btn btn-outline-secondary mr-2' @click='saveAllocations(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving||!allocationComplete'>Save &amp; Activate Target</button></div></div>
      </form>
    </section>
  </div>
</template>
<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{vSelect},
  data(){return{
    loading:true,saving:false,step:1,maxStep:1,errors:{},options:{suppliers:[],products:[],categories:[],warehouses:[],units:[]},
    form:{supplier_id:null,target_name:'',period_type:'annual',start_date:'',end_date:'',measurement_type:'quantity',description:''},
    lines:[],allocations:[],steps:[
      {number:1,label:'Target Details',help:'Supplier and period'},
      {number:2,label:'Product Targets',help:'Products or categories'},
      {number:3,label:'Warehouse Allocation',help:'Branch distribution'}
    ]
  }},
  computed:{
    targetId(){return this.$route.params.id||null},
    totalTarget(){return this.lines.reduce((sum,row)=>sum+Number(row.target_quantity||0),0)},
    allocatedTotal(){return this.allocations.reduce((sum,row)=>sum+Number(row.allocated_quantity||0),0)},
    allocationPercent(){return this.totalTarget?Math.round(this.allocatedTotal/this.totalTarget*1000)/10:0},
    allocationComplete(){return this.totalTarget>0&&Math.abs(this.totalTarget-this.allocatedTotal)<0.0001},
    supplierName(){const supplier=this.options.suppliers.find(item=>String(item.id)===String(this.form.supplier_id));return supplier?supplier.name:'the selected supplier'}
  },
  watch:{
    'form.supplier_id':function(value){if(value)this.loadSupplierChoices(value)}
  },
  async created(){
    try{
      await this.loadOptions();
      if(this.targetId)await this.loadTarget();
      if(!this.lines.length)this.addLine();
      this.syncAllocations();
    }catch(error){this.notifyError(error)}finally{this.loading=false}
  },
  methods:{
    reduceId(option){return option.id},
    fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    error(key){if(this.errors[key])return this.errors[key][0];const nested=Object.keys(this.errors).find(item=>item.indexOf(key+'.')===0);return nested?this.errors[nested][0]:''},
    lineOptions(line){return line.type==='category'?this.options.categories:this.options.products},
    addLine(){this.lines.push({key:Date.now()+Math.random(),type:'product',targetable_id:null,target_quantity:null,unit_id:null})},
    removeLine(index){this.lines.splice(index,1);if(!this.lines.length)this.addLine()},
    goStep(number){if(number<=this.maxStep)this.step=number},
    periodChanged(){
      if(!this.form.start_date)return;
      const start=new Date(this.form.start_date+'T00:00:00');
      if(this.form.period_type==='monthly'){
        this.form.start_date=[start.getFullYear(),String(start.getMonth()+1).padStart(2,'0'),'01'].join('-');
        const end=new Date(start.getFullYear(),start.getMonth()+1,0);
        this.form.end_date=[end.getFullYear(),String(end.getMonth()+1).padStart(2,'0'),String(end.getDate()).padStart(2,'0')].join('-');
      }else{
        this.form.start_date=start.getFullYear()+'-01-01';this.form.end_date=start.getFullYear()+'-12-31';
      }
    },
    async loadOptions(){const response=await axios.get('targets/options');this.options=response.data},
    async loadSupplierChoices(supplierId){
      try{
        const response=await axios.get('targets/options',{params:{supplier_id:supplierId}});
        this.options.products=response.data.products;this.options.categories=response.data.categories;
      }catch(error){this.notifyError(error)}
    },
    async loadTarget(){
      const response=await axios.get('targets/'+this.targetId);const target=response.data.target;
      Object.keys(this.form).forEach(key=>{if(target[key]!==undefined)this.form[key]=target[key]});
      this.lines=target.lines.map(line=>Object.assign({key:line.id},line));
      this.allocations=target.allocations.map(row=>({warehouse_id:row.warehouse_id,warehouse:row.warehouse,allocated_quantity:Number(row.allocated_quantity)}));
      this.maxStep=target.lines.length?(target.allocations.length?3:3):2;
      if(this.$route.query.step)this.step=Math.min(Number(this.$route.query.step),this.maxStep);
    },
    syncAllocations(){
      const values=new Map(this.allocations.map(row=>[String(row.warehouse_id),row.allocated_quantity]));
      this.allocations=this.options.warehouses.map(warehouse=>({warehouse_id:warehouse.id,warehouse:warehouse.name,allocated_quantity:Number(values.get(String(warehouse.id))||0)}));
    },
    async saveDetails(advance){
      this.saving=true;this.errors={};
      try{
        const response=this.targetId?await axios.put('targets/'+this.targetId,this.form):await axios.post('targets',this.form);
        const id=this.targetId||response.data.target.id;
        this.$bvToast.toast(response.data.message,{variant:'success'});
        if(!this.targetId)await this.$router.replace({path:'/app/targets/edit/'+id,query:advance?{step:2}:{step:1}});
        if(advance){this.maxStep=Math.max(this.maxStep,2);this.step=2}
      }catch(error){this.capture(error)}finally{this.saving=false}
    },
    linePayload(){return this.lines.map(row=>({type:row.type,targetable_id:row.targetable_id,target_quantity:Number(row.target_quantity),unit_id:row.unit_id||null}))},
    async saveLines(advance){
      this.saving=true;this.errors={};
      try{
        const response=await axios.put('targets/'+this.targetId+'/lines',{lines:this.linePayload()});
        this.$bvToast.toast(response.data.message,{variant:'success'});
        if(advance){this.maxStep=3;this.step=3;this.syncAllocations()}
      }catch(error){this.capture(error)}finally{this.saving=false}
    },
    distribute(){
      if(!this.allocations.length)return;const precision=1000;const total=Math.round(this.totalTarget*precision);
      const each=Math.floor(total/this.allocations.length);let remainder=total-each*this.allocations.length;
      this.allocations.forEach(row=>{row.allocated_quantity=(each+(remainder-->0?1:0))/precision});
    },
    reset(){this.allocations.forEach(row=>{row.allocated_quantity=0})},
    share(value){return this.totalTarget?Math.round(Number(value||0)/this.totalTarget*1000)/10:0},
    async saveAllocations(activate){
      this.saving=true;this.errors={};
      try{
        const response=await axios.put('targets/'+this.targetId+'/allocations',{allocations:this.allocations.map(row=>({warehouse_id:row.warehouse_id,allocated_quantity:Number(row.allocated_quantity||0)}))});
        this.$bvToast.toast(response.data.message,{variant:'success'});
        if(activate)await this.activateNow();
      }catch(error){this.capture(error)}finally{this.saving=false}
    },
    async activate(){
      const answer=await this.$swal({title:'Activate this target?',text:'Achievement will begin using completed sales.',icon:'question',showCancelButton:true,confirmButtonColor:'#6f2dbd'});
      if(answer.isConfirmed)await this.saveAllocations(true)
    },
    async activateNow(){const response=await axios.post('targets/'+this.targetId+'/activate');this.$bvToast.toast(response.data.message,{variant:'success'});await this.$router.push('/app/targets/'+this.targetId)},
    capture(error){this.errors=(error.response&&error.response.data&&error.response.data.errors)||{};this.notifyError(error)},
    notifyError(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Unable to save the target.',{variant:'danger'})}
  }
};
</script>
