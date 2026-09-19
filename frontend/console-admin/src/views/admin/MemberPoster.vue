<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PosterEditor from '../../components/PosterEditor.vue'
import { addPoster, deletePoster, getPosterList } from '../../api/memberGroup'
import type { PosterListItem } from '../../api/memberGroup'

// 会员分享海报（admin_set_poster type=index，多模板管理：新建/删除/规则文案）
const platform = ref<'wx' | 'mp'>('wx')
const list = ref<PosterListItem[]>([])
const currentId = ref(0)
const listLoading = ref(false)

function fmtTime(ts: number): string {
  if (!ts) return '-'
  const d = new Date(ts * 1000)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`
}

async function loadList(keepCurrent = false) {
  listLoading.value = true
  try {
    const res = await getPosterList({ type: 'index', platform: platform.value })
    list.value = res.list
    if (!keepCurrent || !list.value.some((t) => t.id === currentId.value)) {
      currentId.value = list.value[0]?.id || 0
    }
  } catch {
    // 拦截器已提示
  } finally {
    listLoading.value = false
  }
}

watch(platform, () => loadList())

async function onAdd() {
  try {
    const res = await addPoster({ type: 'index', platform: platform.value })
    ElMessage.success('已新建默认模板')
    await loadList(true)
    currentId.value = res.id
  } catch {
    // 拦截器已提示
  }
}

async function onDelete(item: PosterListItem) {
  if (list.value.length <= 1) {
    ElMessage.warning('至少保留一个海报模板')
    return
  }
  try {
    await ElMessageBox.confirm(
      `删除海报模板 #${item.id}？删除后使用该模板生成的会员海报将一并清理。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await deletePoster({ type: 'index', id: item.id })
    ElMessage.success('删除成功')
    if (currentId.value === item.id) currentId.value = 0
    await loadList()
  } catch {
    // 拦截器已提示
  }
}

onMounted(() => loadList())
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="page-head">
        <div class="list-title">分享海报</div>
        <div class="head-right">
          <el-radio-group v-model="platform">
            <el-radio-button value="wx">小程序端</el-radio-button>
            <el-radio-button value="mp">公众号端</el-radio-button>
          </el-radio-group>
          <el-button type="primary" @click="onAdd">新建模板</el-button>
        </div>
      </div>

      <div class="poster-layout">
        <!-- 左：模板列表 -->
        <div class="tpl-list" v-loading="listLoading">
          <div
            v-for="item in list"
            :key="item.id"
            class="tpl-item"
            :class="{ active: item.id === currentId }"
            @click="currentId = item.id"
          >
            <el-image
              v-if="item.poster_bg"
              :src="item.poster_bg"
              class="tpl-thumb"
              fit="cover"
            />
            <div v-else class="tpl-thumb tpl-thumb-empty">无背景</div>
            <div class="tpl-meta">
              <div class="tpl-name">模板 #{{ item.id }}</div>
              <div class="tpl-sub">{{ item.element_num }} 个元素 · {{ fmtTime(item.createtime) }}</div>
            </div>
            <el-button
              link
              type="danger"
              size="small"
              @click.stop="onDelete(item)"
            >删除</el-button>
          </div>
          <el-empty v-if="!list.length && !listLoading" description="暂无模板" :image-size="60" />
        </div>

        <!-- 右：编辑器 -->
        <div class="editor-area">
          <PosterEditor
            v-if="currentId > 0"
            :key="`${platform}-${currentId}`"
            type="index"
            :platform="platform"
            :poster-id="currentId"
            show-guize
            @saved="() => loadList(true)"
          />
          <el-empty v-else description="请选择或新建海报模板" />
        </div>
      </div>
    </el-card>
  </div>
</template>

<style scoped>
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 14px;
}
.list-title {
  font-size: 16px;
  font-weight: 600;
}
.head-right {
  display: flex;
  align-items: center;
  gap: 10px;
}
.poster-layout {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.tpl-list {
  flex: none;
  width: 200px;
  max-height: 700px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.tpl-item {
  border: 1px solid #e4e7ed;
  border-radius: 6px;
  padding: 8px;
  cursor: pointer;
  transition: border-color 0.2s;
}
.tpl-item:hover {
  border-color: #a0cfff;
}
.tpl-item.active {
  border-color: #409eff;
  box-shadow: 0 0 0 2px rgba(64, 158, 255, 0.15);
}
.tpl-thumb {
  width: 100%;
  height: 90px;
  border-radius: 4px;
  background: #f5f7fa;
  display: block;
}
.tpl-thumb-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #909399;
  font-size: 12px;
}
.tpl-meta {
  margin-top: 6px;
}
.tpl-name {
  font-size: 13px;
  font-weight: 600;
  color: #303133;
}
.tpl-sub {
  font-size: 12px;
  color: #909399;
  margin-top: 2px;
}
.editor-area {
  flex: 1;
  min-width: 0;
}
</style>
