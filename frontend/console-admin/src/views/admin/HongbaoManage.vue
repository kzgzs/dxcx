<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadFile } from 'element-plus'
import {
  deleteHongbao,
  exportHongbaoCodes,
  getHongbaoCodelist,
  getHongbaoDetail,
  getHongbaoList,
  getHongbaoRecords,
  importHongbaoCodes,
  makeHongbaoCodes,
  saveHongbao,
} from '../../api/marketing'
import type { HongbaoCodeItem, HongbaoItem, HongbaoRecordItem } from '../../api/marketing'

// ============ 活动列表 ============
const loading = ref(false)
const tableData = ref<HongbaoItem[]>([])
const total = ref(0)
const selection = ref<HongbaoItem[]>([])
const query = reactive({ keyword: '', page: 1, pagesize: 10 })

async function fetchList() {
  loading.value = true
  try {
    const data = await getHongbaoList({
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

function handleSelectionChange(rows: HongbaoItem[]) {
  selection.value = rows
}

// ============ 新增/编辑弹窗 ============
const dialogVisible = ref(false)
const saving = ref(false)
const form = reactive<Record<string, unknown>>({})

function resetForm() {
  Object.keys(form).forEach((key) => delete form[key])
  Object.assign(form, {
    id: 0,
    name: '',
    sendname: '',
    wishing: '',
    minmoney: 1,
    maxmoney: 10,
    totalmoney: 100,
    pernum: 1,
    timerange: [] as string[],
    status: 1,
    sharepic: '',
    gettj: ['-1'],
    needcode: 0,
  })
}

function handleAdd() {
  resetForm()
  dialogVisible.value = true
}

async function handleEdit(row: HongbaoItem) {
  resetForm()
  try {
    const detail = await getHongbaoDetail(row.id)
    Object.assign(form, {
      id: detail.id,
      name: detail.name,
      sendname: detail.sendname,
      wishing: detail.wishing,
      minmoney: detail.minmoney,
      maxmoney: detail.maxmoney,
      totalmoney: detail.totalmoney,
      pernum: detail.pernum,
      timerange:
        detail.starttime > 0 && detail.endtime > 0
          ? [
              new Date(detail.starttime * 1000).toLocaleString('sv-SE').replace('T', ' '),
              new Date(detail.endtime * 1000).toLocaleString('sv-SE').replace('T', ' '),
            ]
          : [],
      status: detail.status,
      sharepic: detail.sharepic,
      gettj: Array.isArray(detail.gettj) ? detail.gettj.map(String) : ['-1'],
      needcode: detail.needcode,
    })
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function stampOf(value: unknown): number {
  if (!value) return 0
  const stamp = new Date(String(value).replace(/-/g, '/')).getTime()
  return Number.isNaN(stamp) ? 0 : Math.floor(stamp / 1000)
}

async function handleSave() {
  const timerange = (form.timerange as string[]) || []
  saving.value = true
  try {
    await saveHongbao({
      id: form.id,
      name: form.name,
      sendname: form.sendname,
      wishing: form.wishing,
      minmoney: form.minmoney,
      maxmoney: form.maxmoney,
      totalmoney: form.totalmoney,
      pernum: form.pernum,
      starttime: stampOf(timerange[0]),
      endtime: stampOf(timerange[1]),
      status: form.status,
      sharepic: form.sharepic,
      gettj: form.gettj,
      needcode: form.needcode,
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

function handleDelete(row?: HongbaoItem) {
  const ids = row ? [row.id] : selection.value.map((item) => item.id)
  if (ids.length === 0) {
    ElMessage.warning('请选择活动')
    return
  }
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 个活动及其记录与抽奖码吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteHongbao(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

function gettjText(value: unknown): string {
  const list = Array.isArray(value) ? value : String(value ?? '-1').split(',')
  if (list.includes('-1')) return '不限'
  return list
    .map((v) => (String(v) === '0' ? '关注公众号' : `等级${v}`))
    .join('、')
}

// ============ 领取记录弹窗 ============
const recordVisible = ref(false)
const recordLoading = ref(false)
const recordData = ref<HongbaoRecordItem[]>([])
const recordTotal = ref(0)
const recordQuery = reactive({ hid: 0, nickname: '', page: 1, pagesize: 10 })
const currentActivity = ref<HongbaoItem | null>(null)

function handleRecords(row: HongbaoItem) {
  currentActivity.value = row
  recordQuery.hid = row.id
  recordQuery.nickname = ''
  recordQuery.page = 1
  recordVisible.value = true
  fetchRecords()
}

async function fetchRecords() {
  recordLoading.value = true
  try {
    const data = await getHongbaoRecords({
      hid: recordQuery.hid,
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

// ============ 抽奖码弹窗 ============
const codeVisible = ref(false)
const codeLoading = ref(false)
const codeData = ref<HongbaoCodeItem[]>([])
const codeTotal = ref(0)
const codeQuery = reactive({ hid: 0, status: -1, page: 1, pagesize: 10 })

function handleCodes(row: HongbaoItem) {
  currentActivity.value = row
  codeQuery.hid = row.id
  codeQuery.status = -1
  codeQuery.page = 1
  codeVisible.value = true
  fetchCodes()
}

async function fetchCodes() {
  codeLoading.value = true
  try {
    const data = await getHongbaoCodelist({
      hid: codeQuery.hid,
      status: codeQuery.status,
      page: codeQuery.page,
      pagesize: codeQuery.pagesize,
    })
    codeData.value = data.list || []
    codeTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    codeLoading.value = false
  }
}

// 生成抽奖码
const makeForm = reactive({ num: 100, length: 10 })
const making = ref(false)

async function handleMake() {
  making.value = true
  try {
    const result = await makeHongbaoCodes({ hid: codeQuery.hid, num: makeForm.num, length: makeForm.length })
    ElMessage.success(`成功生成 ${result.num} 个抽奖码`)
    fetchCodes()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    making.value = false
  }
}

// 导入
const importing = ref(false)

async function handleImport(uploadFile: UploadFile) {
  const file = uploadFile.raw
  if (!file) return
  importing.value = true
  try {
    const result = await importHongbaoCodes(codeQuery.hid, file)
    ElMessage.success(`导入完成：新增 ${result.imported} 条，跳过 ${result.skipped} 条`)
    fetchCodes()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    importing.value = false
  }
}

async function handleExport() {
  try {
    await exportHongbaoCodes(codeQuery.hid)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="toolbar">
        <el-input
          v-model="query.keyword"
          placeholder="搜索活动名称"
          clearable
          style="width: 200px"
          @keyup.enter="handleSearch"
        />
        <el-button type="primary" @click="handleSearch">搜索</el-button>
        <div class="toolbar-right">
          <el-button type="primary" @click="handleAdd">新增活动</el-button>
          <el-button type="danger" plain :disabled="selection.length === 0" @click="handleDelete()">
            批量删除
          </el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe @selection-change="handleSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="60" />
        <el-table-column prop="name" label="活动名称" min-width="140" show-overflow-tooltip />
        <el-table-column label="金额区间" width="120">
          <template #default="{ row }">￥{{ row.minmoney }} ~ ￥{{ row.maxmoney }}</template>
        </el-table-column>
        <el-table-column label="预算/已发放" width="150">
          <template #default="{ row }">￥{{ row.totalmoney }} / ￥{{ row.givenmoney }}</template>
        </el-table-column>
        <el-table-column label="每人限次" width="90">
          <template #default="{ row }">{{ row.pernum }} 次</template>
        </el-table-column>
        <el-table-column label="活动时间" min-width="240">
          <template #default="{ row }">{{ row.starttime_text }} ~ {{ row.endtime_text }}</template>
        </el-table-column>
        <el-table-column label="人群" width="110">
          <template #default="{ row }">{{ gettjText(row.gettj) }}</template>
        </el-table-column>
        <el-table-column label="抽奖码" width="80">
          <template #default="{ row }">
            <el-tag v-if="row.needcode === 1" type="warning" size="small">需要</el-tag>
            <el-tag v-else type="info" size="small">不需要</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="70">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '开启' : '关闭' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="handleRecords(row)">记录</el-button>
            <el-button link type="primary" @click="handleCodes(row)">抽奖码</el-button>
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
    </el-card>

    <!-- 新增/编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="Number(form.id) > 0 ? '编辑活动' : '新增活动'" width="620px">
      <el-form label-width="110px">
        <el-form-item label="活动名称" required>
          <el-input v-model="form.name" placeholder="请输入活动名称" />
        </el-form-item>
        <el-form-item label="商户号">
          <el-input v-model="form.sendname" placeholder="微信商户号（发放主体）" />
        </el-form-item>
        <el-form-item label="祝福语">
          <el-input v-model="form.wishing" placeholder="红包祝福语" />
        </el-form-item>
        <el-form-item label="金额区间" required>
          <el-input-number v-model="form.minmoney" :min="0.01" :precision="2" controls-position="right" />
          <span class="form-tip">至</span>
          <el-input-number v-model="form.maxmoney" :min="0.01" :precision="2" controls-position="right" />
        </el-form-item>
        <el-form-item label="发放总预算" required>
          <el-input-number v-model="form.totalmoney" :min="0.01" :precision="2" controls-position="right" />
        </el-form-item>
        <el-form-item label="每人限领">
          <el-input-number v-model="form.pernum" :min="1" controls-position="right" />
          <span class="form-tip">次</span>
        </el-form-item>
        <el-form-item label="活动时间" required>
          <el-date-picker
            v-model="form.timerange"
            type="datetimerange"
            value-format="YYYY-MM-DD HH:mm:ss"
            start-placeholder="开始时间"
            end-placeholder="结束时间"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="参与人群">
          <el-select
            v-model="form.gettj"
            multiple
            filterable
            allow-create
            default-first-option
            style="width: 100%"
            placeholder="-1 不限 / 0 关注公众号 / 输入等级ID"
          >
            <el-option label="不限" value="-1" />
            <el-option label="关注公众号" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item label="需要抽奖码">
          <el-switch v-model="form.needcode" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="分享图片">
          <el-input v-model="form.sharepic" placeholder="分享图 URL" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 领取记录弹窗 -->
    <el-dialog v-model="recordVisible" :title="`领取记录 - ${currentActivity?.name || ''}`" width="760px">
      <div class="toolbar">
        <el-input
          v-model="recordQuery.nickname"
          placeholder="会员昵称"
          clearable
          style="width: 180px"
          @keyup.enter="((recordQuery.page = 1), fetchRecords())"
        />
        <el-button type="primary" @click="((recordQuery.page = 1), fetchRecords())">搜索</el-button>
      </div>
      <el-table v-loading="recordLoading" :data="recordData" stripe max-height="420">
        <el-table-column label="会员" min-width="130">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || `会员${row.mid}` }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">￥{{ row.money }}</template>
        </el-table-column>
        <el-table-column label="发放状态" width="110">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'danger'" size="small">
              {{ row.status === 1 ? '发放成功' : '发放失败' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip />
        <el-table-column prop="createtime_text" label="领取时间" width="170" />
      </el-table>
      <el-pagination
        class="pagination"
        background
        layout="total, prev, pager, next"
        :total="recordTotal"
        :current-page="recordQuery.page"
        :page-size="recordQuery.pagesize"
        @current-change="(p: number) => ((recordQuery.page = p), fetchRecords())"
      />
    </el-dialog>

    <!-- 抽奖码弹窗 -->
    <el-dialog v-model="codeVisible" :title="`抽奖码 - ${currentActivity?.name || ''}`" width="860px">
      <div class="toolbar">
        <el-select v-model="codeQuery.status" style="width: 120px" @change="((codeQuery.page = 1), fetchCodes())">
          <el-option label="全部" :value="-1" />
          <el-option label="未使用" :value="0" />
          <el-option label="已使用" :value="1" />
        </el-select>
        <div class="toolbar-right">
          <el-input-number v-model="makeForm.num" :min="1" :max="10000" controls-position="right" style="width: 110px" />
          <el-select v-model="makeForm.length" style="width: 100px">
            <el-option v-for="len in [8, 10, 12, 16]" :key="len" :label="`${len} 位`" :value="len" />
          </el-select>
          <el-button type="primary" :loading="making" @click="handleMake">批量生成</el-button>
          <el-upload :auto-upload="false" :show-file-list="false" accept=".xlsx,.xls,.csv" :on-change="handleImport">
            <el-button :loading="importing">导入</el-button>
          </el-upload>
          <el-button @click="handleExport">导出</el-button>
        </div>
      </div>
      <el-table v-loading="codeLoading" :data="codeData" stripe max-height="420">
        <el-table-column prop="code" label="抽奖码" min-width="160" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : 'info'" size="small">
              {{ row.status === 1 ? '已使用' : '未使用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="使用者" min-width="130">
          <template #default="{ row }">{{ row.nickname || (row.mid > 0 ? `会员${row.mid}` : '-') }}</template>
        </el-table-column>
        <el-table-column label="领取金额" width="100">
          <template #default="{ row }">{{ row.status === 1 ? `￥${row.money}` : '-' }}</template>
        </el-table-column>
        <el-table-column prop="createtime_text" label="生成时间" width="170" />
        <el-table-column prop="usetime_text" label="使用时间" width="170" />
      </el-table>
      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next"
        :total="codeTotal"
        :current-page="codeQuery.page"
        :page-size="codeQuery.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="(p: number) => ((codeQuery.page = p), fetchCodes())"
        @size-change="(s: number) => ((codeQuery.pagesize = s), (codeQuery.page = 1), fetchCodes())"
      />
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
  display: flex;
  gap: 8px;
  align-items: center;
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

.form-tip {
  margin: 0 8px;
  color: #909399;
  font-size: 12px;
}
</style>
