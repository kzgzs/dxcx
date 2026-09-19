<script setup lang="ts">
// 商城-商品分类：三级树形分类（添加/删除/全部展开/全部折叠/刷新 + 图片/序号/显示隐藏/添加子分类）
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import type { FormInstance, FormRules } from 'element-plus'
import {
  addCategory,
  deleteCategory,
  editCategory,
  getCategoryList,
} from '../../api/goods'
import type { CategoryItem } from '../../api/types'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

interface CatRow extends CategoryItem {
  children?: CatRow[]
  deep?: number
}

// ============ 列表（一次拉全量，前端组三级树） ============
const loading = ref(false)
const flatList = ref<CategoryItem[]>([])
const tableRef = ref<any>(null)
const selectedRows = ref<CatRow[]>([])

async function fetchList() {
  loading.value = true
  try {
    const data = await getCategoryList({ page: 1, pagesize: 1000 })
    flatList.value = data.list || []
  } catch {
    // 错误由请求拦截器统一提示
  } finally {
    loading.value = false
  }
}

// 扁平列表 -> 树（递归，最多三级），标注 deep；空 children 删除以免出现展开箭头
const treeData = computed<CatRow[]>(() => {
  const map = new Map<number, CatRow>()
  flatList.value.forEach((r) => map.set(r.id, { ...r }))
  const roots: CatRow[] = []
  map.forEach((node) => {
    const parent = node.pid ? map.get(node.pid) : undefined
    if (parent) {
      ;(parent.children ||= []).push(node)
    } else {
      roots.push(node)
    }
  })
  const mark = (list: CatRow[], deep: number) => {
    list.forEach((n) => {
      n.deep = deep
      if (n.children && n.children.length) {
        mark(n.children, deep + 1)
      } else {
        delete n.children
      }
    })
  }
  mark(roots, 0)
  return roots
})

function walkTree(list: CatRow[], cb: (n: CatRow) => void) {
  list.forEach((n) => {
    cb(n)
    if (n.children) walkTree(n.children, cb)
  })
}

// 全部展开 / 全部折叠
function setAllExpand(open: boolean) {
  walkTree(treeData.value, (n) => tableRef.value?.toggleRowExpansion(n, open))
}

// 数据刷新后默认全部展开（对齐参考系统默认展开；手动折叠在下次刷新前保持）
watch(treeData, () => {
  nextTick(() => setAllExpand(true))
})

function onSelectionChange(rows: CatRow[]) {
  selectedRows.value = rows
}

// ============ 新增 / 编辑 ============
const dialogVisible = ref(false)
const isEdit = ref(false)
const saving = ref(false)
const formRef = ref<FormInstance>()
const form = reactive({ id: 0, pid: 0, name: '', sort: 0, pic: '', status: 1 })

const rules: FormRules = {
  name: [{ required: true, message: '请输入分类名称', trigger: 'blur' }],
}

// 上级分类下拉：仅一、二级可作为父（保证最多三级）；编辑时排除自身及其子孙，防止成环
const parentOptions = computed<{ id: number; name: string; deep: number }[]>(() => {
  const blocked = new Set<number>()
  if (isEdit.value && form.id) {
    const collect = (n: CatRow) => {
      blocked.add(n.id)
      n.children?.forEach(collect)
    }
    const find = (list: CatRow[]): boolean => {
      for (const n of list) {
        if (n.id === form.id) {
          collect(n)
          return true
        }
        if (n.children && find(n.children)) return true
      }
      return false
    }
    find(treeData.value)
  }
  const opts: { id: number; name: string; deep: number }[] = []
  walkTree(treeData.value, (n) => {
    if ((n.deep ?? 0) <= 1 && !blocked.has(n.id)) {
      opts.push({ id: n.id, name: n.name, deep: n.deep ?? 0 })
    }
  })
  return opts
})

function resetForm(pid: number) {
  form.id = 0
  form.pid = pid
  form.name = ''
  form.sort = 0
  form.pic = ''
  form.status = 1
}

function openAdd() {
  isEdit.value = false
  resetForm(0)
  dialogVisible.value = true
}

function openAddChild(row: CatRow) {
  isEdit.value = false
  resetForm(row.id)
  dialogVisible.value = true
}

function openEdit(row: CatRow) {
  isEdit.value = true
  form.id = row.id
  form.pid = row.pid
  form.name = row.name
  form.sort = row.sort
  form.pic = row.pic || ''
  form.status = row.status ?? 1
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    saving.value = true
    try {
      if (isEdit.value) {
        await editCategory({
          id: form.id,
          pid: form.pid,
          name: form.name,
          sort: form.sort,
          pic: form.pic,
          status: form.status,
        })
        ElMessage.success('保存成功')
      } else {
        await addCategory({
          pid: form.pid,
          name: form.name,
          sort: form.sort,
          pic: form.pic,
          status: form.status,
        })
        ElMessage.success('新增成功')
      }
      dialogVisible.value = false
      fetchList()
    } catch {
      // 错误由请求拦截器统一提示
    } finally {
      saving.value = false
    }
  })
}

// ============ 删除 ============
function confirmDelete(ids: number[]) {
  ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', {
    confirmButtonText: '确定',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      await deleteCategory(ids.length === 1 ? ids[0] : ids)
      ElMessage.success('删除成功')
      fetchList()
    })
    .catch(() => {})
}

function handleRowDelete(row: CatRow) {
  confirmDelete([row.id])
}

function handleBatchDelete() {
  if (!selectedRows.value.length) {
    ElMessage.warning('请选择数据')
    return
  }
  confirmDelete(selectedRows.value.map((r) => r.id))
}

// ============ 选图（复用统一选图/图标弹窗，单张） ============
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

onMounted(fetchList)
</script>

<template>
  <div class="goods-category">
    <!-- 顶部工具按钮 -->
    <el-card shadow="never" class="toolbar-card">
      <el-button type="success" @click="openAdd">添加</el-button>
      <el-button @click="handleBatchDelete">删除</el-button>
      <el-button @click="setAllExpand(true)">全部展开</el-button>
      <el-button @click="setAllExpand(false)">全部折叠</el-button>
      <el-button @click="fetchList">刷新</el-button>
    </el-card>

    <!-- 三级树形分类表 -->
    <el-card shadow="never">
      <el-table
        ref="tableRef"
        v-loading="loading"
        :data="treeData"
        border
        row-key="id"
        default-expand-all
        :tree-props="{ children: 'children' }"
        @selection-change="onSelectionChange"
      >
        <el-table-column type="selection" width="48" />
        <el-table-column prop="id" label="ID" width="120" />
        <el-table-column prop="name" label="名称" min-width="220" />
        <el-table-column label="图片" width="100">
          <template #default="{ row }">
            <el-image
              v-if="row.pic"
              :src="row.pic"
              style="width: 50px; height: 50px"
              fit="contain"
              :preview-src-list="[row.pic]"
              preview-teleported
            />
          </template>
        </el-table-column>
        <el-table-column prop="sort" label="序号" width="80" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <span :class="row.status === 1 ? 'st-on' : 'st-off'">
              {{ row.status === 1 ? '显示' : '隐藏' }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="250">
          <template #default="{ row }">
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleRowDelete(row)">删除</el-button>
            <el-button
              v-if="(row.deep ?? 0) < 2"
              link
              type="primary"
              @click="openAddChild(row)"
            >
              添加子分类
            </el-button>
          </template>
        </el-table-column>
        <template #empty>暂无数据</template>
      </el-table>
    </el-card>

    <!-- 添加 / 编辑商品分类 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑商品分类' : '添加商品分类'"
      width="520px"
      destroy-on-close
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="90px"
        label-position="right"
      >
        <el-form-item label="上级分类">
          <el-select v-model="form.pid" style="width: 100%">
            <el-option label="--作为顶级分类--" :value="0" />
            <el-option
              v-for="o in parentOptions"
              :key="o.id"
              :label="(o.deep > 0 ? '　　' : '') + o.name"
              :value="o.id"
            />
          </el-select>
        </el-form-item>

        <el-form-item label="分类名称" prop="name">
          <el-input v-model="form.name" maxlength="30" placeholder="请输入分类名称" />
          <span class="aux">部分前端页面仅显示4个字符</span>
        </el-form-item>

        <el-form-item label="分类图标">
          <div class="pic-line">
            <div v-if="form.pic" class="pic-preview">
              <el-image :src="form.pic" style="width: 60px; height: 60px" fit="cover" />
              <span class="pic-del" @click="form.pic = ''">×</span>
            </div>
            <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">
              {{ form.pic ? '更换图片' : '上传图片' }}
            </el-button>
            <span class="aux">建议尺寸：400×400像素</span>
          </div>
        </el-form-item>

        <el-form-item label="序号">
          <el-input-number
            v-model="form.sort"
            :min="0"
            :step="1"
            controls-position="right"
            style="width: 150px"
          />
          <span class="aux">用于排序，越大越靠前</span>
        </el-form-item>

        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">显示</el-radio>
            <el-radio :value="0">隐藏</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSubmit">提交</el-button>
      </template>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.goods-category {
  padding: 12px;
}

.toolbar-card {
  margin-bottom: 12px;
}

.toolbar-card :deep(.el-card__body) {
  padding: 12px 16px;
}

.st-on {
  color: green;
}

.st-off {
  color: red;
}

.aux {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}

.pic-line {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.pic-preview {
  position: relative;
  width: 60px;
  height: 60px;
}

.pic-del {
  position: absolute;
  top: -8px;
  right: -8px;
  width: 18px;
  height: 18px;
  line-height: 16px;
  text-align: center;
  border-radius: 50%;
  background: #909399;
  color: #fff;
  cursor: pointer;
  font-size: 12px;
}
</style>
