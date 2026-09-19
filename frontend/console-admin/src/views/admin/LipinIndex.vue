<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules, UploadRequestOptions } from 'element-plus'
import {
  deleteLipin,
  getLipinCodeList,
  getLipinDetail,
  getLipinList,
  importLipinCode,
  makeLipinCode,
  saveLipin,
} from '../../api/lipin'
import type { LipinCodeItem, LipinItem } from '../../api/lipin'
import { getLipinCategoryList } from '../../api/lipin'
import type { LipinCategoryItem } from '../../api/lipin'

const TYPE_MAP: Record<number, string> = { 0: '余额', 1: '商品', 2: '积分', 3: '优惠券' }

// ============ 列表 ============
const loading = ref(false)
const list = ref<LipinItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, name: '' })
const categories = ref<LipinCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    const res = await getLipinList({ page: query.page, pagesize: query.pagesize, name: query.name })
    list.value = res.list || []
    total.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function fetchCategories() {
  try {
    const res = await getLipinCategoryList()
    categories.value = res.list || []
  } catch {
    categories.value = []
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function formatTime(ts: number | string) {
  const n = Number(ts)
  if (!n) return '-'
  const d = new Date(n * 1000)
  const p = (x: number) => String(x).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function activeStatus(row: LipinItem): { text: string; type: 'info' | 'warning' | 'success' | 'danger' } {
  if (Number(row.status) !== 1) return { text: '未开启', type: 'info' }
  const now = Math.floor(Date.now() / 1000)
  const st = Number(row.starttime)
  const et = Number(row.endtime)
  if (st > 0 && now < st) return { text: '未开始', type: 'warning' }
  if (et > 0 && now > et) return { text: '已结束', type: 'danger' }
  return { text: '进行中', type: 'success' }
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  cid: 0,
  type: 0,
  money: '',
  prodata: '',
  score: '',
  coupon_ids: '',
  starttime: '',
  endtime: '',
  status: 1,
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入名称', trigger: 'blur' }],
  starttime: [{ required: true, message: '请选择开始时间', trigger: 'change' }],
  endtime: [{ required: true, message: '请选择结束时间', trigger: 'change' }],
}

function resetForm() {
  Object.assign(form, { id: 0, name: '', cid: 0, type: 0, money: '', prodata: '', score: '', coupon_ids: '', starttime: '', endtime: '', status: 1 })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

async function openEdit(row: LipinItem) {
  isEdit.value = true
  resetForm()
  try {
    const detail = await getLipinDetail(row.id)
    Object.assign(form, {
      id: detail.id,
      name: detail.name,
      cid: detail.cid,
      type: Number(detail.type),
      money: detail.money || '',
      prodata: detail.prodata || '',
      score: detail.score || '',
      coupon_ids: detail.coupon_ids || '',
      starttime: detail.starttime || '',
      endtime: detail.endtime || '',
      status: Number(detail.status),
    })
    dialogVisible.value = true
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    submitting.value = true
    try {
      await saveLipin({ ...form, id: form.id || undefined })
      ElMessage.success('保存成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
}

function handleDelete(row: LipinItem) {
  ElMessageBox.confirm(`确定要删除「${row.name}」吗？其下兑换码将一并删除。`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLipin([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 生成兑换码弹窗 ============
const makeVisible = ref(false)
const making = ref(false)
const makeTarget = ref<LipinItem | null>(null)
const makeForm = reactive({ makecount: 100, codelength: 8, codetype: 5, qrtype: 0 })

function openMake(row: LipinItem) {
  makeTarget.value = row
  Object.assign(makeForm, { makecount: 100, codelength: 8, codetype: 5, qrtype: 0 })
  makeVisible.value = true
}

async function handleMake() {
  if (!makeTarget.value) return
  making.value = true
  try {
    const res = await makeLipinCode({ hid: makeTarget.value.id, ...makeForm })
    ElMessage.success(`成功生成${res.created}条兑换码`)
    makeVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    making.value = false
  }
}

// ============ 导入兑换码弹窗 ============
const importVisible = ref(false)
const importing = ref(false)
const importTarget = ref<LipinItem | null>(null)

function openImport(row: LipinItem) {
  importTarget.value = row
  importVisible.value = true
}

async function handleImportFile(options: UploadRequestOptions) {
  if (!importTarget.value) return
  importing.value = true
  try {
    const res = await importLipinCode(importTarget.value.id, options.file)
    ElMessage.success(`成功导入${res.inserted}条兑换码`)
    importVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    importing.value = false
  }
}

// ============ 查看兑换码弹窗 ============
const codeVisible = ref(false)
const codeLoading = ref(false)
const codeList = ref<LipinCodeItem[]>([])
const codeTotal = ref(0)
const codePage = ref(1)
const codeTarget = ref<LipinItem | null>(null)

async function openCodes(row: LipinItem) {
  codeTarget.value = row
  codePage.value = 1
  codeVisible.value = true
  await fetchCodes()
}

async function fetchCodes() {
  if (!codeTarget.value) return
  codeLoading.value = true
  try {
    const res = await getLipinCodeList({ page: codePage.value, pagesize: 10, hid: codeTarget.value.id })
    codeList.value = res.list || []
    codeTotal.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    codeLoading.value = false
  }
}

const CODE_STATUS: Record<number, string> = { 0: '未使用', 1: '已使用', 2: '已售' }

onMounted(() => {
  fetchList()
  fetchCategories()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>礼品卡管理</span>
          <div>
            <el-input
              v-model="query.name"
              placeholder="名称"
              clearable
              style="width: 200px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-button @click="handleSearch">搜索</el-button>
            <el-button type="success" @click="openAdd">新增礼品卡</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="名称" min-width="200">
          <template #default="{ row }">
            <span v-if="row.cname" class="cname">[{{ row.cname }}]</span>
            <span>{{ row.name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="兑换类型" width="100">
          <template #default="{ row }">{{ TYPE_MAP[Number(row.type)] || '-' }}</template>
        </el-table-column>
        <el-table-column label="兑换码总数" width="100">
          <template #default="{ row }">{{ row.totalcount || 0 }}</template>
        </el-table-column>
        <el-table-column label="已兑换数" width="90">
          <template #default="{ row }">{{ row.usedcount || 0 }}</template>
        </el-table-column>
        <el-table-column label="活动时间" min-width="260">
          <template #default="{ row }">
            {{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="activeStatus(row).type" size="small">{{ activeStatus(row).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="360" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="openCodes(row)">兑换码</el-button>
            <el-button link type="primary" @click="openMake(row)">生成兑换码</el-button>
            <el-button link type="primary" @click="openImport(row)">导入兑换码</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > 0"
        class="pager"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        layout="total, prev, pager, next"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑礼品卡' : '新增礼品卡'" width="640px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="left">
        <el-form-item label="名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入名称" maxlength="50" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="form.cid" style="width: 240px">
            <el-option :value="0" label="不选择分类" />
            <el-option v-for="c in categories" :key="c.id" :value="c.id" :label="c.name" />
          </el-select>
        </el-form-item>
        <el-form-item label="开始时间" prop="starttime">
          <el-date-picker v-model="form.starttime" type="datetime" placeholder="选择开始时间" value-format="X" />
        </el-form-item>
        <el-form-item label="结束时间" prop="endtime">
          <el-date-picker v-model="form.endtime" type="datetime" placeholder="选择结束时间" value-format="X" />
        </el-form-item>
        <el-form-item label="兑换类型">
          <el-radio-group v-model="form.type">
            <el-radio :value="0">余额</el-radio>
            <el-radio :value="1">商品</el-radio>
            <el-radio :value="2">积分</el-radio>
            <el-radio :value="3">优惠券</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.type === 0" label="余额金额">
          <el-input v-model="form.money" placeholder="兑换的余额金额" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 1" label="商品ID">
          <el-input v-model="form.prodata" placeholder="兑换的商品ID" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="积分数">
          <el-input v-model="form.score" placeholder="兑换的积分数" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 3" label="优惠券ID">
          <el-input v-model="form.coupon_ids" placeholder="优惠券ID，多个用英文逗号分隔" style="width: 320px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 生成兑换码弹窗 -->
    <el-dialog v-model="makeVisible" :title="'生成兑换码 - ' + (makeTarget?.name || '')" width="520px">
      <el-form label-width="100px" label-position="left">
        <el-form-item label="生成数量">
          <el-input-number v-model="makeForm.makecount" :min="1" :max="5000" :precision="0" :step="1" />
          <span class="form-tip">每次最多 5000</span>
        </el-form-item>
        <el-form-item label="位数">
          <el-input-number v-model="makeForm.codelength" :min="1" :max="52" :precision="0" :step="1" />
        </el-form-item>
        <el-form-item label="类型">
          <el-radio-group v-model="makeForm.codetype">
            <el-radio :value="1">纯数字</el-radio>
            <el-radio :value="2">大小写字母</el-radio>
            <el-radio :value="3">小写字母</el-radio>
            <el-radio :value="4">大写字母</el-radio>
            <el-radio :value="5">数字+小写字母</el-radio>
            <el-radio :value="6">数字+大写字母</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="图形码">
          <el-radio-group v-model="makeForm.qrtype">
            <el-radio :value="0">不生成</el-radio>
            <el-radio :value="1">二维码</el-radio>
            <el-radio :value="2">条形码</el-radio>
            <el-radio :value="3">小程序码</el-radio>
            <el-radio :value="4">公众号二维码</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="makeVisible = false">取消</el-button>
        <el-button type="primary" :loading="making" @click="handleMake">生成</el-button>
      </template>
    </el-dialog>

    <!-- 导入兑换码弹窗 -->
    <el-dialog v-model="importVisible" :title="'导入兑换码 - ' + (importTarget?.name || '')" width="460px">
      <div class="import-tip">上传 Excel 文件，首个 Sheet 第一列为兑换码（自动按兑换码去重）。</div>
      <el-upload
        drag
        :show-file-list="false"
        accept=".xlsx,.xls,.csv"
        :http-request="handleImportFile"
        :disabled="importing"
      >
        <div class="import-box">
          <div>{{ importing ? '导入中...' : '将文件拖到此处，或点击上传' }}</div>
          <div class="form-tip">支持 xlsx / xls / csv，10MB 以内</div>
        </div>
      </el-upload>
    </el-dialog>

    <!-- 查看兑换码弹窗 -->
    <el-dialog v-model="codeVisible" :title="'兑换码 - ' + (codeTarget?.name || '')" width="760px">
      <el-table v-loading="codeLoading" :data="codeList" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="code" label="兑换码" min-width="160" />
        <el-table-column label="使用人" min-width="140">
          <template #default="{ row }">
            <span v-if="row.mid">{{ row.nickname || '-' }}（ID: {{ row.mid }}）</span>
            <span v-else class="text-muted">尚未使用</span>
          </template>
        </el-table-column>
        <el-table-column label="兑换时间" width="160">
          <template #default="{ row }">{{ formatTime(row.usetime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 1 ? 'success' : row.status === 2 ? 'warning' : 'info'" size="small">
              {{ CODE_STATUS[row.status] || '-' }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="codeTotal > 0"
        class="pager"
        v-model:current-page="codePage"
        :page-size="10"
        :total="codeTotal"
        layout="total, prev, pager, next"
        @current-change="fetchCodes"
      />
    </el-dialog>
  </div>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.cname {
  margin-right: 4px;
  color: #f56c6c;
}

.text-muted {
  color: #909399;
}

.import-tip {
  margin-bottom: 10px;
  color: #909399;
  font-size: 12px;
}

.import-box {
  padding: 18px 0;
  text-align: center;
}
</style>
