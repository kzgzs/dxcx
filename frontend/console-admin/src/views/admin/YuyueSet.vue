<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getYuyueSetConfig, saveYuyueSetConfig } from '../../api/yuyue'

// 预约 - 系统设置（严格对照截图14项；多余分组已删除）
const loading = ref(false)
const submitting = ref(false)

const form = reactive({
  paidantype: 0,        // 0服务人员抢单 1指定服务人员
  isautopd: 1,          // 自动派单 1开启 0关闭
  withdraw_weixin: 1,   // 提现方式-微信钱包
  withdraw_aliaccount: 0,
  withdraw_bankcard: 0,
  withdrawmin: '0',    // 提现最小金额(元)
  withdrawfee: '0',     // 提现手续费(%)
  autoclose: 600,       // 自动关闭订单(分钟)
  finish_confirm: 1,    // 服务完成确认
  confirm_type: 2,      // 1会员确认 2商家确认
  autoconfirm: 1,       // 自动确认天数
  discount: 1,          // 会员等级折扣
  iscoupon: 1,         // 优惠券抵扣
  comment_check: 1,    // 评价审核
  yuyue_numtext: '购买数量', // 预约数量文本
  show_free: 1,         // 显示免费
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = (await getYuyueSetConfig()) as Record<string, unknown>
    Object.keys(form).forEach((k) => {
      const key = k as keyof typeof form
      const v = data[key]
      if (v === undefined || v === null) return
      if (typeof form[key] === 'number') {
        ;(form[key] as number) = Number(v) || 0
      } else {
        ;(form[key] as string) = String(v)
      }
    })
  } catch { /* 拦截器 */ } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveYuyueSetConfig({ ...form })
    ElMessage.success('保存成功')
    fetchConfig()
  } catch { /* 拦截器 */ } finally {
    submitting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading" class="set-wrap">
    <el-card shadow="never">
      <el-form label-width="130px" label-position="right" class="set-form">
        <el-form-item label="派单模式">
          <el-radio-group v-model="form.paidantype">
            <el-radio :value="0">服务人员抢单</el-radio>
            <el-radio :value="1">指定服务人员</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.paidantype === 0" label="自动派单">
          <el-radio-group v-model="form.isautopd">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">开启后，订单自动到抢单大厅，无需后台进行派单</span>
        </el-form-item>

        <el-form-item label="提现方式">
          <el-checkbox v-model="form.withdraw_weixin" :true-value="1" :false-value="0">微信钱包</el-checkbox>
          <el-checkbox v-model="form.withdraw_aliaccount" :true-value="1" :false-value="0">支付宝</el-checkbox>
          <el-checkbox v-model="form.withdraw_bankcard" :true-value="1" :false-value="0">银行卡</el-checkbox>
          <div class="tip block">提现到微信钱包需开通企业付款到零钱功能</div>
        </el-form-item>

        <el-form-item label="提现最小金额">
          <el-input v-model="form.withdrawmin" style="width: 160px" />
          <span class="tip">元</span>
        </el-form-item>

        <el-form-item label="提现手续费">
          <el-input v-model="form.withdrawfee" style="width: 160px" />
          <span class="tip">%</span>
        </el-form-item>

        <el-form-item label="自动关闭订单">
          <el-input-number v-model="form.autoclose" :min="0" :precision="0" />
          <span class="tip">分钟　下单后多久不支付自动关闭订单，释放占用的库存</span>
        </el-form-item>

        <el-form-item label="服务完成确认">
          <el-radio-group v-model="form.finish_confirm">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">服务人员提交完成，订单需经确认才能发放服务费</span>
        </el-form-item>

        <el-form-item v-if="form.finish_confirm === 1" label="确认方式">
          <el-radio-group v-model="form.confirm_type">
            <el-radio :value="1">会员确认</el-radio>
            <el-radio :value="2">商家确认</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item v-if="form.finish_confirm === 1" label="自动确认天数">
          <el-input-number v-model="form.autoconfirm" :min="1" :precision="0" />
          <span class="tip">天　多少天会员未确认服务完成自动完成订单（最小值为1）</span>
        </el-form-item>

        <el-form-item label="会员等级折扣">
          <el-radio-group v-model="form.discount">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">是否开启会员等级折扣</span>
        </el-form-item>

        <el-form-item label="优惠券抵扣">
          <el-radio-group v-model="form.iscoupon">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">是否开启优惠券抵扣</span>
        </el-form-item>

        <el-form-item label="评价审核">
          <el-radio-group v-model="form.comment_check">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">关闭后用户的评价将直接显示，不需要后台审核</span>
        </el-form-item>

        <el-form-item label="预约数量文本">
          <el-input v-model="form.yuyue_numtext" style="width: 220px" />
          <span class="tip">预约详情页预约数量自定义文字</span>
        </el-form-item>

        <el-form-item label="显示免费">
          <el-radio-group v-model="form.show_free">
            <el-radio :value="1">开启</el-radio>
            <el-radio :value="0">关闭</el-radio>
          </el-radio-group>
          <span class="tip">开启后，0元商品显示"免费"</span>
        </el-form-item>

        <el-form-item>
          <el-button type="success" :loading="submitting" @click="handleSave">提交</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.set-wrap { margin: 3px; }
.set-form { max-width: 720px; padding: 16px 0; }
.tip { color: #909399; font-size: 12px; margin-left: 10px; }
.tip.block { display: block; margin-left: 0; margin-top: 4px; }
</style>
