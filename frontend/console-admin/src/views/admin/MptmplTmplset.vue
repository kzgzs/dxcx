<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getMpTmplset, saveMpTmplset, addMpTemplate } from '../../api/tmplset'

/**
 * 公众号模板消息设置（mp_tmplset）
 * 对标 canzhao mptmpl/tmplset.html：23 个业务场景字段配置模板ID，
 * 每行「添加」按钮按模板编号调 api_add_template 取回模板ID并即时落库。
 */

interface FieldDef {
  field: string
  label: string
  no: string
  tip?: string
}

// 字段定义（照抄 canzhao tmplset.html 的标签与模板编号）
const FIELDS: FieldDef[] = [
  { field: 'tmpl_orderconfirm', label: '订单提交成功通知', no: 'OPENTM200746866' },
  { field: 'tmpl_orderpay', label: '订单支付成功通知', no: 'OPENTM207498902' },
  { field: 'tmpl_orderfahuo', label: '订单发货通知', no: 'OPENTM202243318' },
  { field: 'tmpl_ordershouhuo', label: '订单收货通知', no: 'OPENTM411154202' },
  { field: 'tmpl_ordertui', label: '退款申请通知', no: 'OPENTM407277862' },
  { field: 'tmpl_tuisuccess', label: '退款成功通知', no: 'TM00430' },
  { field: 'tmpl_tuierror', label: '退款申请驳回通知', no: 'TM00432' },
  { field: 'tmpl_withdraw', label: '提现申请通知', no: 'OPENTM405485000' },
  { field: 'tmpl_tixiansuccess', label: '提现成功通知', no: 'TM00980' },
  { field: 'tmpl_tixianerror', label: '提现失败通知', no: 'TM00981' },
  { field: 'tmpl_collagesuccess', label: '拼团成功通知', no: 'OPENTM400932513' },
  { field: 'tmpl_formsub', label: '表单提交通知', no: 'OPENTM405848758', tip: '用于有客户提交自定义表单时通知管理员' },
  { field: 'tmpl_kehuzixun', label: '客户咨询通知', no: 'OPENTM412547082', tip: '用于有客户在线咨询时通知管理员' },
  { field: 'tmpl_joinin', label: '成员加入提醒', no: 'OPENTM207685059', tip: '用于推荐下级加入提醒' },
  { field: 'tmpl_fenxiaosuccess', label: '分销成功提醒', no: 'OPENTM402027183', tip: '用于分销佣金到账提醒' },
  { field: 'tmpl_uplv', label: '会员升级通知', no: 'OPENTM401075189' },
  { field: 'tmpl_fuwudaoqi', label: '服务到期提醒', no: 'OPENTM408237933', tip: '用于服务型商品到期提醒' },
  { field: 'tmpl_peisongorder', label: '新配送订单通知', no: 'OPENTM417984500', tip: '用于通知配送员有新订单需要配送' },
  { field: 'tmpl_moneychange', label: '余额变动提示', no: 'OPENTM401833445' },
  { field: 'tmpl_restaurant_booking', label: '餐饮预定通知', no: 'OPENTM405628250' },
  { field: 'tmpl_shenhe', label: '审核结果通知', no: 'OPENTM408894858' },
  { field: 'tmpl_prize', label: '抽奖结果通知', no: '' },
  { field: 'tmpl_fenhong', label: '分红到账通知', no: '' },
  { field: 'tmpl_coupon_expire', label: '优惠券到期提醒', no: 'OPENTM411793443' },
]

const info = reactive<Record<string, string>>({})
const loading = ref(false)
const saving = ref(false)
const addingField = ref('')

async function load() {
  loading.value = true
  try {
    const rs = await getMpTmplset()
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
    await saveMpTmplset({ ...info })
    ElMessage.success('设置成功')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

// 添加模板：取回模板ID回填并即时保存落库
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
    const rs = await addMpTemplate(def.no)
    info[def.field] = rs.template_id
    await saveMpTmplset({ ...info })
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
      <template #header><span class="card-title">模板消息设置</span></template>

      <el-alert type="warning" :closable="false" class="quote-alert">
        微信规则变动，已添加过模板的可继续使用，未添加过模板的无法添加，请使用【类目模板消息】功能
      </el-alert>
      <el-alert type="info" :closable="false" class="quote-alert">
        已认证服务号才能开通模板消息功能，在公众平台(mp.weixin.qq.com) [功能]-[添加功能插件]-[模板消息]中申请开通；所在行业选择：IT科技/互联网|电子商务
      </el-alert>

      <el-form label-width="160px" class="form-narrow">
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
              <template v-if="def.tip">，{{ def.tip }}</template>
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
        </el-form-item>
      </el-form>
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
</style>
