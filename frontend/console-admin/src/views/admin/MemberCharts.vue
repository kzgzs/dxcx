<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import * as echarts from 'echarts'
import { getRelationChart, getRelationMore } from '../../api/memberGroup'
import type { RelationNode } from '../../api/memberGroup'

// ===== 查询条件 =====
const midInput = ref('')
const keyword = ref('')
const loading = ref(false)
const deeplevel = ref(0)
const rootInfo = ref<RelationNode | null>(null)

const chartRef = ref<HTMLDivElement>()
let chart: echarts.ECharts | null = null

interface ChartNode {
  name: string
  raw?: RelationNode
  symbol?: string
  symbolSize?: number
  itemStyle?: Record<string, unknown>
  label?: Record<string, unknown>
  children?: ChartNode[]
  _loadMore?: boolean
  collapsed?: boolean
}

// 后端节点 → ECharts 树节点（头像图片节点；hasMore 挂「加载更多」占位子节点）
function toChartNode(node: RelationNode, depth: number): ChartNode {
  const item: ChartNode = {
    name: node.name,
    raw: node,
    symbol: node.headimg ? `image://${node.headimg}` : 'circle',
    symbolSize: 36,
    itemStyle: {
      color: node.hasMore ? '#e6a23c' : '#409eff',
      borderColor: node.hasMore ? '#e6a23c' : '#409eff',
    },
  }

  const children: ChartNode[] = node.children.map((c) => toChartNode(c, depth + 1))
  if (node.hasMore) {
    children.push({
      name: '加载更多…',
      _loadMore: true,
      raw: node,
      symbol: 'roundRect',
      symbolSize: 14,
      itemStyle: { color: '#e6a23c', borderColor: '#e6a23c' },
    })
  }
  if (children.length) {
    item.children = children
    // 超过 2 层默认收起，避免一次性渲染过大
    if (depth >= 2) item.collapsed = true
  }
  return item
}

function buildOption(root: ChartNode) {
  return {
    tooltip: {
      trigger: 'item' as const,
      formatter: (params: { data?: ChartNode }) => {
        const raw = params.data?.raw
        if (!raw) return params.data?.name || ''
        return [
          `<b>${raw.name}</b>（ID: ${raw.id}）`,
          raw.level_name ? `等级：${raw.level_name}` : '',
          raw.tel ? `手机：${raw.tel}` : '',
          `直推：${raw.downcount}　团队：${raw.downcountall}`,
        ]
          .filter(Boolean)
          .join('<br/>')
      },
    },
    series: [
      {
        type: 'tree' as const,
        layout: 'orthogonal' as const,
        orient: 'TB' as const,
        data: [root],
        top: '2%',
        bottom: '20%',
        left: '6%',
        right: '6%',
        symbol: 'circle',
        symbolSize: 36,
        edgeShape: 'polyline' as const,
        initialTreeDepth: 2,
        expandAndCollapse: true,
        roam: true,
        lineStyle: { color: '#c0c4cc', width: 1.2 },
        label: {
          position: 'bottom' as const,
          verticalAlign: 'top' as const,
          align: 'center' as const,
          padding: [6, 0, 0, 0],
          formatter: (params: { data?: ChartNode }) => {
            if (params.data?._loadMore) return `{more|${params.data.name}}`
            const raw = params.data?.raw
            if (!raw) return params.data?.name || ''
            const sub = [raw.level_name, `直推${raw.downcount}`].filter(Boolean).join(' · ')
            return `{name|${raw.name}}\n{sub|${sub}}`
          },
          rich: {
            name: { fontSize: 13, color: '#303133', fontWeight: 'bold' as const, lineHeight: 18 },
            sub: { fontSize: 11, color: '#909399', lineHeight: 16 },
            more: { fontSize: 12, color: '#e6a23c' },
          },
        },
        leaves: {
          label: { position: 'bottom' as const, verticalAlign: 'top' as const, align: 'center' as const },
        },
        emphasis: { focus: 'descendant' as const },
        animationDuration: 300,
        animationDurationUpdate: 300,
      },
    ],
  }
}

async function onQuery() {
  const mid = Number(midInput.value || 0)
  if (!mid && !keyword.value.trim()) {
    ElMessage.warning('请输入会员ID或昵称关键字')
    return
  }
  loading.value = true
  try {
    const res = await getRelationChart({ mid: mid || undefined, keyword: keyword.value.trim() || undefined })
    deeplevel.value = res.deeplevel
    rootInfo.value = res.root
    if (!res.root) {
      ElMessage.warning('未找到匹配的会员')
      chart?.clear()
      return
    }
    chart?.setOption(buildOption(toChartNode(res.root, 0)), true)
  } catch {
    // 拦截器已提示
  } finally {
    loading.value = false
  }
}

// 在树数据中按会员ID定位节点
function findNode(root: ChartNode | undefined, id: number): ChartNode | null {
  if (!root) return null
  if (root.raw?.id === id && !root._loadMore) return root
  for (const child of root.children || []) {
    const hit = findNode(child, id)
    if (hit) return hit
  }
  return null
}

async function onChartClick(params: { data?: ChartNode }) {
  const data = params.data
  if (!data?._loadMore || !data.raw) return

  const target = data.raw
  try {
    const res = await getRelationMore(target.id)
    const seriesData = chart?.getOption().series as Array<{ data: ChartNode[] }> | undefined
    const root = seriesData?.[0]?.data?.[0]
    const node = findNode(root, target.id)
    if (!node || !res.node) return

    // 替换占位节点为真实子树
    node.children = res.node.children.map((c) => toChartNode(c, (node.raw?.deeplevel ?? 0) + 1))
    if (res.node.hasMore) {
      node.children.push({
        name: '加载更多…',
        _loadMore: true,
        raw: res.node,
        symbol: 'roundRect',
        symbolSize: 14,
        itemStyle: { color: '#e6a23c', borderColor: '#e6a23c' },
      })
    }
    node.itemStyle = { color: '#409eff', borderColor: '#409eff' }
    node.collapsed = false
    chart?.setOption({ series: [{ data: [root] }] })
    ElMessage.success(`已加载「${target.name}」的下级关系`)
  } catch {
    // 拦截器已提示
  }
}

function initChart() {
  if (!chartRef.value) return
  chart = echarts.init(chartRef.value)
  chart.on('click', (params) => {
    void onChartClick(params as unknown as { data?: ChartNode })
  })
}

function onResize() {
  chart?.resize()
}

onMounted(() => {
  initChart()
  window.addEventListener('resize', onResize)
  nextTick(() => chart?.resize())
})

onBeforeUnmount(() => {
  window.removeEventListener('resize', onResize)
  chart?.dispose()
  chart = null
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="list-title">会员关系图</div>
      <div class="toolbar">
        <el-input
          v-model="midInput"
          class="filter-item"
          placeholder="会员ID"
          clearable
          type="number"
          @keyup.enter="onQuery"
        />
        <el-input
          v-model="keyword"
          class="filter-item"
          placeholder="昵称/手机关键字"
          clearable
          @keyup.enter="onQuery"
        />
        <el-button type="primary" :loading="loading" @click="onQuery">查询关系图</el-button>
        <span class="tip">
          直推树最多展示 10 层
          <template v-if="rootInfo">
            ；当前根节点「{{ rootInfo.name }}」团队 {{ rootInfo.downcountall }} 人，展开 {{ deeplevel }} 层
          </template>
        </span>
      </div>

      <div ref="chartRef" class="chart-box" v-loading="loading" />
      <el-empty
        v-if="!rootInfo && !loading"
        description="输入会员ID或昵称关键字，查询该会员的直推关系树"
      />
    </el-card>
  </div>
</template>

<style scoped>
.list-title {
  font-size: 16px;
  font-weight: 600;
  margin-bottom: 14px;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}
.filter-item {
  width: 180px;
}
.tip {
  font-size: 12px;
  color: #909399;
}
.chart-box {
  width: 100%;
  height: 620px;
}
</style>
