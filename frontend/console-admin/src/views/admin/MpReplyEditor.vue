<script setup lang="ts">
// 公众号回复内容编辑器（批次7-2 任务210）
// 关键字回复与被关注回复共用：msgtype 七种（text/image/voice/video/music/news/miniprogrampage）
// 素材类文件经 /admin/mp/media/upload 上传，保存时后端自动换取 MediaId
import { reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import type { UploadRequestOptions } from 'element-plus'
import { getMpNewsList, uploadMpMedia, MSGTYPE_OPTIONS } from '../../api/mp'
import type { MpKeywordItem, MpNewsItem } from '../../api/mp'
import ImgIconPicker from '../../components/ImgIconPicker.vue'

const form = reactive({
  msgtype: 'text',
  // 文本
  text: '',
  // image/voice/video 素材地址
  url: '',
  // video/music 标题与描述
  title: '',
  description: '',
  // music
  musicurl: '',
  hqmusicurl: '',
  thumburl: '',
  // news（图文素材 media_id + 摘要信息）
  media_id: '',
  news_title: '',
  news_url: '',
  news_digest: '',
  // miniprogrampage
  appid: '',
  pagepath: '',
  pic: '',
})

function reset() {
  Object.assign(form, {
    msgtype: 'text', text: '', url: '', title: '', description: '',
    musicurl: '', hqmusicurl: '', thumburl: '',
    media_id: '', news_title: '', news_url: '', news_digest: '',
    appid: '', pagepath: '', pic: '',
  })
}

/** 解析 content（text 直存，其余 JSON）并回填表单 */
function setFromRecord(record: MpKeywordItem) {
  reset()
  form.msgtype = record.msgtype || 'text'
  const raw = record.content || ''
  if (form.msgtype === 'text') {
    form.text = raw
    return
  }
  let data: Record<string, string> = {}
  try {
    data = JSON.parse(raw) || {}
  } catch {
    data = {}
  }
  form.url = data.url || ''
  form.title = data.title || ''
  form.description = data.description || ''
  form.musicurl = data.musicurl || ''
  form.hqmusicurl = data.hqmusicurl || ''
  form.thumburl = data.thumburl || ''
  form.media_id = data.media_id || ''
  form.news_title = data.title || ''
  form.news_url = data.url || ''
  form.news_digest = data.digest || ''
  form.appid = data.appid || ''
  form.pagepath = data.pagepath || ''
  form.pic = data.pic || ''
}

/** 组装保存载荷（字段名与后端 buildReplyContent 对齐） */
function toPayload(): Record<string, unknown> {
  const base: Record<string, unknown> = { msgtype: form.msgtype }
  switch (form.msgtype) {
    case 'text':
      base.text = form.text
      break
    case 'image':
    case 'voice':
      base.url = form.url
      break
    case 'video':
      base.url = form.url
      base.title = form.title
      base.description = form.description
      break
    case 'music':
      base.title = form.title
      base.description = form.description
      base.musicurl = form.musicurl
      base.hqmusicurl = form.hqmusicurl
      base.thumburl = form.thumburl
      break
    case 'news':
      base.media_id = form.media_id
      base.title = form.news_title
      base.url = form.news_url
      base.digest = form.news_digest
      break
    case 'miniprogrampage':
      base.appid = form.appid
      base.pagepath = form.pagepath
      base.pic = form.pic
      break
  }
  return base
}

/** 简要内容（列表展示用） */
function summary(): string {
  switch (form.msgtype) {
    case 'text':
      return form.text
    case 'image':
    case 'voice':
      return form.url
    case 'video':
      return form.title || form.url
    case 'music':
      return form.title
    case 'news':
      return form.news_title || form.media_id
    case 'miniprogrampage':
      return form.pagepath
    default:
      return ''
  }
}

// ---------- 素材上传（语音/视频保留本地上传） ----------
async function uploadAsset(options: UploadRequestOptions, type: 'image' | 'voice' | 'video', target: 'url' | 'thumburl' | 'pic') {
  try {
    const res = await uploadMpMedia(options.file, type)
    form[target] = res.url
    ElMessage.success('素材上传成功')
  } catch {
    ElMessage.error('素材上传失败')
  }
}

// ---------- 选图（ImgIconPicker 统一选图） ----------
const imgPickerVisible = ref(false)
let imgPickerSetter: ((url: string) => void) | null = null
function openImgPicker(setter: (url: string) => void) {
  imgPickerSetter = setter
  imgPickerVisible.value = true
}
function onImgPick(urls: string[]) {
  if (urls[0]) imgPickerSetter?.(urls[0])
  imgPickerSetter = null
}

// ---------- 图文素材选择 ----------
const newsVisible = ref(false)
const newsLoading = ref(false)
const newsTotal = ref(0)
const newsItems = ref<MpNewsItem[]>([])

async function openNewsPicker() {
  newsVisible.value = true
  newsLoading.value = true
  try {
    const rs = await getMpNewsList(1, 20)
    newsTotal.value = rs.count
    newsItems.value = rs.data || []
  } catch {
    // 错误提示已由 axios 拦截器统一弹出
  } finally {
    newsLoading.value = false
  }
}

function pickNews(item: MpNewsItem) {
  const article = item.content?.news_item?.[0]
  form.media_id = item.media_id
  form.news_title = article?.title || ''
  form.news_url = article?.url || ''
  form.news_digest = article?.digest || ''
  newsVisible.value = false
}

defineExpose({ form, reset, setFromRecord, toPayload, summary })
</script>

<template>
  <div class="mp-reply-editor">
    <el-form-item label="回复类型">
      <el-radio-group v-model="form.msgtype">
        <el-radio v-for="opt in MSGTYPE_OPTIONS" :key="opt.value" :value="opt.value">{{ opt.label }}</el-radio>
      </el-radio-group>
    </el-form-item>

    <!-- 文本 -->
    <el-form-item v-if="form.msgtype === 'text'" label="回复内容" required>
      <el-input v-model="form.text" type="textarea" :rows="4" maxlength="600" show-word-limit placeholder="请输入回复文本" />
    </el-form-item>

    <!-- 图片 -->
    <el-form-item v-if="form.msgtype === 'image'" label="图片素材" required>
      <div class="media-row">
        <el-image v-if="form.url" :src="form.url" style="width: 100px; height: 100px" fit="cover" />
        <el-button size="small" @click="openImgPicker((u) => (form.url = u))">{{ form.url ? '更换图片' : '选择图片' }}</el-button>
        <el-button v-if="form.url" link type="danger" @click="form.url = ''">移除</el-button>
      </div>
      <div class="form-tip">保存时自动换取微信 media_id</div>
    </el-form-item>

    <!-- 语音 -->
    <el-form-item v-if="form.msgtype === 'voice'" label="语音素材" required>
      <div class="media-row">
        <el-input v-model="form.url" placeholder="语音文件地址" style="width: 320px" readonly />
        <el-upload :show-file-list="false" accept=".mp3,.amr,.wav" :http-request="(opt: UploadRequestOptions) => uploadAsset(opt, 'voice', 'url')">
          <el-button size="small">{{ form.url ? '更换语音' : '上传语音' }}</el-button>
        </el-upload>
      </div>
      <div class="form-tip">支持 mp3/amr/wav，不超过 2MB</div>
    </el-form-item>

    <!-- 视频 -->
    <template v-if="form.msgtype === 'video'">
      <el-form-item label="视频素材" required>
        <div class="media-row">
          <el-input v-model="form.url" placeholder="视频文件地址" style="width: 320px" readonly />
          <el-upload :show-file-list="false" accept=".mp4" :http-request="(opt: UploadRequestOptions) => uploadAsset(opt, 'video', 'url')">
            <el-button size="small">{{ form.url ? '更换视频' : '上传视频' }}</el-button>
          </el-upload>
        </div>
        <div class="form-tip">支持 mp4，不超过 10MB</div>
      </el-form-item>
      <el-form-item label="视频标题" required>
        <el-input v-model="form.title" maxlength="100" placeholder="请输入视频标题" />
      </el-form-item>
      <el-form-item label="视频描述" required>
        <el-input v-model="form.description" type="textarea" :rows="2" maxlength="200" placeholder="请输入视频描述" />
      </el-form-item>
    </template>

    <!-- 音乐 -->
    <template v-if="form.msgtype === 'music'">
      <el-form-item label="音乐标题" required>
        <el-input v-model="form.title" maxlength="100" placeholder="请输入音乐标题" />
      </el-form-item>
      <el-form-item label="音乐描述">
        <el-input v-model="form.description" type="textarea" :rows="2" maxlength="200" placeholder="请输入音乐描述" />
      </el-form-item>
      <el-form-item label="音乐链接" required>
        <el-input v-model="form.musicurl" placeholder="http(s):// 音乐文件地址" />
      </el-form-item>
      <el-form-item label="高品质链接">
        <el-input v-model="form.hqmusicurl" placeholder="高品质音乐链接（可选）" />
      </el-form-item>
      <el-form-item label="封面图">
        <div class="media-row">
          <el-image v-if="form.thumburl" :src="form.thumburl" style="width: 80px; height: 80px" fit="cover" />
          <el-button size="small" @click="openImgPicker((u) => (form.thumburl = u))">{{ form.thumburl ? '更换封面' : '选择图片' }}</el-button>
        </div>
      </el-form-item>
    </template>

    <!-- 图文 -->
    <el-form-item v-if="form.msgtype === 'news'" label="图文素材" required>
      <div class="media-row">
        <el-tag v-if="form.media_id" type="success">{{ form.news_title || form.media_id }}</el-tag>
        <el-button size="small" @click="openNewsPicker">{{ form.media_id ? '重新选择' : '选择图文' }}</el-button>
      </div>
      <div class="form-tip">从公众号图文素材库选择（media_id 直接回复图文消息）</div>
    </el-form-item>

    <!-- 小程序页面 -->
    <template v-if="form.msgtype === 'miniprogrampage'">
      <el-form-item label="小程序appid" required>
        <el-input v-model="form.appid" placeholder="wx 开头的小程序 appid" />
      </el-form-item>
      <el-form-item label="页面路径" required>
        <el-input v-model="form.pagepath" placeholder="pages/index/index" />
      </el-form-item>
      <el-form-item label="封面图" required>
        <div class="media-row">
          <el-image v-if="form.pic" :src="form.pic" style="width: 100px; height: 80px" fit="cover" />
          <el-button size="small" @click="openImgPicker((u) => (form.pic = u))">{{ form.pic ? '更换封面' : '选择图片' }}</el-button>
        </div>
      </el-form-item>
    </template>

    <!-- 图文素材选择弹窗 -->
    <el-dialog v-model="newsVisible" title="选择图文素材" width="640px" append-to-body>
      <div v-loading="newsLoading">
        <el-table v-if="newsItems.length" :data="newsItems" border style="width: 100%">
          <el-table-column label="标题" min-width="220">
            <template #default="{ row }">
              <div class="news-cell">
                <el-image
                  v-if="row.content?.news_item?.[0]?.thumb_url"
                  :src="row.content.news_item[0].thumb_url"
                  style="width: 56px; height: 40px; flex-shrink: 0"
                  fit="cover"
                />
                <span>{{ row.content?.news_item?.[0]?.title || row.media_id }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="media_id" prop="media_id" width="200" show-overflow-tooltip />
          <el-table-column label="操作" width="80" align="center">
            <template #default="{ row }">
              <el-button link type="primary" @click="pickNews(row)">选择</el-button>
            </template>
          </el-table-column>
        </el-table>
        <el-empty v-else-if="!newsLoading" description="暂无图文素材" />
      </div>
      <div class="news-total">共 {{ newsTotal }} 条图文素材</div>
    </el-dialog>

    <ImgIconPicker v-model:visible="imgPickerVisible" default-tab="library" @pick="onImgPick" />
  </div>
</template>

<style scoped>
.media-row {
  display: flex;
  align-items: center;
  gap: 12px;
}
.form-tip {
  color: #999;
  font-size: 12px;
  line-height: 1.6;
}
.news-cell {
  display: flex;
  align-items: center;
  gap: 8px;
}
.news-total {
  margin-top: 8px;
  color: #999;
  font-size: 12px;
  text-align: right;
}
</style>
