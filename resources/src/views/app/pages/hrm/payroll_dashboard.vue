<template>
  <div class="main-content">
    <breadcumb page="Payroll" :folder="$t('hrm')" />
    <b-tabs card>
      <b-tab title="Monthly Payroll" active @click="loadPeriods">
        <div class="d-flex flex-wrap justify-content-between mb-3">
          <div>
            <b-form-select v-model="filters.warehouse_id" :options="warehouseOptions" class="mr-2" @change="loadPeriods" />
            <b-form-select v-model="filters.status" :options="statusOptions" @change="loadPeriods" />
          </div>
          <b-button variant="primary" @click="$router.push('/app/hrm/payrolls/create')"><lucide-icon name="plus" /> Create Salaries</b-button>
        </div>
        <b-row class="mb-3">
          <b-col v-for="card in payrollCards" :key="card.label" md="3" class="mb-2">
            <b-card><small class="text-muted">{{ card.label }}</small><h5 class="mb-0">{{ money(card.value) }}</h5></b-card>
          </b-col>
        </b-row>
        <div v-if="loading" class="loading_page spinner spinner-primary"></div>
        <b-card v-else>
          <div v-for="period in periods" :key="period.id" class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <div><strong>{{ period.reference }}</strong> � {{ period.warehouse.name }} � {{ period.month }}/{{ period.year }}
                <span class="badge ml-2" :class="badge(period.status)">{{ pretty(period.status) }}</span>
              </div>
              <b-button v-if="period.status === 'draft' && can('payroll_approve')" size="sm" variant="success" @click="approve(period)">Approve</b-button>
            </div>
            <b-table small responsive striped :items="period.items" :fields="payrollFields">
              <template #cell(employee)="x">{{ x.item.employee.username }}</template>
              <template #cell(role)="x">{{ x.item.designation.designation }}</template>
              <template #cell(net_payable)="x">{{ money(x.item.net_payable) }}</template>
              <template #cell(remaining_amount)="x">{{ money(x.item.remaining_amount) }}</template>
              <template #cell(status)="x"><span class="badge" :class="badge(x.value)">{{ pretty(x.value) }}</span></template>
              <template #cell(actions)="x">
                <b-button size="sm" variant="outline-info" class="mr-1" @click="showDetail(x.item)">Details</b-button>
                <b-button v-if="can('payroll_pay') && Number(x.item.remaining_amount) > 0 && ['approved_unpaid','partially_paid'].includes(x.item.status)" size="sm" variant="primary" @click="openPay(x.item)">Pay</b-button>
                <b-button v-if="can('payroll_payslip_print')" size="sm" variant="outline-secondary" class="ml-1" @click="downloadPayslip(x.item)">Payslip</b-button>
              </template>
            </b-table>
          </div>
          <b-alert v-if="!periods.length" show variant="info">No generated payroll periods match these filters.</b-alert>
          <div v-if="legacy.length"><h5>Legacy payroll records</h5><b-alert show variant="secondary">These historical instant-payment records are preserved read-only.</b-alert>
            <b-table small :items="legacy" :fields="['Ref','date','employee','amount','payment_status']">
              <template #cell(employee)="x">{{ x.item.employee && x.item.employee.username }}</template>
            </b-table>
          </div>
        </b-card>
      </b-tab>

      <b-tab title="Employee Basic Salaries" @click="loadSalaries">
        <div class="text-right mb-3"><b-button variant="primary" @click="salaryModal=true">Add / Set Basic Salary</b-button></div>
        <b-table responsive striped :items="salaries" :fields="salaryFields">
          <template #cell(basic_salary)="x">{{ money(x.value) }}</template>
          <template #cell(employment_status)="x"><span class="badge" :class="x.value==='active'?'badge-success':'badge-secondary'">{{ x.value }}</span></template>
          <template #cell(actions)="x"><b-button size="sm" @click="editSalary(x.item)">Set new salary</b-button></template>
        </b-table>
      </b-tab>

      <b-tab title="Commission Rules" @click="loadRules">
        <b-alert v-if="impact.exceeds_100" show variant="warning">Combined employee allocation exceeds 100% in at least one branch. Explicit confirmation is required.</b-alert>
        <b-form-group label="Effective from"><b-form-input type="date" v-model="ruleEffectiveFrom" class="w-auto" /></b-form-group>
        <b-table responsive :items="rules" :fields="ruleFields">
          <template #cell(almadina_percentage)="x"><b-form-input type="number" min="0" step="0.01" v-model.number="x.item.almadina_percentage" @input="previewImpact" /></template>
          <template #cell(wholesale_percentage)="x"><b-form-input type="number" min="0" step="0.01" v-model.number="x.item.wholesale_percentage" @input="previewImpact" /></template>
          <template #cell(minimum_percentage)="x"><b-form-input type="number" min="0" step="0.01" v-model.number="x.item.minimum_percentage" @input="previewImpact" /></template>
          <template #cell(is_active)="x"><b-form-checkbox v-model="x.item.is_active" switch /></template>
        </b-table>
        <div v-if="impact.branches && impact.branches.length" class="mb-3">
          <h5>Branch impact preview</h5>
          <b-table small responsive :items="impact.branches" :fields="impactFields">
            <template #cell(almadina_total)="x">{{ percent(x.value) }}</template><template #cell(wholesale_total)="x">{{ percent(x.value) }}</template><template #cell(minimum_total)="x">{{ percent(x.value) }}</template>
          </b-table>
        </div>
        <b-button variant="primary" :disabled="saving" @click="saveRules">Save Commission Rules</b-button>
      </b-tab>

      <b-tab title="Commission Ledger" @click="loadLedger">
        <b-row class="mb-3">
          <b-col v-for="card in ledgerCards" :key="card.label" md="2"><b-card><small>{{ card.label }}</small><div>{{ money(card.value) }}</div></b-card></b-col>
        </b-row>
        <b-row class="mb-3">
          <b-col md="3"><b-form-select v-model="ledgerFilters.warehouse_id" :options="warehouseOptions" @change="loadLedger" /></b-col>
          <b-col md="3"><b-form-select v-model="ledgerFilters.price_type" :options="priceTypeOptions" @change="loadLedger" /></b-col>
          <b-col md="3"><b-form-select v-model="ledgerFilters.status" :options="ledgerStatusOptions" @change="loadLedger" /></b-col>
          <b-col md="3"><b-form-input v-model="ledgerFilters.search" placeholder="Sale reference" @keyup.enter="loadLedger" /></b-col>
        </b-row>
        <b-table responsive striped :items="ledger" :fields="ledgerFields">
          <template #cell(sale)="x">{{ x.item.sale && x.item.sale.Ref }}</template>
          <template #cell(branch)="x">{{ x.item.warehouse.name }}</template><template #cell(employee)="x">{{ x.item.employee.username }}</template>
          <template #cell(role)="x">{{ x.item.designation.designation }}</template><template #cell(product)="x">{{ x.item.product.name }}</template>
          <template #cell(commission_amount)="x">{{ money(x.value) }}</template><template #cell(status)="x"><span class="badge" :class="badge(x.value)">{{ pretty(x.value) }}</span></template>
        </b-table>
      </b-tab>
    </b-tabs>

    <b-modal v-model="salaryModal" title="Set Basic Salary" @ok.prevent="saveSalary">
      <b-form-group label="Employee"><v-select v-model="salary.employee_id" :reduce="o=>o.value" :options="employees.map(e=>({label:e.username,value:e.id,designation_id:e.designation_id}))" @input="selectSalaryEmployee" /></b-form-group>
      <b-form-group label="Branch"><v-select v-model="salary.warehouse_id" :reduce="o=>o.value" :options="warehouses.map(w=>({label:w.name,value:w.id}))" /></b-form-group>
      <b-form-group label="Role"><v-select v-model="salary.designation_id" :reduce="o=>o.value" :options="designations.map(r=>({label:r.designation,value:r.id}))" /></b-form-group>
      <b-form-group label="Monthly basic salary"><b-form-input type="number" min="0" step="0.01" v-model.number="salary.monthly_salary" /></b-form-group>
      <b-form-group label="Effective from"><b-form-input type="date" v-model="salary.effective_from" /></b-form-group>
      <b-form-group label="Effective to (optional)"><b-form-input type="date" v-model="salary.effective_to" /></b-form-group>
      <b-form-checkbox v-model="salary.is_active" switch>Active</b-form-checkbox>
    </b-modal>

    <b-modal v-model="payModal" title="Pay Payroll" @ok.prevent="submitPayment">
      <p><strong>{{ selected.employee && selected.employee.username }}</strong> � Remaining {{ money(selected.remaining_amount) }}</p>
      <b-form-group label="Amount"><b-form-input type="number" min="0.01" :max="selected.remaining_amount" v-model.number="payment.amount" /></b-form-group>
      <b-form-group label="Payment date"><b-form-input type="date" v-model="payment.payment_date" /></b-form-group>
      <b-form-group label="Payment method"><v-select v-model="payment.payment_method_id" :reduce="o=>o.value" :options="paymentMethods.map(m=>({label:m.name,value:m.id}))" /></b-form-group>
      <b-form-group label="Payment account"><v-select v-model="payment.account_id" :reduce="o=>o.value" :options="accounts.map(a=>({label:a.account_name,value:a.id}))" /></b-form-group>
      <b-form-group label="Transaction/reference"><b-form-input v-model="payment.transaction_reference" /></b-form-group>
      <b-form-group label="Note"><b-form-textarea v-model="payment.note" /></b-form-group>
    </b-modal>

    <b-modal v-model="detailModal" size="xl" title="Payroll Details" hide-footer>
      <div v-if="detail.employee">
        <h5>{{ detail.employee.username }} � {{ detail.period.warehouse.name }} � {{ detail.designation.designation }}</h5>
        <b-row><b-col>Gross: {{ money(detail.gross_salary) }}</b-col><b-col>Deductions: {{ money(detail.total_deductions) }}</b-col><b-col>Net: {{ money(detail.net_payable) }}</b-col><b-col>Remaining: {{ money(detail.remaining_amount) }}</b-col></b-row>
        <h6 class="mt-3">Commission breakdown</h6>
        <b-table small responsive :items="(detail.commission_links||[]).map(l=>l.entry)" :fields="['commission_date','price_type','quantity','stored_profit_per_unit','commission_percentage','commission_amount','status']" />
        <h6>Payments / audit trail</h6><b-table small :items="detail.payments" :fields="['payment_date','reference','amount','processed_by','processed_at']" />
      </div>
    </b-modal>
  </div>
</template>

<script>
import NProgress from 'nprogress';
export default {
  data: () => ({
    loading:false,saving:false,periods:[],legacy:[],salaries:[],rules:[],ledger:[],ledgerSummary:{},impact:{branches:[],exceeds_100:false},impactTimer:null,
    warehouses:[],employees:[],designations:[],accounts:[],paymentMethods:[],permissions:[],
    filters:{warehouse_id:'',status:''},ledgerFilters:{warehouse_id:'',price_type:'',status:'',search:''},
    salaryModal:false,payModal:false,detailModal:false,selected:{},detail:{},ruleEffectiveFrom:new Date().toISOString().slice(0,10),
    salary:{employee_id:null,warehouse_id:null,designation_id:null,monthly_salary:0,effective_from:new Date().toISOString().slice(0,10),effective_to:null,is_active:true},
    payment:{amount:0,payment_date:new Date().toISOString().slice(0,10),payment_method_id:null,account_id:null,transaction_reference:'',note:''}
  }),
  computed:{
    warehouseOptions(){return [{value:'',text:'All branches'}].concat(this.warehouses.map(x=>({value:x.id,text:x.name})))},
    statusOptions(){return [{value:'',text:'All statuses'},...['draft','approved_unpaid','partially_paid','paid','cancelled'].map(x=>({value:x,text:this.pretty(x)}))]},
    priceTypeOptions(){return [{value:'',text:'All price types'},...['almadina','wholesale','minimum'].map(x=>({value:x,text:this.pretty(x)}))]},
    ledgerStatusOptions(){return [{value:'',text:'All statuses'},...['accrued','included_in_payroll','paid','partially_reversed','reversed','cancelled'].map(x=>({value:x,text:this.pretty(x)}))]},
    payrollFields(){return ['employee','role','basic_salary','commission','gross_salary','total_deductions','net_payable','paid_amount','remaining_amount','status','actions']},
    salaryFields(){return ['employee','employee_code','branch','role','basic_salary','effective_from','effective_to','employment_status','actions']},
    ruleFields(){return ['role','almadina_percentage','wholesale_percentage','minimum_percentage','is_active','effective_from']},
    impactFields(){return ['branch','almadina_total','wholesale_total','minimum_total']},
    ledgerFields(){return ['commission_date','sale','branch','employee','role','product','quantity','price_type','stored_profit_per_unit','total_applicable_profit','commission_percentage','commission_amount','status']},
    payrollCards(){let items=this.periods.flatMap(p=>p.items||[]); return [
      {label:'Total employees',value:items.length},{label:'Basic salaries',value:items.reduce((a,x)=>a+Number(x.basic_salary),0)},
      {label:'Commissions',value:items.reduce((a,x)=>a+Number(x.commission),0)},{label:'Net payroll',value:items.reduce((a,x)=>a+Number(x.net_payable),0)},
      {label:'Paid amount',value:items.reduce((a,x)=>a+Number(x.paid_amount),0)},{label:'Outstanding',value:items.reduce((a,x)=>a+Number(x.remaining_amount),0)}]},
    ledgerCards(){return [{label:'Today',value:this.ledgerSummary.today},{label:'Current month',value:this.ledgerSummary.current_month},{label:'Accrued',value:this.ledgerSummary.accrued},{label:'In payroll',value:this.ledgerSummary.included},{label:'Paid',value:this.ledgerSummary.paid},{label:'Reversed',value:this.ledgerSummary.reversed}]}
  },
  methods:{
    can(p){return this.permissions.includes(p)},money(v){return 'PKR '+Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})},percent(v){return Number(v||0).toFixed(2)+'%'},pretty(v){return String(v||'').replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase())},
    badge(s){return ['paid','active'].includes(s)?'badge-success':s==='draft'?'badge-secondary':s==='approved_unpaid'?'badge-info':String(s).includes('revers')?'badge-danger':'badge-warning'},
    async meta(){let {data}=await axios.get('payroll-management/meta');Object.assign(this,{warehouses:data.warehouses,employees:data.employees,designations:data.designations,accounts:data.accounts,paymentMethods:data.payment_methods,permissions:data.permissions})},
    async loadPeriods(){this.loading=true;NProgress.start();try{let {data}=await axios.get('payroll-management/periods',{params:this.filters});this.periods=data.periods.data;this.legacy=data.legacy_payrolls}finally{this.loading=false;NProgress.done()}},
    async loadSalaries(){let {data}=await axios.get('payroll-management/salaries',{params:{limit:100}});this.salaries=data.data},
    async loadRules(){let {data}=await axios.get('payroll-management/commission-rules');this.rules=data;this.previewImpact()},
    previewImpact(){clearTimeout(this.impactTimer);this.impactTimer=setTimeout(async()=>{if(!this.rules.length)return;let rules=this.rules.map(rule=>({...rule,almadina_percentage:Number(rule.almadina_percentage)||0,wholesale_percentage:Number(rule.wholesale_percentage)||0,minimum_percentage:Number(rule.minimum_percentage)||0}));try{let {data}=await axios.post('payroll-management/commission-rules/impact',{rules});this.impact=data}catch(e){this.error(e)}},250)},
    async saveRules(){this.saving=true;try{if(this.impact.exceeds_100){let ok=await this.$swal({title:'Allocation exceeds 100%',text:'Each employee receives the full role percentage. Save anyway?',type:'warning',showCancelButton:true});if(!ok.value)return}await axios.post('payroll-management/commission-rules',{effective_from:this.ruleEffectiveFrom,rules:this.rules,confirm_overallocation:this.impact.exceeds_100});this.toast('success','Commission rules saved');await this.loadRules()}catch(e){this.error(e)}finally{this.saving=false}},
    async loadLedger(){let {data}=await axios.get('payroll-management/commission-ledger',{params:{...this.ledgerFilters,limit:100}});this.ledger=data.entries.data;this.ledgerSummary=data.summary},
    selectSalaryEmployee(id){let e=this.employees.find(x=>x.id===id);if(e)this.salary.designation_id=e.designation_id},
    editSalary(row){this.salary={employee_id:row.employee_id,warehouse_id:row.warehouse_id,designation_id:row.designation_id,monthly_salary:row.basic_salary,effective_from:new Date().toISOString().slice(0,10),effective_to:null,is_active:true};this.salaryModal=true},
    async saveSalary(){try{await axios.post('payroll-management/salaries',this.salary);this.salaryModal=false;this.toast('success','Salary history updated');this.loadSalaries()}catch(e){this.error(e)}},
    async approve(p){let r=await this.$swal({title:'Approve payroll?',text:'Financial values will be locked after approval.',type:'warning',showCancelButton:true});if(r.value){await axios.post('payroll-management/periods/'+p.id+'/approve');this.loadPeriods()}},
    openPay(item){this.selected=item;this.payment={amount:Number(item.remaining_amount),payment_date:new Date().toISOString().slice(0,10),payment_method_id:null,account_id:null,transaction_reference:'',note:''};this.payModal=true},
    async submitPayment(){try{let {data}=await axios.post('payroll-management/items/'+this.selected.id+'/pay',this.payment);for(let p of this.periods){let i=p.items.findIndex(x=>x.id===data.item.id);if(i>=0)this.$set(p.items,i,data.item)}this.payModal=false;this.toast('success','Payment recorded')}catch(e){this.error(e)}},
    async showDetail(item){let {data}=await axios.get('payroll-management/items/'+item.id);this.detail=data;this.detailModal=true},
    async downloadPayslip(item){let {data}=await axios.get('payroll-management/items/'+item.id+'/payslip',{responseType:'blob'});let u=URL.createObjectURL(data),a=document.createElement('a');a.href=u;a.download='payslip-'+item.id+'.pdf';a.click();URL.revokeObjectURL(u)},
    toast(v,m){this.$root.$bvToast.toast(m,{title:v==='success'?'Success':'Failed',variant:v,solid:true})},
    error(e){let errors=e.response&&e.response.data&&e.response.data.errors;this.toast('danger',errors?Object.values(errors).flat().join(' '):'Unable to complete the request.')}
  },
  async created(){await this.meta();await this.loadPeriods()},
  beforeDestroy(){clearTimeout(this.impactTimer)}
}
</script>
