<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  delPeisongUser,
  getPeisongUserList,
  savePeisongUser,
  setstPeisongUser,
} from '../../api/peisong'
import type { PeisongUserItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'

// 租户后台 - 同城配送·配送员（批次8 任务223；对标参考系统 PeisongUser）

const loading = ref(false)
const tableData = ref<PeisongUserItem[]>([])
const total = ref(0)
const selection = ref<PeisongUserItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 10,
  realname: '',
  status: '',
})

// 编辑弹窗
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({
  id: 0,
  realname: '',
  tel: '',
  status: 1,
  sort: 0,
  weixin: '',
  aliaccount: '',
  bankname: '',
  bankcarduser: '',
  bankcardnum: '',
  longitude: '',
  latitude: '',
})
const formRules = {
  realname: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
}

async function fetchList() {
  loading.value = true
  try {
    const data = await getPeisongUserList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname || undefined,
      status: query.status === '' ? undefined : query.status,
    })
    tableData.value = data.list || []
    total.value = data.total || 0
  } catch {
    // axios 拦截器已提示
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.realname = ''
  query.status = ''
  query.page = 1
  fetchList()
}

function handleAdd() {
  Object.assign(form, {
    id: 0, realname: '', tel: '', status: 1, sort: 0, weixin: '', aliaccount: '',
    bankname: '', bankcarduser: '', bankcardnum: '', longitude: '', latitude: '',
  })
  dialogVisible.value = true
}

function handleEdit(row: PeisongUserItem) {
  Object.assign(form, {
    id: row.id,
    realname: row.realname,
    tel: row.tel,
    status: Number(row.status),
    sort: Number(row.sort),
    weixin: row.weixin || '',
    aliaccount: row.aliaccount || '',
    bankname: row.bankname || '',
    bankcarduser: row.bankcarduser || '',
    bankcardnum: row.bankcardnum || '',
    longitude: row.longitude || '',
    latitude: row.latitude || '',
  })
  dialogVisible.value = true
}

async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate()
  await savePeisongUser({ ...form })
  ElMessage.success('保存成功')
  dialogVisible.value = false
  fetchList()
}

async function handleStatusChange(row: PeisongUserItem, val: number | string | boolean) {
  try {
    await setstPeisongUser({ ids: [row.id], st: Number(val) })
  } catch {
    fetchList()
  }
}

async function handleBatchSetst(st: number) {
  const ids = selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择配送员')
  await setstPeisongUser({ ids, st })
  ElMessage.success('操作成功')
  fetchList()
}

async function handleDel(row?: PeisongUserItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择配送员')
  await ElMessageBox.confirm(`确认删除选中的 ${ids.length} 名配送员？`, '提示', { type: 'warning' })
  await delPeisongUser(ids)
  ElMessage.success('删除成功')
  fetchList()
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div>
    <!-- 搜索工具栏 -->
    <el-card shadow="never" class="toolbar-card">
      <el-form inline @submit.prevent>
        <el-form-item label="姓名">
          <el-input v-model="query.realname" placeholder="配送员姓名" clearable style="width: 140px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
            <el-option label="启用" value="1" />
            <el-option label="停用" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 列表 -->
    <el-card shadow="never">
      <div class="op-row">
        <el-button type="primary" @click="handleAdd">添加配送员</el-button>
        <el-button @click="handleBatchSetst(1)">批量启用</el-button>
        <el-button @click="handleBatchSetst(0)">批量停用</el-button>
        <el-button type="danger" plain @click="handleDel()">批量删除</el-button>
      </div>

      <el-table v-loading="loading" :data="tableData" stripe border size="small" @selection-change="(rows: PeisongUserItem[]) => (selection = rows)">
        <el-table-column type="selection" width="42" />
        <el-table-column prop="id" label="ID" width="56" class-name="compact-col" />
        <el-table-column label="配送员" min-width="120" show-overflow-tooltip>
          <template #default="{ row }">
            {{ row.realname }}
            <span v-if="row.tel" class="sub-text">{{ row.tel }}</span>
          </template>
        </el-table-column>
        <el-table-column label="可提现余额" width="100" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.money).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="累计收入" width="100" class-name="compact-col">
          <template #default="{ row }">￥{{ Number(row.totalmoney).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column prop="totalnum" label="累计单量" width="80" class-name="compact-col" />
        <el-table-column label="评分" width="108" class-name="compact-col">
          <template #default="{ row }">
            {{ Number(row.comment_score).toFixed(1) }}分
            <span class="sub-text">好评{{ row.comment_haopercent }}%</span>
          </template>
        </el-table-column>
        <el-table-column label="配送中/完成" width="96" class-name="compact-col">
          <template #default="{ row }">{{ row.pszCount || 0 }} / {{ row.ywcCount || 0 }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="64" class-name="compact-col" />
        <el-table-column label="状态" width="76" class-name="compact-col">
          <template #default="{ row }">
            <el-switch :model-value="Number(row.status) === 1" @change="(val: number | string | boolean) => handleStatusChange(row, val)" />
          </template>
        </el-table-column>
        <el-table-column label="加入时间" width="88" class-name="compact-col">
          <template #default="{ row }">
            <el-tooltip v-if="row.createtime" :content="formatTime(row.createtime)" placement="top">
              <span>{{ formatTime(row.createtime).slice(2, 10) }}</span>
            </el-tooltip>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="110" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDel(row)">删除</el-button>
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
        @current-change="(p: number) => { query.page = p; fetchList() }"
        @size-change="(s: number) => { query.pagesize = s; query.page = 1; fetchList() }"
      />
    </el-card>

    <!-- 编辑弹窗 -->
    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑配送员' : '添加配送员'" width="560px" destroy-on-close>
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="100px">
        <el-form-item label="姓名" prop="realname">
          <el-input v-model="form.realname" placeholder="配送员姓名" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="form.tel" placeholder="联系手机号" />
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">启用</el-radio>
            <el-radio :value="0">停用</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="微信号">
          <el-input v-model="form.weixin" placeholder="微信收款/联系" />
        </el-form-item>
        <el-form-item label="支付宝账号">
          <el-input v-model="form.aliaccount" placeholder="支付宝收款账号" />
        </el-form-item>
        <el-form-item label="开户银行">
          <el-input v-model="form.bankname" placeholder="银行卡开户行" />
        </el-form-item>
        <el-form-item label="持卡人">
          <el-input v-model="form.bankcarduser" placeholder="银行卡持卡人" />
        </el-form-item>
        <el-form-item label="银行卡号">
          <el-input v-model="form.bankcardnum" placeholder="银行卡号" />
        </el-form-item>
        <el-form-item label="经度">
          <el-input v-model="form.longitude" placeholder="文本填写，如 116.397128" />
        </el-form-item>
        <el-form-item label="纬度">
          <el-input v-model="form.latitude" placeholder="文本填写，如 39.916527" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 12px;
}

.op-row {
  margin-bottom: 12px;
}

.sub-text {
  color: #909399;
  font-size: 12px;
  margin-left: 4px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

:deep(.compact-col .cell) {
  padding-left: 6px;
  padding-right: 6px;
}
</style>
