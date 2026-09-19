<script setup lang="ts">
import { nextTick, onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import type { ElTree } from 'element-plus'
import {
  deleteUserGroup,
  getUserGroupDetail,
  getUserGroupList,
  getUserGroupMenuTree,
  saveUserGroup,
  type AuthTreeNode,
  type UserGroupItem,
} from '../../api/system'
import { formatTime } from '../../utils/time'

// ============ 列表 ============
const loading = ref(false)
const tableData = ref<UserGroupItem[]>([])
const total = ref(0)

const query = reactive({
  page: 1,
  pagesize: 10,
  name: '',
})

async function fetchList() {
  loading.value = true
  try {
    const data = await getUserGroupList({
      page: query.page,
      pagesize: query.pagesize,
      name: query.name || undefined,
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

function handlePageChange(page: number) {
  query.page = page
  fetchList()
}

function handleSizeChange(size: number) {
  query.pagesize = size
  query.page = 1
  fetchList()
}

// ============ 删除 ============
function handleDelete(row: UserGroupItem) {
  ElMessageBox.confirm(`确定要删除权限组「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteUserGroup(row.id)
        ElMessage.success('删除成功')
        fetchList()
      } catch {
        // 错误提示已由 axios 拦截器统一弹出
      }
    })
    .catch(() => {})
}

// ============ 权限树 ============
const treeLoading = ref(false)
const treeData = ref<AuthTreeNode[]>([])
const treeRef = ref<InstanceType<typeof ElTree>>()

async function fetchTree() {
  if (treeData.value.length) return
  treeLoading.value = true
  try {
    treeData.value = await getUserGroupMenuTree()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    treeLoading.value = false
  }
}

// 收集所有叶子 path（初始化回显只设叶子，父子联动由 el-tree 自动补全）
function collectLeafPaths(nodes: AuthTreeNode[]): Set<string> {
  const set = new Set<string>()
  const walk = (list: AuthTreeNode[]) => {
    for (const n of list) {
      if (n.child && n.child.length) walk(n.child)
      else set.add(n.path)
    }
  }
  walk(nodes)
  return set
}

// ============ 新增 / 编辑弹窗 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()
const submitting = ref(false)

const form = reactive({
  id: 0,
  name: '',
  sort: 0,
  status: 1,
  remark: '',
  auth_mode: 'keys' as 'all' | 'keys',
})

const rules: FormRules = {
  name: [{ required: true, message: '请输入权限组名称', trigger: 'blur' }],
}

function openAdd() {
  isEdit.value = false
  Object.assign(form, { id: 0, name: '', sort: 0, status: 1, remark: '', auth_mode: 'keys' })
  dialogVisible.value = true
  fetchTree().then(() => {
    nextTick(() => treeRef.value?.setCheckedKeys([]))
  })
}

async function openEdit(row: UserGroupItem) {
  isEdit.value = true
  Object.assign(form, {
    id: row.id,
    name: row.name,
    sort: row.sort,
    status: row.status,
    remark: row.remark || '',
    auth_mode: 'keys',
  })
  dialogVisible.value = true
  await fetchTree()
  try {
    const detail = await getUserGroupDetail(row.id)
    form.auth_mode = detail.auth_mode
    await nextTick()
    if (detail.auth_mode === 'keys') {
      const leafSet = collectLeafPaths(treeData.value)
      const leafKeys = (detail.auth_keys || []).filter((k) => leafSet.has(k))
      treeRef.value?.setCheckedKeys(leafKeys)
    } else {
      treeRef.value?.setCheckedKeys([])
    }
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return

    let authKeys: string[] = []
    if (form.auth_mode === 'keys') {
      // 勾选 + 半选父级一并入库（对标参考系统保存方式）
      const checked = treeRef.value?.getCheckedKeys(false) as string[]
      const half = treeRef.value?.getHalfCheckedKeys() as string[]
      authKeys = [...checked, ...half]
      if (!authKeys.length) {
        ElMessage.warning('请至少勾选一项菜单权限')
        return
      }
    }

    submitting.value = true
    try {
      await saveUserGroup({
        id: form.id || undefined,
        name: form.name,
        sort: form.sort,
        status: form.status,
        remark: form.remark,
        auth_mode: form.auth_mode,
        auth_keys: authKeys,
      })
      ElMessage.success(isEdit.value ? '保存成功' : '新增成功')
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误提示已由 axios 拦截器统一弹出
    } finally {
      submitting.value = false
    }
  })
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
        <el-form-item label="权限组名称">
          <el-input
            v-model="query.name"
            placeholder="请输入权限组名称"
            clearable
            style="width: 220px"
            @keyup.enter="handleSearch"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </el-form-item>
        <el-form-item style="float: right">
          <el-button type="success" @click="openAdd">新增权限组</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <!-- 权限组列表 -->
    <el-card shadow="never">
      <el-table v-loading="loading" :data="tableData" stripe>
        <el-table-column prop="id" label="ID" width="70" />
        <el-table-column prop="name" label="权限组名称" min-width="150" show-overflow-tooltip />
        <el-table-column label="权限范围" width="140">
          <template #default="{ row }">
            <el-tag v-if="row.auth_mode === 'all'" type="warning">所有权限</el-tag>
            <el-tag v-else>已选 {{ row.auth_count }} 项</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="member_count" label="成员数" width="90" />
        <el-table-column prop="sort" label="排序" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag v-if="row.status === 1" type="success">正常</el-tag>
            <el-tag v-else type="danger">停用</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
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
        @current-change="handlePageChange"
        @size-change="handleSizeChange"
      />
    </el-card>

    <!-- 新增 / 编辑弹窗（含权限树） -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑权限组' : '新增权限组'"
      width="720px"
      top="6vh"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="left"
      >
        <el-form-item label="权限组名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入权限组名称" style="width: 300px" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" :max="9999" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="正常"
            inactive-text="停用"
          />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="form.remark" type="textarea" :rows="2" placeholder="选填" />
        </el-form-item>
        <el-form-item label="权限范围">
          <el-radio-group v-model="form.auth_mode">
            <el-radio value="all">所有权限</el-radio>
            <el-radio value="keys">选择权限</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="form.auth_mode === 'keys'" label="菜单权限">
          <div v-loading="treeLoading" class="auth-tree-box">
            <el-tree
              v-if="treeData.length"
              ref="treeRef"
              :data="treeData"
              node-key="path"
              show-checkbox
              default-expand-all
              :props="{ label: 'name', children: 'child' }"
            />
            <el-empty v-else-if="!treeLoading" description="暂无菜单数据" :image-size="60" />
          </div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.toolbar-card {
  margin-bottom: 16px;
}

.toolbar-card :deep(.el-card__body) {
  padding-bottom: 2px;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}

.auth-tree-box {
  width: 100%;
  max-height: 380px;
  overflow: auto;
  border: 1px solid #ebeef5;
  border-radius: 6px;
  padding: 8px;
  box-sizing: border-box;
}
</style>
