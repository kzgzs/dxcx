<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getCollectReward,
  getCouponManageList,
  getPayaftergive,
  saveCollectReward,
  savePayaftergive,
} from '../../api/couponManage'
import { getMemberLevelList as getLevels } from '../../api/member'
import type {
  CollectRewardData,
  CouponManageItem,
  MemberLevelItem,
  PayaftergiveData,
} from '../../api/types'

const activeTab = ref<'payaftergive' | 'collect'>('payaftergive')

// 等级/券候选
const levelOptions = ref<MemberLevelItem[]>([])
const couponOptions = ref<CouponManageItem[]>([])

async function loadCandidates() {
  try {
    const [levels, coupons] = await Promise.all([
      getLevels(),
      getCouponManageList({ page: 1, pagesize: 100 }),
    ])
    levelOptions.value = levels.list || []
    couponOptions.value = coupons.list || []
  } catch {
    // 候选加载失败不阻断配置
  }
}

// ============ 支付后赠 ============
const payLoading = ref(false)
const paySaving = ref(false)

const payForm = reactive<PayaftergiveData>({
  name: '',
  gettj: '-1',
  paygive_scene: '',
  limittimes: 0,
  pricestart: 0,
  priceend: 0,
  starttime: 0,
  endtime: 0,
  money: 0,
  score: 0,
  give_coupon: 0,
  coupon_ids: '',
  tourl: '',
  btntext: '',
  status: 0,
  sort: 0,
})
const payTimeRange = ref<[Date, Date] | null>(null)
const payCouponArr = ref<number[]>([])

async function fetchPay() {
  payLoading.value = true
  try {
    const d = await getPayaftergive()
    Object.assign(payForm, {
      name: d.name || '',
      gettj: d.gettj || '-1',
      paygive_scene: d.paygive_scene || '',
      limittimes: d.limittimes || 0,
      pricestart: d.pricestart || 0,
      priceend: d.priceend || 0,
      starttime: d.starttime || 0,
      endtime: d.endtime || 0,
      money: d.money || 0,
      score: d.score || 0,
      give_coupon: d.give_coupon || 0,
      coupon_ids: d.coupon_ids || '',
      tourl: d.tourl || '',
      btntext: d.btntext || '',
      status: d.status || 0,
      sort: d.sort || 0,
    })
    payTimeRange.value =
      d.starttime && d.endtime
        ? [new Date(d.starttime * 1000), new Date(d.endtime * 1000)]
        : null
    payCouponArr.value = d.coupon_ids ? d.coupon_ids.split(',').map(Number) : []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    payLoading.value = false
  }
}

async function handleSavePay() {
  if (!payForm.name) {
    ElMessage.warning('请填写活动名称')
    return
  }
  if (payForm.money <= 0 && payForm.score <= 0 && payForm.give_coupon !== 1) {
    ElMessage.warning('赠送余额/积分/赠券至少配置一项')
    return
  }
  paySaving.value = true
  try {
    await savePayaftergive({
      ...payForm,
      starttime:
        payTimeRange.value && payTimeRange.value[0]
          ? Math.floor(payTimeRange.value[0].getTime() / 1000)
          : 0,
      endtime:
        payTimeRange.value && payTimeRange.value[1]
          ? Math.floor(payTimeRange.value[1].getTime() / 1000)
          : 0,
      coupon_ids: payCouponArr.value.join(','),
    })
    ElMessage.success('保存成功')
    fetchPay()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    paySaving.value = false
  }
}

// ============ 确认收货奖励 ============
const collectLoading = ref(false)
const collectSaving = ref(false)

const ORDER_TYPE_OPTIONS = [
  { value: 'shop', label: '商城订单' },
  { value: 'yuyue', label: '预约订单' },
  { value: 'restaurant', label: '餐饮订单' },
  { value: 'hotel', label: '酒店订单' },
]
const PLATFORM_OPTIONS = [
  { value: 'wx', label: '微信小程序' },
  { value: 'h5', label: 'H5' },
  { value: 'app', label: 'APP' },
]

const collectForm = reactive<CollectRewardData>({
  start_time: 0,
  end_time: 0,
  reward_type: 1,
  score: 0,
  coupon_id: 0,
  coupon_name: '',
  money: 0,
  commission: 0,
  order_type: [],
  platform: [],
  gettj: ['-1'],
  min_order_amount: 0,
  max_daily_return: 0,
  max_month_return: 0,
  prompt: '',
  bgcolor: '#EF9B39',
  fontcolor: '',
  status: 0,
})
const collectTimeRange = ref<[Date, Date] | null>(null)

async function fetchCollect() {
  collectLoading.value = true
  try {
    const d = await getCollectReward()
    Object.assign(collectForm, {
      start_time: d.start_time || 0,
      end_time: d.end_time || 0,
      reward_type: d.reward_type || 1,
      score: d.score || 0,
      coupon_id: d.coupon_id || 0,
      coupon_name: d.coupon_name || '',
      money: d.money || 0,
      commission: d.commission || 0,
      order_type: d.order_type || [],
      platform: d.platform || [],
      gettj: d.gettj && d.gettj.length > 0 ? d.gettj : ['-1'],
      min_order_amount: d.min_order_amount || 0,
      max_daily_return: d.max_daily_return || 0,
      max_month_return: d.max_month_return || 0,
      prompt: d.prompt || '',
      bgcolor: d.bgcolor || '#EF9B39',
      fontcolor: d.fontcolor || '',
      status: d.status || 0,
    })
    collectTimeRange.value =
      d.start_time && d.end_time
        ? [new Date(d.start_time * 1000), new Date(d.end_time * 1000)]
        : null
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    collectLoading.value = false
  }
}

async function handleSaveCollect() {
  if (!collectTimeRange.value || collectTimeRange.value.length !== 2) {
    ElMessage.warning('请选择活动时间')
    return
  }
  if (collectForm.order_type.length === 0) {
    ElMessage.warning('请选择订单范围')
    return
  }
  if (collectForm.platform.length === 0) {
    ElMessage.warning('请选择使用平台')
    return
  }
  collectSaving.value = true
  try {
    await saveCollectReward({
      ...collectForm,
      start_time: Math.floor(collectTimeRange.value[0].getTime() / 1000),
      end_time: Math.floor(collectTimeRange.value[1].getTime() / 1000),
    })
    ElMessage.success('保存成功')
    fetchCollect()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    collectSaving.value = false
  }
}

onMounted(() => {
  loadCandidates()
  fetchPay()
  fetchCollect()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab">
        <!-- ============ 支付后赠 ============ -->
        <el-tab-pane label="支付后赠" name="payaftergive">
          <el-form
            v-loading="payLoading"
            label-width="110px"
            label-position="right"
            class="compact-form"
          >
            <el-form-item label="活动状态">
              <el-switch v-model="payForm.status" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="活动名称">
              <el-input v-model="payForm.name" placeholder="请输入活动名称" style="width: 320px" />
            </el-form-item>
            <el-form-item label="活动时间">
              <el-date-picker
                v-model="payTimeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间"
                end-placeholder="结束时间"
                style="width: 380px"
              />
              <span class="form-tip">留空表示长期有效</span>
            </el-form-item>
            <el-form-item label="参与人群">
              <el-select v-model="payForm.gettj" style="width: 260px">
                <el-option label="不限制" value="-1" />
                <el-option
                  v-for="lv in levelOptions"
                  :key="lv.id"
                  :label="lv.name"
                  :value="String(lv.id)"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="订单金额范围">
              <el-input-number v-model="payForm.pricestart" :min="0" :precision="2" />
              <span class="form-tip">至</span>
              <el-input-number v-model="payForm.priceend" :min="0" :precision="2" />
              <span class="form-tip">0 表示不限</span>
            </el-form-item>
            <el-form-item label="每人限次">
              <el-input-number v-model="payForm.limittimes" :min="0" />
              <span class="form-tip">0 表示不限</span>
            </el-form-item>
            <el-form-item label="赠送余额">
              <el-input-number v-model="payForm.money" :min="0" :precision="2" />
            </el-form-item>
            <el-form-item label="赠送积分">
              <el-input-number v-model="payForm.score" :min="0" />
            </el-form-item>
            <el-form-item label="赠送优惠券">
              <el-switch v-model="payForm.give_coupon" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item v-if="payForm.give_coupon === 1" label="选择券">
              <el-select
                v-model="payCouponArr"
                multiple
                filterable
                style="width: 420px"
                placeholder="请选择要赠送的优惠券"
              >
                <el-option
                  v-for="c in couponOptions"
                  :key="c.id"
                  :label="c.name"
                  :value="c.id"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="跳转链接">
              <el-input v-model="payForm.tourl" placeholder="支付成功后引导跳转（可选）" style="width: 380px" />
            </el-form-item>
            <el-form-item label="按钮文案">
              <el-input v-model="payForm.btntext" placeholder="如：去查看（可选）" style="width: 260px" />
            </el-form-item>
            <el-form-item label="排序">
              <el-input-number v-model="payForm.sort" :min="0" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="paySaving" @click="handleSavePay">保存</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- ============ 确认收货奖励 ============ -->
        <el-tab-pane label="确认收货奖励" name="collect">
          <el-form
            v-loading="collectLoading"
            label-width="110px"
            label-position="right"
            class="compact-form"
          >
            <el-form-item label="活动状态">
              <el-switch v-model="collectForm.status" :active-value="1" :inactive-value="0" />
            </el-form-item>
            <el-form-item label="活动时间">
              <el-date-picker
                v-model="collectTimeRange"
                type="datetimerange"
                range-separator="至"
                start-placeholder="开始时间"
                end-placeholder="结束时间"
                style="width: 380px"
              />
            </el-form-item>
            <el-form-item label="奖励类型">
              <el-radio-group v-model="collectForm.reward_type">
                <el-radio :value="1">积分</el-radio>
                <el-radio :value="2">优惠券</el-radio>
                <el-radio :value="3">余额</el-radio>
                <el-radio :value="4">佣金</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="collectForm.reward_type === 1" label="奖励积分">
              <el-input-number v-model="collectForm.score" :min="1" />
            </el-form-item>
            <el-form-item v-if="collectForm.reward_type === 2" label="奖励券">
              <el-select
                v-model="collectForm.coupon_id"
                filterable
                style="width: 320px"
                placeholder="请选择奖励优惠券"
              >
                <el-option
                  v-for="c in couponOptions"
                  :key="c.id"
                  :label="c.name"
                  :value="c.id"
                />
              </el-select>
            </el-form-item>
            <el-form-item v-if="collectForm.reward_type === 3" label="奖励余额">
              <el-input-number v-model="collectForm.money" :min="0.01" :precision="2" />
            </el-form-item>
            <el-form-item v-if="collectForm.reward_type === 4" label="奖励佣金">
              <el-input-number v-model="collectForm.commission" :min="0.01" :precision="2" />
            </el-form-item>
            <el-form-item label="订单范围">
              <el-checkbox-group v-model="collectForm.order_type">
                <el-checkbox
                  v-for="o in ORDER_TYPE_OPTIONS"
                  :key="o.value"
                  :value="o.value"
                  :label="o.label"
                />
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="使用平台">
              <el-checkbox-group v-model="collectForm.platform">
                <el-checkbox
                  v-for="p in PLATFORM_OPTIONS"
                  :key="p.value"
                  :value="p.value"
                  :label="p.label"
                />
              </el-checkbox-group>
            </el-form-item>
            <el-form-item label="参与条件">
              <el-select v-model="collectForm.gettj" multiple style="width: 320px" placeholder="不限">
                <el-option label="不限制" value="-1" />
                <el-option
                  v-for="lv in levelOptions"
                  :key="lv.id"
                  :label="lv.name"
                  :value="String(lv.id)"
                />
              </el-select>
            </el-form-item>
            <el-form-item label="满额门槛">
              <el-input-number v-model="collectForm.min_order_amount" :min="0" :precision="2" />
              <span class="form-tip">订单金额达到该值才奖励，0 表示不限</span>
            </el-form-item>
            <el-form-item label="每日上限">
              <el-input-number v-model="collectForm.max_daily_return" :min="0" />
              <span class="form-tip">0 表示不限</span>
            </el-form-item>
            <el-form-item label="每月上限">
              <el-input-number v-model="collectForm.max_month_return" :min="0" />
              <span class="form-tip">0 表示不限</span>
            </el-form-item>
            <el-form-item label="引导话术">
              <el-input
                v-model="collectForm.prompt"
                placeholder="不超过20字"
                maxlength="20"
                show-word-limit
                style="width: 320px"
              />
            </el-form-item>
            <el-form-item label="背景颜色">
              <el-color-picker v-model="collectForm.bgcolor" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="collectSaving" @click="handleSaveCollect">
                保存
              </el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<style scoped>
.compact-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}
</style>
