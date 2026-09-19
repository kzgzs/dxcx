<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { getYuyueSetConfig, saveYuyueSetConfig } from '../../api/yuyue'
import type { YuyueSetSaveParams } from '../../api/types'

// 预约 - 系统设置（任务222，对照参考系统 YuyueSet）
const loading = ref(false)
const submitting = ref(false)

const ZHOU_OPTIONS = [
  { label: '周一', value: '1' },
  { label: '周二', value: '2' },
  { label: '周三', value: '3' },
  { label: '周四', value: '4' },
  { label: '周五', value: '5' },
  { label: '周六', value: '6' },
  { label: '周日', value: '7' },
]

const form = reactive({
  status: 1,
  diyname: '',
  pic: '',
  desc: '',
  detailpic: '',
  yuyue_numtext: '',
  yuyue_success: '',
  show_free: 0,
  comment_check: 0,
  // 预约时间
  rqtype: 1,
  yybegintime: '',
  yyendtime: '',
  yyzhouqi: [] as string[],
  zaohour: 9,
  wanhour: 18,
  timejg: 30,
  timepoint: '',
  minminute: 30,
  pdprehour: 2,
  // 订单确认
  autoclose: 30,
  autoshdays: 7,
  isautopd: 0,
  finish_confirm: 0,
  confirm_type: 0,
  autoconfirm: 3,
  // 提成与提现
  paidantype: 1,
  withdraw_weixin: 1,
  withdraw_aliaccount: 0,
  withdraw_bankcard: 0,
  withdrawmin: '1',
  withdrawfee: '0',
  businessst: 0,
  businessfee: '0',
  apply_paymoney: '0',
  // 营销
  discount: 0,
  iscoupon: 0,
  sign_juli: '0',
  // 协议与广告
  xieyi_show: 0,
  xieyi: '',
  ad_status: 0,
  ad_pic: '',
  ad_link: '',
  video_status: 0,
  video_tag: '',
  video_title: '',
  // 表单
  formurl: '',
  formdata: '',
})

async function fetchConfig() {
  loading.value = true
  try {
    const data = await getYuyueSetConfig()
    Object.keys(form).forEach((key) => {
      const val = (data as Record<string, unknown>)[key]
      if (val === undefined || val === null) return
      if (key === 'yyzhouqi') {
        form.yyzhouqi = Array.isArray(val) ? (val as string[]).map(String) : []
      } else if (typeof (form as Record<string, unknown>)[key] === 'number') {
        ;(form as Record<string, unknown>)[key] = Number(val) || 0
      } else {
        ;(form as Record<string, unknown>)[key] = String(val)
      }
    })
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await saveYuyueSetConfig({ ...form } as YuyueSetSaveParams)
    ElMessage.success('保存成功')
    fetchConfig()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    submitting.value = false
  }
}

onMounted(fetchConfig)
</script>

<template>
  <div v-loading="loading">
    <el-form label-width="150px" label-position="left" class="set-form">
      <!-- 基本设置 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">基本设置</span></template>
        <el-form-item label="启用预约功能">
          <el-switch v-model="form.status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="自定义名称">
          <el-input v-model="form.diyname" placeholder="如：预约服务（默认“预约”）" style="width: 320px" />
        </el-form-item>
        <el-form-item label="列表头图">
          <el-input v-model="form.pic" placeholder="图片地址" style="width: 320px" />
        </el-form-item>
        <el-form-item label="列表描述">
          <el-input v-model="form.desc" type="textarea" :rows="2" style="width: 480px" />
        </el-form-item>
        <el-form-item label="详情顶部图">
          <el-input v-model="form.detailpic" placeholder="图片地址" style="width: 320px" />
        </el-form-item>
        <el-form-item label="预约按钮文案">
          <el-input v-model="form.yuyue_numtext" placeholder="如：立即预约" style="width: 320px" />
        </el-form-item>
        <el-form-item label="预约成功提示">
          <el-input v-model="form.yuyue_success" type="textarea" :rows="2" style="width: 480px" />
        </el-form-item>
        <el-form-item label="显示免费标签">
          <el-switch v-model="form.show_free" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="评价需审核">
          <el-switch v-model="form.comment_check" :active-value="1" :inactive-value="0" />
        </el-form-item>
      </el-card>

      <!-- 预约时间 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">预约时间</span></template>
        <el-form-item label="日期选择方式">
          <el-radio-group v-model="form.rqtype">
            <el-radio :value="1">按天</el-radio>
            <el-radio :value="2">按时段</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="可预约开始日期">
          <el-date-picker v-model="form.yybegintime" type="date" value-format="YYYY-MM-DD" placeholder="开始日期" style="width: 180px" />
        </el-form-item>
        <el-form-item label="可预约结束日期">
          <el-date-picker v-model="form.yyendtime" type="date" value-format="YYYY-MM-DD" placeholder="结束日期" style="width: 180px" />
        </el-form-item>
        <el-form-item label="预约周期">
          <el-checkbox-group v-model="form.yyzhouqi">
            <el-checkbox v-for="opt in ZHOU_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-checkbox>
          </el-checkbox-group>
        </el-form-item>
        <el-form-item label="早班开始（点）">
          <el-input-number v-model="form.zaohour" :min="0" :max="23" :precision="0" />
        </el-form-item>
        <el-form-item label="晚班结束（点）">
          <el-input-number v-model="form.wanhour" :min="1" :max="24" :precision="0" />
        </el-form-item>
        <el-form-item label="时段间隔（分钟）">
          <el-input-number v-model="form.timejg" :min="5" :precision="0" :step="5" />
        </el-form-item>
        <el-form-item label="自定义时段">
          <el-input v-model="form.timepoint" placeholder="多个用逗号分隔，如：09:00,10:30,14:00" style="width: 320px" />
        </el-form-item>
        <el-form-item label="最晚提前（分钟）">
          <el-input-number v-model="form.minminute" :min="0" :precision="0" :step="10" />
        </el-form-item>
        <el-form-item label="派单提前（小时）">
          <el-input-number v-model="form.pdprehour" :min="0" :precision="0" />
        </el-form-item>
      </el-card>

      <!-- 订单确认 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">订单确认</span></template>
        <el-form-item label="未付款自动关闭（分）">
          <el-input-number v-model="form.autoclose" :min="0" :precision="0" :step="5" />
        </el-form-item>
        <el-form-item label="自动完成（天）">
          <el-input-number v-model="form.autoshdays" :min="0" :precision="0" />
        </el-form-item>
        <el-form-item label="自动派单">
          <el-switch v-model="form.isautopd" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="完成需确认">
          <el-switch v-model="form.finish_confirm" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="确认方式">
          <el-radio-group v-model="form.confirm_type">
            <el-radio :value="0">人员确认</el-radio>
            <el-radio :value="1">会员确认</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="自动确认预约（天）">
          <el-input-number v-model="form.autoconfirm" :min="1" :precision="0" />
          <span class="form-tip">不能小于 1 天</span>
        </el-form-item>
      </el-card>

      <!-- 提成与提现 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">提成与提现</span></template>
        <el-form-item label="派单方式">
          <el-radio-group v-model="form.paidantype">
            <el-radio :value="1">手动派单</el-radio>
            <el-radio :value="2">人员抢单</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="提现方式">
          <el-checkbox v-model="form.withdraw_weixin" :true-value="1" :false-value="0">微信</el-checkbox>
          <el-checkbox v-model="form.withdraw_aliaccount" :true-value="1" :false-value="0">支付宝</el-checkbox>
          <el-checkbox v-model="form.withdraw_bankcard" :true-value="1" :false-value="0">银行卡</el-checkbox>
        </el-form-item>
        <el-form-item label="最低提现金额（元）">
          <el-input v-model="form.withdrawmin" style="width: 160px" />
        </el-form-item>
        <el-form-item label="提现手续费（%）">
          <el-input v-model="form.withdrawfee" style="width: 160px" />
        </el-form-item>
        <el-form-item label="开启商家分账">
          <el-switch v-model="form.businessst" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="商家分账比例（%）">
          <el-input v-model="form.businessfee" style="width: 160px" />
        </el-form-item>
        <el-form-item label="入驻保证金（元）">
          <el-input v-model="form.apply_paymoney" style="width: 160px" />
        </el-form-item>
      </el-card>

      <!-- 营销 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">营销设置</span></template>
        <el-form-item label="折扣显示">
          <el-switch v-model="form.discount" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="支持优惠券">
          <el-switch v-model="form.iscoupon" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="签到奖励（积分）">
          <el-input v-model="form.sign_juli" style="width: 160px" />
        </el-form-item>
      </el-card>

      <!-- 协议与广告 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">协议与广告</span></template>
        <el-form-item label="显示预约协议">
          <el-switch v-model="form.xieyi_show" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="协议内容">
          <el-input v-model="form.xieyi" type="textarea" :rows="3" style="width: 480px" />
        </el-form-item>
        <el-form-item label="列表广告">
          <el-switch v-model="form.ad_status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="广告图片">
          <el-input v-model="form.ad_pic" placeholder="图片地址" style="width: 320px" />
        </el-form-item>
        <el-form-item label="广告链接">
          <el-input v-model="form.ad_link" placeholder="跳转链接" style="width: 320px" />
        </el-form-item>
        <el-form-item label="列表视频">
          <el-switch v-model="form.video_status" :active-value="1" :inactive-value="0" />
        </el-form-item>
        <el-form-item label="视频标签">
          <el-input v-model="form.video_tag" style="width: 320px" />
        </el-form-item>
        <el-form-item label="视频标题">
          <el-input v-model="form.video_title" style="width: 320px" />
        </el-form-item>
      </el-card>

      <!-- 预约表单 -->
      <el-card shadow="never" class="group-card">
        <template #header><span class="group-title">预约表单</span></template>
        <el-form-item label="自定义表单链接">
          <el-input v-model="form.formurl" placeholder="选填，优先于下方表单项" style="width: 320px" />
        </el-form-item>
        <el-form-item label="表单项配置">
          <el-input v-model="form.formdata" type="textarea" :rows="4" style="width: 480px" placeholder="JSON 配置，一般无需修改" />
        </el-form-item>
      </el-card>

      <div class="save-bar">
        <el-button type="primary" size="large" :loading="submitting" @click="handleSave">保存设置</el-button>
      </div>
    </el-form>
  </div>
</template>

<style scoped>
.group-card {
  margin-bottom: 16px;
}

.group-title {
  font-weight: 600;
}

.form-tip {
  color: #909399;
  font-size: 12px;
  margin-left: 8px;
}

.save-bar {
  padding: 8px 0 16px;
}
</style>
