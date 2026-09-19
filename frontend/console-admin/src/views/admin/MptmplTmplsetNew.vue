<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getMpTmplsetNew, saveMpTmplsetNew, addMpTemplateNew } from '../../api/tmplset'

/**
 * 类目模板消息设置（mp_tmplset_new）
 * 对标 canzhao mptmpl/tmplset_new.html：类目模板编号配置 + 内置类目模板编号库
 * （照抄 canzhao Mptmpl::getKeywordsList 约 28 个类目模板编号）。
 * 「添加」调 api_add_template（附关键词，后端自动设行业 互联网|电子商务，
 * 48001=仅认证服务号错误提示）。
 */

interface FieldDef {
  field: string
  label: string
  no: string
  tip?: string
}

// 表单字段定义（照抄 canzhao tmplset_new.html 的标签与类目模板编号）
const FIELDS: FieldDef[] = [
  { field: 'tmpl_orderconfirm', label: '订单提交成功通知', no: '45764' },
  { field: 'tmpl_orderpay', label: '订单支付成功通知', no: '43216' },
  { field: 'tmpl_orderfahuo', label: '订单发货通知', no: '42984' },
  { field: 'tmpl_ordershouhuo', label: '订单收货通知', no: '46234' },
  { field: 'tmpl_ordertui', label: '退款申请通知', no: '46044' },
  { field: 'tmpl_tuisuccess', label: '退款成功通知', no: '46622' },
  { field: 'tmpl_tuierror', label: '退款驳回通知', no: '46623' },
  { field: 'tmpl_maidanpay', label: '买单支付成功通知', no: '47568', tip: '标题:收款成功通知' },
  { field: 'tmpl_peisongorder', label: '收到客户新订单通知', no: '48089', tip: '场景：发给订单相关人员处理订单，如：派单、配送、抢单等' },
  { field: 'tmpl_tixiansuccess', label: '提现成功通知', no: '52381' },
  { field: 'tmpl_formsub', label: '收到报名申请通知', no: '46046', tip: '会员提交自定义表单时通知管理员' },
  { field: 'tmpl_moneychange', label: '余额消费通知', no: '42830' },
  { field: 'tmpl_shenhe', label: '审核结果通知', no: '48306' },
  { field: 'tmpl_kehuzixun', label: '客户咨询通知', no: '52660', tip: '标题:售后订单处理通知' },
  { field: 'tmpl_yuyue_jiedan', label: '预约接单提醒', no: '58919', tip: '标题:接单成功提醒，添加类目 工具 > 办公' },
  { field: 'tmpl_joinfree', label: '加入免单通知', no: '' },
  { field: 'tmpl_zhaopin_notice', label: '招聘通知', no: '' },
  { field: 'tmpl_register', label: '注册成功通知', no: '' },
]

// 内置类目模板编号库（照抄 canzhao Mptmpl::getKeywordsList）
interface LibItem {
  no: string
  title: string
  keywords: string[]
}

const LIBRARY: LibItem[] = [
  { no: '45764', title: '订单提交成功通知', keywords: ['订单号', '下单门店', '商品名称', '下单金额', '提交时间'] },
  { no: '43216', title: '订单支付成功通知', keywords: ['门店', '订单号', '商品名称', '会员名称', '支付金额'] },
  { no: '47568', title: '买单支付成功通知', keywords: ['门店', '订单编号', '商品名称', '付款人', '交易金额'] },
  { no: '42984', title: '订单发货通知', keywords: ['商品名称', '快递公司', '快递单号', '收货人'] },
  { no: '46234', title: '订单完成通知', keywords: ['收货人', '订单号', '送达时间'] },
  { no: '46044', title: '退款申请通知', keywords: ['订单号', '退款金额'] },
  { no: '46622', title: '退款成功通知', keywords: ['订单编号', '商品名称', '退款金额'] },
  { no: '46623', title: '退款驳回通知', keywords: ['订单编号', '商品名称', '退款金额'] },
  { no: '42830', title: '消费成功通知', keywords: ['消费项目', '消费金额', '卡内余额', '消费时间'] },
  { no: '48089', title: '收到客户新订单通知', keywords: ['订单编号', '门店名称', '商品名称', '客户地址', '订单时间'] },
  { no: '52381', title: '提现成功通知', keywords: ['提现金额', '提现时间'] },
  { no: '46046', title: '收到报名申请通知', keywords: ['报名名称', '申请时间'] },
  { no: '48306', title: '报名审核结果通知', keywords: ['报名名称', '审核结果', '审核时间'] },
  { no: '57863', title: '订单缺货提醒', keywords: ['订单编号', '下单时间', '商品名称'] },
  { no: '48189', title: '账单结算成功通知', keywords: ['店铺名称', '结算时间', '结算金额', '可用余额'] },
  { no: '53349', title: '设备缺货提醒', keywords: ['机器名称', '地点'] },
  { no: '46230', title: '会员购买成功通知', keywords: ['到期时间', '购买类型'] },
  { no: '52660', title: '售后订单处理通知', keywords: ['商品名称', '客户名称'] },
  { no: '54501', title: '下级确认收货通知', keywords: ['订单编号', '商品名称', '订单金额', '订单时间', '预计佣金'] },
  { no: '42773', title: '客户预约车险通知', keywords: ['车牌号', '客户姓名', '到期时间', '联系电话'] },
  { no: '54729', title: '车辆保养申请成功提醒', keywords: ['车牌号码', '本次时间'] },
  { no: '53913', title: '车辆年检申请提醒', keywords: ['申请人', '车牌号', '到期时间', '咨询电话'] },
  { no: '47391', title: '收款已完成通知', keywords: ['活动名称', '活动时间'] },
  { no: '42933', title: '设备告警提醒', keywords: ['设备名称', '告警原因', '告警时间'] },
  { no: '47967', title: '取货提醒', keywords: ['取货时间', '门店名称', '商品名称', '取货地址', '联系方式'] },
  { no: '48014', title: '客户预定成功通知', keywords: ['酒店名称', '入住人', '入离时间', '手机号', '订单号'] },
  { no: '58919', title: '接单成功提醒', keywords: ['订单编号', '接单人', '服务时间', '联系电话'] },
]

const info = reactive<Record<string, string>>({})
const loading = ref(false)
const saving = ref(false)
const addingField = ref('')
const showLibrary = ref(false)

async function load() {
  loading.value = true
  try {
    const rs = await getMpTmplsetNew()
    const data = rs.info || {}
    for (const def of FIELDS) {
      info[def.field] = String(data[def.field] ?? '')
    }
    info.tmpl_message_link = String(data.tmpl_message_link ?? 'mp')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  saving.value = true
  try {
    await saveMpTmplsetNew({ ...info })
    ElMessage.success('设置成功')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

// 添加类目模板：取回模板ID回填并即时保存落库
async function handleAdd(def: FieldDef) {
  if (!def.no) return
  if (info[def.field]) {
    try {
      await ElMessageBox.confirm('已填写模板ID，确定要重新添加吗？', '操作确认', {
        type: 'warning',
        confirmButtonText: '确定',
        cancelButtonText: '取消',
      })
    } catch {
      return
    }
  }

  addingField.value = def.field
  try {
    const rs = await addMpTemplateNew(def.no)
    info[def.field] = rs.template_id
    await saveMpTmplsetNew({ ...info })
    ElMessage.success('模板已添加并保存')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    addingField.value = ''
  }
}

onMounted(load)
</script>

<template>
  <div class="page-wrap" v-loading="loading">
    <el-card shadow="never">
      <template #header><span class="card-title">类目模板消息设置</span></template>

      <el-alert type="info" :closable="false" class="quote-alert">
        已认证服务号才能开通模板消息功能，在公众平台(mp.weixin.qq.com) [广告与服务]-[模板消息]-[模板库]-[类目模板库]中申请开通；所在服务类目选择：生活服务&gt;百货/超市/便利店；工具&gt;预约/报名
      </el-alert>

      <el-form label-width="170px" class="form-narrow">
        <el-form-item v-for="def in FIELDS" :key="def.field" :label="def.label">
          <div class="tmpl-row">
            <el-input v-model="info[def.field]" placeholder="模板ID" style="width: 400px" />
            <el-button
              v-if="def.no"
              :loading="addingField === def.field"
              @click="handleAdd(def)"
            >添加</el-button>
            <span class="tmpl-no">
              <template v-if="def.no">模板编号：{{ def.no }}</template>
              <template v-else>手工填写模板ID</template>
              <template v-if="def.tip">（{{ def.tip }}）</template>
            </span>
          </div>
        </el-form-item>

        <el-form-item label="模板消息链接">
          <el-radio-group v-model="info.tmpl_message_link">
            <el-radio value="mp">打开公众号</el-radio>
            <el-radio value="wx">打开小程序</el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保 存</el-button>
          <el-button link type="primary" @click="showLibrary = !showLibrary">
            {{ showLibrary ? '收起类目模板编号库' : '查看类目模板编号库' }}
          </el-button>
        </el-form-item>
      </el-form>

      <!-- 内置类目模板编号库 -->
      <div v-if="showLibrary" class="library-box">
        <p class="page-tip">内置类目模板编号库（共 {{ LIBRARY.length }} 个，添加时自动携带对应关键词）</p>
        <el-table :data="LIBRARY" border size="small" style="max-width: 900px">
          <el-table-column prop="no" label="模板编号" width="110" />
          <el-table-column prop="title" label="模板标题" width="180" />
          <el-table-column label="关键词">
            <template #default="{ row }">{{ row.keywords.join('、') }}</template>
          </el-table-column>
        </el-table>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.page-wrap {
  padding: 4px;
}

.card-title {
  font-weight: 600;
}

.quote-alert {
  margin-bottom: 10px;
}

.form-narrow {
  max-width: 1000px;
  margin-top: 10px;
}

.tmpl-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.tmpl-no {
  color: #909399;
  font-size: 12px;
}

.library-box {
  margin-top: 8px;
  padding-top: 12px;
  border-top: 1px solid #ebeef5;
}

.page-tip {
  margin: 0 0 10px;
  color: #909399;
  font-size: 13px;
}
</style>
