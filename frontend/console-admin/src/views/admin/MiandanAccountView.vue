<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  bindMiandanAccount,
  getAccountList,
  getDeliveryOptions,
  unbindMiandanAccount,
} from '../../api/ewaybill'
import type { DeliveryOption, MiandanAccount } from '../../api/ewaybill'

defineEmits<{ (e: 'close'): void }>()

// ===== 账号列表 =====
const loading = ref(false)
const list = ref<MiandanAccount[]>([])

async function load() {
  loading.value = true
  try {
    const d = await getAccountList()
    list.value = d.list || []
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function statusTag(row: MiandanAccount) {
  const map: Record<number, { color: string; text: string }> = {
    0: { color: 'green', text: '绑定成功' },
    1: { color: '#409eff', text: '审核中' },
    2: { color: 'red', text: '绑定失败' },
    3: { color: '#999', text: '已解绑' },
  }
  return map[row.status_code] || { color: '#999', text: row.status_name || '未知' }
}

async function handleUnbind(row: MiandanAccount) {
  try {
    await ElMessageBox.confirm('确定要解绑吗？', '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await unbindMiandanAccount({ biz_id: row.biz_id, delivery_id: row.delivery_id })
    ElMessage.success('解绑成功')
    load()
  } catch {
    /* 拦截器已提示 */
  }
}

// ===== 绑定表单 =====
const mode = ref<'list' | 'bind'>('list')
const options = ref<DeliveryOption[]>([])
const form = reactive({
  delivery_id: 'ANN',
  biz_id: '',
  password: '',
  remark_content: '',
})
const submitting = ref(false)

async function openBind() {
  mode.value = 'bind'
  if (options.value.length === 0) {
    try {
      const d = await getDeliveryOptions()
      options.value = d.list || []
    } catch {
      /* ignore */
    }
  }
}

async function submitBind() {
  if (!form.delivery_id) {
    ElMessage.warning('请选择快递公司')
    return
  }
  if (!form.biz_id) {
    ElMessage.warning('请填写客户编码')
    return
  }
  submitting.value = true
  try {
    await bindMiandanAccount({ ...form })
    ElMessage.success('绑定成功')
    mode.value = 'list'
    load()
  } catch {
    /* 拦截器已提示 */
  } finally {
    submitting.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="subpage">
    <!-- 列表态 -->
    <template v-if="mode === 'list'">
      <div class="subpage-head">
        <span class="subpage-title">物流账号列表</span>
        <span class="subpage-close" @click="$emit('close')">✕</span>
      </div>
      <div class="toolbar">
        <el-button type="primary" @click="openBind">绑定物流账号</el-button>
      </div>
      <el-table v-loading="loading" :data="list" style="width: 100%" :header-cell-style="{ background: '#e6e6e6' }">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="biz_id" label="客户编码" min-width="120" align="left" />
        <el-table-column prop="delivery_name" label="快递公司" min-width="110" align="left" />
        <el-table-column prop="delivery_id" label="快递公司ID" min-width="100" align="left" />
        <el-table-column label="绑定状态" min-width="90" align="left">
          <template #default="{ row }">
            <span :style="{ color: statusTag(row).color }">{{ statusTag(row).text }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="create_time_text" label="绑定时间" min-width="150" align="left" />
        <el-table-column prop="quota_num" label="电子面单余额" min-width="100" align="left" />
        <el-table-column prop="remark_content" label="备注" min-width="120" align="left" />
        <el-table-column label="操作" min-width="80" align="left">
          <template #default="{ row }">
            <el-button v-if="row.status_code === 0" link type="primary" @click="handleUnbind(row)">解绑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </template>

    <!-- 绑定表单态 -->
    <template v-else>
      <div class="subpage-head">
        <span class="subpage-title">✚ 绑定物流账号</span>
        <span class="subpage-close" @click="mode = 'list'">✕</span>
      </div>
      <div class="banner">
        EMS，顺丰，京东快递暂不支持系统绑定，请使用小程序管理员微信登录
        <a href="https://mp.weixin.qq.com" target="_blank" rel="noopener">微信公众平台</a>
        →物流助手→快递配送→去绑定
      </div>
      <el-form label-width="110px" style="max-width: 640px; margin-top: 3px" @submit.prevent>
        <el-form-item label="快递公司：" required>
          <el-select v-model="form.delivery_id" class="field-md">
            <el-option v-for="o in options" :key="o.delivery_id" :label="o.delivery_name" :value="o.delivery_id" />
          </el-select>
          <span class="tip-inline">快递公司</span>
        </el-form-item>
        <el-form-item label="客户编码：" required>
          <el-input v-model="form.biz_id" class="field-md" placeholder="" />
          <span class="tip-inline">快递公司客户编码（韵达为客户K码）</span>
        </el-form-item>
        <el-form-item label="客户密码：" required>
          <el-input v-model="form.password" class="field-md" placeholder="" />
          <span class="tip-inline">快递公司客户密码, ems，顺丰，京东非必填</span>
        </el-form-item>
        <el-form-item label="备注内容：">
          <el-input v-model="form.remark_content" class="field-md" placeholder="" />
          <span class="tip-inline">提交EMS审核需要</span>
        </el-form-item>
        <el-form-item>
          <el-button type="danger" :loading="submitting" @click="submitBind">提 交</el-button>
        </el-form-item>
      </el-form>
    </template>
  </div>
</template>

<style scoped>
.subpage-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 3px 0;
}
.subpage-title {
  font-size: 16px;
  font-weight: 600;
}
.subpage-close {
  cursor: pointer;
  font-size: 20px;
  font-weight: bold;
  color: #606266;
  padding: 0 6px;
}
.subpage-close:hover {
  color: #f56c6c;
}
.toolbar {
  margin: 3px 0;
}
.banner {
  background: #f4f8f4;
  border-left: 4px solid #23aa5e;
  padding: 8px 12px;
  font-size: 13px;
  color: #555;
  margin: 3px 0;
}
.tip-inline {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}
</style>
