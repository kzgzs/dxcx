<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getCouponOptions,
  getRegisterGiveConfig,
  saveRegisterGiveConfig,
  type CouponOption,
} from '../../api/registerGive'

const loading = ref(false)
const saving = ref(false)

const form = reactive({
  money: 0,
  score: 0,
  wanshan_score: 0,
  give_coupon: 0,
  status: 0,
})

const couponIds = ref<number[]>([])
const timeRange = ref<[string, string] | null>(null)
const couponOptions = ref<CouponOption[]>([])

async function fetchConfig() {
  loading.value = true
  try {
    const d = await getRegisterGiveConfig()
    form.money = d.money || 0
    form.score = d.score || 0
    form.wanshan_score = d.wanshan_score || 0
    form.give_coupon = d.give_coupon || 0
    form.status = d.status || 0
    couponIds.value = d.coupon_ids
      ? d.coupon_ids.split(',').filter(Boolean).map(Number)
      : []
    timeRange.value = d.starttime && d.endtime ? [d.starttime, d.endtime] : null
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function loadCoupons() {
  try {
    const d = await getCouponOptions()
    couponOptions.value = d.list || []
  } catch {
    // 候选加载失败不阻断配置
  }
}

async function handleSave() {
  if (form.money <= 0 && form.score <= 0 && form.wanshan_score <= 0 && form.give_coupon !== 1) {
    ElMessage.warning('赠送余额/积分/赠券至少配置一项')
    return
  }
  if (form.give_coupon === 1 && couponIds.value.length === 0) {
    ElMessage.warning('请选择赠送的优惠券')
    return
  }
  saving.value = true
  try {
    await saveRegisterGiveConfig({
      ...form,
      coupon_ids: couponIds.value.join(','),
      starttime: timeRange.value ? timeRange.value[0] : '',
      endtime: timeRange.value ? timeRange.value[1] : '',
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
  loadCoupons()
})
</script>

<template>
  <el-card v-loading="loading" shadow="never">
    <template #header>
      <div class="card-header">
        <span>注册赠送设置</span>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </template>

    <el-form label-width="140px" style="max-width: 640px">
      <el-form-item label="活动状态">
        <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        <span class="form-tip">开启后新注册会员享受赠送</span>
      </el-form-item>
      <el-form-item label="赠送余额">
        <el-input-number v-model="form.money" :min="0" :precision="2" :step="1" />
        <span class="unit">元</span>
      </el-form-item>
      <el-form-item label="赠送积分">
        <el-input-number v-model="form.score" :min="0" :step="1" />
      </el-form-item>
      <el-form-item label="完善资料赠送积分">
        <el-input-number v-model="form.wanshan_score" :min="0" :step="1" />
        <span class="form-tip">会员完善资料（姓名/手机/性别/生日齐全）时赠送，只送一次</span>
      </el-form-item>
      <el-form-item label="赠送优惠券">
        <el-switch v-model="form.give_coupon" :active-value="1" :inactive-value="0" />
      </el-form-item>
      <el-form-item v-if="form.give_coupon === 1" label="选择优惠券">
        <el-select v-model="couponIds" multiple filterable placeholder="请选择要赠送的优惠券">
          <el-option
            v-for="item in couponOptions"
            :key="item.id"
            :label="item.name"
            :value="item.id"
          />
        </el-select>
      </el-form-item>
      <el-form-item label="活动时间段">
        <el-date-picker
          v-model="timeRange"
          type="datetimerange"
          value-format="YYYY-MM-DD HH:mm:ss"
          range-separator="至"
          start-placeholder="开始时间"
          end-placeholder="结束时间"
        />
        <span class="form-tip">留空表示永久生效</span>
      </el-form-item>
    </el-form>
  </el-card>
</template>

<style scoped>
.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
.unit {
  margin-left: 8px;
  color: #606266;
}
</style>
