<template>
  <div class="main-content">
    <breadcumb page="Create Salaries" folder="Payroll" />
    <b-card>
      <b-progress :value="step" :max="4" class="mb-4" />
      <div v-if="step===1">
        <h4>1. Payroll period</h4>
        <b-row>
          <b-col md="2"><b-form-group label="Month"><b-form-select v-model.number="form.month" :options="months" /></b-form-group></b-col>
          <b-col md="2"><b-form-group label="Year"><b-form-input type="number" v-model.number="form.year" /></b-form-group></b-col>
          <b-col md="4"><b-form-group label="Branch"><v-select v-model="form.warehouse_id" :reduce="o=>o.value" :options="warehouses.map(w=>({label:w.name,value:w.id}))" /></b-form-group></b-col>
          <b-col md="2"><b-form-group label="Commission cutoff"><b-form-input type="date" v-model="form.commission_cutoff_date" /></b-form-group></b-col>
          <b-col md="2"><b-form-group label="Payment date"><b-form-input type="date" v-model="form.payment_date" /></b-form-group></b-col>
        </b-row>
        <b-form-group label="Note"><b-form-textarea v-model="form.note" /></b-form-group>
        <b-button variant="primary" :disabled="!validPeriod" @click="loadEmployees">Load Employees</b-button>
      </div>
      <div v-else-if="step===2 || step===3">
        <h4>{{ step }}. {{ step===2?'Payroll components':'Preview and confirm' }}</h4>
        <b-alert show variant="info">Only unlinked commissions are included. Historical salary, role, branch, profit, and rate snapshots are preserved.</b-alert>
        <b-table responsive striped :items="items" :fields="fields">
          <template #cell(basic_salary)="x">{{ money(x.value) }}</template>
          <template #cell(commission)="x">{{ money(x.value) }}</template>
          <template #cell(bonus)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.bonus" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(allowances)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.allowances" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(overtime)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.overtime" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(deductions)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.deductions" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(salary_advance)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.salary_advance" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(loan_recovery)="x"><b-form-input v-if="step===2" type="number" min="0" v-model.number="x.item.loan_recovery" @input="recalculate(x.item)" /><span v-else>{{money(x.value)}}</span></template>
          <template #cell(commission_adjustments)="x"><span :class="Number(x.value)<0?'text-danger':''">{{ money(x.value) }}</span></template>
          <template #cell(gross_salary)="x">{{ money(x.value) }}</template><template #cell(net_payable)="x"><strong>{{ money(x.value) }}</strong></template>
        </b-table>
        <div class="d-flex justify-content-between"><b-button @click="step--">Back</b-button><b-button variant="primary" @click="step===2?preview():generate()">{{ step===2?'Preview':'Generate Draft Payroll' }}</b-button></div>
      </div>
      <div v-else>
        <h4>4. Payroll generated</h4><b-alert show variant="success">Draft payroll {{ created.reference }} was generated successfully.</b-alert>
        <b-button variant="primary" @click="$router.push('/app/hrm/payrolls')">Return to Payroll</b-button>
      </div>
    </b-card>
  </div>
</template>
<script>
export default {
  data(){let d=new Date();return{step:1,warehouses:[],items:[],created:{},months:Array.from({length:12},(_,i)=>({value:i+1,text:new Date(2000,i).toLocaleString('default',{month:'long'})})),form:{month:d.getMonth()+1,year:d.getFullYear(),warehouse_id:null,commission_cutoff_date:new Date(d.getFullYear(),d.getMonth()+1,0).toISOString().slice(0,10),payment_date:new Date().toISOString().slice(0,10),note:''}}},
  computed:{validPeriod(){return this.form.month&&this.form.year&&this.form.warehouse_id&&this.form.commission_cutoff_date},fields(){return ['employee','role','branch','basic_salary','commission','bonus','allowances','overtime','deductions','salary_advance','loan_recovery','commission_adjustments','gross_salary','net_payable','status']}},
  methods:{
    money(v){return 'PKR '+Number(v||0).toLocaleString(undefined,{minimumFractionDigits:2,maximumFractionDigits:2})},
    payload(){return {...this.form,items:this.items.map(x=>({employee_id:x.employee_id,bonus:x.bonus,allowances:x.allowances,overtime:x.overtime,absence_deduction:x.absence_deduction,deductions:x.deductions,salary_advance:x.salary_advance,loan_recovery:x.loan_recovery}))}},
    async loadEmployees(){try{let {data}=await axios.post('payroll-management/preview',this.payload());this.items=data.items;this.step=2}catch(e){this.error(e)}},
    recalculate(x){x.gross_salary=Number(x.basic_salary)+Number(x.commission)+Number(x.bonus||0)+Number(x.allowances||0)+Number(x.overtime||0);x.total_deductions=Number(x.absence_deduction||0)+Number(x.deductions||0)+Number(x.salary_advance||0)+Number(x.loan_recovery||0)+Math.abs(Math.min(0,Number(x.commission_adjustments||0)));x.net_payable=Math.max(0,x.gross_salary-x.total_deductions)},
    async preview(){try{let {data}=await axios.post('payroll-management/preview',this.payload());this.items=data.items;this.step=3}catch(e){this.error(e)}},
    async generate(){let r=await this.$swal({title:'Generate draft payroll?',text:'Eligible commission entries will be locked to this payroll.',type:'warning',showCancelButton:true});if(!r.value)return;try{let {data}=await axios.post('payroll-management/periods',this.payload());this.created=data.period;this.step=4}catch(e){this.error(e)}},
    error(e){let x=e.response&&e.response.data&&e.response.data.errors;this.$root.$bvToast.toast(x?Object.values(x).flat().join(' '):'Unable to complete the request.',{title:'Failed',variant:'danger',solid:true})}
  },
  async created(){let {data}=await axios.get('payroll-management/meta');this.warehouses=data.warehouses}
}
</script>
