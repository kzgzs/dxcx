<template>
  <div class="card-code-page">
    <el-card shadow="never" class="list-card">
      <!-- 操作+搜索同一行：左侧操作按钮，右侧筛选与搜索 -->
      <div class="cc-bar">
        <div class="cc-actions">
          <el-button type="primary" @click="openMake">生成卡密</el-button>
          <el-button @click="openImport">导入卡密</el-button>
          <el-button @click="doExport">导出卡密</el-button>
          <el-button type="danger" plain :disabled="selected.length === 0" @click="doDelete">
            批量删除{{ selected.length > 0 ? `(${selected.length})` : '' }}
          </el-button>
        </div>
        <el-form inline class="cc-search" @submit.prevent>
          <el-form-item>
            <el-select
              v-model="query.proid"
              placeholder="商品"
              clearable
              filterable
              style="width: 180px"
              @change="reload"
            >
              <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-input v-model="query.content" placeholder="卡密内容" clearable style="width: 130px" @keyup.enter="reload" />
          </el-form-item>
          <el-form-item>
            <el-input v-model="query.ordernum" placeholder="订单号" clearable style="width: 130px" @keyup.enter="reload" />
          </el-form-item>
          <el-form-item>
            <el-input v-model="query.nickname" placeholder="昵称" clearable style="width: 120px" @keyup.enter="reload" />
          </el-form-item>
          <el-form-item>
            <el-select v-model="query.status" placeholder="状态" style="width: 90px" @change="reload">
              <el-option label="全部" value="" />
              <el-option label="未售出" :value="0" />
              <el-option label="已售出" :value="1" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" :icon="Search" @click="reload" />
          </el-form-item>
        </el-form>
      </div>

      <!-- 列表 -->
    <el-table
      v-loading="loading"
      :data="rows"
      border
      stripe
      style="width: 100%"
      @selection-change="(list: CodeItem[]) => (selected = list)"
    >
      <el-table-column type="selection" width="44" :selectable="(row: CodeItem) => row.status === 0" />
      <el-table-column label="ID" prop="id" width="70" />
      <el-table-column label="所属商品" min-width="140" show-overflow-tooltip>
        <template #default="{ row }">{{ goodsNameOf(row.proid) }}</template>
      </el-table-column>
      <el-table-column label="卡密" prop="content" min-width="200" show-overflow-tooltip />
      <el-table-column label="订单号" prop="ordernum" min-width="160" show-overflow-tooltip />
      <el-table-column label="购买会员" prop="nickname" min-width="120" show-overflow-tooltip />
      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.status === 1" type="success" size="small">已售出</el-tag>
          <el-tag v-else type="info" size="small">未售出</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="购买时间" prop="buy_time" width="160" />
      <el-table-column label="创建时间" prop="create_time" width="160" />
      <el-table-column label="操作" width="90" align="center">
        <template #default="{ row }">
          <el-button v-if="row.status === 0" type="danger" link @click="doDelete([row])">删除</el-button>
          <span v-else class="text-muted">—</span>
        </template>
      </el-table-column>
    </el-table>

    <!-- 分页 -->
    <div class="pager">
      <el-pagination
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :total="total"
        :page-sizes="[20, 50, 100]"
        layout="total, sizes, prev, pager, next, jumper"
        @current-change="loadList"
        @size-change="reload"
      />
    </div>
    </el-card>

    <!-- 生成卡密对话框 -->
    <el-dialog v-model="makeVisible" title="生成卡密" width="460px" destroy-on-close>
      <el-form :model="makeForm" label-width="90px">
        <el-form-item label="所属商品">
          <el-select v-model="makeForm.proid" placeholder="选择商品" filterable style="width: 100%">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="生成数量">
          <el-input-number v-model="makeForm.makecount" :min="1" :max="5000" style="width: 100%" />
        </el-form-item>
        <el-form-item label="卡密长度">
          <el-input-number v-model="makeForm.codelength" :min="6" :max="35" style="width: 100%" />
        </el-form-item>
        <el-form-item label="卡密类型">
          <el-select v-model="makeForm.codetype" style="width: 100%">
            <el-option label="纯数字" :value="1" />
            <el-option label="大写字母+小写字母" :value="2" />
            <el-option label="小写字母" :value="3" />
            <el-option label="大写字母" :value="4" />
            <el-option label="数字+小写字母" :value="5" />
            <el-option label="数字+大写字母" :value="6" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="makeVisible = false">取消</el-button>
        <el-button type="primary" :loading="makeLoading" @click="doMake">确定生成</el-button>
      </template>
    </el-dialog>

    <!-- 导入卡密对话框 -->
    <el-dialog v-model="importVisible" title="导入卡密" width="460px" destroy-on-close>
      <el-form label-width="90px">
        <el-form-item label="所属商品">
          <el-select v-model="importProid" placeholder="选择商品" filterable style="width: 100%">
            <el-option v-for="g in goodsOptions" :key="g.id" :label="g.name" :value="g.id" />
          </el-select>
        </el-form-item>
      </el-form>
      <el-upload
        drag
        :auto-upload="false"
        :limit="1"
        accept=".xlsx,.xls,.csv"
        :on-change="(f: UploadFile) => (importFile = (f.raw as File) || null)"
        :on-exceed="() => ElMessage.warning('一次仅可上传一个文件')"
      >
        <el-icon class="el-icon--upload"><upload-filled /></el-icon>
        <div class="el-upload__text">拖拽文件到此处，或<em>点击选择</em></div>
        <template #tip>
          <div class="el-upload__tip">支持 xlsx/xls/csv，首列为卡密内容，重复卡密自动跳过</div>
        </template>
      </el-upload>
      <template #footer>
        <el-button @click="importVisible = false">取消</el-button>
        <el-button type="primary" :loading="importLoading" @click="doImport">开始导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { UploadFile } from 'element-plus'
import { UploadFilled, Search } from '@element-plus/icons-vue'
import { getGoodsList } from '../../api/goods'
import { deleteCode, exportCode, getCodeList, importCode, makeCode } from '../../api/shopCode'
import type { CodeItem, GoodsItem } from '../../api/types'

defineOptions({ name: 'CardCode' })

// ===== 列表 =====
const loading = ref(false)
const rows = ref<CodeItem[]>([])
const total = ref(0)
const selected = ref<CodeItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  proid: '' as number | '',
  content: '',
  ordernum: '',
  nickname: '',
  status: '' as number | '',
})

async function loadList() {
  loading.value = true
  try {
    const res = await getCodeList({
      page: query.page,
      pagesize: query.pagesize,
      proid: query.proid === '' ? undefined : query.proid,
      content: query.content,
      ordernum: query.ordernum,
      nickname: query.nickname,
      status: query.status,
    })
    rows.value = res.list
    total.value = res.total
  } finally {
    loading.value = false
  }
}

function reload() {
  query.page = 1
  loadList()
}

// ===== 商品选项（筛选/生成/导入共用） =====
const goodsOptions = ref<GoodsItem[]>([])

async function loadGoods() {
  const res = await getGoodsList({ page: 1, pagesize: 500 })
  goodsOptions.value = res.list
}

function goodsNameOf(proid: number) {
  return goodsOptions.value.find((g) => g.id === proid)?.name || `商品#${proid}`
}

// ===== 生成卡密 =====
const makeVisible = ref(false)
const makeLoading = ref(false)
const makeForm = reactive({
  proid: '' as number | '',
  makecount: 100,
  codelength: 16,
  codetype: 5,
})

function openMake() {
  makeForm.proid = query.proid
  makeVisible.value = true
}

async function doMake() {
  if (!makeForm.proid) {
    ElMessage.warning('请选择所属商品')
    return
  }
  makeLoading.value = true
  try {
    const res = await makeCode({
      proid: makeForm.proid as number,
      makecount: makeForm.makecount,
      codelength: makeForm.codelength,
      codetype: makeForm.codetype,
    })
    ElMessage.success(`成功生成${res.created}条卡密`)
    makeVisible.value = false
    reload()
  } finally {
    makeLoading.value = false
  }
}

// ===== 导入卡密 =====
const importVisible = ref(false)
const importLoading = ref(false)
const importProid = ref<number | ''>('')
const importFile = ref<File | null>(null)

function openImport() {
  importProid.value = query.proid
  importFile.value = null
  importVisible.value = true
}

async function doImport() {
  if (!importProid.value) {
    ElMessage.warning('请选择所属商品')
    return
  }
  if (!importFile.value) {
    ElMessage.warning('请选择要导入的 Excel 文件')
    return
  }
  importLoading.value = true
  try {
    const res = await importCode(importProid.value as number, importFile.value)
    ElMessage.success(`成功新增${res.inserted}条，重复跳过${res.duplicated}条`)
    importVisible.value = false
    reload()
  } finally {
    importLoading.value = false
  }
}

// ===== 导出 =====
async function doExport() {
  await exportCode(typeof query.proid === 'number' ? query.proid : 0)
  ElMessage.success('导出成功')
}

// ===== 删除（仅未售出） =====
async function doDelete(target?: CodeItem[]) {
  const list = target && target.length > 0 ? target : selected.value
  if (list.length === 0) {
    return
  }
  await ElMessageBox.confirm(`确认删除选中的 ${list.length} 条未售出卡密？`, '提示', { type: 'warning' })
  const res = await deleteCode(list.map((v) => v.id))
  ElMessage.success(`已删除${res.deleted}条`)
  reload()
}

onMounted(() => {
  loadGoods()
  loadList()
})
</script>

<style scoped>
.card-code-page {
  padding: 0;
}
.list-card :deep(.el-card__body) {
  padding: 10px 12px 12px;
}
/* 操作与搜索同一行：左操作、右搜索，排不下时横向滚动而不是换行 */
.cc-bar {
  display: flex;
  align-items: center;
  flex-wrap: nowrap;
  gap: 12px;
  margin-bottom: 10px;
}
.cc-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}
.cc-search {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: nowrap;
  overflow-x: auto;
}
.cc-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
  flex: 0 0 auto;
  min-width: max-content;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
.text-muted {
  color: #c0c4cc;
}
</style>
