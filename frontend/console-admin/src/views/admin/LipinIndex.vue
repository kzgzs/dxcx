<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  deleteLipin,
  getLipinCodeList,
  getLipinDetail,
  getLipinList,
  getLipinSet,
  importLipinCode,
  makeLipinCode,
  saveLipin,
  saveLipinSet,
  lipinSetst,
  lipinCodelistDel,
  lipinCodelistExcel,
} from '../../api/lipin'
import type { LipinCodeItem, LipinItem, LipinSetData } from '../../api/lipin'
import { getLipinCategoryList } from '../../api/lipin'
import type { LipinCategoryItem } from '../../api/lipin'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import SimpleRichEditor from '../../components/SimpleRichEditor.vue'

const TYPE_MAP: Record<number, string> = { 0: '余额', 1: '商品', 2: '积分', 3: '优惠券' }
// 兑换码状态：0未售 1已使用 2已售（对齐截图）
const CODE_STATUS: Record<number, { text: string; type: 'info' | 'success' | 'danger' }> = {
  0: { text: '未售', type: 'info' },
  1: { text: '已使用', type: 'success' },
  2: { text: '已售', type: 'danger' },
}

// ============ 视图切换：列表 / 兑换码子视图（平铺主区域，非弹窗） ============
const viewMode = ref<'list' | 'codes'>('list')
const codeTarget = ref<LipinItem | null>(null)

// ============ 列表 ============
const loading = ref(false)
const list = ref<LipinItem[]>([])
const total = ref(0)
const selected = ref<LipinItem[]>([])
const query = reactive({ page: 1, pagesize: 10, name: '', cid: 0 })
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

function onSelectionChange(rows: LipinItem[]) {
  selected.value = rows
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
  if (et > 0 && now > et) return { text: '已结束', type: 'info' }
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
  name: [{ required: true, message: '请输入礼品卡名称', trigger: 'blur' }],
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
      starttime: detail.starttime ? String(detail.starttime) : '',
      endtime: detail.endtime ? String(detail.endtime) : '',
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

function handleDeleteRow(row: LipinItem) {
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

function handleDeleteSelected() {
  if (selected.value.length === 0) {
    ElMessage.warning('请先勾选礼品卡')
    return
  }
  const ids = selected.value.map((r) => r.id)
  ElMessageBox.confirm(`确定删除选中的 ${ids.length} 张礼品卡吗？`, '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        await deleteLipin(ids)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 设置 ============
const setVisible = ref(false)
const setSaving = ref(false)
const setForm = reactive<LipinSetData>({ status: 1, scanshow: 1, guize: '' })

async function openSet() {
  setVisible.value = true
  try {
    const res = await getLipinSet()
    Object.assign(setForm, { status: Number(res.status), scanshow: Number(res.scanshow), guize: res.guize || '' })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSetSubmit() {
  setSaving.value = true
  try {
    await saveLipinSet({ ...setForm })
    ElMessage.success('设置保存成功')
    setVisible.value = false
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setSaving.value = false
  }
}

// ============ 查看链接 ============
const linkVisible = ref(false)
function openLink() {
  linkVisible.value = true
}

// ============ 生成兑换码弹窗 ============
const makeVisible = ref(false)
const making = ref(false)
const makeTarget = ref<LipinItem | null>(null)
const makeForm = reactive({ makecount: 100, codelength: 8, codetype: 1, qrtype: 0 })

function openMake(row: LipinItem) {
  makeTarget.value = row
  Object.assign(makeForm, { makecount: 100, codelength: 8, codetype: 1, qrtype: 0 })
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
const importFileName = ref('')
const importFile = ref<File | null>(null)
const importInputRef = ref<HTMLInputElement | null>(null)

function openImport(row: LipinItem) {
  importTarget.value = row
  importFileName.value = ''
  importFile.value = null
  importVisible.value = true
}

function onPickFile(e: Event) {
  const files = (e.target as HTMLInputElement).files
  if (files && files.length > 0) {
    importFile.value = files[0]
    importFileName.value = files[0].name
  }
}

function triggerPick() {
  importInputRef.value?.click()
}

async function handleImport() {
  if (!importTarget.value) return
  if (!importFile.value) {
    ElMessage.warning('请先选择 Excel 文件')
    return
  }
  importing.value = true
  try {
    const res = await importLipinCode(importTarget.value.id, importFile.value)
    ElMessage.success(`成功导入${res.inserted}条兑换码`)
    importVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    importing.value = false
    if (importInputRef.value) importInputRef.value.value = ''
  }
}

function downloadImportFormat() {
  // 下载导入格式模板：CSV，首行表头「兑换码」
  const csv = '兑换码\nDEMO-CODE-001\nDEMO-CODE-002\n'
  const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = '礼品卡兑换码导入模板.csv'
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

// ============ 兑换码子视图（平铺主区域） ============
const codeLoading = ref(false)
const codeList = ref<LipinCodeItem[]>([])
const codeTotal = ref(0)
const codePage = ref(1)
const codeSelected = ref<LipinCodeItem[]>([])
const codeQuery = reactive({ code: '', mid: '', nickname: '', status: '' })

function openCodes(row: LipinItem) {
  codeTarget.value = row
  codePage.value = 1
  codeSelected.value = []
  Object.assign(codeQuery, { code: '', mid: '', nickname: '', status: '' })
  viewMode.value = 'codes'
  fetchCodes()
}

function backToList() {
  viewMode.value = 'list'
  codeTarget.value = null
  fetchList()
}

async function fetchCodes() {
  if (!codeTarget.value) return
  codeLoading.value = true
  try {
    const res = await getLipinCodeList({
      page: codePage.value,
      pagesize: 10,
      hid: codeTarget.value.id,
      code: codeQuery.code || undefined,
      mid: codeQuery.mid ? Number(codeQuery.mid) : undefined,
      nickname: codeQuery.nickname || undefined,
      status: codeQuery.status,
    })
    codeList.value = res.list || []
    codeTotal.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    codeLoading.value = false
  }
}

function handleCodeSearch() {
  codePage.value = 1
  fetchCodes()
}

function onCodeSelectionChange(rows: LipinCodeItem[]) {
  codeSelected.value = rows
}

async function codeSetStatus(st: number, ids?: number[]) {
  let idList = ids
  if (!idList) {
    idList = codeSelected.value.map((r) => r.id)
    if (idList.length === 0) {
      ElMessage.warning('请先勾选兑换码')
      return
    }
  }
  const label = st === 1 ? '已使用' : '已售'
  try {
    await ElMessageBox.confirm(`确定将 ${idList.length} 条兑换码改为「${label}」吗？`, '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await lipinSetst(idList, st)
    ElMessage.success('操作成功')
    fetchCodes()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function codeSetStatusRow(row: LipinCodeItem, st: number) {
  codeSetStatus(st, [row.id])
}

async function codeDelete(ids?: number[]) {
  let idList = ids
  if (!idList) {
    idList = codeSelected.value.map((r) => r.id)
    if (idList.length === 0) {
      ElMessage.warning('请先勾选兑换码')
      return
    }
  }
  try {
    await ElMessageBox.confirm(`确定删除选中的 ${idList.length} 条兑换码吗？`, '删除确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await lipinCodelistDel(idList)
    ElMessage.success('删除成功')
    fetchCodes()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function codeDeleteRow(row: LipinCodeItem) {
  codeDelete([row.id])
}

async function codeExport() {
  if (!codeTarget.value) return
  try {
    await lipinCodelistExcel({
      hid: codeTarget.value.id,
      code: codeQuery.code || undefined,
      mid: codeQuery.mid ? Number(codeQuery.mid) : undefined,
      nickname: codeQuery.nickname || undefined,
      status: codeQuery.status,
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

onMounted(() => {
  fetchList()
  fetchCategories()
})
</script>

<template>
  <div>
    <!-- ==================== 礼品卡列表视图 ==================== -->
    <el-card v-if="viewMode === 'list'" shadow="never">
      <!-- 顶部说明条 -->
      <div class="tip-bar">用于制作实体充值卡、礼品卡等，线下发卡线上兑换，可兑换余额或商品</div>

      <!-- 工具栏一行 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleDeleteSelected">删除</el-button>
          <el-button @click="openLink">查看链接</el-button>
          <el-button @click="openSet">设置</el-button>
        </div>
        <div class="toolbar-right">
          <el-select v-model="query.cid" placeholder="分类" clearable style="width: 130px" @change="handleSearch">
            <el-option :value="0" label="全部" />
            <el-option v-for="c in categories" :key="c.id" :value="c.id" :label="c.name" />
          </el-select>
          <el-input v-model="query.name" placeholder="名称" clearable style="width: 180px" @keyup.enter="handleSearch" @clear="handleSearch" />
          <el-button type="success" @click="handleSearch">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="loading" :data="list" stripe row-key="id" @selection-change="onSelectionChange">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="名称" min-width="180">
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
        <el-table-column label="活动时间" min-width="240">
          <template #default="{ row }">
            {{ formatTime(row.starttime) }} ~ {{ formatTime(row.endtime) }}
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="activeStatus(row).type" size="small">{{ activeStatus(row).text }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="300">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="openCodes(row)">兑换码</el-button>
            <el-button v-if="activeStatus(row).text === '进行中'" link type="primary" @click="openMake(row)">生成兑换码</el-button>
            <el-button v-if="activeStatus(row).text === '进行中'" link type="primary" @click="openImport(row)">导入兑换码</el-button>
            <el-button link type="danger" @click="handleDeleteRow(row)">删除</el-button>
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

    <!-- ==================== 兑换码子视图（平铺主区域） ==================== -->
    <el-card v-else shadow="never">
      <template #header>
        <div class="code-header">
          <span class="code-title">兑换码 - {{ codeTarget?.name || '' }}</span>
          <el-button size="small" @click="backToList">返回列表</el-button>
        </div>
      </template>

      <!-- 工具栏一行 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button type="warning" plain @click="codeSetStatus(1)">改为已使用</el-button>
          <el-button type="warning" plain @click="codeSetStatus(2)">改为已售</el-button>
          <el-button type="danger" plain @click="codeDelete()">删除</el-button>
          <el-button type="primary" plain @click="codeExport">导出</el-button>
        </div>
        <div class="toolbar-right">
          <el-input v-model="codeQuery.code" placeholder="兑换码" clearable style="width: 150px" @keyup.enter="handleCodeSearch" @clear="handleCodeSearch" />
          <el-input v-model="codeQuery.mid" placeholder="使用人ID" clearable style="width: 120px" @keyup.enter="handleCodeSearch" @clear="handleCodeSearch" />
          <el-input v-model="codeQuery.nickname" placeholder="使用人昵称" clearable style="width: 140px" @keyup.enter="handleCodeSearch" @clear="handleCodeSearch" />
          <el-select v-model="codeQuery.status" placeholder="状态" clearable style="width: 110px" @change="handleCodeSearch">
            <el-option value="0" label="未售" />
            <el-option value="1" label="已使用" />
            <el-option value="2" label="已售" />
          </el-select>
          <el-button type="success" @click="handleCodeSearch">搜索</el-button>
        </div>
      </div>

      <el-table v-loading="codeLoading" :data="codeList" stripe row-key="id" @selection-change="onCodeSelectionChange">
        <el-table-column type="selection" width="45" />
        <el-table-column prop="hid" label="活动ID" width="80" />
        <el-table-column prop="hname" label="活动名称" min-width="140" />
        <el-table-column prop="code" label="兑换码" min-width="150" />
        <el-table-column label="图形码" width="90">
          <template #default="{ row }">
            <el-image v-if="row.qr" :src="row.qr" class="qr-img" fit="cover" :preview-src-list="[row.qr]" />
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="使用人" min-width="160">
          <template #default="{ row }">
            <div v-if="row.mid" class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || '-' }}（ID: {{ row.mid }}）</span>
            </div>
            <span v-else class="text-muted">尚未使用</span>
          </template>
        </el-table-column>
        <el-table-column label="兑换时间" width="150">
          <template #default="{ row }">{{ formatTime(row.usetime) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="CODE_STATUS[Number(row.status)]?.type || 'info'" size="small">
              {{ CODE_STATUS[Number(row.status)]?.text || '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="remark" label="备注" min-width="120" />
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <el-button link type="primary" v-if="row.status !== 2" @click="codeSetStatusRow(row, 2)">改为已售</el-button>
            <el-button link type="primary" v-if="row.status !== 1" @click="codeSetStatusRow(row, 1)">改为已使用</el-button>
            <el-button link type="danger" @click="codeDeleteRow(row)">删除</el-button>
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
    </el-card>

    <!-- 新增 / 编辑弹窗（宽面板，右上X） -->
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑礼品卡' : '添加礼品卡'" width="860px">
      <el-form ref="formRef" :model="form" :rules="rules" label-width="100px" label-position="left">
        <el-form-item label="礼品卡名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入礼品卡名称" maxlength="50" style="width: 320px" />
        </el-form-item>
        <el-form-item label="分类">
          <el-select v-model="form.cid" placeholder="-请选择-" style="width: 240px">
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
        <el-form-item v-if="form.type === 0" label="兑换金额">
          <el-input v-model="form.money" placeholder="兑换的余额金额" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 1" label="商品ID">
          <el-input v-model="form.prodata" placeholder="兑换的商品ID" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 2" label="兑换积分">
          <el-input v-model="form.score" placeholder="兑换的积分数" style="width: 200px" />
        </el-form-item>
        <el-form-item v-if="form.type === 3" label="优惠券ID">
          <el-input v-model="form.coupon_ids" placeholder="优惠券ID，多个用英文逗号分隔" style="width: 320px" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="success" :loading="submitting" @click="handleSubmit">提交</el-button>
      </template>
    </el-dialog>

    <!-- 设置弹窗（宽面板，右上X） -->
    <el-dialog v-model="setVisible" title="礼品卡设置" width="860px">
      <el-form label-width="100px" label-position="left">
        <el-form-item label="状态">
          <el-radio-group v-model="setForm.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="扫一扫图标">
          <el-radio-group v-model="setForm.scanshow">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">不显示</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="规则说明">
          <SimpleRichEditor v-model="setForm.guize" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="success" :loading="setSaving" @click="handleSetSubmit">提交</el-button>
      </template>
    </el-dialog>

    <!-- 查看链接（全站统一弹窗） -->
    <LinkAddressModal v-model:visible="linkVisible" path="/pagesExt/lipin/index" name="礼品卡" />

    <!-- 生成兑换码弹窗 -->
    <el-dialog v-model="makeVisible" title="生成兑换码" width="520px">
      <el-form label-width="100px" label-position="left">
        <el-form-item label="生成数量">
          <el-input v-model.number="makeForm.makecount" :min="1" :max="5000" />
        </el-form-item>
        <el-form-item label="兑换码位数">
          <el-input v-model.number="makeForm.codelength" :min="1" :max="52" />
        </el-form-item>
        <el-form-item label="兑换码类型">
          <el-radio-group v-model="makeForm.codetype">
            <el-radio :value="1">纯数字</el-radio>
            <el-radio :value="2">大小写字母</el-radio>
            <el-radio :value="3">小写字母</el-radio>
            <el-radio :value="4">大写字母</el-radio>
            <el-radio :value="5">数字+小写字母</el-radio>
            <el-radio :value="6">数字+大写字母</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="图形码类型">
          <el-radio-group v-model="makeForm.qrtype">
            <el-radio :value="0">不生成</el-radio>
            <el-radio :value="1">二维码</el-radio>
            <el-radio :value="2">条形码</el-radio>
            <el-radio :value="3">链接二维码</el-radio>
            <el-radio :value="4">小程序码</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button type="primary" :loading="making" @click="handleMake">生成</el-button>
      </template>
    </el-dialog>

    <!-- 导入兑换码弹窗（对齐截图：选择文件+上传/格式模板/确定导入） -->
    <el-dialog v-model="importVisible" title="导入兑换码" width="460px">
      <div class="import-row">
        <span class="import-label">上传EXCEL文件：</span>
        <el-input v-model="importFileName" readonly placeholder="未选择文件" style="width: 220px" />
        <el-button @click="triggerPick">上传</el-button>
        <input ref="importInputRef" type="file" accept=".xlsx,.xls,.csv" style="display: none" @change="onPickFile" />
      </div>
      <div class="import-warn">请按照导入格式进行导入</div>
      <div class="import-format" @click="downloadImportFormat">点击下载查看导入格式</div>
      <template #footer>
        <el-button type="primary" :loading="importing" @click="handleImport">确定导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.tip-bar {
  padding: 8px 12px;
  margin-bottom: 8px;
  background: #f5f7fa;
  border-left: 3px solid #00b8a3;
  color: #606266;
  font-size: 13px;
}

.toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.toolbar-left {
  display: flex;
  gap: 3px;
}

.toolbar-right {
  display: flex;
  gap: 3px;
  align-items: center;
}

.code-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.code-title {
  font-weight: 600;
}

.pager {
  margin-top: 12px;
  justify-content: flex-end;
}

.cname {
  margin-right: 4px;
  color: #f56c6c;
}

.text-muted {
  color: #909399;
}

.qr-img {
  width: 70px;
  height: 70px;
  border-radius: 4px;
}

.member-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.import-row {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
}

.import-label {
  font-size: 13px;
  white-space: nowrap;
}

.import-warn {
  color: #f56c6c;
  font-size: 13px;
  margin-bottom: 8px;
}

.import-format {
  color: #409eff;
  font-size: 13px;
  cursor: pointer;
  margin-bottom: 8px;
}
</style>
