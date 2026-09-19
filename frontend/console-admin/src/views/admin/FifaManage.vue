<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteFifa,
  deleteFifaRecords,
  getFifaList,
  getFifaRecords,
  getFifaSet,
  openFifaMatch,
  saveFifa,
  saveFifaSet,
} from '../../api/marketing'
import type { FifaMatchItem, FifaRecordItem, FifaSetData } from '../../api/marketing'

const activeTab = ref('matches')

// ============ 赛事列表 ============
const loading = ref(false)
const tableData = ref<FifaMatchItem[]>([])
const total = ref(0)
const selection = ref<FifaMatchItem[]>([])
const query = reactive({ keyword: '', page: 1, pagesize: 10 })

async function fetchList() {
  loading.value = true
  try {
    const data = await getFifaList({
      keyword: query.keyword,
      page: query.page,
      pagesize: query.pagesize,
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
  fetchList()
}

function handleTabChange() {
  if (activeTab.value === 'matches') fetchList()
  else if (activeTab.value === 'records') fetchRecords()
  else if (activeTab.value === 'set') fetchSet()
}

function handleSelectionChange(rows: FifaMatchItem[]) {
  selection.value = rows
}

// ============ 赛事录入弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.keys(form).forEach((key) => delete form[key])
  Object.assign(form, {
    id: 0,
    leftTeam_name: '',
    rightTeam_name: '',
    date: '',
    time: '20:00',
    matchStage: '',
    matchDesc: '',
    leftTeam_score: '',
    rightTeam_score: '',
  })
}

function handleAdd() {
  resetForm()
  dialogVisible.value = true
}

function handleEdit(row: FifaMatchItem) {
  resetForm()
  Object.assign(form, {
    id: row.id,
    leftTeam_name: row.leftTeam_name,
    rightTeam_name: row.rightTeam_name,
    date: row.startDate ? `${new Date().getFullYear()}-${row.startDate}` : '',
    time: row.startTime || '20:00',
    matchStage: row.matchStage,
    matchDesc: row.matchDesc,
    leftTeam_score: row.leftTeam_score,
    rightTeam_score: row.rightTeam_score,
  })
  dialogVisible.value = true
}

async function handleSave() {
  saving.value = true
  try {
    await saveFifa({
      id: form.id,
      leftTeam_name: form.leftTeam_name,
      rightTeam_name: form.rightTeam_name,
      startDate: form.date,
      startTime: form.time,
      matchStage: form.matchStage,
      matchDesc: form.matchDesc,
      leftTeam_score: form.leftTeam_score,
      rightTeam_score: form.rightTeam_score,
    })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

function handleDelete(row?: FifaMatchItem) {
  const ids = row ? [row.id] : selection.value.map((item) => item.id)
  if (ids.length === 0) {
    ElMessage.warning('请选择赛事')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 场赛事及其竞猜记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteFifa(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 开奖 ============
async function handleOpen(row: FifaMatchItem) {
  if (!row.leftTeam_score || !row.rightTeam_score) {
    ElMessage.warning('请先编辑赛事录入常规赛比分再开奖')
    return
  }
  ElMessageBox.confirm(
    `确定按比分 ${row.leftTeam_score}:${row.rightTeam_score} 开奖吗？开奖后将判定全部竞猜并发放积分。`,
    '开奖确认',
    { confirmButtonText: '开奖', cancelButtonText: '取消', type: 'warning' },
  )
    .then(async () => {
      try {
        const result = await openFifaMatch(row.id)
        ElMessage.success(
          `开奖完成：共 ${result.total} 条竞猜，猜中胜负 ${result.win1} 条，猜中比分 ${result.win2} 条`,
        )
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 竞猜记录 ============
const recordLoading = ref(false)
const recordData = ref<FifaRecordItem[]>([])
const recordTotal = ref(0)
const recordSelection = ref<FifaRecordItem[]>([])
const recordQuery = reactive({ hid: 0, nickname: '', page: 1, pagesize: 10 })

async function fetchRecords() {
  recordLoading.value = true
  try {
    const data = await getFifaRecords({
      hid: recordQuery.hid > 0 ? recordQuery.hid : undefined,
      nickname: recordQuery.nickname,
      page: recordQuery.page,
      pagesize: recordQuery.pagesize,
    })
    recordData.value = data.list || []
    recordTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    recordLoading.value = false
  }
}

function handleRecordSearch() {
  recordQuery.page = 1
  fetchRecords()
}

function guessResultText(row: FifaRecordItem, which: 1 | 2): string {
  const st = which === 1 ? row.guess1st : row.guess2st
  if (st === 1) return '猜中'
  if (st === 2) return '未中'
  return '待开奖'
}

async function handleDeleteRecords() {
  const ids = recordSelection.value.map((item) => item.id)
  if (ids.length === 0) {
    ElMessage.warning('请选择记录')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 条竞猜记录吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteFifaRecords(ids)
        ElMessage.success('删除成功')
        fetchRecords()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 竞猜设置 ============
const setForm = reactive<Partial<FifaSetData>>({
  name: '世界杯竞猜',
  givescore1: 0,
  givescore2: 0,
  guess1set: ['主胜', '平局', '客胜'],
  guess2set: [],
  status: 0,
  guize: '',
  starthour: 24,
})
const guess2Input = ref('')

async function fetchSet() {
  try {
    const data = await getFifaSet()
    Object.assign(setForm, data)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function addGuess2() {
  const value = guess2Input.value.trim()
  if (!value) return
  const list = Array.isArray(setForm.guess2set) ? setForm.guess2set : []
  if (!list.includes(value)) list.push(value)
  setForm.guess2set = [...list]
  guess2Input.value = ''
}

async function handleSaveSet() {
  try {
    await saveFifaSet({ ...setForm })
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <el-tab-pane label="赛事管理" name="matches" />
        <el-tab-pane label="竞猜记录" name="records" />
        <el-tab-pane label="竞猜设置" name="set" />
      </el-tabs>

      <!-- 赛事管理 -->
      <template v-if="activeTab === 'matches'">
        <div class="toolbar">
          <el-input
            v-model="query.keyword"
            placeholder="搜索球队名称"
            clearable
            style="width: 200px"
            @keyup.enter="handleSearch"
          />
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <div class="toolbar-right">
            <el-button type="primary" @click="handleAdd">录入赛事</el-button>
            <el-button type="danger" plain :disabled="selection.length === 0" @click="handleDelete()">
              批量删除
            </el-button>
          </div>
        </div>

        <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
          <el-table-column type="selection" width="40" />
          <el-table-column label="开赛时间" width="150">
            <template #default="{ row }">{{ row.startDate }} {{ row.startTime }}</template>
          </el-table-column>
          <el-table-column label="对阵" min-width="180">
            <template #default="{ row }">
              {{ row.leftTeam_name }} vs {{ row.rightTeam_name }}
            </template>
          </el-table-column>
          <el-table-column prop="matchStage" label="阶段" width="100" />
          <el-table-column label="常规赛比分" width="110">
            <template #default="{ row }">
              <template v-if="row.leftTeam_score !== '' && row.rightTeam_score !== ''">
                {{ row.leftTeam_score }} : {{ row.rightTeam_score }}
              </template>
              <span v-else class="text-muted">未录入</span>
            </template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag v-if="row.open" type="success" size="small">已开奖</el-tag>
              <el-tag v-else type="info" size="small">进行中</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="180" fixed="right">
            <template #default="{ row }">
              <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
              <el-button v-if="!row.open" link type="warning" @click="handleOpen(row)">开奖</el-button>
              <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>

        <el-pagination
          class="pagination"
          background
          layout="total, sizes, prev, pager, next"
          :total="total"
          :current-page="query.page"
          :page-size="query.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="(p: number) => ((query.page = p), fetchList())"
          @size-change="(s: number) => ((query.pagesize = s), (query.page = 1), fetchList())"
        />
      </template>

      <!-- 竞猜记录 -->
      <template v-if="activeTab === 'records'">
        <div class="toolbar">
          <el-input-number
            v-model="recordQuery.hid"
            :min="0"
            placeholder="赛事ID"
            controls-position="right"
            style="width: 130px"
          />
          <el-input
            v-model="recordQuery.nickname"
            placeholder="会员昵称"
            clearable
            style="width: 160px"
            @keyup.enter="handleRecordSearch"
          />
          <el-button type="primary" @click="handleRecordSearch">搜索</el-button>
          <div class="toolbar-right">
            <el-button type="danger" plain :disabled="recordSelection.length === 0" @click="handleDeleteRecords">
              批量删除
            </el-button>
          </div>
        </div>

        <el-table
          v-loading="recordLoading"
          :data="recordData"
          stripe
          @selection-change="(rows: FifaRecordItem[]) => (recordSelection = rows)"
        >
          <el-table-column type="selection" width="40" />
          <el-table-column label="会员" min-width="130">
            <template #default="{ row }">
              <div class="member-cell">
                <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
                <span>{{ row.nickname || `会员${row.mid}` }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="duiju" label="赛事" min-width="160" show-overflow-tooltip />
          <el-table-column label="猜胜负" width="150">
            <template #default="{ row }">
              <template v-if="row.guess1">{{ row.guess1 }}（{{ guessResultText(row, 1) }}）</template>
              <span v-else class="text-muted">-</span>
            </template>
          </el-table-column>
          <el-table-column label="猜比分" width="150">
            <template #default="{ row }">
              <template v-if="row.guess2">{{ row.guess2 }}（{{ guessResultText(row, 2) }}）</template>
              <span v-else class="text-muted">-</span>
            </template>
          </el-table-column>
          <el-table-column label="实发积分" width="100">
            <template #default="{ row }">{{ Number(row.givescore1 || 0) + Number(row.givescore2 || 0) }}</template>
          </el-table-column>
          <el-table-column prop="createtime_text" label="竞猜时间" width="170" />
        </el-table>

        <el-pagination
          class="pagination"
          background
          layout="total, sizes, prev, pager, next"
          :total="recordTotal"
          :current-page="recordQuery.page"
          :page-size="recordQuery.pagesize"
          :page-sizes="[10, 20, 50]"
          @current-change="(p: number) => ((recordQuery.page = p), fetchRecords())"
          @size-change="(s: number) => ((recordQuery.pagesize = s), (recordQuery.page = 1), fetchRecords())"
        />
      </template>

      <!-- 竞猜设置 -->
      <template v-if="activeTab === 'set'">
        <el-form label-width="130px" style="max-width: 640px">
          <el-form-item label="活动名称">
            <el-input v-model="setForm.name" />
          </el-form-item>
          <el-form-item label="猜胜负选项">
            <el-select v-model="setForm.guess1set" multiple filterable allow-create style="width: 100%">
              <el-option label="主胜" value="主胜" />
              <el-option label="平局" value="平局" />
              <el-option label="客胜" value="客胜" />
            </el-select>
          </el-form-item>
          <el-form-item label="猜比分选项">
            <div class="guess2-box">
              <el-tag
                v-for="(item, i) in setForm.guess2set || []"
                :key="item"
                closable
                style="margin-right: 6px"
                @close="(setForm.guess2set || []).splice(i, 1)"
              >
                {{ item }}
              </el-tag>
              <el-input v-model="guess2Input" size="small" style="width: 90px" placeholder="如 1:0" @keyup.enter="addGuess2" />
              <el-button size="small" @click="addGuess2">添加</el-button>
            </div>
          </el-form-item>
          <el-form-item label="猜胜负送积分">
            <el-input-number v-model="setForm.givescore1" :min="0" controls-position="right" />
          </el-form-item>
          <el-form-item label="猜比分送积分">
            <el-input-number v-model="setForm.givescore2" :min="0" controls-position="right" />
          </el-form-item>
          <el-form-item label="竞猜窗口">
            <el-input-number v-model="setForm.starthour" :min="0" controls-position="right" />
            <span class="form-tip">开赛前 N 小时内开放竞猜</span>
          </el-form-item>
          <el-form-item label="活动规则">
            <el-input v-model="setForm.guize" type="textarea" :rows="3" />
          </el-form-item>
          <el-form-item label="功能状态">
            <el-switch v-model="setForm.status" :active-value="1" :inactive-value="0" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" @click="handleSaveSet">保存设置</el-button>
          </el-form-item>
        </el-form>
      </template>
    </el-card>

    <!-- 赛事录入弹窗 -->
    <el-dialog v-model="dialogVisible" :title="Number(form.id) > 0 ? '编辑赛事' : '录入赛事'" width="560px">
      <el-form label-width="110px">
        <el-form-item label="主队" required>
          <el-input v-model="form.leftTeam_name" placeholder="主队名称" />
        </el-form-item>
        <el-form-item label="客队" required>
          <el-input v-model="form.rightTeam_name" placeholder="客队名称" />
        </el-form-item>
        <el-form-item label="开赛日期" required>
          <el-date-picker v-model="form.date" type="date" value-format="YYYY-MM-DD" placeholder="选择日期" style="width: 100%" />
        </el-form-item>
        <el-form-item label="开赛时间" required>
          <el-time-picker v-model="form.time" format="HH:mm" value-format="HH:mm" placeholder="选择时间" style="width: 100%" />
        </el-form-item>
        <el-form-item label="赛事阶段">
          <el-input v-model="form.matchStage" placeholder="如：小组赛 / 1/8决赛" />
        </el-form-item>
        <el-form-item label="赛事描述">
          <el-input v-model="form.matchDesc" placeholder="赛事描述（选填）" />
        </el-form-item>
        <el-form-item label="常规赛比分">
          <el-input v-model="form.leftTeam_score" placeholder="主队" style="width: 100px" />
          <span class="form-tip">:</span>
          <el-input v-model="form.rightTeam_score" placeholder="客队" style="width: 100px" />
          <span class="form-tip">开奖依据，未开赛留空</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar {
  display: flex;
  gap: 8px;
  align-items: center;
  margin-bottom: 12px;
}

.toolbar-right {
  margin-left: auto;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.text-muted {
  color: #909399;
  font-size: 12px;
}

.form-tip {
  margin: 0 8px;
  color: #909399;
  font-size: 12px;
}

.guess2-box {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
}
</style>
