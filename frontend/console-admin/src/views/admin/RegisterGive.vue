<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getRegisterGiveConfig,
  saveRegisterGiveConfig,
} from '../../api/registerGive'
import { getCouponManageList, type CouponManageItem } from '../../api/couponManage'
import CouponPicker from '../../components/CouponPicker.vue'

interface SelectedCoupon {
  id: number
  name: string
  stock: number
}

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  status: 0,
  money: 0,
  score: 0,
  wanshan_score: 0,
  give_coupon: 0,
})

const startTime = ref('')
const endTime = ref('')
const selectedCoupons = ref<SelectedCoupon[]>([])
const pickerVisible = ref(false)

async function fetchConfig() {
  loading.value = true
  try {
    const d = await getRegisterGiveConfig()
    form.status = d.status || 0
    form.money = d.money || 0
    form.score = d.score || 0
    form.wanshan_score = d.wanshan_score || 0
    form.give_coupon = d.give_coupon || 0
    startTime.value = d.starttime || ''
    endTime.value = d.endtime || ''
    await resolveCoupons(d.coupon_ids)
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

/** 由 coupon_ids（逗号分隔）解析出名称/库存用于表格回显 */
async function resolveCoupons(couponIds: string) {
  const ids = (couponIds || '').split(',').filter(Boolean).map(Number)
  if (ids.length === 0) {
    selectedCoupons.value = []
    return
  }
  try {
    const res = await getCouponManageList({ page: 1, pagesize: 200 })
    const pool = res.list || []
    selectedCoupons.value = ids
      .map((id) => pool.find((c) => c.id === id))
      .filter((c): c is CouponManageItem => !!c)
      .map((c) => ({ id: c.id, name: c.name, stock: c.stock }))
  } catch {
    // 解析失败仅影响回显展示，不阻断
    selectedCoupons.value = ids.map((id) => ({ id, name: '', stock: 0 }))
  }
}

function onPickCoupon(item: CouponManageItem) {
  if (selectedCoupons.value.some((c) => c.id === item.id)) {
    ElMessage.warning('该优惠券已在赠送列表中')
    return
  }
  selectedCoupons.value.push({ id: item.id, name: item.name, stock: item.stock })
}

function removeCoupon(index: number) {
  selectedCoupons.value.splice(index, 1)
}

async function handleSave() {
  if (form.give_coupon === 1 && selectedCoupons.value.length === 0) {
    ElMessage.warning('请选择赠送的优惠券')
    return
  }
  saving.value = true
  try {
    await saveRegisterGiveConfig({
      status: form.status,
      money: form.money,
      score: form.score,
      wanshan_score: form.wanshan_score,
      give_coupon: form.give_coupon,
      coupon_ids: selectedCoupons.value.map((c) => c.id).join(','),
      starttime: startTime.value || '',
      endtime: endTime.value || '',
    })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchConfig()
})
</script>

<template>
  <div class="page-wrap">
    <div v-loading="loading" class="panel">
      <el-form label-width="110px" class="rg-form">
        <el-form-item label="状态">
          <el-radio-group v-model="form.status">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="赠送余额">
          <el-input v-model.number="form.money" type="number" class="w-180" />
          <span class="unit">元</span>
        </el-form-item>

        <el-form-item label="赠送积分">
          <el-input v-model.number="form.score" type="number" class="w-180" />
        </el-form-item>

        <el-form-item label="完善资料送积分">
          <el-input v-model.number="form.wanshan_score" type="number" class="w-180" />
          <div class="tip">指姓名、手机号、性别和生日全部填写</div>
        </el-form-item>

        <el-form-item label="开始时间">
          <el-date-picker
            v-model="startTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="活动开始时间"
          />
        </el-form-item>

        <el-form-item label="结束时间">
          <el-date-picker
            v-model="endTime"
            type="datetime"
            value-format="YYYY-MM-DD HH:mm:ss"
            placeholder="活动结束时间"
          />
        </el-form-item>

        <el-form-item label="赠送优惠券">
          <el-radio-group v-model="form.give_coupon">
            <el-radio :value="0">关闭</el-radio>
            <el-radio :value="1">开启</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.give_coupon === 1" label-width="110px">
          <el-table :data="selectedCoupons" border size="small" class="coupon-table">
            <el-table-column prop="id" label="ID" width="90" align="center" />
            <el-table-column prop="name" label="名称" min-width="180" />
            <el-table-column prop="stock" label="库存" width="110" align="center" />
            <el-table-column label="操作" width="90" align="center">
              <template #default="{ $index }">
                <el-button size="small" @click="removeCoupon($index)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-button size="small" class="mt-8" @click="pickerVisible = true">添加</el-button>
          <div class="tip mt-8">请确认优惠券的有效期、库存有效</div>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="saving" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </div>

    <CouponPicker v-model:visible="pickerVisible" @select="onPickCoupon" />
  </div>
</template>

<style scoped>
.page-wrap {
  margin: 3px;
}
.panel {
  padding: 12px;
}
.rg-form :deep(.el-form-item) {
  margin-bottom: 14px;
}
.w-180 {
  width: 180px;
}
.unit {
  margin-left: 8px;
  color: #606266;
}
.tip {
  margin-top: 4px;
  color: #909399;
  font-size: 12px;
  line-height: 1.4;
}
.mt-8 {
  margin-top: 8px;
}
.coupon-table {
  width: 560px;
}
</style>
