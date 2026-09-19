<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { deleteLuntanCategory, getLuntanCategoryList, saveLuntanCategory } from '../../api/luntan'
import type { LuntanCategoryItem } from '../../api/luntan'

// ============ 列表 ============
const loading = ref(false)
const tree = ref<LuntanCategoryItem[]>([])

async function fetchList() {
  loading.value = true
  try {
    tree.value = await getLuntanCategoryList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

// ============ 编辑弹窗 ============
const dialogVisible = ref(false)
const form = reactive({
  id: 0,
  pid: 0,
  name: '',
  pic: '',
  banner: '',
  sort: 1,
  status: 1,
  goods_status: 0,
  post_pay: 0,
  post_score: 0,
  top_options: [] as { hour: number; price: number }[],
  coupon_ids: '',
  starttime: '',
  endtime: '',
  limitnum: 0,
})

function openAdd(pid: number) {
  Object.assign(form, {
    id: 0,
    pid,
    name: '',
    pic: '',
    banner: '',
    sort: 1,
    status: 1,
    goods_status: 0,
    post_pay: 0,
    post_score: 0,
    top_options: [],
    coupon_ids: '',
    starttime: '',
    endtime: '',
    limitnum: 0,
  })
  dialogVisible.value = true
}

function openEdit(row: LuntanCategoryItem) {
  Object.assign(form, {
    id: row.id,
    pid: row.pid,
    name: row.name,
    pic: row.pic || '',
    banner: row.banner || '',
    sort: row.sort,
    status: row.status,
    goods_status: row.goods_status,
    post_pay: row.post_pay,
    post_score: row.post_score,
    top_options: (row.top_options || []).map((t) => ({ hour: t.hour, price: t.price })),
    coupon_ids: row.coupon_ids || '',
    starttime: row.starttime_text || '',
    endtime: row.endtime_text || '',
    limitnum: row.limitnum,
  })
  dialogVisible.value = true
}

function addTopRow() {
  form.top_options.push({ hour: 24, price: 0 })
}

function removeTopRow(idx: number) {
  form.top_options.splice(idx, 1)
}

async function handleSave() {
  if (!form.name.trim()) {
    ElMessage.warning('请填写版块名称')
    return
  }
  try {
    await saveLuntanCategory({
      id: form.id || undefined,
      pid: form.pid,
      name: form.name.trim(),
      pic: form.pic,
      banner: form.banner,
      sort: form.sort,
      status: form.status,
      goods_status: form.goods_status,
      post_pay: form.post_pay,
      post_score: form.post_score,
      top_options: form.top_options,
      coupon_ids: form.coupon_ids,
      starttime: form.starttime,
      endtime: form.endtime,
      limitnum: form.limitnum,
    })
    ElMessage.success('保存成功')
    dialogVisible.value = false
    fetchList()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  }
}

function handleDelete(row: LuntanCategoryItem) {
  ElMessageBox.confirm(`确定要删除版块「${row.name}」吗？`, '删除确认', {
    confirmButtonText: '删除',
    cancelButtonText: '取消',
    type: 'warning',
  })
    .then(async () => {
      try {
        await deleteLuntanCategory([row.id])
        ElMessage.success('删除成功')
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
          <span>版块管理（二级结构）</span>
          <el-button type="primary" @click="openAdd(0)">新增顶级版块</el-button>
        </div>
      </template>

      <el-table v-loading="loading" :data="tree" stripe row-key="id" default-expand-all :tree-props="{ children: 'children' }">
        <el-table-column prop="name" label="版块名称" min-width="180" />
        <el-table-column label="帖子数" width="90">
          <template #default="{ row }">{{ row.postnum || 0 }}</template>
        </el-table-column>
        <el-table-column prop="sort" label="排序" width="70" />
        <el-table-column label="挂商品" width="90">
          <template #default="{ row }">
            <el-tag :type="row.goods_status ? 'success' : 'info'" size="small">{{ row.goods_status ? '开启' : '关闭' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="发帖收费" width="110">
          <template #default="{ row }">
            <span v-if="row.post_pay">{{ row.post_score }} 积分</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="置顶套餐" width="130">
          <template #default="{ row }">
            <span v-if="row.top_options && row.top_options.length">{{ row.top_options.length }} 档</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="发帖赠券" width="130">
          <template #default="{ row }">
            <span v-if="row.coupon_ids">券 {{ row.coupon_ids }} / 限 {{ row.limitnum }} 次</span>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status ? 'success' : 'info'" size="small">{{ row.status ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button v-if="row.pid === 0" link type="primary" @click="openAdd(row.id)">加子版块</el-button>
            <el-button link type="primary" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="form.id ? '编辑版块' : form.pid ? '新增子版块' : '新增顶级版块'" width="640px">
      <el-form label-width="110px">
        <el-form-item label="版块名称" required>
          <el-input v-model="form.name" placeholder="如：生活闲聊" style="width: 260px" />
        </el-form-item>
        <el-form-item label="图标">
          <el-input v-model="form.pic" placeholder="图片地址" />
        </el-form-item>
        <el-form-item label="横幅 Banner">
          <el-input v-model="form.banner" placeholder="图片地址" />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number v-model="form.sort" :min="0" />
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" active-text="启用" inactive-text="停用" />
        </el-form-item>
        <el-form-item label="帖子挂商品">
          <el-switch v-model="form.goods_status" :active-value="1" :inactive-value="0" />
          <span class="tip">开启后发帖可选择挂载商城商品</span>
        </el-form-item>
        <el-form-item label="发帖收积分">
          <el-switch v-model="form.post_pay" :active-value="1" :inactive-value="0" />
          <el-input-number v-if="form.post_pay" v-model="form.post_score" :min="1" style="margin-left: 8px" />
          <span v-if="form.post_pay" class="tip">发帖扣除的积分数</span>
        </el-form-item>
        <el-form-item label="付费置顶套餐">
          <div style="width: 100%">
            <div v-for="(t, idx) in form.top_options" :key="idx" class="top-row">
              <el-input-number v-model="t.hour" :min="1" controls-position="right" style="width: 110px" />
              <span>小时</span>
              <el-input-number v-model="t.price" :min="0" :precision="2" controls-position="right" style="width: 130px" />
              <span>元</span>
              <el-button link type="danger" @click="removeTopRow(idx)">移除</el-button>
            </div>
            <el-button size="small" @click="addTopRow">+ 添加套餐</el-button>
          </div>
        </el-form-item>
        <el-form-item label="发帖赠券">
          <el-input v-model="form.coupon_ids" placeholder="优惠券 ID，多个用逗号分隔" style="width: 260px" />
        </el-form-item>
        <el-form-item v-if="form.coupon_ids" label="每人限领次数">
          <el-input-number v-model="form.limitnum" :min="0" />
          <span class="tip">0 表示不限</span>
        </el-form-item>
        <el-form-item v-if="form.coupon_ids" label="赠券开始时间">
          <el-date-picker
            v-model="form.starttime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空表示不限"
          />
        </el-form-item>
        <el-form-item v-if="form.coupon_ids" label="赠券结束时间">
          <el-date-picker
            v-model="form.endtime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="留空表示不限"
          />
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
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.top-row {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}

.tip {
  margin-left: 8px;
  color: #999;
  font-size: 12px;
}
</style>
