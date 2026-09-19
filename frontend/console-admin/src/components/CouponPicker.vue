<script setup lang="ts">
/**
 * 优惠券选择器组件（设计器优惠券组件属性面板用）
 * 弹窗展示优惠券列表，每行一个"选择"按钮，点击即选并关闭
 */
import { ref, reactive, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getCouponManageList, type CouponManageItem } from '../api/couponManage'

interface Props {
  visible: boolean
}

interface Emits {
  (e: 'update:visible', value: boolean): void
  (e: 'select', item: CouponManageItem): void
}

const props = withDefaults(defineProps<Props>(), {})
const emit = defineEmits<Emits>()

const pickerVisible = ref(props.visible)
const couponList = ref<CouponManageItem[]>([])
const total = ref(0)
const loading = ref(false)
const keyword = ref('')
const page = ref(1)
const pagesize = ref(10)

async function loadList() {
  loading.value = true
  try {
    const res = await getCouponManageList({
      keyword: keyword.value,
      page: page.value,
      pagesize: pagesize.value
    })
    couponList.value = res.list || []
    total.value = res.total || 0
  } catch (error) {
    console.error('加载优惠券列表失败:', error)
    ElMessage.error('加载优惠券列表失败')
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  loadList()
}

function onPageChange(val: number) {
  page.value = val
  loadList()
}

/** 点击选择按钮：直接 emit 并关闭 */
function handleSelect(row: CouponManageItem) {
  emit('select', row)
  pickerVisible.value = false
  emit('update:visible', false)
}

function handleClose() {
  pickerVisible.value = false
  emit('update:visible', false)
}

watch(() => props.visible, (newVal) => {
  pickerVisible.value = newVal
  if (newVal) {
    page.value = 1
    keyword.value = ''
    loadList()
  }
}, { immediate: true })

onMounted(() => {
  if (pickerVisible.value) loadList()
})
</script>

<template>
  <el-dialog
    v-model="pickerVisible"
    title="选择优惠券"
    width="820px"
    :close-on-click-modal="false"
    destroy-teleport-node
    @close="handleClose"
  >
    <!-- 搜索栏 -->
    <div class="cp-search-bar">
      <el-input
        v-model="keyword"
        placeholder="名称"
        clearable
        style="width: 260px"
        @keyup.enter="handleSearch"
      >
        <template #append>
          <el-button @click="handleSearch">搜索</el-button>
        </template>
      </el-input>
    </div>

    <!-- 优惠券列表 -->
    <el-table
      v-loading="loading"
      :data="couponList"
      border
      stripe
      style="width: 100%;"
      max-height="420"
    >
      <el-table-column prop="id" label="ID" width="70" align="center" />
      <el-table-column prop="name" label="名称" min-width="140" show-overflow-tooltip />
      <el-table-column prop="type_txt" label="类型" width="90" align="center">
        <template #default="{ row }">
          <el-tag size="small" :type="row.type === 1 ? '' : row.type === 3 ? 'success' : row.type === 10 ? 'warning' : 'info'">
            {{ row.type_txt }}
          </el-tag>
        </template>
      </el-table-column>
      <el-table-column label="优惠金额" width="110" align="center">
        <template #default="{ row }">
          <span v-if="row.type === 1">¥{{ row.money }}</span>
          <span v-else-if="row.type === 10">{{ row.discount }}%</span>
          <span v-else-if="row.type === 3">{{ row.value || '-' }}次</span>
          <span v-else>-</span>
        </template>
      </el-table-column>
      <el-table-column prop="stock" label="库存" width="70" align="center" />
      <el-table-column prop="getnum" label="已领取" width="70" align="center" />
      <el-table-column label="活动时间" min-width="180">
        <template #default="{ row }">
          <div style="font-size: 12px; line-height: 1.5;">
            {{ row.starttime }}<br/>
            ~ {{ row.endtime }}
          </div>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="90" align="center">
        <template #default="{ row }">
          <el-tag v-if="row.phase === 'ongoing'" type="success" size="small">进行中</el-tag>
          <el-tag v-else-if="row.phase === 'ended'" type="info" size="small">已结束</el-tag>
          <el-tag v-else type="warning" size="small">未开始</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="操作" width="80" align="center" fixed="right">
        <template #default="{ row }">
          <el-button size="small" @click="handleSelect(row)">选择</el-button>
        </template>
      </el-table-column>
    </el-table>

    <!-- 分页 -->
    <div class="cp-pagination">
      <el-pagination
        v-model:current-page="page"
        v-model:page-size="pagesize"
        :total="total"
        :page-sizes="[10, 20, 30]"
        background
        layout="total, prev, pager, next"
        @current-change="onPageChange"
      />
    </div>
  </el-dialog>
</template>

<style scoped>
.cp-search-bar {
  display: flex;
  gap: 10px;
  margin-bottom: 12px;
  align-items: center;
}

.cp-pagination {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
</style>
