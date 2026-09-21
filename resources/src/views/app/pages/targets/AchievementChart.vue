<template>
  <div v-if="points.length" class="target-achievement-chart" role="img" :aria-label="description">
    <div class="target-chart-legend">
      <span><i class="target-chart-key target-chart-key-target"></i>Target</span>
      <span><i class="target-chart-key target-chart-key-achieved"></i>Achieved</span>
    </div>
    <div class="target-chart-scroll">
      <div class="target-chart-body">
        <div class="target-chart-axis">
          <span v-for="tick in ticks" :key="tick">{{ fmt(tick) }}</span>
        </div>
        <div class="target-chart-main">
          <div class="target-chart-plot">
            <div v-for="index in 6" :key="index" class="target-chart-gridline" :style="{ bottom: ((index - 1) * 20) + '%' }"></div>
            <div class="target-chart-columns" :style="columnsStyle">
              <div v-for="(point, index) in points" :key="index" class="target-chart-column">
                <span class="target-chart-bar target-chart-bar-target" :style="{ height: barHeight(point.target) }" :title="point.label + ' target: ' + fmt(point.target) + ' units'"></span>
                <span class="target-chart-bar target-chart-bar-achieved" :style="{ height: barHeight(point.achieved) }" :title="point.label + ' achieved: ' + fmt(point.achieved) + ' units'"></span>
              </div>
            </div>
          </div>
          <div class="target-chart-labels" :style="columnsStyle">
            <span v-for="(point, index) in points" :key="index">{{ point.label }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div v-else class="text-muted p-4">No achievement data for these filters.</div>
</template>

<script>
export default {
  props: {
    points: { type: Array, default: () => [] }
  },
  computed: {
    columnsStyle() { return { gridTemplateColumns: `repeat(${this.points.length}, minmax(0, 1fr))` } },
    scale() {
      const highest = Math.max(0, ...this.points.map(point => Math.max(Number(point.target) || 0, Number(point.achieved) || 0)));
      if (!highest) return 5;
      const rawStep = highest / 5;
      const magnitude = Math.pow(10, Math.floor(Math.log10(rawStep)));
      const rounded = [1, 2, 2.5, 5, 10].find(step => step * magnitude >= rawStep);
      return rounded * magnitude * 5;
    },
    ticks() { return Array.from({ length: 6 }, (_, index) => this.scale * (5 - index) / 5) },
    description() {
      return this.points.map(point => `${point.label}: target ${this.fmt(point.target)} units, achieved ${this.fmt(point.achieved)} units`).join('; ');
    }
  },
  methods: {
    barHeight(value) { return Math.max(0, Math.min(100, Number(value || 0) / this.scale * 100)) + '%' },
    fmt(value) { return Number(value || 0).toLocaleString(undefined, { maximumFractionDigits: 3 }) }
  }
};
</script>
