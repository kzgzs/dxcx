<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getCollectReward, getCouponManageList, saveCollectReward } from '../../api/couponManage'
import { getMemberLevelList as getLevels } from '../../api/member'
import type { CollectRewardData, CouponManageItem, MemberLevelItem } from '../../api/types'

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

// 订单范围（对齐点大 order_collect_reward/set.html）
const ORDER_TYPE_OPTIONS = [
  { value: 'shop', label: '商城订单' },
  { value: 'collage', label: '多人拼团订单' },
  { value: 'tuangou', label: '团购订单' },
  { value: 'scoreshop', label: '积分兑换订单' },
  { value: 'businessshop', label: '商家订单' },
]

// 使用平台（对齐点大 getplatformname：mp/wx/alipay/baidu/toutiao/qq/h5/app）
const PLATFORM_OPTIONS = [
  { value: 'mp', label: '微信公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝小程序' },
  { value: 'baidu', label: '百度小程序' },
  { value: 'toutiao', label: '抖音小程序' },
  { value: 'qq', label: 'QQ小程序' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]

const loading = ref(false)
const saving = ref(false)
const timeRange = ref<[Date, Date] | null>(null)

const form = reactive<CollectRewardData>({
  start_time: 0,
  end_time: 0,
  reward_type: 1,
  score: 0,
  coupon_id: 0,
  coupon_name: '',
  money: 0,
  commission: 0,
  order_type: ['shop'],
  platform: ['wx'],
  gettj: ['-1'],
  min_order_amount: 0,
  max_daily_return: 0,
  max_month_return: 0,
  prompt: '',
  bgcolor: '#EF9B39',
  fontcolor: '#FFFFFF',
  status: 0,
})

async function fetchDetail() {
  loading.value = true
  try {
    const d = await getCollectReward()
    form.start_time = d.start_time || 0
    form.end_time = d.end_time || 0
    form.reward_type = d.reward_type || 1
    form.score = d.score || 0
    form.coupon_id = d.coupon_id || 0
    form.coupon_name = d.coupon_name || ''
    form.money = d.money || 0
    form.commission = d.commission || 0
    form.order_type = d.order_type && d.order_type.length > 0 ? [...d.order_type] : []
    form.platform = d.platform && d.platform.length > 0 ? [...d.platform] : []
    form.gettj = d.gettj && d.gettj.length > 0 ? [...d.gettj] : ['-1']
    form.min_order_amount = d.min_order_amount || 0
    form.max_daily_return = d.max_daily_return || 0
    form.max_month_return = d.max_month_return || 0
    form.prompt = d.prompt || ''
    form.bgcolor = d.bgcolor || '#EF9B39'
    form.fontcolor = d.fontcolor || '#FFFFFF'
    form.status = d.status || 0
    timeRange.value =
      d.start_time && d.end_time
        ? [new Date(d.start_time * 1000), new Date(d.end_time * 1000)]
        : null
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  if (!timeRange.value || timeRange.value.length !== 2) {
    ElMessage.warning('请选择活动时间')
    return
  }
  if (form.order_type.length === 0) {
    ElMessage.warning('请选择订单范围')
    return
  }
  if (form.platform.length === 0) {
    ElMessage.warning('请选择使用平台')
    return
  }
  if (form.gettj.length === 0) {
    ElMessage.warning('请选择参与条件')
    return
  }
  if (form.reward_type === 1 && form.score <= 0) {
    ElMessage.warning('请输入奖励积分')
    return
  }
  if (form.reward_type === 2 && form.coupon_id <= 0) {
    ElMessage.warning('请选择奖励优惠券')
    return
  }
  if (form.reward_type === 3 && form.money <= 0) {
    ElMessage.warning('请输入奖励余额')
    return
  }
  if (form.reward_type === 4 && form.commission <= 0) {
    ElMessage.warning('请输入奖励佣金')
    return
  }
  saving.value = true
  try {
    await saveCollectReward({
      ...form,
      start_time: Math.floor(timeRange.value[0].getTime() / 1000),
      end_time: Math.floor(timeRange.value[1].getTime() / 1000),
    })
    ElMessage.success('保存成功')
    fetchDetail()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  loadCandidates()
  fetchDetail()
})
</script>

<template>
  <div>
    <el-card shadow="never" v-loading="loading">
      <template #header>
        <span class="card-title">奖励设置</span>
      </template>

      <el-form label-width="170px" label-position="right" class="compact-form">
        <el-form-item label="确认收货奖励">
          <el-switch
            v-model="form.status"
            :active-value="1"
            :inactive-value="0"
            active-text="开启"
            inactive-text="关闭"
          />
        </el-form-item>

        <el-form-item label="活动时间" required>
          <div class="range-wrap">
            <el-date-picker
              v-model="timeRange"
              type="datetimerange"
              range-separator="至"
              start-placeholder="开始时间"
              end-placeholder="结束时间"
            />
          </div>
          <span class="form-tip">仅限该时间内下单的订单参与</span>
        </el-form-item>

        <el-form-item label="奖励类型" required>
          <el-radio-group v-model="form.reward_type">
            <el-radio :value="1">积分</el-radio>
            <el-radio :value="2">优惠券</el-radio>
            <el-radio :value="3">余额返现</el-radio>
            <el-radio :value="4">佣金返现</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.reward_type === 1" label="奖励积分" required>
          <el-input-number v-model="form.score" :min="1" :precision="0" style="width: 200px" />
          <span class="form-tip">每单赠送多少积分</span>
        </el-form-item>

        <el-form-item v-if="form.reward_type === 2" label="优惠券" required>
          <el-select
            v-model="form.coupon_id"
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

        <el-form-item v-if="form.reward_type === 3" label="奖励余额" required>
          <el-input-number v-model="form.money" :min="0.01" :precision="2" style="width: 200px" />
          <span class="form-tip">每单赠送多少金额</span>
        </el-form-item>

        <el-form-item v-if="form.reward_type === 4" label="奖励佣金" required>
          <el-input-number v-model="form.commission" :min="0.01" :precision="2" style="width: 200px" />
          <span class="form-tip">每单赠送多少佣金</span>
        </el-form-item>

        <el-form-item label="订单范围" required>
          <el-checkbox-group v-model="form.order_type">
            <el-checkbox
              v-for="o in ORDER_TYPE_OPTIONS"
              :key="o.value"
              :value="o.value"
              :label="o.label"
            />
          </el-checkbox-group>
          <div class="form-tip block">商家订单仅支持商家商城订单，且奖励跟随平台的设置</div>
        </el-form-item>

        <el-form-item label="使用平台" required>
          <el-checkbox-group v-model="form.platform">
            <el-checkbox
              v-for="p in PLATFORM_OPTIONS"
              :key="p.value"
              :value="p.value"
              :label="p.label"
            />
          </el-checkbox-group>
          <div class="form-tip block">
            会员使用哪个平台确认收货可获得奖励（奖励与确认收货平台绑定，与下单平台无关）
          </div>
        </el-form-item>

        <el-form-item label="参与奖励门槛">
          <span class="inline-label">单笔订单满</span>
          <el-input-number v-model="form.min_order_amount" :min="0" :precision="2" style="width: 140px" />
          <span class="inline-label">元</span>
          <div class="form-tip block">
            实际支付的订单金额，即：商品原价总和 - 各类优惠（满减、折扣、优惠券、运费等）后的实付金额。0表示不限制
          </div>
        </el-form-item>

        <el-form-item label="每账号每日最多返">
          <el-input-number v-model="form.max_daily_return" :min="0" :precision="0" style="width: 140px" />
          <span class="inline-label">单</span>
          <span class="form-tip">0表示不限制</span>
        </el-form-item>

        <el-form-item label="每账号每月最多返">
          <el-input-number v-model="form.max_month_return" :min="0" :precision="0" style="width: 140px" />
          <span class="inline-label">单</span>
          <span class="form-tip">0表示不限制</span>
        </el-form-item>

        <el-form-item label="参与条件" required>
          <el-checkbox-group v-model="form.gettj">
            <el-checkbox value="-1" label="所有人" />
            <el-checkbox value="0" label="关注用户" />
            <el-checkbox
              v-for="lv in levelOptions"
              :key="lv.id"
              :value="String(lv.id)"
              :label="lv.name"
            />
          </el-checkbox-group>
        </el-form-item>

        <el-divider content-position="left">前端设置</el-divider>

        <el-form-item label="引导话术">
          <el-input
            v-model="form.prompt"
            placeholder="例：确认收货得0.5元余额返现，赶紧领取！"
            maxlength="20"
            show-word-limit
            style="width: 320px"
          />
          <div class="form-tip block">引导会员确认收货的提示语，限20字以内</div>
        </el-form-item>

        <el-form-item label="字体颜色">
          <el-color-picker v-model="form.fontcolor" />
          <span class="form-tip">{{ form.fontcolor || '#FFFFFF' }}</span>
        </el-form-item>

        <el-form-item label="背景颜色">
          <el-color-picker v-model="form.bgcolor" />
          <span class="form-tip">{{ form.bgcolor || '#EF9B39' }}</span>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保 存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.compact-form :deep(.el-form-item) {
  margin-bottom: 14px;
}

.card-title {
  font-weight: 600;
}

.form-tip {
  margin-left: 8px;
  color: #909399;
  font-size: 12px;
}

.form-tip.block {
  display: block;
  margin-left: 0;
  margin-top: 4px;
  line-height: 1.5;
}

.inline-label {
  margin: 0 6px;
  color: #606266;
}

.range-wrap {
  display: inline-block;
  width: 400px;
  flex: 0 0 auto;
}

.range-wrap :deep(.el-date-editor) {
  width: 100% !important;
}

.compact-form :deep(.el-form-item__label) {
  white-space: nowrap;
}
</style>
