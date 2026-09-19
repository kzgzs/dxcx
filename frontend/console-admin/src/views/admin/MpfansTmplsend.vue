<template>
  <div class="mpfans-tmplsend">
    <el-card shadow="never">
      <el-tabs v-model="activeTab">
        <!-- ==================== 模板消息群发 ==================== -->
        <el-tab-pane label="模板消息群发" name="tmpl">
          <el-form label-width="110px" class="send-form">
            <el-form-item label="任务标题">
              <el-input v-model="tmplForm.title" placeholder="便于识别的发送标题" style="width: 360px" />
            </el-form-item>
            <el-form-item label="选择模板">
              <el-select v-model="tmplForm.template_id" placeholder="请选择模板（来自模板消息配置）" style="width: 360px">
                <el-option
                  v-for="t in templates"
                  :key="t.field"
                  :label="t.label"
                  :value="t.template_id_new || t.template_id"
                />
              </el-select>
              <span v-if="!templates.length" class="form-tip">暂无可用模板，请先在「模板消息配置」中绑定模板ID</span>
            </el-form-item>
            <el-form-item label="模板数据">
              <div class="data-rows">
                <div v-for="(row, i) in dataRows" :key="i" class="data-row">
                  <el-input v-model="row.key" placeholder="字段名 如 first / keyword1 / remark" style="width: 220px" />
                  <el-input v-model="row.value" placeholder="字段内容" style="width: 320px" />
                  <el-button link type="danger" @click="dataRows.splice(i, 1)">删除</el-button>
                </div>
                <el-button size="small" @click="dataRows.push({ key: '', value: '' })">+ 添加字段</el-button>
              </div>
            </el-form-item>
            <el-form-item label="详情跳转链接">
              <el-input v-model="tmplForm.url" placeholder="选填，模板消息详情跳转 URL" style="width: 360px" />
            </el-form-item>
            <el-form-item label="发送对象">
              <el-radio-group v-model="tmplForm.to_type">
                <el-radio :label="3">全部粉丝（{{ fanscount }} 人）</el-radio>
                <el-radio :label="2">按标签</el-radio>
                <el-radio :label="1">指定 openid</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item v-if="tmplForm.to_type === 2" label="选择标签">
              <el-select v-model="tmplForm.tagid" placeholder="请选择标签" style="width: 260px">
                <el-option v-for="t in tags" :key="t.tagid" :label="`${t.name}（${t.count || 0} 人）`" :value="t.tagid" />
              </el-select>
            </el-form-item>
            <el-form-item v-if="tmplForm.to_type === 1" label="openid 列表">
              <el-input
                v-model="tmplForm.openids"
                type="textarea"
                :rows="4"
                placeholder="每行一个 openid，或用逗号/空格分隔"
                style="width: 480px"
              />
            </el-form-item>
            <el-form-item label="每批发送数">
              <el-input-number v-model="tmplForm.pagelimit" :min="1" :max="500" :step="50" />
              <span class="form-tip">分页循环发送，失败自动记录并继续</span>
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="tmplSending" :disabled="!templates.length" @click="startTmplSend">开始群发</el-button>
            </el-form-item>
          </el-form>
          <el-alert v-if="tmplProgress" :title="tmplProgress" :type="tmplDone ? 'success' : 'info'" :closable="false" show-icon />
        </el-tab-pane>

        <!-- ==================== 图文消息群发（客服消息，素材库数据源） ==================== -->
        <el-tab-pane label="图文消息群发" name="kfmsg">
          <el-form label-width="110px" class="send-form">
            <el-form-item label="任务标题">
              <el-input v-model="kfForm.title" placeholder="便于识别的发送标题" style="width: 360px" />
            </el-form-item>
            <el-form-item label="选择素材">
              <el-select v-model="kfForm.media_id" placeholder="请选择图文素材（素材库）" style="width: 360px">
                <el-option v-for="s in sources" :key="s.media_id" :label="sourceLabel(s)" :value="s.media_id" />
              </el-select>
              <span v-if="!sources.length" class="form-tip">暂无素材，请先在「素材库」标签页新建图文</span>
            </el-form-item>
            <el-form-item label="发送对象">
              <el-radio-group v-model="kfForm.to_type">
                <el-radio :label="3">全部粉丝（{{ fanscount }} 人）</el-radio>
                <el-radio :label="2">按标签</el-radio>
                <el-radio :label="1">指定 openid</el-radio>
              </el-radio-group>
              <span class="form-tip">客服消息仅可发送给 48 小时内有过互动的粉丝</span>
            </el-form-item>
            <el-form-item v-if="kfForm.to_type === 2" label="选择标签">
              <el-select v-model="kfForm.tagid" placeholder="请选择标签" style="width: 260px">
                <el-option v-for="t in tags" :key="t.tagid" :label="`${t.name}（${t.count || 0} 人）`" :value="t.tagid" />
              </el-select>
            </el-form-item>
            <el-form-item v-if="kfForm.to_type === 1" label="openid 列表">
              <el-input
                v-model="kfForm.openids"
                type="textarea"
                :rows="4"
                placeholder="每行一个 openid，或用逗号/空格分隔"
                style="width: 480px"
              />
            </el-form-item>
            <el-form-item label="每批发送数">
              <el-input-number v-model="kfForm.pagelimit" :min="1" :max="500" :step="50" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="kfSending" :disabled="!sources.length" @click="startKfSend">开始群发</el-button>
            </el-form-item>
          </el-form>
          <el-alert v-if="kfProgress" :title="kfProgress" :type="kfDone ? 'success' : 'info'" :closable="false" show-icon />
        </el-tab-pane>

        <!-- ==================== 发送历史 ==================== -->
        <el-tab-pane label="发送历史" name="history">
          <h4 class="section-title">模板消息发送记录</h4>
          <el-table :data="tmplLogs" border size="small" v-loading="logLoading">
            <el-table-column label="标题" prop="title" min-width="140" class-name="compact-col">
              <template #default="{ row }">{{ row.title || '-' }}</template>
            </el-table-column>
            <el-table-column label="发送对象" min-width="130" class-name="compact-col">
              <template #default="{ row }">{{ toTypeText(row) }}</template>
            </el-table-column>
            <el-table-column label="发送/成功/失败" width="130" align="center" class-name="compact-col">
              <template #default="{ row }">{{ row.sendcount }} / {{ row.successcount }} / <span class="err-num">{{ row.errorcount }}</span></template>
            </el-table-column>
            <el-table-column label="进度" width="120" align="center" class-name="compact-col">
              <template #default="{ row }">第 {{ row.last_page }} 页（每页 {{ row.pagelimit }}）</template>
            </el-table-column>
            <el-table-column label="发送时间" width="160" align="center" class-name="compact-col">
              <template #default="{ row }">{{ formatTime(row.createtime) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="200" align="center" fixed="right" class-name="compact-col">
              <template #default="{ row }">
                <div class="op-wrap">
                  <el-button link type="primary" size="small" :disabled="row.errorcount <= 0" @click="openErrLog('tmpl', row.id)">失败明细</el-button>
                  <el-button link type="warning" size="small" :loading="resumingId === row.id" @click="resumeTmpl(row)">续发</el-button>
                  <el-button link type="danger" size="small" @click="handleDeleteTmplLog(row)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>

          <h4 class="section-title">图文消息发送记录</h4>
          <el-table :data="kfLogs" border size="small" v-loading="logLoading">
            <el-table-column label="标题" prop="title" min-width="140" class-name="compact-col">
              <template #default="{ row }">{{ (row.title as string) || '-' }}</template>
            </el-table-column>
            <el-table-column label="素材ID" prop="media_id" min-width="170" class-name="compact-col" />
            <el-table-column label="发送对象" min-width="130" class-name="compact-col">
              <template #default="{ row }">{{ toTypeText(row) }}</template>
            </el-table-column>
            <el-table-column label="发送/成功/失败" width="130" align="center" class-name="compact-col">
              <template #default="{ row }">{{ row.sendcount }} / {{ row.successcount }} / <span class="err-num">{{ row.errorcount }}</span></template>
            </el-table-column>
            <el-table-column label="发送时间" width="160" align="center" class-name="compact-col">
              <template #default="{ row }">{{ formatTime(row.createtime as number) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="200" align="center" fixed="right" class-name="compact-col">
              <template #default="{ row }">
                <div class="op-wrap">
                  <el-button link type="primary" size="small" :disabled="(row.errorcount as number) <= 0" @click="openErrLog('kf', row.id as number)">失败明细</el-button>
                  <el-button link type="warning" size="small" :loading="resumingId === row.id" @click="resumeKf(row)">续发</el-button>
                  <el-button link type="danger" size="small" @click="handleDeleteKfLog(row)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- ==================== 素材库 ==================== -->
        <el-tab-pane label="素材库" name="source">
          <div class="source-toolbar">
            <el-button type="primary" size="small" @click="openSourceEdit('')">新建图文素材</el-button>
          </div>
          <el-table :data="sources" border size="small" v-loading="sourceLoading">
            <el-table-column label="首篇标题" min-width="220" class-name="compact-col">
              <template #default="{ row }">{{ sourceLabel(row) }}</template>
            </el-table-column>
            <el-table-column label="素材ID" prop="media_id" min-width="190" class-name="compact-col" />
            <el-table-column label="文章数" width="80" align="center" class-name="compact-col">
              <template #default="{ row }">{{ (row.content?.news_item || []).length }}</template>
            </el-table-column>
            <el-table-column label="更新时间" width="160" align="center" class-name="compact-col">
              <template #default="{ row }">{{ formatTime(row.update_time) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="150" align="center" fixed="right" class-name="compact-col">
              <template #default="{ row }">
                <div class="op-wrap">
                  <el-button link type="primary" size="small" @click="openSourceEdit(row.media_id)">编辑</el-button>
                  <el-button link type="danger" size="small" @click="handleSourceDelete(row)">删除</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 失败明细弹窗 -->
    <el-dialog v-model="errDialogVisible" title="发送失败明细" width="640px" destroy-on-close>
      <el-table :data="errLogs" border size="small">
        <el-table-column label="openid" prop="openid" min-width="200" />
        <el-table-column label="错误码" prop="errcode" width="90" align="center" />
        <el-table-column label="错误信息" prop="errmsg" min-width="180" />
      </el-table>
    </el-dialog>

    <!-- 素材编辑弹窗 -->
    <el-dialog v-model="sourceDialogVisible" :title="sourceForm.media_id ? '编辑图文素材' : '新建图文素材'" width="720px" destroy-on-close>
      <div v-for="(art, i) in sourceForm.news_item" :key="i" class="article-block">
        <div class="article-head">
          <span>文章 {{ i + 1 }}</span>
          <el-button v-if="sourceForm.news_item.length > 1" link type="danger" size="small" @click="sourceForm.news_item.splice(i, 1)">删除本篇</el-button>
        </div>
        <el-form label-width="80px">
          <el-form-item label="标题">
            <el-input v-model="art.title" placeholder="文章标题" />
          </el-form-item>
          <el-form-item label="摘要">
            <el-input v-model="art.digest" placeholder="选填，不填自动抓取正文前54个字" />
          </el-form-item>
          <el-form-item label="封面图">
            <el-input v-model="art.thumb_url" placeholder="图片地址（站内路径或 URL）" />
          </el-form-item>
          <el-form-item label="正文">
            <el-input v-model="art.content" type="textarea" :rows="5" placeholder="正文 HTML 或纯文本" />
          </el-form-item>
          <el-form-item label="跳转链接">
            <el-input v-model="art.content_source_url" placeholder="选填，阅读原文链接" />
          </el-form-item>
        </el-form>
      </div>
      <el-button size="small" @click="sourceForm.news_item.push(emptyArticle())">+ 添加文章</el-button>
      <template #footer>
        <el-button @click="sourceDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="sourceSaving" @click="handleSourceSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref, onMounted } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  getTmplsendPrepare,
  sendTmplMsg,
  getTmplSendLog,
  deleteTmplSendLog,
  getTmplSendErrLog,
  sendKfMsg,
  getKfMsgSendLog,
  deleteKfMsgSendLog,
  getKfMsgSendErrLog,
  getSourceList,
  getSourceDetail,
  saveSource,
  deleteSource,
  type TmplOption,
  type FansTagItem,
  type TmplSendLogItem,
  type SendErrLogItem,
  type SourceItem,
  type SendResult,
} from '../../api/mpfans'
import { formatTime } from '../../utils/time'

const activeTab = ref('tmpl')

const templates = ref<TmplOption[]>([])
const tags = ref<FansTagItem[]>([])
const fanscount = ref(0)
const sources = ref<SourceItem[]>([])

// ---------- 模板消息群发 ----------
const tmplForm = reactive({
  title: '',
  template_id: '',
  url: '',
  to_type: 3,
  tagid: undefined as number | undefined,
  openids: '',
  pagelimit: 100,
})
const dataRows = ref<Array<{ key: string; value: string }>>([
  { key: 'first', value: '' },
  { key: 'remark', value: '' },
])
const tmplSending = ref(false)
const tmplDone = ref(false)
const tmplProgress = ref('')

async function startTmplSend() {
  if (!tmplForm.template_id) {
    ElMessage.warning('请选择模板')
    return
  }
  if (tmplForm.to_type === 2 && !tmplForm.tagid) {
    ElMessage.warning('请选择标签')
    return
  }
  if (tmplForm.to_type === 1 && !tmplForm.openids.trim()) {
    ElMessage.warning('请填写 openid')
    return
  }
  const data: Record<string, string> = {}
  dataRows.value.forEach((r) => {
    if (r.key.trim()) data[r.key.trim()] = r.value
  })

  tmplSending.value = true
  tmplDone.value = false
  tmplProgress.value = '正在发送第 1 批…'
  try {
    let r: SendResult = await sendTmplMsg({
      info: {
        title: tmplForm.title,
        template_id: tmplForm.template_id,
        url: tmplForm.url,
        to_type: tmplForm.to_type,
        tagid: tmplForm.tagid,
        openids: tmplForm.openids,
      },
      data,
      pagenum: 1,
      pagelimit: tmplForm.pagelimit,
    })
    while (r.status === 2) {
      tmplProgress.value = `已发送 ${r.sendcount} 条（成功 ${r.successcount} / 失败 ${r.errorcount}），正在发送第 ${r.pagenum + 1} 批…`
      r = await sendTmplMsg({ logid: r.logid })
    }
    tmplProgress.value = `群发完成：共发送 ${r.sendcount} 条，成功 ${r.successcount} 条，失败 ${r.errorcount} 条（可在「发送历史」查看明细）`
    tmplDone.value = true
    ElMessage.success('模板消息群发完成')
    fetchLogs()
  } catch (e) {
    tmplDone.value = true
    tmplProgress.value = '群发中断，可在「发送历史」中点击续发继续'
  } finally {
    tmplSending.value = false
  }
}

// ---------- 图文消息群发 ----------
const kfForm = reactive({
  title: '',
  media_id: '',
  to_type: 3,
  tagid: undefined as number | undefined,
  openids: '',
  pagelimit: 100,
})
const kfSending = ref(false)
const kfDone = ref(false)
const kfProgress = ref('')

async function startKfSend() {
  if (!kfForm.media_id) {
    ElMessage.warning('请选择素材')
    return
  }
  if (kfForm.to_type === 2 && !kfForm.tagid) {
    ElMessage.warning('请选择标签')
    return
  }
  if (kfForm.to_type === 1 && !kfForm.openids.trim()) {
    ElMessage.warning('请填写 openid')
    return
  }

  kfSending.value = true
  kfDone.value = false
  kfProgress.value = '正在发送第 1 批…'
  try {
    let r: SendResult = await sendKfMsg({
      info: {
        title: kfForm.title,
        media_id: kfForm.media_id,
        to_type: kfForm.to_type,
        tagid: kfForm.tagid,
        openids: kfForm.openids,
      },
      pagenum: 1,
      pagelimit: kfForm.pagelimit,
    })
    while (r.status === 2) {
      kfProgress.value = `已发送 ${r.sendcount} 条（成功 ${r.successcount} / 失败 ${r.errorcount}），正在发送第 ${r.pagenum + 1} 批…`
      r = await sendKfMsg({ logid: r.logid })
    }
    kfProgress.value = `群发完成：共发送 ${r.sendcount} 条，成功 ${r.successcount} 条，失败 ${r.errorcount} 条`
    kfDone.value = true
    ElMessage.success('图文消息群发完成')
    fetchLogs()
  } catch (e) {
    kfDone.value = true
    kfProgress.value = '群发中断，可在「发送历史」中点击续发继续'
  } finally {
    kfSending.value = false
  }
}

// ---------- 发送历史 ----------
const logLoading = ref(false)
const tmplLogs = ref<TmplSendLogItem[]>([])
const kfLogs = ref<Array<Record<string, unknown>>>([])
const resumingId = ref(0)

function toTypeText(row: Record<string, unknown>): string {
  const toType = Number(row.to_type)
  if (toType === 1) return '指定 openid'
  if (toType === 2) return `标签：${row.tagname || row.tagid}`
  return '全部粉丝'
}

async function fetchLogs() {
  logLoading.value = true
  try {
    const [t, k] = await Promise.all([
      getTmplSendLog({ page: 1, pagesize: 50 }),
      getKfMsgSendLog({ page: 1, pagesize: 50 }),
    ])
    tmplLogs.value = t.list || []
    kfLogs.value = k.list || []
  } finally {
    logLoading.value = false
  }
}

async function resumeTmpl(row: TmplSendLogItem) {
  resumingId.value = row.id
  try {
    let r: SendResult = await sendTmplMsg({ logid: row.id })
    while (r.status === 2) {
      r = await sendTmplMsg({ logid: r.logid })
    }
    ElMessage.success(`续发完成：共发送 ${r.sendcount} 条，成功 ${r.successcount} 条，失败 ${r.errorcount} 条`)
    fetchLogs()
  } finally {
    resumingId.value = 0
  }
}

async function resumeKf(row: Record<string, unknown>) {
  const logid = row.id as number
  resumingId.value = logid
  try {
    let r: SendResult = await sendKfMsg({ logid })
    while (r.status === 2) {
      r = await sendKfMsg({ logid: r.logid })
    }
    ElMessage.success(`续发完成：共发送 ${r.sendcount} 条，成功 ${r.successcount} 条，失败 ${r.errorcount} 条`)
    fetchLogs()
  } finally {
    resumingId.value = 0
  }
}

async function handleDeleteTmplLog(row: TmplSendLogItem) {
  await ElMessageBox.confirm('确定删除该发送记录？', '提示', { type: 'warning' })
  await deleteTmplSendLog([row.id])
  ElMessage.success('删除成功')
  fetchLogs()
}

async function handleDeleteKfLog(row: Record<string, unknown>) {
  await ElMessageBox.confirm('确定删除该发送记录？', '提示', { type: 'warning' })
  await deleteKfMsgSendLog([row.id as number])
  ElMessage.success('删除成功')
  fetchLogs()
}

// ---------- 失败明细 ----------
const errDialogVisible = ref(false)
const errLogs = ref<SendErrLogItem[]>([])

async function openErrLog(type: 'tmpl' | 'kf', logid: number) {
  const res = type === 'tmpl' ? await getTmplSendErrLog({ logid, page: 1, pagesize: 100 }) : await getKfMsgSendErrLog({ logid, page: 1, pagesize: 100 })
  errLogs.value = res.list || []
  errDialogVisible.value = true
}

// ---------- 素材库 ----------
const sourceLoading = ref(false)
const sourceDialogVisible = ref(false)
const sourceSaving = ref(false)
const sourceForm = reactive({
  media_id: '',
  news_item: [] as Array<Record<string, string>>,
  old_news_item: [] as Array<Record<string, string>>,
})

function emptyArticle(): Record<string, string> {
  return { title: '', digest: '', thumb_url: '', content: '', content_source_url: '', need_open_comment: '0', only_fans_can_comment: '0' }
}

function sourceLabel(s: SourceItem): string {
  const first = s.content?.news_item?.[0] as Record<string, unknown> | undefined
  return (first?.title as string) || s.media_id
}

async function fetchSources() {
  sourceLoading.value = true
  try {
    const res = await getSourceList({ page: 1, pagesize: 20 })
    sources.value = res.list || []
  } finally {
    sourceLoading.value = false
  }
}

async function openSourceEdit(mediaId: string) {
  if (mediaId) {
    const detail = await getSourceDetail(mediaId)
    sourceForm.media_id = mediaId
    sourceForm.news_item = (detail.news_item || []).map((it) => ({ ...emptyArticle(), ...it, thumb_url: (it.thumb_url as string) || '' }))
    sourceForm.old_news_item = JSON.parse(JSON.stringify(detail.news_item || []))
  } else {
    sourceForm.media_id = ''
    sourceForm.news_item = [emptyArticle()]
    sourceForm.old_news_item = []
  }
  sourceDialogVisible.value = true
}

async function handleSourceSave() {
  if (!sourceForm.news_item.length || !sourceForm.news_item[0].title?.trim()) {
    ElMessage.warning('请至少填写一篇文章的标题')
    return
  }
  sourceSaving.value = true
  try {
    await saveSource({
      media_id: sourceForm.media_id || undefined,
      news_item: sourceForm.news_item,
      old_news_item: sourceForm.old_news_item,
    })
    ElMessage.success('保存成功')
    sourceDialogVisible.value = false
    fetchSources()
  } finally {
    sourceSaving.value = false
  }
}

async function handleSourceDelete(row: SourceItem) {
  await ElMessageBox.confirm('确定删除该图文素材？', '提示', { type: 'warning' })
  await deleteSource(row.media_id)
  ElMessage.success('删除成功')
  fetchSources()
}

// ---------- 初始化 ----------
async function fetchPrepare() {
  const res = await getTmplsendPrepare()
  templates.value = res.templates || []
  tags.value = res.tags || []
  fanscount.value = res.fanscount || 0
}

onMounted(() => {
  fetchPrepare()
  fetchSources()
  fetchLogs()
})
</script>

<style scoped>
.send-form {
  max-width: 900px;
  padding: 8px 0;
}
.form-tip {
  margin-left: 10px;
  font-size: 12px;
  color: #909399;
}
.data-rows {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.data-row {
  display: flex;
  align-items: center;
  gap: 8px;
}
.section-title {
  margin: 16px 0 10px;
  font-size: 14px;
}
.err-num {
  color: #f56c6c;
}
.op-wrap {
  display: flex;
  justify-content: center;
  flex-wrap: nowrap;
}
.source-toolbar {
  margin-bottom: 12px;
}
.article-block {
  border: 1px solid #ebeef5;
  border-radius: 4px;
  padding: 12px 12px 0;
  margin-bottom: 12px;
}
.article-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  font-weight: 500;
}
</style>
