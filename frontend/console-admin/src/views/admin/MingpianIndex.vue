<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  deleteMingpian,
  deleteMingpianLog,
  getMingpianFavoritelog,
  getMingpianList,
  getMingpianReadlog,
  saveMingpian,
} from '../../api/mingpian'
import type { MingpianItem, MingpianLogItem } from '../../api/mingpian'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

// ============ 列表 ============
const loading = ref(false)
const list = ref<MingpianItem[]>([])
const total = ref(0)
const query = reactive({ page: 1, pagesize: 10, realname: '', tel: '', nickname: '' })

async function fetchList() {
  loading.value = true
  try {
    const res = await getMingpianList({
      page: query.page,
      pagesize: query.pagesize,
      realname: query.realname,
      tel: query.tel,
      nickname: query.nickname,
    })
    list.value = res.list || []
    total.value = res.total || 0
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

function formatTime(ts: number) {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

function touxianText(row: MingpianItem) {
  return [row.touxian1, row.touxian2, row.touxian3].filter(Boolean).join(' / ') || '-'
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)

const form = reactive({
  id: 0,
  mid: 0,
  bgpic: '',
  headimg: '',
  realname: '',
  touxian1: '',
  touxian2: '',
  touxian3: '',
  tel: '',
  weixin: '',
  address: '',
  email: '',
  douyin: '',
  weibo: '',
  toutiao: '',
  field1: '',
  field2: '',
  field3: '',
  field4: '',
  field5: '',
  detail: '',
  sharetitle: '',
})

function resetForm() {
  Object.assign(form, {
    id: 0, mid: 0, bgpic: '', headimg: '', realname: '',
    touxian1: '', touxian2: '', touxian3: '',
    tel: '', weixin: '', address: '', email: '', douyin: '', weibo: '', toutiao: '',
    field1: '', field2: '', field3: '', field4: '', field5: '',
    detail: '', sharetitle: '',
  })
}

function openAdd() {
  isEdit.value = false
  resetForm()
  dialogVisible.value = true
}

function openEdit(row: MingpianItem) {
  isEdit.value = true
  resetForm()
  Object.assign(form, {
    id: row.id,
    mid: row.mid,
    bgpic: row.bgpic || '',
    headimg: row.headimg || '',
    realname: row.realname || '',
    touxian1: row.touxian1 || '',
    touxian2: row.touxian2 || '',
    touxian3: row.touxian3 || '',
    tel: row.tel || '',
    weixin: row.weixin || '',
    address: row.address || '',
    email: row.email || '',
    douyin: row.douyin || '',
    weibo: row.weibo || '',
    toutiao: row.toutiao || '',
    field1: row.field1 || '',
    field2: row.field2 || '',
    field3: row.field3 || '',
    field4: row.field4 || '',
    field5: row.field5 || '',
    detail: row.detail || '',
    sharetitle: row.sharetitle || '',
  })
  dialogVisible.value = true
}

// ============ 选图（ImgIconPicker 统一选图） ============
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

async function handleSubmit() {
  if (!form.realname.trim()) {
    ElMessage.warning('请填写姓名')
    return
  }
  submitting.value = true
  try {
    await saveMingpian({ ...form, id: form.id || undefined })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

// ============ 删除 ============
function handleDelete(row: MingpianItem) {
  ElMessageBox.confirm(`确定要删除「${row.realname}」的名片吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteMingpian([row.id])
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 浏览 / 收藏记录弹窗 ============
const logVisible = ref(false)
const logType = ref<'readlog' | 'favoritelog'>('readlog')
const logLoading = ref(false)
const logList = ref<MingpianLogItem[]>([])
const logTotal = ref(0)
const logPage = ref(1)
const currentMp = ref<MingpianItem | null>(null)

async function openLog(row: MingpianItem, type: 'readlog' | 'favoritelog') {
  currentMp.value = row
  logType.value = type
  logPage.value = 1
  logVisible.value = true
  await fetchLog()
}

async function fetchLog() {
  if (!currentMp.value) return
  logLoading.value = true
  try {
    const params = { page: logPage.value, pagesize: 10, mpid: currentMp.value.id }
    const res = logType.value === 'readlog'
      ? await getMingpianReadlog(params)
      : await getMingpianFavoritelog(params)
    logList.value = res.list || []
    logTotal.value = res.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    logLoading.value = false
  }
}

function handleLogDelete(row: MingpianLogItem) {
  ElMessageBox.confirm('确定要删除该记录吗？', '删除确认', { type: 'warning' })
    .then(async () => {
      try {
        await deleteMingpianLog(logType.value === 'readlog' ? 'mingpian_readlog' : 'mingpian_favorite', [row.id])
        ElMessage.success('删除成功')
        fetchLog()
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

onMounted(fetchList)
</script>

<template>
  <div>
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>名片管理</span>
          <div>
            <el-input
              v-model="query.realname"
              placeholder="姓名"
              clearable
              style="width: 140px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-input
              v-model="query.tel"
              placeholder="电话"
              clearable
              style="width: 140px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-input
              v-model="query.nickname"
              placeholder="会员昵称"
              clearable
              style="width: 140px; margin-right: 8px"
              @keyup.enter="handleSearch"
              @clear="handleSearch"
            />
            <el-button @click="handleSearch">搜索</el-button>
            <el-button type="success" @click="openAdd">新增名片</el-button>
          </div>
        </div>
      </template>

      <el-table v-loading="loading" :data="list" stripe row-key="id">
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="mid" label="会员ID" width="90" />
        <el-table-column label="会员信息" min-width="160">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="32" />
              <span>{{ row.nickname || '-' }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column prop="realname" label="姓名" min-width="100" />
        <el-table-column label="头衔" min-width="160">
          <template #default="{ row }">{{ touxianText(row) }}</template>
        </el-table-column>
        <el-table-column prop="tel" label="电话" min-width="120" />
        <el-table-column label="浏览" width="80">
          <template #default="{ row }">
            <el-tag type="info" size="small">{{ row.readcount || 0 }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="收藏" width="80">
          <template #default="{ row }">
            <el-tag type="warning" size="small">{{ row.favcount || 0 }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="150">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="270" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="openLog(row, 'readlog')">查看记录</el-button>
            <el-button link type="primary" @click="openLog(row, 'favoritelog')">收藏记录</el-button>
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
    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑名片' : '新增名片'" width="680px">
      <el-form :model="form" label-width="90px" label-position="left">
        <el-form-item label="会员ID">
          <el-input-number v-model="form.mid" :min="0" :precision="0" :step="1" />
          <span class="form-tip">归属会员（0 为不关联）</span>
        </el-form-item>
        <el-form-item label="姓名" required>
          <el-input v-model="form.realname" placeholder="请输入姓名" maxlength="30" />
        </el-form-item>
        <el-form-item label="头衔1">
          <el-input v-model="form.touxian1" placeholder="头衔1" maxlength="50" />
        </el-form-item>
        <el-form-item label="头衔2">
          <el-input v-model="form.touxian2" placeholder="头衔2" maxlength="50" />
        </el-form-item>
        <el-form-item label="头衔3">
          <el-input v-model="form.touxian3" placeholder="头衔3" maxlength="50" />
        </el-form-item>
        <el-form-item label="背景图">
          <div class="upload-line">
            <el-image v-if="form.bgpic" :src="form.bgpic" class="thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker((u) => (form.bgpic = u))">{{ form.bgpic ? '更换' : '选择图片' }}</el-button>
          </div>
        </el-form-item>
        <el-form-item label="头像">
          <div class="upload-line">
            <el-image v-if="form.headimg" :src="form.headimg" class="thumb" fit="cover" />
            <el-button size="small" @click="openImgPicker((u) => (form.headimg = u))">{{ form.headimg ? '更换' : '选择图片' }}</el-button>
          </div>
        </el-form-item>
        <el-form-item label="电话">
          <el-input v-model="form.tel" placeholder="电话" maxlength="30" />
        </el-form-item>
        <el-form-item label="微信">
          <el-input v-model="form.weixin" placeholder="微信" maxlength="50" />
        </el-form-item>
        <el-form-item label="地址">
          <el-input v-model="form.address" placeholder="地址" maxlength="200" />
        </el-form-item>
        <el-form-item label="邮箱">
          <el-input v-model="form.email" placeholder="邮箱" maxlength="100" />
        </el-form-item>
        <el-form-item label="抖音">
          <el-input v-model="form.douyin" placeholder="抖音" maxlength="100" />
        </el-form-item>
        <el-form-item label="微博">
          <el-input v-model="form.weibo" placeholder="微博" maxlength="100" />
        </el-form-item>
        <el-form-item label="头条">
          <el-input v-model="form.toutiao" placeholder="头条" maxlength="100" />
        </el-form-item>
        <el-form-item label="自定义1">
          <el-input v-model="form.field1" placeholder="自定义字段1" maxlength="200" />
        </el-form-item>
        <el-form-item label="自定义2">
          <el-input v-model="form.field2" placeholder="自定义字段2" maxlength="200" />
        </el-form-item>
        <el-form-item label="自定义3">
          <el-input v-model="form.field3" placeholder="自定义字段3" maxlength="200" />
        </el-form-item>
        <el-form-item label="自定义4">
          <el-input v-model="form.field4" placeholder="自定义字段4" maxlength="200" />
        </el-form-item>
        <el-form-item label="自定义5">
          <el-input v-model="form.field5" placeholder="自定义字段5" maxlength="200" />
        </el-form-item>
        <el-form-item label="详细介绍">
          <el-input v-model="form.detail" type="textarea" :rows="4" placeholder="详细介绍" />
        </el-form-item>
        <el-form-item label="分享标题">
          <el-input v-model="form.sharetitle" placeholder="分享标题" maxlength="100" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>

    <!-- 浏览 / 收藏记录弹窗 -->
    <el-dialog v-model="logVisible" :title="(logType === 'readlog' ? '浏览记录' : '收藏记录') + ' - ' + (currentMp?.realname || '')" width="720px">
      <el-table v-loading="logLoading" :data="logList" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column label="会员" min-width="180">
          <template #default="{ row }">
            <div class="member-cell">
              <el-avatar v-if="row.headimg" :src="row.headimg" :size="28" />
              <span>{{ row.nickname || '-' }}（ID: {{ row.mid }}）</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="90">
          <template #default="{ row }">
            <el-button link type="danger" @click="handleLogDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        v-if="logTotal > 0"
        class="pager"
        v-model:current-page="logPage"
        :page-size="10"
        :total="logTotal"
        layout="total, prev, pager, next"
        @current-change="fetchLog"
      />
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
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

.member-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}

.upload-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.thumb {
  width: 70px;
  height: 48px;
  border-radius: 4px;
}
</style>
