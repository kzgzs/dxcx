<template>
  <div class="mpfans-list">
    <!-- 工具栏 -->
    <el-card class="toolbar-card" shadow="never">
      <el-form inline>
        <el-form-item label="昵称">
          <el-input v-model="query.keyword" placeholder="昵称/openid" clearable style="width: 180px" @keyup.enter="handleSearch" />
        </el-form-item>
        <el-form-item label="标签">
          <el-select v-model="query.tagid" placeholder="全部标签" clearable style="width: 160px">
            <el-option v-for="t in tags" :key="t.tagid" :label="t.name" :value="t.tagid" />
          </el-select>
        </el-form-item>
        <el-form-item label="关注状态">
          <el-select v-model="query.subscribe" placeholder="全部" clearable style="width: 130px">
            <el-option label="已关注" value="1" />
            <el-option label="未关注" value="0" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
          <el-button @click="handleReset">重置</el-button>
        </el-form-item>
        <el-form-item>
          <el-button type="success" :loading="syncing" @click="handleSync">同步粉丝</el-button>
          <el-button @click="tagDialogVisible = true">标签管理</el-button>
          <el-button type="warning" plain :disabled="!selected.length" @click="openSetTag">批量打标签</el-button>
        </el-form-item>
      </el-form>
      <div v-if="syncing || syncDone" class="sync-tip">
        <el-alert :title="syncText" :type="syncDone ? 'success' : 'info'" :closable="false" show-icon />
      </div>
    </el-card>

    <!-- 粉丝列表 -->
    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="list"
        stripe
        border
        size="small"
        @selection-change="(rows: FansItem[]) => (selected = rows)"
      >
        <el-table-column type="selection" width="44" />
        <el-table-column label="粉丝" min-width="200" class-name="compact-col">
          <template #default="{ row }">
            <div class="fans-cell">
              <el-avatar :size="34" :src="row.headimgurl || undefined">{{ (row.nickname || '?').slice(0, 1) }}</el-avatar>
              <div class="fans-info">
                <div class="fans-name">{{ row.nickname || '未知' }}</div>
                <div class="fans-openid">{{ row.openid }}</div>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="地区" min-width="140" class-name="compact-col">
          <template #default="{ row }">{{ [row.province, row.city].filter(Boolean).join(' ') || '-' }}</template>
        </el-table-column>
        <el-table-column label="标签" min-width="150" class-name="compact-col">
          <template #default="{ row }">
            <el-tag v-for="name in splitTag(row.tagname)" :key="name" size="small" class="tag-item">{{ name }}</el-tag>
            <span v-if="!row.tagname">-</span>
          </template>
        </el-table-column>
        <el-table-column label="关注状态" width="90" align="center" class-name="compact-col">
          <template #default="{ row }">
            <el-tag :type="row.subscribe === 1 ? 'success' : 'info'" size="small">{{ row.subscribe === 1 ? '已关注' : '未关注' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="关注时间" width="160" align="center" class-name="compact-col">
          <template #default="{ row }">{{ row.subscribe_time ? formatTime(row.subscribe_time) : '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" align="center" fixed="right" class-name="compact-col">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" size="small" @click="openSetTagSingle(row)">打标签</el-button>
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination
        class="pagination"
        background
        layout="total, sizes, prev, pager, next, jumper"
        :total="total"
        v-model:current-page="query.page"
        v-model:page-size="query.pagesize"
        :page-sizes="[10, 20, 50, 100]"
        @current-change="fetchList"
        @size-change="handleSearch"
      />
    </el-card>

    <!-- 标签管理弹窗 -->
    <el-dialog v-model="tagDialogVisible" title="标签管理" width="560px" destroy-on-close>
      <div class="tag-toolbar">
        <el-button type="primary" size="small" @click="openTagEdit(0)">新增标签</el-button>
        <el-button size="small" :loading="tagSyncing" @click="handleSyncTags">同步微信标签</el-button>
      </div>
      <el-table :data="tags" border size="small">
        <el-table-column label="标签ID" prop="tagid" width="90" align="center" />
        <el-table-column label="标签名称" prop="name" min-width="140" />
        <el-table-column label="粉丝数" prop="count" width="90" align="center" />
        <el-table-column label="操作" width="130" align="center">
          <template #default="{ row }">
            <div class="op-wrap">
              <el-button link type="primary" size="small" @click="openTagEdit(row.id)">编辑</el-button>
              <el-button link type="danger" size="small" @click="handleTagDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 标签编辑弹窗 -->
    <el-dialog v-model="tagEditVisible" :title="tagForm.id ? '编辑标签' : '新增标签'" width="400px" destroy-on-close>
      <el-form label-width="80px">
        <el-form-item label="标签名称">
          <el-input v-model="tagForm.name" placeholder="请输入标签名称" maxlength="20" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="tagEditVisible = false">取消</el-button>
        <el-button type="primary" :loading="tagSaving" @click="handleTagSave">保存</el-button>
      </template>
    </el-dialog>

    <!-- 打标签弹窗 -->
    <el-dialog v-model="setTagVisible" :title="`设置标签（${setTagFans.length} 位粉丝）`" width="420px" destroy-on-close>
      <el-checkbox-group v-model="setTagIds">
        <el-checkbox v-for="t in tags" :key="t.tagid" :label="t.tagid">{{ t.name }}</el-checkbox>
      </el-checkbox-group>
      <template #footer>
        <el-button @click="setTagVisible = false">取消</el-button>
        <el-button type="primary" :loading="setTagSaving" @click="handleSetTag">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getFansList,
  deleteFans,
  setFansTag,
  syncFans,
  getTagsList,
  saveTag,
  deleteTags,
  syncTags,
  type FansItem,
  type FansTagItem,
} from '../../api/mpfans'
import { formatTime } from '../../utils/time'

const loading = ref(false)
const list = ref<FansItem[]>([])
const total = ref(0)
const selected = ref<FansItem[]>([])
const tags = ref<FansTagItem[]>([])

const query = reactive({
  page: 1,
  pagesize: 20,
  keyword: '',
  tagid: undefined as number | undefined,
  subscribe: '',
})

// ---------- 列表 ----------
async function fetchList() {
  loading.value = true
  try {
    const res = await getFansList({
      page: query.page,
      pagesize: query.pagesize,
      keyword: query.keyword || undefined,
      tagid: query.tagid,
      subscribe: query.subscribe || undefined,
    })
    list.value = res.list || []
    total.value = res.total || 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.page = 1
  fetchList()
}

function handleReset() {
  query.keyword = ''
  query.tagid = undefined
  query.subscribe = ''
  handleSearch()
}

function splitTag(tagname: string): string[] {
  return tagname ? tagname.split(',').filter(Boolean) : []
}

async function fetchTags() {
  const res = await getTagsList()
  tags.value = res.list || []
}

// ---------- 删除粉丝 ----------
async function handleDelete(row: FansItem) {
  await ElMessageBox.confirm(`确定删除粉丝「${row.nickname || row.openid}」？`, '提示', { type: 'warning' })
  await deleteFans([row.id])
  ElMessage.success('删除成功')
  fetchList()
}

// ---------- 四步同步（前端驱动：getpage→逐页 getopenid→每100 getuserinfo→xiuzheng） ----------
const syncing = ref(false)
const syncDone = ref(false)
const syncText = ref('')
const syncFetched = ref(0)

async function handleSync() {
  syncing.value = true
  syncDone.value = false
  syncFetched.value = 0
  try {
    // 第一步：取总页数
    syncText.value = '正在获取粉丝总量…'
    const pg = await syncFans({ op: 'getpage' })
    const totalpage = pg.totalpage || 1
    syncText.value = `共 ${pg.total} 位粉丝 / ${totalpage} 页，开始拉取 openid…`

    // 第二步+第三步：逐页取 openid，每 100 个批量取用户信息
    let next = ''
    for (let p = 1; p <= totalpage; p++) {
      const r = await syncFans({ op: 'getopenid', next_openid: next })
      next = r.next_openid || ''
      for (const chunk of r.openids || []) {
        await syncFans({ op: 'getuserinfo', openids: chunk })
        syncFetched.value += chunk.length
        syncText.value = `正在同步第 ${p}/${totalpage} 页，已获取 ${syncFetched.value} 位粉丝…`
      }
    }

    // 第四步：校正清理失效 openid
    syncText.value = '正在校正清理失效粉丝…'
    const fix = await syncFans({ op: 'xiuzheng' })
    syncText.value = `同步完成：共获取 ${syncFetched.value} 位粉丝，清理失效 ${fix.fixed || 0} 位`
    syncDone.value = true
    ElMessage.success('粉丝同步完成')
    await fetchTags()
    handleSearch()
  } catch (e) {
    syncDone.value = true
    syncText.value = '同步中断，可点击「同步粉丝」重试'
  } finally {
    syncing.value = false
  }
}

// ---------- 标签管理 ----------
const tagDialogVisible = ref(false)
const tagSyncing = ref(false)
const tagEditVisible = ref(false)
const tagSaving = ref(false)
const tagForm = reactive({ id: 0, name: '' })

function openTagEdit(id: number) {
  const row = id > 0 ? tags.value.find((t) => t.id === id) : undefined
  tagForm.id = id
  tagForm.name = row?.name || ''
  tagEditVisible.value = true
}

async function handleTagSave() {
  if (!tagForm.name.trim()) {
    ElMessage.warning('请输入标签名称')
    return
  }
  tagSaving.value = true
  try {
    await saveTag({ id: tagForm.id || undefined, name: tagForm.name.trim() })
    ElMessage.success('保存成功')
    tagEditVisible.value = false
    fetchTags()
  } finally {
    tagSaving.value = false
  }
}

async function handleTagDelete(row: FansTagItem) {
  await ElMessageBox.confirm(`确定删除标签「${row.name}」？`, '提示', { type: 'warning' })
  await deleteTags([row.tagid])
  ElMessage.success('删除成功')
  fetchTags()
}

async function handleSyncTags() {
  tagSyncing.value = true
  try {
    await syncTags()
    ElMessage.success('标签同步成功')
    fetchTags()
  } finally {
    tagSyncing.value = false
  }
}

// ---------- 打标签 ----------
const setTagVisible = ref(false)
const setTagSaving = ref(false)
const setTagFans = ref<FansItem[]>([])
const setTagIds = ref<number[]>([])

function openSetTag() {
  setTagFans.value = [...selected.value]
  setTagIds.value = []
  setTagVisible.value = true
}

function openSetTagSingle(row: FansItem) {
  setTagFans.value = [row]
  setTagIds.value = row.tag ? String(row.tag).split(',').map(Number).filter(Boolean) : []
  setTagVisible.value = true
}

async function handleSetTag() {
  setTagSaving.value = true
  try {
    await setFansTag(setTagFans.value.map((f) => f.id), setTagIds.value)
    ElMessage.success('设置成功')
    setTagVisible.value = false
    fetchList()
    fetchTags()
  } finally {
    setTagSaving.value = false
  }
}

onMounted(() => {
  fetchList()
  fetchTags()
})
</script>

<style scoped>
.mpfans-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.sync-tip {
  margin-top: 4px;
}
.fans-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.fans-info {
  min-width: 0;
}
.fans-name {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.fans-openid {
  font-size: 12px;
  color: #909399;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.tag-item {
  margin-right: 4px;
}
.op-wrap {
  display: flex;
  justify-content: center;
  flex-wrap: nowrap;
}
.tag-toolbar {
  margin-bottom: 10px;
  display: flex;
  gap: 8px;
}
.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
