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
  status: 1,
  smstype: 2,
  accesskey: '',
  accesssecret: '',
  sdkappid: '',
  sign_name: '',
  code_length: 0,
  var_length: 0,
})

// 模板清单（严格对齐点大演示站，按分组组织）
const TMPL_GROUPS: { title: string; items: { key: string; label: string; tip: string }[] }[] = [
  {
    title: '',
    items: [
      { key: 'smscode', label: '短信验证码', tip: '您的验证码：{1}，该验证码5分钟内有效，请勿泄漏于他人！' },
    ],
  },
  {
    title: '会员通知',
    items: [
      { key: 'orderpay', label: '订单支付成功', tip: '订单支付成功，订单号：{1}，我们会尽快为您发货。' },
      { key: 'orderfahuo', label: '订单发货通知', tip: '您的订单{1}已发货，快递公司：{2}，快递单号：{3}，请留意查收。' },
      { key: 'collagesuccess', label: '拼团成功通知', tip: '订单{1}拼团成功，我们会尽快为您发货。' },
      { key: 'tuisuccess', label: '退款成功通知', tip: '您的订单{1}退款成功，退款金额：{2}元，请留意查收。' },
      { key: 'tuierror', label: '退款驳回通知', tip: '抱歉您的订单{1}退款申请失败，原因：{2}。' },
      { key: 'tixiansuccess', label: '提现成功通知', tip: '提现成功，打款金额：{1}，请留意查收。' },
      { key: 'tixianerror', label: '提现失败通知', tip: '抱歉您的提现申请失败，原因：{1}。' },
      { key: 'fenxiaosuccess', label: '分销成功提醒', tip: '成功获得佣金：{1}元，请留意查收。' },
      { key: 'restaurant_booking', label: '餐饮预定成功提醒', tip: '预定成功，餐厅名称：{1}，订位信息：{2}，预定时间：{3}，请准时到达。' },
      { key: 'restaurant_booking_fail', label: '餐饮预定失败提醒', tip: '抱歉您的预定失败，餐厅名称：{1}，请重新预定。' },
    ],
  },
  {
    title: '管理员通知',
    items: [
      { key: 'yysneworder', label: '预约服务人员新订单提醒', tip: '您有新的预约服务订单，订单号：{1}，请尽快处理。' },
      { key: 'jiedan_timeout', label: '接单超时通知', tip: '有订单接单超时，订单号：{1}，派单时间：{2}，请及时处理；同城配送快递鸟接单超时使用' },
    ],
  },
]
const TMPL_LIST = TMPL_GROUPS.flatMap(g => g.items)

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
            <el-form-item label="短信状态">
              <el-radio-group v-model="info.status">
                <el-radio :value="1">开启</el-radio>
                <el-radio :value="0">关闭</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="短信接口">
              <el-select v-model="info.smstype" style="width:200px">
                <el-option :value="1" label="阿里云" />
                <el-option :value="2" label="腾讯云" />
                <el-option :value="3" label="定制短信通道" />
              </el-select>
            </el-form-item>
            <!-- 阿里云 -->
            <template v-if="Number(info.smstype) === 1">
              <el-form-item label="AccessKey ID">
                <el-input v-model="info.accesskey" placeholder="已隐藏内容，点击查看或编辑" />
                <span class="form-tip">阿里云后台-访问密钥管理-AccessKey ID</span>
              </el-form-item>
              <el-form-item label="AccessKey Secret">
                <el-input v-model="info.accesssecret" type="password" show-password placeholder="已隐藏内容，点击查看或编辑" />
                <span class="form-tip">阿里云后台-访问密钥管理-AccessKey Secret（创建时复制保存，无法二次查看）</span>
              </el-form-item>
            </template>
            <!-- 腾讯云 -->
            <template v-if="Number(info.smstype) === 2">
              <el-form-item label="SecretId">
                <el-input v-model="info.accesskey" placeholder="已隐藏内容，点击查看或编辑" />
                <a class="form-link" href="https://console.cloud.tencent.com/cam/capi" target="_blank">腾讯云后台-访问管理-访问密钥-密钥管理-SecretId</a>
              </el-form-item>
              <el-form-item label="SecretKey">
                <el-input v-model="info.accesssecret" type="password" show-password placeholder="已隐藏内容，点击查看或编辑" />
                <a class="form-link" href="https://console.cloud.tencent.com/cam/capi" target="_blank">腾讯云后台-访问管理-访问密钥-密钥管理-SecretKey（创建时复制保存 无法二次查看复制）</a>
              </el-form-item>
              <el-form-item label="应用SDK AppID">
                <el-input v-model="info.sdkappid" placeholder="已隐藏内容，点击查看或编辑" />
                <a class="form-link" href="https://console.cloud.tencent.com/smsv2/app-manage" target="_blank">腾讯云后台-短信-应用管理-应用列表-SDKAppID</a>
              </el-form-item>
            </template>
            <!-- 定制短信通道 -->
            <template v-if="Number(info.smstype) === 3">
              <el-form-item label="用户名">
                <el-input v-model="info.accesskey" placeholder="已隐藏内容，点击查看或编辑" />
              </el-form-item>
              <el-form-item label="密码">
                <el-input v-model="info.accesssecret" type="password" show-password placeholder="已隐藏内容，点击查看或编辑" />
              </el-form-item>
            </template>
            <el-form-item label="短信签名">
              <el-input v-model="info.sign_name" placeholder="请填写已审核通过的短信签名" />
              <a v-if="Number(info.smstype) === 2" class="form-link" href="https://console.cloud.tencent.com/smsv2/csms-sign" target="_blank">腾讯云后台-短信-国内短信-签名管理-签名内容</a>
            </el-form-item>
            <el-form-item label="变量长度">
              <el-radio-group v-model="info.var_length">
                <el-radio :value="0">不限制</el-radio>
                <el-radio :value="6">6个字符</el-radio>
                <el-radio :value="-1">不含变量</el-radio>
              </el-radio-group>
              <div class="form-tip">腾讯云新账号限制变量最多6个字符，选择"不含变量"则模板中没有任何变量（短信验证码除外）</div>
            </el-form-item>

            <div class="section-title">短信模板设置</div>

            <template v-for="group in TMPL_GROUPS" :key="group.title">
              <div v-if="group.title" class="section-title">{{ group.title }}</div>
              <div v-for="t in group.items" :key="t.key" class="tmpl-row">
                <div class="tmpl-label">{{ t.label }}</div>
                <el-input
                  v-model="info['tmpl_' + t.key]"
                  placeholder="请填写模板ID"
                  style="width: 220px"
                />
                <el-switch
                  v-model="info['tmpl_' + t.key + '_st']"
                  :active-value="1"
                  :inactive-value="0"
                  active-text="开启"
                  inactive-text="关闭"
                />
                <div class="tmpl-tip">请填写模板ID 模板内容示例：{{ t.tip }}</div>
              </div>
            </template>

            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveSet">确认修改</el-button>
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

.section-title {
  font-size: 14px;
  font-weight: 600;
  color: #303133;
  padding: 14px 0 8px;
  border-bottom: 1px solid #ebeef5;
  margin-bottom: 8px;
}

.form-link {
  margin-left: 10px;
  font-size: 12px;
  color: #409eff;
  text-decoration: none;
}
.form-link:hover {
  text-decoration: underline;
}
</style>
