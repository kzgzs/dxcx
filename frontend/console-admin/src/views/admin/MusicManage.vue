<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { consoleMusicApi, type MusicRow } from '../../api/music'

// 任务315：本页挂超管控制台壳，接口集走 /console/music/*（平台级 aid=0）
const { getMusicList, addMusic, delMusic, uploadAudio, cleanMusic } = consoleMusicApi

// ============ 列表（分页 + 搜索） ============
const loading = ref(false)
const list = ref<MusicRow[]>([])
const total = ref(0)
const page = ref(1)
const size = ref(10)
const keyword = ref('')

async function loadData() {
  loading.value = true
  try {
    const result = await getMusicList({ keyword: keyword.value, page: page.value, size: size.value })
    list.value = result.list || []
    total.value = result.total || 0
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  page.value = 1
  loadData()
}

// ============ 添加音乐（双方式：上传音频 / 填写链接；成功后自动入库刷新） ============
const adding = ref(false)
const addMode = ref<'upload' | 'link'>('upload')
const addName = ref('')
const linkUrl = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
const pickedFile = ref<File | null>(null)
const AUDIO_ACCEPT = '.mp3,.wav,.m4a,.aac'
const AUDIO_MAX = 20 * 1024 * 1024

function pickFile() {
  fileInput.value?.click()
}

function onFileChange() {
  const f = fileInput.value?.files?.[0] || null
  if (!f) return
  const ext = (f.name.split('.').pop() || '').toLowerCase()
  if (!['mp3', 'wav', 'm4a', 'aac'].includes(ext)) {
    ElMessage.warning('仅支持 mp3/wav/m4a/aac 格式的音频')
    return
  }
  if (f.size > AUDIO_MAX) {
    ElMessage.warning('音频大小不能超过20MB')
    return
  }
  pickedFile.value = f
  if (!addName.value.trim()) {
    addName.value = f.name.replace(/\.[^.]+$/, '')
  }
}

async function handleAdd() {
  // 链接方式：校验 http(s) 开头，建议 .mp3 等音频后缀（不强制）
  if (addMode.value === 'link') {
    if (!addName.value.trim()) {
      ElMessage.warning('请填写音乐名称')
      return
    }
    const url = linkUrl.value.trim()
    if (!/^https?:\/\//i.test(url)) {
      ElMessage.warning('音频链接必须以 http:// 或 https:// 开头')
      return
    }
    if (!/\.(mp3|wav|m4a|aac)(\?.*)?$/i.test(url)) {
      ElMessage.warning('链接不以常见音频后缀（.mp3/.wav/.m4a/.aac）结尾，请确认地址可直接播放音频')
    }
    adding.value = true
    try {
      await addMusic({ name: addName.value.trim(), url })
      ElMessage.success('添加成功')
      addName.value = ''
      linkUrl.value = ''
      page.value = 1
      loadData()
    } catch {
      // 错误提示由 axios 拦截器统一处理
    } finally {
      adding.value = false
    }
    return
  }
  // 上传方式：既有通用上传链路
  if (!pickedFile.value) {
    ElMessage.warning('请先选择音频文件')
    return
  }
  adding.value = true
  try {
    const up = await uploadAudio(pickedFile.value)
    await addMusic({
      name: addName.value.trim() || pickedFile.value.name,
      url: up.url,
      size: up.size || '',
    })
    ElMessage.success('添加成功')
    addName.value = ''
    pickedFile.value = null
    if (fileInput.value) fileInput.value.value = ''
    page.value = 1
    loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    adding.value = false
  }
}

// 来源标记：http(s) 外链记为「链接」，其余为本地「上传」
function sourceOf(row: MusicRow): string {
  return /^https?:\/\//i.test(row.url) ? '链接' : '上传'
}

// ============ 试听（单实例 audio，切换播放） ============
const player = ref<HTMLAudioElement | null>(null)
const playingId = ref(0)

function resolveSrc(url: string): string {
  if (/^https?:\/\//i.test(url)) return url
  return import.meta.env.BASE_URL.replace(/\/$/, '') + url
}

function togglePlay(row: MusicRow) {
  const el = player.value
  if (!el) return
  if (playingId.value === row.id && !el.paused) {
    el.pause()
    playingId.value = 0
    return
  }
  el.src = resolveSrc(row.url)
  el.play().catch(() => ElMessage.warning('音频播放失败'))
  playingId.value = row.id
}

function onEnded() {
  playingId.value = 0
}

// ============ 删除 / 清理音频（任务315：本地文件一并删，外链只删记录） ============
async function handleDel(row: MusicRow) {
  try {
    await ElMessageBox.confirm(`确定删除音乐「${row.name}」吗？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await delMusic(row.id)
    ElMessage.success('删除成功')
    if (playingId.value === row.id) {
      player.value?.pause()
      playingId.value = 0
    }
    loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

const cleaning = ref(false)
async function handleClean() {
  try {
    await ElMessageBox.confirm('将删除全部音乐，本地音频文件存在则一并删除，确定清理吗？', '清理音频', { type: 'warning', confirmButtonText: '确定清理' })
  } catch {
    return
  }
  cleaning.value = true
  try {
    const res = await cleanMusic()
    ElMessage.success(`已清理 ${res.count || 0} 首音乐`)
    player.value?.pause()
    playingId.value = 0
    page.value = 1
    loadData()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    cleaning.value = false
  }
}

// ============ 展示格式化 ============
function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

onMounted(() => {
  loadData()
})
</script>

<template>
  <div v-loading="loading">
    <!-- 添加音乐区 -->
    <el-card shadow="never" class="music-card">
      <template #header>
        <span class="card-title">添加音乐</span>
      </template>
      <el-radio-group v-model="addMode" class="add-mode">
        <el-radio-button value="upload">上传音频</el-radio-button>
        <el-radio-button value="link">填写链接</el-radio-button>
      </el-radio-group>
      <div v-if="addMode === 'upload'" class="add-row">
        <el-input
          v-model="addName"
          placeholder="音乐名称"
          clearable
          class="add-name"
          @keyup.enter="handleAdd"
        />
        <el-button @click="pickFile">选择音频文件</el-button>
        <span class="picked-name">{{ pickedFile ? pickedFile.name : '支持 mp3/wav/m4a/aac，≤20MB' }}</span>
        <el-button type="primary" :loading="adding" @click="handleAdd">上传并添加</el-button>
        <input
          ref="fileInput"
          type="file"
          :accept="AUDIO_ACCEPT"
          style="display: none"
          @change="onFileChange"
        />
      </div>
      <div v-else class="add-row">
        <el-input
          v-model="addName"
          placeholder="音乐名称"
          clearable
          class="add-name"
          @keyup.enter="handleAdd"
        />
        <el-input
          v-model="linkUrl"
          placeholder="音频链接，http(s) 开头，建议 .mp3 等后缀（不强制）"
          clearable
          class="add-url"
          @keyup.enter="handleAdd"
        />
        <el-button type="primary" :loading="adding" @click="handleAdd">添加</el-button>
      </div>
    </el-card>

    <!-- 音乐列表 -->
    <el-card shadow="never" class="music-card">
      <template #header>
        <div class="list-head">
          <span class="card-title">音乐列表</span>
          <el-button type="danger" plain size="small" :loading="cleaning" @click="handleClean">清理音频</el-button>
          <el-input
            v-model="keyword"
            placeholder="搜索音乐名称"
            clearable
            class="search-input"
            @keyup.enter="handleSearch"
            @clear="handleSearch"
          />
          <el-button type="primary" @click="handleSearch">搜索</el-button>
        </div>
      </template>

      <el-table :data="list" border stripe>
        <el-table-column prop="name" label="名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="音频" width="110" align="center">
          <template #default="{ row }">
            <el-button
              :type="playingId === row.id ? 'success' : 'primary'"
              size="small"
              circle
              @click="togglePlay(row)"
            >
              <span class="play-ico">{{ playingId === row.id ? '❚❚' : '▶' }}</span>
            </el-button>
          </template>
        </el-table-column>
        <el-table-column label="来源" width="90" align="center">
          <template #default="{ row }">
            <el-tag :type="sourceOf(row) === '上传' ? 'success' : 'info'" size="small">{{ sourceOf(row) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="size" label="大小" width="110" align="center">
          <template #default="{ row }">{{ row.size || '-' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="170" align="center">
          <template #default="{ row }">{{ fmtTime(row.createtime) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="100" align="center">
          <template #default="{ row }">
            <el-button type="danger" size="small" link @click="handleDel(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="size"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @current-change="loadData"
          @size-change="loadData"
        />
      </div>
    </el-card>

    <audio ref="player" style="display: none" @ended="onEnded" />
  </div>
</template>

<style scoped>
.music-card + .music-card {
  margin-top: 12px;
}
.card-title {
  font-weight: 600;
}
.add-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.add-mode {
  margin-bottom: 12px;
}
.add-name {
  width: 260px;
}
.add-url {
  width: 420px;
}
.picked-name {
  font-size: 12px;
  color: #909399;
  max-width: 320px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.list-head {
  display: flex;
  align-items: center;
  gap: 10px;
}
.search-input {
  width: 220px;
  margin-left: auto;
}
.play-ico {
  font-size: 11px;
  line-height: 1;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}
.page-tip {
  margin: 0 0 12px;
  font-size: 12px;
  color: #909399;
}
</style>
