<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  delWxleimu,
  getWxleimuList,
  getWxleimuOptions,
  saveWxleimu,
} from '../../api/wxext'
import type { WxCategoryItem, WxCategoryOption, WxleimuSaveItem } from '../../api/wxext'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 资质图片选择（任务294：ImgIconPicker，默认素材库，多选） ============
const imgPickerVisible = ref(false)
const imgPickTarget = ref(0)

function openImgPicker(index: number) {
  imgPickTarget.value = index
  imgPickerVisible.value = true
}

function onImgPick(urls: string[]) {
  if (urls.length) rows.value[imgPickTarget.value]?.certicates.push(...urls)
}

const loading = ref(false)
const tableData = ref<WxCategoryItem[]>([])
const createtype = ref(0)
const isMock = ref(false)

const createtypeText = computed(() => {
  if (createtype.value === 1) return '开放平台代注册'
  if (createtype.value === 2) return '开放平台代创建'
  return '自行注册'
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getWxleimuList()
    tableData.value = data.list || []
    createtype.value = data.createtype || 0
    isMock.value = !!data.is_mock
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ===== 审核状态（1 审核中 2 未通过 3 已通过） =====
function auditTag(status: number | undefined) {
  if (status === 1) return { type: 'warning', text: '审核中' }
  if (status === 2) return { type: 'danger', text: '未通过' }
  return { type: 'success', text: '已通过' }
}

// ===== 添加类目弹窗 =====
interface AddRow {
  value: string // first_second
  certicates: string[]
}

const addVisible = ref(false)
const saving = ref(false)
const options = ref<WxCategoryOption[]>([])
const rows = ref<AddRow[]>([])

// 可选类目按一级类目分组（级联展示 first_name > second_name）
const optionGroups = computed(() => {
  const groups: Array<{ first: number; first_name: string; children: WxCategoryOption[] }> = []
  for (const opt of options.value) {
    let g = groups.find((x) => x.first === opt.first)
    if (!g) {
      g = { first: opt.first, first_name: opt.first_name, children: [] }
      groups.push(g)
    }
    g.children.push(opt)
  }
  return groups
})

function currentOption(value: string): WxCategoryOption | undefined {
  return options.value.find((o) => `${o.first}_${o.second}` === value)
}

function addRow() {
  if (rows.value.length >= 5) {
    ElMessage.warning('单次最多添加 5 个类目')
    return
  }
  rows.value.push({ value: '', certicates: [] })
}

function removeRow(index: number) {
  rows.value.splice(index, 1)
}

async function openAdd() {
  try {
    const data = await getWxleimuOptions()
    options.value = data.list || []
  } catch {
    options.value = []
  }
  rows.value = [{ value: '', certicates: [] }]
  addVisible.value = true
}

function removeCert(rowIndex: number, certIndex: number) {
  rows.value[rowIndex].certicates.splice(certIndex, 1)
}

async function handleSave() {
  if (rows.value.length === 0) {
    ElMessage.warning('请至少选择一个类目')
    return
  }
  const payload: WxleimuSaveItem[] = []
  for (const row of rows.value) {
    if (!row.value) {
      ElMessage.warning('存在未选择的类目')
      return
    }
    const [first, second] = row.value.split('_').map(Number)
    payload.push({ first, second, certicates: row.certicates })
  }
  saving.value = true
  try {
    await saveWxleimu(payload)
    ElMessage.success('提交成功')
    addVisible.value = false
    fetchList()
  } catch {
    // 拦截器已提示
  } finally {
    saving.value = false
  }
}

// ===== 删除 =====
async function handleDel(row: WxCategoryItem) {
  try {
    await ElMessageBox.confirm(
      `确定删除类目「${row.first_class || row.first_name} > ${row.second_class || row.second_name}」吗？`,
      '提示',
      { type: 'warning' },
    )
  } catch {
    return
  }
  try {
    await delWxleimu(row.first, row.second)
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    // 拦截器已提示
  }
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 顶部说明 -->
    <el-card shadow="never" class="tip-card">
      <div class="tip-line">
        <span>小程序注册方式：<b>{{ createtypeText }}</b></span>
        <el-tag v-if="isMock" type="info" size="small" class="mock-tag">MOCK 演示数据</el-tag>
      </div>
      <div class="tip-desc">
        服务类目为小程序能力接口（无本地存储），添加/删除直接透传微信接口；审核结果以微信侧为准。
      </div>
    </el-card>

    <!-- 工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-button type="primary" @click="openAdd">添加类目</el-button>
      <el-button @click="fetchList">刷新</el-button>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe border size="small">
        <el-table-column label="类目名称" min-width="220">
          <template #default="{ row }">
            {{ row.first_class || row.first_name }} &gt; {{ row.second_class || row.second_name }}
          </template>
        </el-table-column>
        <el-table-column label="一级类目ID" prop="first" width="110" class-name="compact-col" />
        <el-table-column label="二级类目ID" prop="second" width="110" class-name="compact-col" />
        <el-table-column label="状态" width="130">
          <template #default="{ row }">
            <el-tooltip
              v-if="row.audit_status === 2 && row.audit_reason"
              :content="row.audit_reason"
              placement="top"
            >
              <el-tag :type="auditTag(row.audit_status).type" size="small">
                {{ auditTag(row.audit_status).text }}
              </el-tag>
            </el-tooltip>
            <el-tag v-else :type="auditTag(row.audit_status).type" size="small">
              {{ auditTag(row.audit_status).text }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button type="danger" link size="small" @click="handleDel(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 添加类目弹窗 -->
    <el-dialog v-model="addVisible" title="添加服务类目" width="640px" :close-on-click-modal="false">
      <div v-for="(row, index) in rows" :key="index" class="add-row">
        <div class="add-row-head">
          <span class="add-row-title">类目 {{ index + 1 }}</span>
          <el-button v-if="rows.length > 1" type="danger" link size="small" @click="removeRow(index)">
            移除
          </el-button>
        </div>
        <el-form label-width="80px" @submit.prevent>
          <el-form-item label="选择类目">
            <el-select v-model="row.value" placeholder="请选择类目" style="width: 100%" filterable>
              <el-option-group
                v-for="group in optionGroups"
                :key="group.first"
                :label="group.first_name"
              >
                <el-option
                  v-for="opt in group.children"
                  :key="`${opt.first}_${opt.second}`"
                  :label="`${opt.first_name} > ${opt.second_name}`"
                  :value="`${opt.first}_${opt.second}`"
                />
              </el-option-group>
            </el-select>
          </el-form-item>
          <el-form-item v-if="currentOption(row.value)?.exter_list?.length" label="资质要求">
            <div class="cert-require">
              <el-tag v-for="(name, i) in currentOption(row.value)!.exter_list" :key="i" size="small" type="warning">
                {{ name }}
              </el-tag>
            </div>
          </el-form-item>
          <el-form-item label="资质图片">
            <div class="cert-list">
              <div v-for="(cert, ci) in row.certicates" :key="ci" class="cert-thumb">
                <img :src="cert" alt="资质图片" />
                <span class="cert-remove" @click="removeCert(index, ci)">×</span>
              </div>
              <el-button size="small" @click="openImgPicker(index)">选择图片</el-button>
            </div>
          </el-form-item>
        </el-form>
      </div>

      <el-button v-if="rows.length < 5" plain size="small" @click="addRow">+ 再加一个类目</el-button>

      <template #footer>
        <el-button @click="addVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">提交</el-button>
      </template>
    </el-dialog>

    <!-- 选图弹窗（任务294，多选） -->
    <ImgIconPicker
      v-model:visible="imgPickerVisible"
      multi
      default-tab="library"
      @pick="(urls: string[]) => onImgPick(urls)"
    />
  </div>
</template>

<style scoped>
.tip-card {
  margin-bottom: 12px;
}

.tip-line {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 14px;
  color: #303133;
}

.mock-tag {
  margin-left: 4px;
}

.tip-desc {
  margin-top: 6px;
  color: #909399;
  font-size: 12px;
}

.toolbar-card {
  margin-bottom: 12px;
}

.add-row {
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 12px 12px 0;
  margin-bottom: 12px;
}

.add-row-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.add-row-title {
  font-weight: 600;
  color: #303133;
}

.cert-require {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.cert-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.cert-thumb {
  position: relative;
  width: 64px;
  height: 64px;
}

.cert-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  border-radius: 4px;
  border: 1px solid #ebeef5;
}

.cert-remove {
  position: absolute;
  top: -6px;
  right: -6px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  background: #f56c6c;
  color: #fff;
  border-radius: 50%;
  cursor: pointer;
  font-size: 13px;
}

/* 紧凑列：收紧单元格左右内边距 */
:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
