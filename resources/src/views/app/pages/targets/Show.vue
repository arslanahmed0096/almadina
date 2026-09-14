<template>
  <div class='main-content targets-page'>
    <div v-if='loading' class='text-center p-5'><div class='spinner-border text-primary'></div></div>
    <template v-else>
      <div class='targets-hero'><div><h2>{{ target.target_name }}</h2><p>{{ target.supplier }} | {{ title(target.period_type) }} | {{ target.start_date }} - {{ target.end_date }}</p></div><div><span class='target-status mr-2' :class='target.status'>{{ title(target.status) }}</span><button class='btn btn-light mr-2' @click='printReport'>Print</button><router-link v-if='editable' class='btn btn-outline-light' :to='editLink'>Edit</router-link></div></div>
      <div class='target-kpis'><div v-for='card in cards' :key='card.label' class='target-card target-kpi'><div class='target-kpi-icon'><lucide-icon :name='card.icon'/></div><div class='flex-grow-1'><div class='target-kpi-label'>{{ card.label }}</div><div class='target-kpi-value'>{{ fmt(card.value) }}{{ card.percent?'%':' Units' }}</div><div class='target-progress'><span :style='{width:width(card.progress)}'></span></div></div></div></div>
      <div class='target-grid'>
        <section class='target-card target-section'><div class='target-section-title'><h3>Target Lines</h3></div><div class='target-table-wrap'><table class='target-table'><thead><tr><th>Type</th><th>Name</th><th>Unit</th><th>Target</th><th>Achieved</th><th>Status</th></tr></thead><tbody><tr v-for='(line,index) in target.lines' :key='line.id'><td>{{ title(line.type) }}</td><td>{{ line.name }}</td><td>{{ line.unit||'Units' }}</td><td>{{ fmt(line.target_quantity) }}</td><td>{{ fmt(metrics.lines[index] ? metrics.lines[index].achieved : 0) }}</td><td><span v-if='metrics.lines[index]' class='target-status' :class='statusClass(metrics.lines[index].status)'>{{ metrics.lines[index].status }}</span></td></tr></tbody></table></div></section>
        <section class='target-card target-section'><div class='target-section-title'><h3>Warehouse Allocation</h3></div><div v-for='row in metrics.warehouses' :key='row.id' class='mb-3'><div class='d-flex justify-content-between'><strong>{{ row.name }}</strong><span>{{ row.percentage }}%</span></div><small>{{ fmt(row.achieved) }} / {{ fmt(row.target) }} units, {{ fmt(row.remaining) }} remaining</small><div class='target-progress mt-1'><span :style='{width:width(row.percentage)}'></span></div></div></section>
      </div>
      <section class='target-card target-section mb-3'><div class='target-section-title'><h3>Performance Timeline</h3></div><apexchart height='250' type='bar' :options='chartOptions' :series='chartSeries'/></section>
      <section class='target-card target-section'><div class='target-section-title'><h3>Audit History</h3></div><div v-if='!target.histories.length' class='text-muted'>No history recorded.</div><div v-for='history in target.histories' :key='history.id' class='border-bottom py-2'><strong>{{ title(history.event) }}</strong><br><small>{{ history.user||'System' }} - {{ dateTime(history.created_at) }}</small></div></section>
    </template>
  </div>
</template>
<script>
import VueApexCharts from 'vue-apexcharts';
import '../../../../assets/styles/targets.scss';

export default {
  components:{apexchart:VueApexCharts},
  data(){return{loading:true,target:{lines:[],histories:[]},metrics:{target:0,achieved:0,remaining:0,percentage:0,lines:[],warehouses:[],monthly:[]}}},
  computed:{
    permissions(){return this.$store.getters.currentUserPermissions||[]},
    editable(){return this.permissions.includes('targets.edit')&&!['completed','cancelled'].includes(this.target.status)},
    editLink(){return '/app/targets/edit/'+this.target.id+'?step=3'},
    cards(){return[{label:'Total Target',value:this.metrics.target,progress:100,icon:'target'},{label:'Achieved',value:this.metrics.achieved,progress:this.metrics.percentage,icon:'chart-no-axes-column'},{label:'Remaining',value:this.metrics.remaining,progress:100-this.metrics.percentage,icon:'chart-pie'},{label:'Achievement',value:this.metrics.percentage,progress:this.metrics.percentage,icon:'trophy',percent:true}]},
    chartSeries(){return[{name:'Target',data:this.metrics.monthly.map(x=>x.target)},{name:'Achieved',data:this.metrics.monthly.map(x=>x.achieved)}]},
    chartOptions(){return{chart:{toolbar:{show:false}},colors:['#7938d4','#cab6f4'],xaxis:{categories:this.metrics.monthly.map(x=>x.label)},dataLabels:{enabled:false}}}
  },
  async created(){try{const response=await axios.get('targets/'+this.$route.params.id);this.target=response.data.target;this.metrics=this.target.metrics||this.metrics}catch(error){this.$bvToast.toast('Unable to load target.',{variant:'danger'})}finally{this.loading=false}},
  methods:{title(value){value=String(value||'').replace(/_/g,' ');return value.charAt(0).toUpperCase()+value.slice(1)},fmt(value){return Number(value||0).toLocaleString(undefined,{maximumFractionDigits:3})},width(value){return Math.max(0,Math.min(Number(value||0),100))+'%'},statusClass(value){return String(value||'').toLowerCase().replace(/\s+/g,'-')},dateTime(value){return value?new Date(value).toLocaleString():'-'},async printReport(){try{const response=await axios.get('targets/report/print',{params:{target_id:this.target.id},responseType:'blob'});window.open(URL.createObjectURL(response.data),'_blank')}catch(error){this.$bvToast.toast('Unable to open report.',{variant:'danger'})}}}
};
</script>
