<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { auditLevelup, getLevelupList } from '../../api/memberGroup'
import type { LevelupListQuery, LevelupOrderItem } from '../../api/memberGroup'

// ===== 过滤条件 =====
const filters = reactive({
  orderid: '' as number | '',
  mid: '' as number | '',
  nickname: '',
  type: 'all',
  status: 'all',
  timerange: [] as string[],
  formx: '',
})

const page = ref(1)
const pageSize = ref(10)
const total = ref(0)
const list = ref<LevelupOrderItem[]>([])
const loading = ref(false)

function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function paytypeText(v: string): string {
  const map: Record<string, string> = {
    wechat: '微信支付',
    alipay: '支付宝',
    money: '余额支付',
    score: '积分支付',
  }
  return map[v] || v || '-'
}

function statusTag(v: number): 'info' | 'warning' | 'success' | 'danger' {
  if (v === 2) return 'success'
  if (v === 3) return 'danger'
  if (v === 1) return 'warning'
  return 'info'
}

async function load() {
  loading.value = true
  try {
    const params: LevelupListQuery = {
      page: page.value,
      pagesize: pageSize.value,
      orderid: filters.orderid,
      mid: filters.mid,
      nickname: filters.nickname,
      type: filters.type,
      status: filters.status,
      formx: filters.formx,
      start_time: filters.timerange?.[0] || '',
      end_time: filters.timerange?.[1] || '',
    }
    const res = await getLevelupList(params)
    list.value = res.list
    total.value = res.total
  } catch {
    // 错误提示已由请求拦截器统一处理
  } finally {
    loading.value = false
  }
}

function onSearch() {
  page.value = 1
  load()
}

function onReset() {
  filters.orderid = ''
  filters.mid = ''
  filters.nickname = ''
  filters.type = 'all'
  filters.status = 'all'
  filters.timerange = []
  filters.formx = ''
  onSearch()
}

// ===== 表单内容查看 =====
const formVisible = ref(false)
const formRow = ref<LevelupOrderItem | null>(null)

function viewForm(row: LevelupOrderItem) {
  formRow.value = row
  formVisible.value = true
}

// ===== 审核 =====
async function doAudit(row: LevelupOrderItem, st: 2 | 3) {
  const pass = st === 2
  let remark = ''
  try {
    const ret = await ElMessageBox.prompt(
      pass
        ? `通过「${row.nickname}」的${row.type_text}申请（${row.before_level || '-'} → ${row.apply_level || '-'}）？通过后将变更会员等级并发放升级奖励。`
        : `驳回「${row.nickname}」的${row.type_text}申请？${row.totalprice > 0 && row.paytime > 0 ? '已付款金额将按原支付方式退回。' : ''}`,
      pass ? '审核通过' : '驳回申请',
      {
        confirmButtonText: pass ? '确认通过' : '确认驳回',
        cancelButtonText: '取消',
        inputPlaceholder: '审核备注（选填）',
        inputValue: '',
        type: pass ? 'success' : 'warning',
      },
    )
    remark = String(ret.value || '')
  } catch {
    return
  }

  try {
    await auditLevelup({ id: row.id, st, remark })
    ElMessage.success(pass ? '已通过审核' : '已驳回申请')
    load()
  } catch {
    // 拦截器已提示
  }
}

onMounted(load)
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="list-title">升级申请记录</div>
      <div class="toolbar">
        <el-input
          v-model="filters.orderid"
          class="filter-item"
          placeholder="申请单ID"
          clearable
          type="number"
        />
        <el-input
          v-model="filters.mid"
          class="filter-item"
          placeholder="会员ID"
          clearable
          type="number"
        />
        <el-input
          v-model="filters.nickname"
          class="filter-item"
          placeholder="会员昵称"
          clearable
        />
        <el-select v-model="filters.type" class="filter-item" style="width: 110px">
          <el-option label="全部类型" value="all" />
          <el-option label="升级" value="0" />
          <el-option label="降级" value="1" />
        </el-select>
        <el-select v-model="filters.status" class="filter-item" style="width: 120px">
          <el-option label="全部状态" value="all" />
          <el-option label="待支付" value="0" />
          <el-option label="待审核" value="1" />
          <el-option label="已通过" value="2" />
          <el-option label="已驳回" value="3" />
        </el-select>
        <el-date-picker
          v-model="filters.timerange"
          class="filter-item"
          type="daterange"
          value-format="YYYY-MM-DD"
          start-placeholder="开始日期"
          end-placeholder="结束日期"
          style="width: 240px"
        />
        <el-input
          v-model="filters.formx"
          class="filter-item"
          placeholder="表单内容关键字"
          clearable
        />
        <el-button type="primary" @click="onSearch">搜索</el-button>
        <el-button @click="onReset">重置</el-button>
      </div>

      <el-table v-loading="loading" :data="list" border stripe style="width: 100%">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="会员" min-width="150">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar :size="32" :src="row.headimg || undefined">
                {{ (row.nickname || '会员').slice(0, 1) }}
              </el-avatar>
              <div class="member-meta">
                <div>{{ row.nickname || `会员${row.mid}` }}</div>
                <div class="member-sub">ID: {{ row.mid }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="申请类型" width="90">
          <template #default="{ row }">
            <el-tag :type="row.type === 1 ? 'warning' : 'primary'" size="small">
              {{ row.type_text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="等级变更" min-width="160">
          <template #default="{ row }">
            {{ row.before_level || '普通会员' }} → {{ row.apply_level || '-' }}
          </template>
        </el-table-column>
        <el-table-column label="金额" width="90" align="right">
          <template #default="{ row }">￥{{ Number(row.totalprice).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="支付方式" width="100">
          <template #default="{ row }">{{ paytypeText(row.paytype) }}</template>
        </el-table-column>
        <el-table-column label="申请时间" width="150">
          <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)" size="small">{{ row.status_text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="210" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewForm(row)">表单内容</el-button>
            <template v-if="row.status === 1">
              <el-button link type="success" size="small" @click="doAudit(row, 2)">通过</el-button>
              <el-button link type="danger" size="small" @click="doAudit(row, 3)">驳回</el-button>
            </template>
            <template v-else-if="row.status === 2 && row.levelup_time > 0">
              <span class="audit-time">通过于 {{ fmtTime(row.levelup_time) }}</span>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="onSearch"
        />
      </div>
    </el-card>

    <!-- 表单内容弹窗 -->
    <el-dialog v-model="formVisible" title="申请表单内容" width="560px">
      <template v-if="formRow">
        <el-descriptions :column="1" border>
          <el-descriptions-item label="申请单ID">{{ formRow.id }}</el-descriptions-item>
          <el-descriptions-item label="订单号">{{ formRow.ordernum || '-' }}</el-descriptions-item>
          <el-descriptions-item label="申请标题">{{ formRow.title || '-' }}</el-descriptions-item>
          <el-descriptions-item
            v-for="(field, idx) in formRow.formdata"
            :key="idx"
            :label="field.title || `字段${idx + 1}`"
          >
            <el-image
              v-if="field.type === 'upload' && field.value"
              :src="field.value"
              :preview-src-list="[field.value]"
              style="width: 80px; height: 80px"
              fit="cover"
            />
            <span v-else>{{ field.value || '-' }}</span>
          </el-descriptions-item>
        </el-descriptions>
        <el-empty v-if="!formRow.formdata.length" description="无表单内容" :image-size="60" />
      </template>
    </el-dialog>
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
  gap: 8px;
  margin-bottom: 14px;
}
.filter-item {
  width: 150px;
}
.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.member-meta {
  line-height: 1.3;
}
.member-sub {
  font-size: 12px;
  color: #909399;
}
.pager {
  margin-top: 14px;
  display: flex;
  justify-content: flex-end;
}
.audit-time {
  font-size: 12px;
  color: #909399;
}
</style>
