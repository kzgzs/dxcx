<script setup lang="ts">
import { onMounted, reactive, ref, type Ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getWxTmplset,
  saveWxTmplset,
  getWxSubscribeTitle,
  addWxTemplate,
  type WxSubscribeTitleRow,
} from '../../api/tmplset'

/**
 * 小程序订阅消息设置（wx_tmplset）
 * 对标 canzhao wxtmpl/tmplset.html：主字段组 + _new 订阅消息字段组，
 * 两组各自独立保存；「获取模板标题」展示小程序类目列表，
 * 每行「添加」按 tid+kidList 组合模板并添加至账号，回填模板ID并即时落库。
 */

interface FieldDef {
  field: string
  label: string
  no: string
  keywords: string
}

// 主字段组（照抄 canzhao wxtmpl/tmplset.html 的 tid 与关键词编号）
const MAIN_FIELDS: FieldDef[] = [
  { field: 'tmpl_orderconfirm', label: '订单下单提醒', no: '585', keywords: '11,2,10,13,27' },
  { field: 'tmpl_orderpay', label: '支付成功通知', no: '3578', keywords: '3,4,9,12,14' },
  { field: 'tmpl_orderfahuo', label: '订单发货通知', no: '855', keywords: '1,7,4,11' },
  { field: 'tmpl_ordershouhuo', label: '收货结果通知', no: '1481', keywords: '2,6,3,5' },
  { field: 'tmpl_ordertui', label: '申请退款通知', no: '1468', keywords: '1,4,2,9,10' },
  { field: 'tmpl_tuisuccess', label: '退款成功通知', no: '7517', keywords: '6,3,2' },
  { field: 'tmpl_tuierror', label: '退款申请驳回通知', no: '4493', keywords: '3,2,1' },
  { field: 'tmpl_withdraw', label: '提现申请通知', no: '6789', keywords: '3,1,2,4' },
  { field: 'tmpl_tixiansuccess', label: '提现成功通知', no: '2001', keywords: '1,3,5' },
  { field: 'tmpl_tixianerror', label: '提现失败通知', no: '3173', keywords: '1,3,4' },
  { field: 'tmpl_collagesuccess', label: '拼团成功通知', no: '980', keywords: '1,10,12' },
  { field: 'tmpl_fuwudaoqi', label: '订单到期提醒', no: '12624', keywords: '1,3' },
  { field: 'tmpl_shenhe', label: '审核结果通知', no: '818', keywords: '8,2,4' },
  { field: 'tmpl_kehuzixun', label: '收到留言通知', no: '3840', keywords: '1,3,2' },
  { field: 'tmpl_choujiang', label: '抽奖结果通知', no: '', keywords: '' },
]

// _new 订阅消息字段组
const NEW_FIELDS: FieldDef[] = [
  { field: 'tmpl_orderfahuo_new', label: '发货通知', no: '1458', keywords: '29,1,2,9' },
  { field: 'tmpl_tuisuccess_new', label: '退款成功通知', no: '9244', keywords: '3,6,4' },
  { field: 'tmpl_tuierror_new', label: '退款拒绝通知', no: '8746', keywords: '3,8,4' },
  { field: 'tmpl_tixiansuccess_new', label: '提现到账通知', no: '5263', keywords: '3,9,8' },
  { field: 'tmpl_tixianerror_new', label: '提现结果通知', no: '1470', keywords: '1,2,4,12' },
  { field: 'tmpl_collagesuccess_new', label: '拼团成功通知', no: '3098', keywords: '7,12,2' },
  { field: 'tmpl_shenhe_new', label: '审核结果通知', no: '4082', keywords: '2,1,5' },
]

const info = reactive<Record<string, string>>({})
const loading = ref(false)
const savingMain = ref(false)
const savingNew = ref(false)
const addingField = ref('')

// 模板标题（类目列表）
const titleList = ref<WxSubscribeTitleRow[]>([])
const showTitle = ref(false)
const loadingTitle = ref(false)

async function load() {
  loading.value = true
  try {
    const rs = await getWxTmplset()
    const data = rs.info || {}
    for (const def of [...MAIN_FIELDS, ...NEW_FIELDS]) {
      info[def.field] = String(data[def.field] ?? '')
    }
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

// 分组保存：仅提交本组字段
async function doSave(fields: FieldDef[], saving: Ref<boolean>) {
  saving.value = true
  try {
    const payload: Record<string, string> = {}
    for (const def of fields) payload[def.field] = info[def.field] || ''
    await saveWxTmplset(payload)
    ElMessage.success('设置成功')
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    saving.value = false
  }
}

// 分组保存入口（避免在模板里直接传 ref，模板表达式会将 ref 解包为 boolean）
async function handleSaveMain() {
  await doSave(MAIN_FIELDS, savingMain)
}

async function handleSaveNew() {
  await doSave(NEW_FIELDS, savingNew)
}

// 获取模板标题（小程序类目列表）
async function handleGetTitle() {
  loadingTitle.value = true
  try {
    const rs = await getWxSubscribeTitle()
    titleList.value = rs.list || []
    showTitle.value = true
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loadingTitle.value = false
  }
}

// 添加模板：tid+kidList 组合后添加至账号，回填并即时落库
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
    const rs = await addWxTemplate(def.no, def.keywords)
    info[def.field] = rs.template_id
    await saveWxTmplset({ [def.field]: rs.template_id })
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
      <template #header>
        <div class="card-header">
          <span class="card-title">小程序订阅消息设置</span>
          <el-button :loading="loadingTitle" @click="handleGetTitle">获取模板标题</el-button>
        </div>
      </template>

      <el-alert type="warning" :closable="false" class="quote-alert">
        小程序订阅消息需用户主动授权订阅。模板超限（45026/200012）时请先在微信公众平台 [订阅消息] 中删除不用的模板。
      </el-alert>

      <!-- 模板标题（类目列表） -->
      <el-table v-if="showTitle" :data="titleList" border stripe size="small" class="title-table">
        <el-table-column prop="tid" label="类目编号(tid)" width="160" align="center" />
        <el-table-column prop="title" label="模板标题" min-width="240" />
        <template #empty>
          <el-empty description="暂无类目模板标题" :image-size="60" />
        </template>
      </el-table>

      <!-- 主字段组 -->
      <div class="group-title">订阅消息设置</div>
      <el-form label-width="160px" class="form-narrow">
        <el-form-item v-for="def in MAIN_FIELDS" :key="def.field" :label="def.label">
          <div class="tmpl-row">
            <el-input v-model="info[def.field]" placeholder="模板ID" style="width: 380px" />
            <el-button
              v-if="def.no"
              :loading="addingField === def.field"
              @click="handleAdd(def)"
            >添加</el-button>
            <span class="tmpl-no">
              <template v-if="def.no">类目编号：{{ def.no }}</template>
              <template v-else>手工填写模板ID</template>
            </span>
          </div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingMain" @click="handleSaveMain">保 存</el-button>
        </el-form-item>
      </el-form>

      <!-- _new 订阅消息字段组 -->
      <div class="group-title">订阅消息设置（新版）</div>
      <el-form label-width="160px" class="form-narrow">
        <el-form-item v-for="def in NEW_FIELDS" :key="def.field" :label="def.label">
          <div class="tmpl-row">
            <el-input v-model="info[def.field]" placeholder="模板ID" style="width: 380px" />
            <el-button
              v-if="def.no"
              :loading="addingField === def.field"
              @click="handleAdd(def)"
            >添加</el-button>
            <span class="tmpl-no">类目编号：{{ def.no }}</span>
          </div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="savingNew" @click="handleSaveNew">保 存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<style scoped>
.page-wrap {
  padding: 4px;
}

.card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.card-title {
  font-weight: 600;
}

.quote-alert {
  margin-bottom: 10px;
}

.title-table {
  margin-bottom: 16px;
  max-width: 700px;
}

.group-title {
  font-weight: 600;
  font-size: 14px;
  margin: 14px 0 6px;
  padding-left: 8px;
  border-left: 3px solid var(--el-color-primary);
}

.form-narrow {
  max-width: 1000px;
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
