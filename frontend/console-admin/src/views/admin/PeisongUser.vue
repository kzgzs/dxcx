<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance } from 'element-plus'
import {
  delPeisongUser,
  getPeisongUserList,
  savePeisongUser,
} from '../../api/peisong'
import type { PeisongUserItem } from '../../api/peisong'
import { formatTime } from '../../utils/time'
import LinkAddressModal from '../../components/LinkAddressModal.vue'
import PeisongOrder from './PeisongOrder.vue'

// 租户后台 - 同城配送·配送员（批次8 任务223；对标 canzhao PeisongUser）
// 列表 / 平铺增改 / 查看配送记录 三态互斥
type ViewMode = 'list' | 'form' | 'records'
const viewMode = ref<ViewMode>('list')

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

// 顶部「扫码查看」弹窗
const qrVisible = ref(false)

// 平铺增改
const formRef = ref<FormInstance>()
const form = reactive({
  id: 0,
  realname: '',
  tel: '',
  mid: 0,
  sort: 0,
  status: 1,
})
const formRules = {
  realname: [{ required: true, message: '请输入姓名', trigger: 'blur' }],
}

// 查看配送记录（按配送员过滤）
const recordsPsid = ref<string | number>('')
const recordsName = ref('')

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

function openAdd() {
  Object.assign(form, { id: 0, realname: '', tel: '', mid: 0, sort: 0, status: 1 })
  viewMode.value = 'form'
}

function openEdit(row: PeisongUserItem) {
  Object.assign(form, {
    id: row.id,
    realname: row.realname,
    tel: row.tel,
    mid: Number(row.mid) || 0,
    sort: Number(row.sort) || 0,
    status: Number(row.status) === 1 ? 1 : 0,
  })
  viewMode.value = 'form'
}

function backToList() {
  viewMode.value = 'list'
  fetchList()
}

async function handleSave() {
  if (!formRef.value) return
  await formRef.value.validate()
  await savePeisongUser({ ...form })
  ElMessage.success('保存成功')
  backToList()
}

async function handleDel(row?: PeisongUserItem) {
  const ids = row ? [row.id] : selection.value.map((v) => v.id)
  if (!ids.length) return ElMessage.warning('请先选择配送员')
  await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    type: 'warning',
    confirmButtonText: '确定',
    cancelButtonText: '取消',
  })
  await delPeisongUser(ids)
  ElMessage.success('删除成功')
  fetchList()
}

/** 查看配送记录：平铺进入该配送员的配送单（按 psid 过滤） */
function openRecords(row: PeisongUserItem) {
  recordsPsid.value = row.id
  recordsName.value = row.realname
  viewMode.value = 'records'
}

onMounted(() => {
  fetchList()
})
</script>

<template>
  <div class="peisong-user-page">
    <!-- ============ 列表 ============ -->
    <div v-if="viewMode === 'list'">
      <div class="page-title"><span class="title-bar">≡</span>配送员管理</div>

      <!-- 顶部提示条（含「扫码查看」） -->
      <div class="notice-bar">
        订单发货时可选择指定的配送员进行配送，请设置模板消息通知，派单后配送员会收到模板消息，
        <a class="notice-link" @click="qrVisible = true">扫码查看</a>
      </div>

      <!-- 工具栏：批量钮左 + 搜索右（同行不换行） -->
      <el-card shadow="never" class="toolbar-card">
        <div class="toolbar-left">
          <el-button type="success" @click="openAdd">添加</el-button>
          <el-button @click="handleDel()">删除</el-button>
        </div>
        <el-form inline class="toolbar-search" @submit.prevent>
          <el-form-item label="姓名">
            <el-input v-model="query.realname" clearable style="width: 130px" @keyup.enter="handleSearch" />
          </el-form-item>
          <el-form-item label="状态">
            <el-select v-model="query.status" placeholder="全部" clearable style="width: 110px">
              <el-option label="全部" value="" />
              <el-option label="已开启" value="1" />
              <el-option label="已关闭" value="0" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="success" class="search-btn" @click="handleSearch">
              <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
            </el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <!-- 表格（无 fixed，表头 #e6e6e6，内容居左） -->
      <el-card shadow="never">
        <el-table
          v-loading="loading"
          :data="tableData"
          border
          :header-cell-style="{ background: '#e6e6e6' }"
          @selection-change="(rows: PeisongUserItem[]) => (selection = rows)"
        >
          <el-table-column type="selection" width="42" />
          <el-table-column prop="id" label="ID" width="70" align="left" header-align="left" />
          <el-table-column prop="realname" label="配送员姓名" min-width="110" align="left" header-align="left" />
          <el-table-column prop="tel" label="配送员电话" min-width="120" align="left" header-align="left" />
          <el-table-column label="头像昵称" min-width="140" align="left" header-align="left">
            <template #default="{ row }">
              <div v-if="row.nickname" class="nick-cell">
                <img v-if="row.headimg" :src="row.headimg" class="nick-img" />
                <span>{{ row.nickname }}</span>
              </div>
              <span v-else-if="row.tel" class="sub-text">{{ row.tel.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2') }}</span>
            </template>
          </el-table-column>
          <el-table-column label="配送中/已完成" width="110" align="left" header-align="left">
            <template #default="{ row }">{{ row.pszCount || 0 }} / {{ row.ywcCount || 0 }}</template>
          </el-table-column>
          <el-table-column label="创建时间" width="150" align="left" header-align="left">
            <template #default="{ row }">
              {{ row.createtime ? formatTime(Number(row.createtime)).slice(0, 16) : '-' }}
            </template>
          </el-table-column>
          <el-table-column label="状态" width="80" align="left" header-align="left">
            <template #default="{ row }">
              <span :style="{ color: Number(row.status) === 1 ? '#67c23a' : '#f56c6c' }">
                {{ Number(row.status) === 1 ? '已开启' : '已关闭' }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="120" align="left" header-align="left">
            <template #default="{ row }">
              <div class="row-ops">
                <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
                <el-button link type="primary" size="small" @click="handleDel(row)">删除</el-button>
              </div>
              <el-button link type="primary" size="small" @click="openRecords(row)">查看配送记录</el-button>
            </template>
          </el-table-column>
          <template #empty>暂无数据</template>
        </el-table>

        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next, jumper"
          class="pagination"
          @change="fetchList"
        />
      </el-card>
    </div>

    <!-- ============ 平铺 增/改 配送员（右上 X，与列表互斥） ============ -->
    <div v-else-if="viewMode === 'form'" class="edit-panel">
      <div class="edit-head">
        <span class="edit-title">{{ form.id ? '✎ 编辑配送员' : '＋ 添加配送员' }}</span>
        <el-button class="edit-close" circle @click="backToList">
          <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M764.288 214.595 512 466.883 259.712 214.595a32 32 0 0 0-45.248 45.248L466.752 512 214.464 764.288a32 32 0 1 0 45.248 45.248L512 557.117l252.288 252.419a32 32 0 0 0 45.248-45.248L557.248 512l252.288-252.157a32 32 0 0 0-45.248-45.248z" /></svg>
        </el-button>
      </div>
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="90px" class="edit-form">
        <el-form-item label="姓名" prop="realname">
          <el-input v-model="form.realname" style="width: 220px" />
        </el-form-item>
        <el-form-item label="手机号">
          <el-input v-model="form.tel" style="width: 220px" />
        </el-form-item>
        <el-form-item label="会员ID">
          <el-input v-model="form.mid" style="width: 220px" />
        </el-form-item>
        <el-form-item label="序号">
          <el-input v-model="form.sort" style="width: 220px" />
          <span class="hint">用于排序,越大越靠前</span>
        </el-form-item>
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item>
          <el-button type="danger" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <!-- ============ 查看配送记录（按当前配送员 psid 过滤，内嵌配送单列表） ============ -->
    <div v-else-if="viewMode === 'records'">
      <PeisongOrder :psid="recordsPsid" embedded @close="backToList" />
    </div>

    <!-- 扫码查看（查看链接小弹窗，照截图） -->
    <LinkAddressModal v-model:visible="qrVisible" path="/activity/peisong/orderlist" name="配送员端" />
  </div>
</template>

<style scoped>
.peisong-user-page {
  display: block;
}
.page-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  padding: 4px 2px 8px;
}
.title-bar {
  margin-right: 4px;
}
/* 顶部提示条 */
.notice-bar {
  background: #f4f9f4;
  border-left: 3px solid #67c23a;
  padding: 10px 14px;
  font-size: 13px;
  color: #606266;
  margin-bottom: 3px;
}
.notice-link {
  color: #409eff;
  cursor: pointer;
}
.notice-link:hover {
  text-decoration: underline;
}
.toolbar-card {
  margin-bottom: 3px;
}
.toolbar-card :deep(.el-card__body) {
  padding: 10px 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: nowrap;
}
.toolbar-left {
  display: flex;
  gap: 8px;
  flex: 0 0 auto;
}
.toolbar-left .el-button {
  margin: 0;
}
.toolbar-search {
  display: flex;
  align-items: center;
  flex: 1 1 auto;
  justify-content: flex-end;
  flex-wrap: nowrap;
  margin: 0;
}
.toolbar-search :deep(.el-form-item) {
  margin-bottom: 0;
  margin-right: 8px;
}
.toolbar-search :deep(.el-form-item:last-child) {
  margin-right: 0;
}
.search-btn {
  padding: 8px 14px;
}
.search-btn svg {
  display: block;
}
.pagination {
  margin-top: 12px;
  justify-content: flex-end;
}
.nick-cell {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}
.nick-img {
  width: 32px;
  height: 32px;
  border-radius: 4px;
}
.sub-text {
  color: #909399;
  font-size: 12px;
}
.row-ops {
  display: flex;
  gap: 10px;
}
.row-ops .el-button {
  margin: 0;
}
/* 平铺增改 */
.edit-panel {
  background: #fff;
  border: 1px solid #e6e6e6;
  padding: 8px 20px 20px;
}
.edit-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #f0f0f0;
  margin-bottom: 16px;
}
.edit-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}
.edit-close {
  border: none;
  background: transparent;
  color: #909399;
}
.edit-close:hover {
  color: #409eff;
  background: transparent;
}
.edit-form :deep(.el-form-item) {
  margin-bottom: 16px;
}
.hint {
  color: #909399;
  font-size: 12px;
  margin-left: 10px;
}
</style>
