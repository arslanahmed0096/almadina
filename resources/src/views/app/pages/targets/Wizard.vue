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
          <div class='form-group col-md-6'><label>Supplier *</label><v-select v-model='form.supplier_id' :options='options.suppliers' label='name' :reduce='reduceId' append-to-body/><small class='text-danger'>{{ error('supplier_id') }}</small></div>
          <div class='form-group col-md-6'><label>Target Name *</label><input v-model.trim='form.target_name' class='form-control'><small class='text-danger'>{{ error('target_name') }}</small></div>
          <div class='form-group col-md-4'><label>Target Period *</label><select v-model='form.period_type' class='form-control' @change='periodChanged'><option value='annual'>Annual</option><option value='quarterly'>Quarterly</option><option value='monthly'>Monthly</option></select></div>
          <div class='form-group col-md-4'><label>Start Date *</label><input v-model='form.start_date' type='date' class='form-control' @change='periodChanged'><small class='text-danger'>{{ error('start_date') }}</small></div>
          <div class='form-group col-md-4'><label>End Date *</label><input v-model='form.end_date' type='date' class='form-control'><small class='text-danger'>{{ error('end_date') }}</small></div>
          <div class='form-group col-md-4'><label>Measurement</label><input value='Quantity (Units)' class='form-control' disabled></div>
          <div class='form-group col-md-8'><label>Description / Notes</label><textarea v-model='form.description' rows='3' class='form-control'></textarea></div>
        </div><div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='saveDetails(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving'>Save &amp; Continue</button></div>
      </form>
      <form v-if='step===2' class='target-product-step' @submit.prevent='saveLines(true)'>
        <div class='target-section-title'><div><h3>Product &amp; Category Targets</h3><small>Select only items attributed to {{ supplierName }}. Company further discount is a supplier incentive and does not reduce the sale price.</small></div><button type='button' class='btn btn-sm btn-outline-primary' @click='addLine'>+ Add Row</button></div>
        <div class='target-table-wrap target-product-table'><table class='target-table'><thead><tr><th>Type</th><th>Product / Category</th><th>Target Quantity</th><th>Further Discounts</th><th>Unit</th><th></th></tr></thead><tbody>
          <tr v-for='(line,index) in lines' :key='line.key'><td><select v-model='line.type' class='form-control' @change='line.targetable_id=null'><option value='product'>Product</option><option value='category'>Category</option></select></td><td><v-select v-model='line.targetable_id' :options='lineOptions(line)' label='name' :reduce='reduceId' append-to-body/></td><td><input v-model.number='line.target_quantity' type='number' min='0.001' step='0.001' class='form-control'></td><td><button type='button' class='btn btn-sm btn-outline-primary target-discount-button' @click='openDiscountModal(index)'><lucide-icon name='plus'/> Add / Edit <span v-if='line.further_discounts.length' class='badge badge-primary ml-1'>{{ line.further_discounts.length }}</span></button><small v-if='line.further_discounts.length' class='d-block mt-1 text-muted'>{{ discountSummary(line.further_discounts) }}</small></td><td><v-select v-model='line.unit_id' :options='options.units' label='ShortName' :reduce='reduceId' append-to-body/></td><td><button type='button' class='btn btn-link text-danger' @click='removeLine(index)'>Remove</button></td></tr>
        </tbody></table></div>
        <div class='allocation-total my-3'>{{ lines.length }} target lines &nbsp; | &nbsp; Total supplier target: {{ fmt(totalTarget) }} units</div>
        <div v-if='error(lines)' class='alert alert-danger'>{{ error('lines') }}</div>
        <div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='step=1'>Back</button><div><button type='button' class='btn btn-outline-secondary mr-2' @click='saveLines(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving'>Save &amp; Continue</button></div></div>
      </form>
      <form v-if='step===3' @submit.prevent='activate'>
        <div class='target-section-title'><div><h3>Branch &amp; Category Allocation</h3><small>Use branch sales history, equal distribution, or enter decimal quantities manually.</small></div><div><button type='button' class='btn btn-sm target-purple-btn mr-2' :disabled='allocationSuggestionBusy' @click='distributeBySales'>{{ allocationSuggestionBusy ? 'Calculating...' : 'Allocate by Branch Sales' }}</button><button type='button' class='btn btn-sm btn-outline-primary mr-2' @click='distribute'>Distribute Equally</button><button type='button' class='btn btn-sm btn-outline-secondary' @click='reset'>Reset</button></div></div>
        <div v-if='allocationNotice' class='alert alert-info'>{{ allocationNotice }}</div>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Product / Category</th><th v-for='warehouse in options.warehouses' :key='warehouse.id'>{{ warehouse.name }}</th><th>Allocated / Target</th></tr></thead><tbody>
          <tr v-for='line in lines' :key='line.id'><td><strong>{{ line.name }}</strong></td><td v-for='warehouse in options.warehouses' :key='warehouse.id'><input v-model.number='allocationFor(line.id,warehouse.id).allocated_quantity' type='number' min='0' step='0.001' placeholder='0.000' class='form-control'><small v-if='historicalFor(line.id,warehouse.id)' class='text-muted d-block mt-1'>Net sales: {{ fmt(historicalFor(line.id,warehouse.id).quantity) }} ({{ historicalFor(line.id,warehouse.id).share }}%)</small></td><td :class='allocationClass(line)'>{{ fmt(lineAllocatedTotal(line.id)) }} / {{ fmt(line.target_quantity) }}</td></tr>
        </tbody></table></div>
        <div class='row my-3'><div class='col-md-3'><div class='allocation-total'>Target: {{ fmt(totalTarget) }}</div></div><div class='col-md-3'><div class='allocation-total'>Allocated: {{ fmt(allocatedTotal) }}</div></div><div class='col-md-3'><div class='allocation-total'>Unallocated: {{ fmt(Math.max(totalTarget-allocatedTotal,0)) }}</div></div><div class='col-md-3'><div class='allocation-total'>Allocation: {{ allocationPercent }}%</div></div></div>
        <div v-if='allocationError' class='alert alert-danger'>{{ allocationError }}</div>
        <div class='d-flex justify-content-between'><button type='button' class='btn btn-light' @click='step=2'>Back</button><div><button type='button' class='btn btn-outline-secondary mr-2' @click='saveAllocations(false)'>Save as Draft</button><button class='btn target-purple-btn' :disabled='saving||!allocationComplete'>Save &amp; Activate Target</button></div></div>
      </form>
    </section>
    <b-modal id='target-further-discount-modal' size='lg' centered hide-footer title='Target Further Discounts' @hidden='closeDiscountModal'>
      <div v-if='activeDiscountLine'>
        <div class='alert alert-info py-2'>Add company discount categories such as Payment Clearance, Target, or Per Item. Pricing will calculate percentages from the product purchase price.</div>
        <div v-for='(discount,index) in discountDraft' :key='"target-discount-"+index' class='row align-items-end border-bottom mb-3'>
          <div class='form-group col-md-4'><label>Discount Category / Name</label><input v-model.trim='discount.label' maxlength='100' class='form-control' placeholder='e.g. Payment Clearance'></div>
          <div class='form-group col-md-3'><label>Type</label><select v-model='discount.type' class='form-control'><option value='percentage'>Percentage (%)</option><option value='fixed'>Fixed amount / item</option></select></div>
          <div class='form-group col-md-3'><label>Value</label><input v-model='discount.value' type='number' min='0' step='0.01' class='form-control'></div>
          <div class='form-group col-md-2'><button type='button' class='btn btn-outline-danger btn-block' @click='removeDiscount(index)'>Remove</button></div>
        </div>
        <div v-if='!discountDraft.length' class='text-center text-muted border rounded p-4 mb-3'>No further discounts added.</div>
        <div class='d-flex justify-content-between'><button type='button' class='btn btn-outline-primary' @click='addDiscount'><lucide-icon name='plus'/> Add Discount</button><div><button type='button' class='btn btn-light mr-2' @click='$bvModal.hide("target-further-discount-modal")'>Cancel</button><button type='button' class='btn target-purple-btn' @click='saveDiscounts'>Save Discounts</button></div></div>
      </div>
    </b-modal>
  </div>
</template>
<script>
import vSelect from 'vue-select';
import 'vue-select/dist/vue-select.css';
import '../../../../assets/styles/targets.scss';

export default {
  components:{vSelect},
  data(){return{
    loading:true,saving:false,allocationSuggestionBusy:false,allocationNotice:'',allocationHistory:{},step:1,maxStep:1,errors:{},options:{suppliers:[],products:[],categories:[],warehouses:[],units:[]},
    form:{supplier_id:null,target_name:'',period_type:'annual',start_date:'',end_date:'',measurement_type:'quantity',description:''},
    lines:[],allocations:[],lineAllocations:[],activeDiscountLineIndex:null,discountDraft:[],steps:[
      {number:1,label:'Target Details',help:'Supplier and period'},
      {number:2,label:'Product Targets',help:'Products or categories'},
      {number:3,label:'Warehouse Allocation',help:'Branch distribution'}
    ]
  }},
  computed:{
    targetId(){return this.$route.params.id||null},
    totalTarget(){return this.lines.reduce((sum,row)=>sum+Number(row.target_quantity||0),0)},
    allocatedTotal(){return this.lineAllocations.reduce((sum,row)=>sum+Number(row.allocated_quantity||0),0)},
    allocationPercent(){return this.totalTarget?Math.round(this.allocatedTotal/this.totalTarget*1000)/10:0},
    allocationComplete(){return this.totalTarget>0&&this.lines.every(line=>this.lineComplete(line))},
    allocationError(){return this.error('line_allocations')||this.error('allocations')},
    activeDiscountLine(){return this.activeDiscountLineIndex===null?null:this.lines[this.activeDiscountLineIndex]},
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
    fmt(value){return Number(value||0).toLocaleString(undefined,{minimumFractionDigits:3,maximumFractionDigits:3})},
    error(key){if(this.errors[key])return this.errors[key][0];const nested=Object.keys(this.errors).find(item=>item.indexOf(key+'.')===0);return nested?this.errors[nested][0]:''},
    lineOptions(line){return line.type==='category'?this.options.categories:this.options.products},
    normalizeDiscounts(discounts){return(Array.isArray(discounts)?discounts:[]).map(item=>({label:String(item.label||''),type:item.type==='fixed'?'fixed':'percentage',value:item.value===undefined||item.value===null?'':item.value}))},
    addLine(){this.lines.push({key:Date.now()+Math.random(),type:'product',targetable_id:null,target_quantity:null,further_discounts:[],unit_id:null})},
    removeLine(index){this.lines.splice(index,1);if(!this.lines.length)this.addLine()},
    openDiscountModal(index){this.activeDiscountLineIndex=index;this.discountDraft=this.normalizeDiscounts(this.lines[index].further_discounts);this.$bvModal.show('target-further-discount-modal')},
    closeDiscountModal(){this.activeDiscountLineIndex=null;this.discountDraft=[]},
    addDiscount(){this.discountDraft.push({label:'',type:'percentage',value:''})},
    removeDiscount(index){this.discountDraft.splice(index,1)},
    discountSummary(discounts){return discounts.map(item=>item.label+': '+item.value+(item.type==='percentage'?'%':' fixed')).join(' | ')},
    saveDiscounts(){
      for(const item of this.discountDraft){const value=Number(item.value);if(!item.label||!Number.isFinite(value)||value<0||(item.type==='percentage'&&value>100)){this.$bvToast.toast('Enter a valid name and value for every discount. Percentages cannot exceed 100%.',{variant:'danger'});return}}
      if(this.activeDiscountLineIndex===null)return;
      this.$set(this.lines[this.activeDiscountLineIndex],'further_discounts',this.normalizeDiscounts(this.discountDraft));
      this.$bvModal.hide('target-further-discount-modal');
    },
    goStep(number){if(number<=this.maxStep)this.step=number},
    periodChanged(){
      if(!this.form.start_date)return;
      const start=new Date(this.form.start_date+'T00:00:00');
      if(this.form.period_type==='monthly'){
        this.form.start_date=[start.getFullYear(),String(start.getMonth()+1).padStart(2,'0'),'01'].join('-');
        const end=new Date(start.getFullYear(),start.getMonth()+1,0);
        this.form.end_date=[end.getFullYear(),String(end.getMonth()+1).padStart(2,'0'),String(end.getDate()).padStart(2,'0')].join('-');
      }else if(this.form.period_type==='quarterly'){
        const quarterStart=Math.floor(start.getMonth()/3)*3;
        this.form.start_date=[start.getFullYear(),String(quarterStart+1).padStart(2,'0'),'01'].join('-');
        const end=new Date(start.getFullYear(),quarterStart+3,0);
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
      this.lines=target.lines.map(line=>Object.assign({key:line.id},line,{further_discounts:this.normalizeDiscounts(line.further_discounts)}));
      this.allocations=target.allocations.map(row=>({warehouse_id:row.warehouse_id,warehouse:row.warehouse,allocated_quantity:Number(row.allocated_quantity)}));
      this.lineAllocations=(target.line_allocations||[]).map(row=>({supplier_target_line_id:row.supplier_target_line_id,warehouse_id:row.warehouse_id,allocated_quantity:Number(row.allocated_quantity)}));
      this.maxStep=target.lines.length?(target.allocations.length?3:3):2;
      if(this.$route.query.step)this.step=Math.min(Number(this.$route.query.step),this.maxStep);
    },
    syncAllocations(){
      const values=new Map(this.allocations.map(row=>[String(row.warehouse_id),row.allocated_quantity]));
      this.allocations=this.options.warehouses.map(warehouse=>({warehouse_id:warehouse.id,warehouse:warehouse.name,allocated_quantity:Number(values.get(String(warehouse.id))||0)}));
      const lineValues=new Map(this.lineAllocations.map(row=>[String(row.supplier_target_line_id)+':'+String(row.warehouse_id),row.allocated_quantity]));
      this.lineAllocations=[];
      this.lines.filter(line=>line.id).forEach(line=>this.options.warehouses.forEach(warehouse=>{
        const key=String(line.id)+':'+String(warehouse.id);
        this.lineAllocations.push({supplier_target_line_id:line.id,warehouse_id:warehouse.id,allocated_quantity:Number(lineValues.get(key)||0)});
      }));
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
    linePayload(){return this.lines.map(row=>({type:row.type,targetable_id:row.targetable_id,target_quantity:Number(row.target_quantity),further_discounts:this.normalizeDiscounts(row.further_discounts).map(item=>({label:item.label,type:item.type,value:Number(item.value)})),unit_id:row.unit_id||null}))},
    async saveLines(advance){
      this.saving=true;this.errors={};
      try{
        const response=await axios.put('targets/'+this.targetId+'/lines',{lines:this.linePayload()});
        this.lines=response.data.target.lines.map(line=>Object.assign({key:line.id},line,{further_discounts:this.normalizeDiscounts(line.further_discounts)}));
        this.$bvToast.toast(response.data.message,{variant:'success'});
        if(advance){this.maxStep=3;this.step=3;this.lineAllocations=[];this.syncAllocations()}
      }catch(error){this.capture(error)}finally{this.saving=false}
    },
    allocationFor(lineId,warehouseId){
      let row=this.lineAllocations.find(item=>String(item.supplier_target_line_id)===String(lineId)&&String(item.warehouse_id)===String(warehouseId));
      if(!row){row={supplier_target_line_id:lineId,warehouse_id:warehouseId,allocated_quantity:0};this.lineAllocations.push(row)}
      return row;
    },
    lineAllocatedTotal(lineId){return this.lineAllocations.filter(row=>String(row.supplier_target_line_id)===String(lineId)).reduce((sum,row)=>sum+Number(row.allocated_quantity||0),0)},
    historicalFor(lineId,warehouseId){return this.allocationHistory[String(lineId)+':'+String(warehouseId)]||null},
    lineComplete(line){return Math.abs(Number(line.target_quantity||0)-this.lineAllocatedTotal(line.id))<0.0001},
    allocationClass(line){return this.lineComplete(line)?'':'text-danger'},
    distribute(){
      const warehouses=this.options.warehouses;if(!warehouses.length)return;
      this.lines.forEach(line=>{
        const precision=1000,total=Math.round(Number(line.target_quantity||0)*precision),each=Math.floor(total/warehouses.length);
        let remainder=total-each*warehouses.length;
        warehouses.forEach(warehouse=>{this.allocationFor(line.id,warehouse.id).allocated_quantity=(each+(remainder-->0?1:0))/precision});
      });
      this.allocationHistory={};
      this.allocationNotice='Equal distribution applied. You can adjust any branch quantity manually before saving.';
    },
    async distributeBySales(){
      if(!this.targetId)return;
      this.allocationSuggestionBusy=true;
      try{
        const response=await axios.get('targets/'+this.targetId+'/allocation-suggestion');
        const suggestion=response.data.suggestion;
        const history={};
        suggestion.line_allocations.forEach(row=>{
          this.allocationFor(row.supplier_target_line_id,row.warehouse_id).allocated_quantity=Number(row.allocated_quantity);
          history[String(row.supplier_target_line_id)+':'+String(row.warehouse_id)]={quantity:Number(row.historical_quantity),share:Number(row.historical_share)};
        });
        this.allocationHistory=history;
        const fallback=suggestion.fallback_line_ids.length;
        this.allocationNotice='Allocation is based on net branch sales from '+suggestion.history_start+' to '+suggestion.history_end+'.'+(fallback?' '+fallback+' target line(s) had no sales history and were split equally.':'')+' You can adjust all quantities manually.';
      }catch(error){this.notifyError(error)}
      finally{this.allocationSuggestionBusy=false}
    },
    reset(){this.lineAllocations.forEach(row=>{row.allocated_quantity=0});this.allocationHistory={};this.allocationNotice='Allocation cleared. Enter decimal branch quantities manually or choose an automatic method.'},
    share(value){return this.totalTarget?Math.round(Number(value||0)/this.totalTarget*1000)/10:0},
    async saveAllocations(activate){
      this.saving=true;this.errors={};
      try{
        const response=await axios.put('targets/'+this.targetId+'/allocations',{line_allocations:this.lineAllocations.map(row=>({supplier_target_line_id:row.supplier_target_line_id,warehouse_id:row.warehouse_id,allocated_quantity:Number(row.allocated_quantity||0)}))});
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
