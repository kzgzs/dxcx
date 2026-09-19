<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  adjustScore,
  getScoreConfig,
  getScoreLog,
  getScoreMemberList,
  saveScoreConfig,
} from '../../api/score'
import type { ScoreLogItem, ScoreMemberItem } from '../../api/types'
import { formatTime } from '../../utils/time'

// ============ 积分配置 ============
const configLoading = ref(false)
const saving = ref(false)

const config = reactive({
  score_switch: 0,
  score_ratio: 1,
  pay_score: 0,
})

async function fetchConfig() {
  configLoading.value = true
  try {
    const data = await getScoreConfig()
    config.score_switch = data.score_switch
    config.score_ratio = data.score_ratio
    config.pay_score = data.pay_score
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    configLoading.value = false
  }
}

async function handleSaveConfig() {
  saving.value = true
  try {
    await saveScoreConfig({
      score_switch: config.score_switch,
      score_ratio: config.score_ratio,
      pay_score: config.pay_score,
    })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 会员积分列表 ============
const loading = ref(false)
const tableData = ref<ScoreMemberItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  keyword: '',
})

async function fetchMembers() {
  loading.value = true
  try {
    const data = await getScoreMemberList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchMembers()
}

function handleReset() {
  query.keyword = ''
  query.page = 1
  fetchMembers()
}

function handlePageChange(page: number) {
  query.page = page
  fetchMembers()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchMembers()
}

// ============ 积分调整弹窗 ============
const adjustVisible = ref(false)
const adjustSubmitting = ref(false)
const adjustTarget = ref<ScoreMemberItem | null>(null)
const adjustFormRef = ref<FormInstance>()

const adjustForm = reactive({
  score: 0,
  remark: '',
})

const adjustRules: FormRules = {
  score: [
    {
      validator: (_rule, value: number, callback) => {
        if (value === undefined || value === null || Number.isNaN(value)) {
          callback(new Error('请输入调整积分'))
        } else if (!Number.isInteger(value)) {
          callback(new Error('积分必须为整数'))
        } else if (value === 0) {
          callback(new Error('调整积分不能为 0'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  remark: [{ required: true, message: '请输入调整备注', trigger: 'blur' }],
}

function openAdjust(row: ScoreMemberItem) {
  adjustTarget.value = row
  adjustForm.score = 0
  adjustForm.remark = ''
  adjustVisible.value = true
}

async function handleAdjust() {
  if (!adjustFormRef.value || !adjustTarget.value) return
  await adjustFormRef.value.validate(async (valid) => {
    if (!valid) return
    adjustSubmitting.value = true
    try {
      await adjustScore({
        mid: adjustTarget.value!.mid,
        score: adjustForm.score,
        remark: adjustForm.remark,
      })
      ElMessage.success('调整成功')
      adjustVisible.value = false
      fetchMembers()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      adjustSubmitting.value = false
    }
  })
}

// ============ 积分明细抽屉 ============
const logVisible = ref(false)
const logLoading = ref(false)
const logTarget = ref<ScoreMemberItem | null>(null)
const logData = ref<ScoreLogItem[]>([])
const logTotal = ref(0)
const logPage = ref(1)
const logPagesize = ref(10)

const LOG_TYPE_TEXT: Record<number, string> = {
  1: '支付赠送',
  2: '下单抵扣',
  3: '取消返还',
  4: '后台调整',
}

async function fetchLogs() {
  if (!logTarget.value) return
  logLoading.value = true
  try {
    const data = await getScoreLog({
      mid: logTarget.value.mid,
      page: logPage.value,
      pagesize: logPagesize.value,
    })
    logData.value = data.list || []
    logTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    logLoading.value = false
  }
}

function openLog(row: ScoreMemberItem) {
  logTarget.value = row
  logPage.value = 1
  logVisible.value = true
  fetchLogs()
}

function handleLogPageChange(page: number) {
  logPage.value = page
  fetchLogs()
}

onMounted(() => {
  fetchConfig()
  fetchMembers()
})
</script>

<template>
  <div>
    <!-- 积分配置卡 -->
    <el-card v-loading="configLoading" shadow="never" class="config-card">
      <template #header>
        <span class="card-title">积分配置</span>
      </template>
      <el-form inline>
        <el-form-item label="积分功能">
          <el-switch
            v-model="config.score_switch"
            :active-value="1"
            :inactive-value="0"
            active-text="开启"
            inactive-text="关闭"
          />
        </el-form-item>
        <el-form-item label="抵扣比例">
          <el-input-number v-model="config.score_ratio" :min="1" :step="1" />
          <span class="form-tip">N 积分抵 1 元</span>
        </el-form-item>
        <el-form-item label="支付赠送">
          <el-input-number v-model="config.pay_score" :min="0" :step="1" />
          <span class="form-tip">每满 1 元送 N 积分</span>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSaveConfig">
            保存配置
          </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 会员积分列表 -->
    <el-card shadow="never">
      <el-form inline @submit.prevent>
        <el-form-item label="手机号">
          <el-input
            v-model="query.keyword"
            placeholder="会员手机号"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>

      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="mid" label="会员ID" width="100" />
        <el-table-column prop="phone" label="手机号" width="150" />
        <el-table-column prop="score" label="当前积分" width="120" />
        <el-table-column label="操作" width="160">
          <template #default="{ row }">
            <el-button link type="primary" @click="openAdjust(row)">调整</el-button>
            <el-button link type="primary" @click="openLog(row)">明细</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        :current-page="query.page"
        :page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 积分调整弹窗 -->
    <el-dialog v-model="adjustVisible" title="积分调整" width="420px">
      <el-form
        ref="adjustFormRef"
        :model="adjustForm"
        :rules="adjustRules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="会员">
          <span>{{ adjustTarget?.phone }}（ID：{{ adjustTarget?.mid }}）</span>
        </el-form-item>
        <el-form-item label="当前积分">
          <span>{{ adjustTarget?.score }}</span>
        </el-form-item>
        <el-form-item label="调整积分" prop="score">
          <el-input-number v-model="adjustForm.score" :step="1" />
          <span class="form-tip">正数加分，负数扣分</span>
        </el-form-item>
        <el-form-item label="调整备注" prop="remark">
          <el-input
            v-model="adjustForm.remark"
            type="textarea"
            :rows="3"
            placeholder="请输入调整原因"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="adjustVisible = false">取消</el-button>
        <el-button type="primary" :loading="adjustSubmitting" @click="handleAdjust">
          确定
        </el-button>
      </template>
    </el-dialog>

    <!-- 积分明细抽屉 -->
    <el-drawer
      v-model="logVisible"
      :title="`积分明细 - ${logTarget?.phone || ''}`"
      size="640px"
    >
      <el-table v-loading="logLoading" :data="logData" stripe>
        <el-table-column label="变动积分" width="110">
          <template #default="{ row }">
            <span :class="row.score > 0 ? 'score-plus' : 'score-minus'">
              {{ row.score > 0 ? `+${row.score}` : row.score }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="类型" width="110">
          <template #default="{ row }">{{ LOG_TYPE_TEXT[row.type] || '未知' }}</template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="160" show-overflow-tooltip />
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.create_time) }}</template>
        </el-table-column>
      </el-table>

      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="logTotal"
        :current-page="logPage"
        :page-size="logPagesize"
        @current-change="handleLogPageChange"
      />
    </el-drawer>
  </div>
</template>

<style scoped>
.config-card {
  margin-bottom: 16px;
}

.card-title {
  font-weight: 600;
}

.config-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.score-plus {
  color: #67c23a;
  font-weight: 600;
}

.score-minus {
  color: #f56c6c;
  font-weight: 600;
}
</style>
