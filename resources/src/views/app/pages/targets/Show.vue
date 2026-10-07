<template>
  <div class='main-content targets-page'>
    <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
    <template v-else>
      <div class='targets-hero'><div><h2>{{ target.target_name }}</h2><p>{{ target.supplier }} | {{ title(target.period_type) }} | {{ target.start_date }} - {{ target.end_date }}</p></div><div><span class='target-status mr-2' :class='target.status'>{{ title(target.status) }}</span><button class='btn btn-light mr-2' @click='printReport'>Print</button><router-link v-if='editable' class='btn btn-outline-light' :to='editLink'>Edit</router-link></div></div>
      <div class='target-kpis'><div v-for='card in cards' :key='card.label' class='target-card target-kpi'><div class='target-kpi-icon'><lucide-icon :name='card.icon'/></div><div class='flex-grow-1'><div class='target-kpi-label'>{{ card.label }}</div><div class='target-kpi-value'>{{ fmt(card.value) }}{{ card.percent?'%':' Units' }}</div><div class='target-progress'><span :style='{width:width(card.progress)}'></span></div></div></div></div>
      <div class='target-grid'>
        <section class='target-card target-section'><div class='target-section-title'><h3>Target Lines</h3></div><div class='target-table-wrap'><table class='target-table'><thead><tr><th>Type</th><th>Name</th><th>Target</th><th>Achieved</th><th>Further Discounts</th><th>Accrued</th><th>Status</th></tr></thead><tbody><tr v-for='(line,index) in target.lines' :key='line.id'><td>{{ title(line.type) }}</td><td>{{ line.name }}</td><td>{{ fmt(line.target_quantity) }}</td><td>{{ fmt(metrics.lines[index] ? metrics.lines[index].achieved : 0) }}</td><td>{{ discountSummary(line.further_discounts) }}</td><td>{{ money(metrics.lines[index] ? metrics.lines[index].discount_earned : 0) }}</td><td><span v-if='metrics.lines[index]' class='target-status' :class='statusClass(metrics.lines[index].status)'>{{ metrics.lines[index].status }}</span></td></tr></tbody></table></div></section>
        <section class='target-card target-section'><div class='target-section-title'><h3>Warehouse Allocation</h3></div><div v-for='row in metrics.warehouses' :key='row.id' class='mb-3'><div class='d-flex justify-content-between'><strong>{{ row.name }}</strong><span>{{ row.percentage }}%</span></div><small>{{ fmt(row.achieved) }} / {{ fmt(row.target) }} units, {{ fmt(row.remaining) }} remaining</small><div class='target-progress mt-1'><span :style='{width:width(row.percentage)}'></span></div></div></section>
      </div>
      <section class='target-card target-section mb-3'><div class='target-section-title'><h3>Performance Timeline</h3></div><achievement-chart :points='metrics.monthly'/></section>
      <section class='target-card target-section mb-3'>
        <div class='target-section-title'><div><h3>Monthly Branch Plan</h3><small>Each branch/category allocation is divided exactly across the target months.</small></div></div>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Month</th><th>Quarter</th><th>Branch</th><th>Monthly Target</th><th>Achieved</th><th>Category Detail</th></tr></thead><tbody>
          <template v-for='month in metrics.monthly_plan'><tr v-for='branch in month.branches' :key='branchKey(month,branch)'><td>{{ month.label }}</td><td>{{ month.quarter }}</td><td>{{ branch.warehouse }}</td><td>{{ fmt(branch.target) }}</td><td>{{ fmt(branch.achieved) }}</td><td><span v-for='line in branch.lines' :key='line.line_id' class='d-block'>{{ line.name }}: {{ fmt(line.target) }} / {{ fmt(line.achieved) }}</span></td></tr></template>
        </tbody></table></div>
      </section>
      <section v-if='canLedger' class='target-card target-section mb-3'>
        <div class='target-section-title'><div><h3>Further Discount Ledger</h3><small>Accrues on every valid sold unit up to the target quantity; company postings may be entered in parts.</small></div></div>
        <div class='row mb-3'><div class='col-md-4'><div class='allocation-total'>Accrued: {{ money(metrics.discount.earned) }}</div></div><div class='col-md-4'><div class='allocation-total'>Posted: {{ money(metrics.discount.posted) }}</div></div><div class='col-md-4'><div class='allocation-total'>Balance: {{ money(metrics.discount.balance) }}</div></div></div>
        <form v-if='canPostDiscount' class='row align-items-end mb-3' @submit.prevent='postDiscount'><div class='form-group col-md-3'><label>Category / Product</label><select v-model='posting.supplier_target_line_id' class='form-control' required><option :value='null' disabled>Select</option><option v-for='line in discountLines' :key='line.id' :value='line.id'>{{ line.name }}</option></select></div><div class='form-group col-md-2'><label>Date</label><input v-model='posting.posting_date' type='date' class='form-control' required></div><div class='form-group col-md-2'><label>Posted Amount</label><input v-model.number='posting.amount' type='number' min='0.01' step='0.01' class='form-control' required></div><div class='form-group col-md-2'><label>Reference</label><input v-model='posting.reference' class='form-control'></div><div class='form-group col-md-2'><label>Notes</label><input v-model='posting.notes' class='form-control'></div><div class='form-group col-md-1'><button class='btn target-purple-btn' :disabled='postingBusy'>Post</button></div></form>
        <div class='target-table-wrap'><table class='target-table'><thead><tr><th>Date</th><th>Category</th><th>Amount</th><th>Reference</th><th>Posted By</th><th></th></tr></thead><tbody><tr v-for='row in target.discount_postings' :key='row.id'><td>{{ row.posting_date }}</td><td>{{ row.line_name }}</td><td>{{ money(row.amount) }}</td><td>{{ row.reference||'-' }}</td><td>{{ row.created_by||'-' }}</td><td><button v-if='canPostDiscount' class='btn btn-sm btn-link text-danger' @click='deletePosting(row)'>Delete</button></td></tr><tr v-if='!target.discount_postings.length'><td colspan='6' class='text-center text-muted'>No company discount has been posted yet.</td></tr></tbody></table></div>
      </section>
      <section class='target-card target-section'><div class='target-section-title'><h3>Audit History</h3></div><div v-if='!target.histories.length' class='text-muted'>No history recorded.</div><div v-for='history in target.histories' :key='history.id' class='border-bottom py-2'><strong>{{ title(history.event) }}</strong><br><small>{{ history.user||'System' }} - {{ dateTime(history.created_at) }}</small></div></section>
    </template>
  </div>
</template>
<script>
import AchievementChart from './AchievementChart.vue';
import '../../../../assets/styles/targets.scss';

export default {
  components:{AchievementChart},
  data(){return{loading:true,postingBusy:false,target:{lines:[],histories:[],discount_postings:[]},metrics:{target:0,achieved:0,remaining:0,percentage:0,lines:[],warehouses:[],monthly:[],monthly_plan:[],quarters:[],discount:{earned:0,posted:0,balance:0}},posting:{supplier_target_line_id:null,posting_date:new Date().toISOString().slice(0,10),amount:null,reference:'',notes:''}}},
  computed:{
    permissions(){return this.$store.getters.currentUserPermissions||[]},
    editable(){return this.permissions.includes('targets.edit')&&!['completed','cancelled'].includes(this.target.status)},
    canLedger(){return this.permissions.includes('targets.discount_ledger')},
    canPostDiscount(){return this.permissions.includes('targets.discount_postings')},
    discountLines(){return this.target.lines.filter(line=>(line.further_discounts||[]).length||Number(line.further_discount_per_unit||0)>0)},
    editLink(){return '/app/targets/edit/'+this.target.id+'?step=3'},
    cards(){return[{label:'Total Target',value:this.metrics.target,progress:100,icon:'target'},{label:'Achieved',value:this.metrics.achieved,progress:this.metrics.percentage,icon:'chart-no-axes-column'},{label:'Remaining',value:this.metrics.remaining,progress:100-this.metrics.percentage,icon:'chart-pie'},{label:'Achievement',value:this.metrics.percentage,progress:this.metrics.percentage,icon:'trophy',percent:true}]},
  },
  async created(){await this.load()},
  methods:{
    async load(){this.loading=true;try{const response=await axios.get('targets/'+this.$route.params.id);this.target=response.data.target;this.metrics=this.target.metrics||this.metrics}catch(error){this.$bvToast.toast('Unable to load target.',{variant:'danger'})}finally{this.loading=false}},
    title(value){value=String(value||'').replace(/_/g,' ');return value.charAt(0).toUpperCase()+value.slice(1)},
    fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},
    money(value){return Number(value||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})},
    discountSummary(discounts){return(discounts||[]).length?discounts.map(item=>item.label+': '+item.value+(item.type==='percentage'?'%':' fixed')).join(' | '):'None'},
    width(value){return Math.max(0,Math.min(Number(value||0),100))+'%'},
    statusClass(value){return String(value||'').toLowerCase().replace(/\s+/g,'-')},
    dateTime(value){return value?new Date(value).toLocaleString():'-'},
    branchKey(month,branch){return month.label+'-'+branch.warehouse_id},
    async postDiscount(){this.postingBusy=true;try{const response=await axios.post('targets/'+this.target.id+'/discount-postings',this.posting);this.$bvToast.toast(response.data.message,{variant:'success'});this.posting={supplier_target_line_id:null,posting_date:new Date().toISOString().slice(0,10),amount:null,reference:'',notes:''};await this.load()}catch(error){this.$bvToast.toast((error.response&&error.response.data&&error.response.data.message)||'Unable to record posting.',{variant:'danger'})}finally{this.postingBusy=false}},
    async deletePosting(row){const answer=await this.$swal({title:'Delete this posting?',icon:'warning',showCancelButton:true});if(!answer.isConfirmed)return;try{const response=await axios.delete('targets/'+this.target.id+'/discount-postings/'+row.id);this.$bvToast.toast(response.data.message,{variant:'success'});await this.load()}catch(error){this.$bvToast.toast('Unable to delete posting.',{variant:'danger'})}},
    async printReport(){try{const response=await axios.get('targets/report/print',{params:{target_id:this.target.id},responseType:'blob'});window.open(URL.createObjectURL(response.data),'_blank')}catch(error){this.$bvToast.toast('Unable to open report.',{variant:'danger'})}}
  }
};
</script>
