<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import {
  getSmsSendlog,
  getSmsSet,
  saveSmsSet,
  testSms,
  type SmsConfig,
  type SmsLogItem,
} from '../../api/system'
import { formatTime } from '../../utils/time'

const activeTab = ref('set')

// ============ 短信配置表单 ============
const setloading = ref(false)
const saving = ref(false)
const info = ref<SmsConfig>({
  status: 0,
  smstype: 1,
  accesskey: '',
  accesssecret: '',
  sdkappid: '',
  sign_name: '',
  code_length: 0,
})

// 模板清单（key / 名称 / 内容占位符说明，对齐参考系统短信模板）
const TMPL_LIST: { key: string; label: string; tip: string }[] = [
  { key: 'smscode', label: '短信验证码', tip: '您的验证码：${code}，该验证码5分钟内有效，请勿泄漏于他人！' },
  { key: 'orderconfirm', label: '订单提交成功', tip: '订单 ${ordernum} 提交成功。' },
  { key: 'orderpay', label: '订单支付成功', tip: '订单支付成功，订单号：${ordernum}，我们会尽快为您发货。' },
  { key: 'orderfahuo', label: '订单发货通知', tip: '您的订单${ordernum}已发货，快递公司：${express_com}，快递单号：${express_no}，请留意查收。' },
  { key: 'collagesuccess', label: '拼团成功通知', tip: '订单${ordernum}拼团成功，我们会尽快为您发货。' },
  { key: 'tuisuccess', label: '退款成功通知', tip: '您的订单${ordernum}退款成功，退款金额：${money}元，请留意查收。' },
  { key: 'tuierror', label: '退款驳回通知', tip: '抱歉您的订单${ordernum}退款申请失败，原因：${reason}。' },
  { key: 'tixiansuccess', label: '提现成功通知', tip: '提现成功，打款金额：${money}，请留意查收。' },
  { key: 'tixianerror', label: '提现失败通知', tip: '抱歉您的提现申请失败，原因：${reason}。' },
  { key: 'fenxiaosuccess', label: '分销成功提醒', tip: '成功获得佣金：${money}元，请留意查收。' },
  { key: 'restaurant_booking', label: '餐饮预定成功提醒', tip: '预定成功，餐厅名称：${restaurant_name}，订位信息：${table}，预定时间：${time_range}，请准时到达。' },
  { key: 'restaurant_booking_fail', label: '餐饮预定失败提醒', tip: '抱歉您的预定失败，餐厅名称：${restaurant_name}，请重新预定。' },
  { key: 'checksuccess', label: '审核通过通知', tip: '恭喜您的注册审核已通过。' },
  { key: 'checkerror', label: '审核驳回通知', tip: '抱歉您的注册审核未通过，驳回原因${reason}。' },
  { key: 'yysucess', label: '预约成功通知', tip: '您预定的${name}，已为您准备好，预定时间：${time}，敬请准时赴约。' },
  { key: 'recharge', label: '充值成功通知', tip: '会员充值成功，请登录查看。' },
  { key: 'sysmsg_notice', label: '新私信提醒', tip: '您有一条来自于新的私信未读，请登录查看。' },
  { key: 'checknotice', label: '待审核通知', tip: '您有一条新的审核信息，请登录查看。' },
  { key: 'send_coupon', label: '发放优惠券通知', tip: '{coupon_name}已发放，{expiration_time}失效。（coupon_name优惠券名称，expiration_time失效时间）' },
  { key: 'coupon_expired', label: '优惠券快过期通知', tip: '您的{coupon_name}还有{days}天就过期了，默认到期前3天提示。' },
  { key: 'admin_operate_money', label: '余额变更通知', tip: '您的余额因为{remark}，金额{operate}了{money}元，有异议联系商家客服。' },
  { key: 'admin_recharge_money', label: '余额充值通知', tip: '您本次充值的{money}元已添加到您的会员余额，有异议联系商家客服。' },
  { key: 'member_uplevel', label: '会员等级变更通知', tip: '您的会员等级从{old_level}等级变为{new_level}等级，新的等级有效期{expiration_days}。' },
  { key: 'member_uplevel_expired', label: '会员等级到期通知', tip: '您的{level}等级还有7天就要到期了，届时会员权益将同步降为默认等级权益。' },
]

async function fetchSet() {
  setloading.value = true
  try {
    const data = await getSmsSet()
    // 合并：保证模板字段齐全
    const merged: SmsConfig = { ...info.value, ...data }
    for (const t of TMPL_LIST) {
      merged['tmpl_' + t.key] = merged['tmpl_' + t.key] ?? ''
      merged['tmpl_' + t.key + '_st'] = merged['tmpl_' + t.key + '_st'] ?? 0
    }
    info.value = merged
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    setloading.value = false
  }
}

async function handleSaveSet() {
  saving.value = true
  try {
    await saveSmsSet(info.value)
    ElMessage.success('保存成功')
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    saving.value = false
  }
}

// ============ 测试发送 ============
const testVisible = ref(false)
const testSending = ref(false)
const testForm = reactive({ mobile: '', tmpl_key: '', label: '' })

function openTest(key: string, label: string) {
  testForm.mobile = ''
  testForm.tmpl_key = key
  testForm.label = label
  testVisible.value = true
}

async function handleTest() {
  if (!/^1\d{10}$/.test(testForm.mobile)) {
    ElMessage.warning('请输入正确的手机号')
    return
  }
  testSending.value = true
  try {
    await testSms(testForm.mobile, testForm.tmpl_key)
    ElMessage.success('发送成功')
    testVisible.value = false
    if (activeTab.value === 'log') fetchLog()
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    testSending.value = false
  }
}

// ============ 发送记录 ============
const logLoading = ref(false)
const logData = ref<SmsLogItem[]>([])
const logTotal = ref(0)
const logQuery = reactive({
  page: 1,
  pagesize: 10,
  status: '' as number | '',
  phone: '',
  range: [] as string[],
})

async function fetchLog() {
  logLoading.value = true
  try {
    const data = await getSmsSendlog({
      page: logQuery.page,
      pagesize: logQuery.pagesize,
      status: logQuery.status === '' ? undefined : logQuery.status,
      phone: logQuery.phone || undefined,
      start: logQuery.range?.[0] || undefined,
      end: logQuery.range?.[1] || undefined,
    })
    logData.value = data.list || []
    logTotal.value = data.total || 0
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    logLoading.value = false
  }
}

function handleLogSearch() {
  logQuery.page = 1
  fetchLog()
}

function handleLogReset() {
  logQuery.status = ''
  logQuery.phone = ''
  logQuery.range = []
  logQuery.page = 1
  fetchLog()
}

function handleTabChange(tab: string | number) {
  if (tab === 'log' && !logData.value.length) fetchLog()
}

onMounted(() => {
  fetchSet()
})
</script>

<template>
  <div>
    <el-card shadow="never">
      <el-tabs v-model="activeTab" @tab-change="handleTabChange">
        <!-- 短信设置 -->
        <el-tab-pane label="短信设置" name="set">
          <el-form
            v-loading="setloading"
            label-width="110px"
            label-position="left"
            style="max-width: 760px"
          >
            <el-form-item label="短信功能">
              <el-switch
                v-model="info.status"
                :active-value="1"
                :inactive-value="0"
                active-text="开启"
                inactive-text="关闭"
              />
            </el-form-item>
            <el-form-item label="短信网关">
              <el-radio-group v-model="info.smstype">
                <el-radio :value="1">阿里云短信</el-radio>
                <el-radio :value="2">聚合数据</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="AccessKey">
              <el-input v-model="info.accesskey" placeholder="请填写 AccessKey ID" />
            </el-form-item>
            <el-form-item label="AccessSecret">
              <el-input
                v-model="info.accesssecret"
                type="password"
                show-password
                placeholder="请填写 AccessKey Secret"
              />
            </el-form-item>
            <el-form-item v-if="Number(info.smstype) === 2" label="SdkAppId">
              <el-input v-model="info.sdkappid" placeholder="请填写 SdkAppId（选填）" />
            </el-form-item>
            <el-form-item label="短信签名">
              <el-input v-model="info.sign_name" placeholder="请填写已审核通过的短信签名" />
            </el-form-item>
            <el-form-item label="验证码长度">
              <el-input-number v-model="info.code_length" :min="0" :max="8" />
              <span class="form-tip">0=使用默认长度</span>
            </el-form-item>

            <el-divider content-position="left">短信模板（填写模板编号并按需开启）</el-divider>

            <div v-for="t in TMPL_LIST" :key="t.key" class="tmpl-row">
              <div class="tmpl-label">{{ t.label }}</div>
              <el-input
                v-model="info['tmpl_' + t.key]"
                placeholder="模板编号"
                style="width: 220px"
              />
              <el-switch
                v-model="info['tmpl_' + t.key + '_st']"
                :active-value="1"
                :inactive-value="0"
                active-text="开启"
                inactive-text="关闭"
              />
              <el-button link type="primary" @click="openTest(t.key, t.label)">测试</el-button>
              <div class="tmpl-tip">模板内容示例：{{ t.tip }}</div>
            </div>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveSet">保存设置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 发送记录 -->
        <el-tab-pane label="发送记录" name="log">
          <el-form inline @submit.prevent>
            <el-form-item label="状态">
              <el-select v-model="logQuery.status" placeholder="全部" clearable style="width: 120px">
                <el-option label="成功" :value="1" />
                <el-option label="失败" :value="2" />
                <el-option label="发送中" :value="0" />
              </el-select>
            </el-form-item>
            <el-form-item label="手机号">
              <el-input v-model="logQuery.phone" placeholder="手机号" clearable style="width: 160px" />
            </el-form-item>
            <el-form-item label="时间">
              <el-date-picker
                v-model="logQuery.range"
                type="daterange"
                value-format="YYYY-MM-DD"
                range-separator="至"
                start-placeholder="开始日期"
                end-placeholder="结束日期"
                style="width: 240px"
              />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" @click="handleLogSearch">搜索</el-button>
              <el-button @click="handleLogReset">重置</el-button>
            </el-form-item>
          </el-form>

          <el-table v-loading="logLoading" :data="logData" stripe>
            <el-table-column prop="id" label="ID" width="70" />
            <el-table-column prop="PhoneNumbers" label="手机号" width="140" />
            <el-table-column prop="SignName" label="签名" width="110" show-overflow-tooltip />
            <el-table-column prop="TemplateCode" label="模板编号" width="130" show-overflow-tooltip />
            <el-table-column prop="TemplateContent" label="模板内容" min-width="180" show-overflow-tooltip />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag v-if="row.status === 1" type="success">成功</el-tag>
                <el-tag v-else-if="row.status === 2" type="danger">失败</el-tag>
                <el-tag v-else type="info">发送中</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="err_msg" label="失败原因" min-width="140" show-overflow-tooltip />
            <el-table-column label="发送时间" width="170">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
          </el-table>

          <el-pagination
            class="pagination"
            background
            layout="total, sizes, prev, pager, next, jumper"
            :total="logTotal"
            :current-page="logQuery.page"
            :page-size="logQuery.pagesize"
            :page-sizes="[10, 20, 50, 100]"
            @current-change="(p: number) => { logQuery.page = p; fetchLog() }"
            @size-change="(s: number) => { logQuery.pagesize = s; logQuery.page = 1; fetchLog() }"
          />
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 测试发送弹窗 -->
    <el-dialog v-model="testVisible" title="测试发送短信" width="420px">
      <el-form label-width="90px" label-position="left">
        <el-form-item label="短信模板">
          <span>{{ testForm.label }}</span>
        </el-form-item>
        <el-form-item label="接收手机">
          <el-input v-model="testForm.mobile" placeholder="请输入接收测试短信的手机号" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="testVisible = false">取消</el-button>
        <el-button type="primary" :loading="testSending" @click="handleTest">发送</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.form-tip {
  margin-left: 10px;
  color: #909399;
  font-size: 12px;
}

.tmpl-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;
  padding: 8px 0;
  border-bottom: 1px dashed #ebeef5;
}

.tmpl-label {
  width: 130px;
  font-size: 13px;
  color: #303133;
}

.tmpl-tip {
  flex-basis: 100%;
  padding-left: 142px;
  color: #909399;
  font-size: 12px;
  line-height: 1.6;
}

.pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
