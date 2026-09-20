<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PosterEditor from '../../components/PosterEditor.vue'
import { addPoster, deletePoster, getPosterList } from '../../api/memberGroup'
import type { PosterListItem } from '../../api/memberGroup'

// 分享海报（admin_set_poster type=index）：8 平台，每平台可维护多张海报
type Platform = 'mp' | 'wx' | 'alipay' | 'baidu' | 'toutiao' | 'qq' | 'h5' | 'app'

const PLATFORMS: { key: Platform; name: string }[] = [
  { key: 'mp', name: '微信公众号' },
  { key: 'wx', name: '微信小程序' },
  { key: 'alipay', name: '支付宝小程序' },
  { key: 'baidu', name: '百度小程序' },
  { key: 'toutiao', name: '抖音小程序' },
  { key: 'qq', name: 'QQ小程序' },
  { key: 'h5', name: '手机H5' },
  { key: 'app', name: '手机APP' },
]

const platform = ref<Platform>('mp')
const list = ref<PosterListItem[]>([])
const currentId = ref(0)
const listLoading = ref(false)

async function loadList(keepCurrent = false) {
  listLoading.value = true
  try {
    const res = await getPosterList({ type: 'index', platform: platform.value })
    list.value = res.list || []
    if (!keepCurrent || !list.value.some((t) => t.id === currentId.value)) {
      currentId.value = list.value[0]?.id || 0
    }
  } catch {
    // 拦截器已提示
  } finally {
    listLoading.value = false
  }
}

function onPlatformChange(name: string | number) {
  platform.value = String(name) as Platform
}

watch(platform, () => loadList())

async function onAdd() {
  try {
    const res = await addPoster({ type: 'index', platform: platform.value })
    ElMessage.success('已新增一张海报')
    await loadList(true)
    currentId.value = res.id
  } catch {
    // 拦截器已提示
  }
}

async function onDeleteCurrent() {
  const item = list.value.find((t) => t.id === currentId.value)
  if (!item) return
  if (list.value.length <= 1) {
    ElMessage.warning('至少保留一张海报')
    return
  }
  try {
    await ElMessageBox.confirm(
      `确定删除当前「海报${list.value.findIndex((t) => t.id === item.id) + 1}」吗？删除后使用该模板生成的分享海报将一并清理。`,
      '删除确认',
      { type: 'warning', confirmButtonText: '删除', cancelButtonText: '取消' },
    )
  } catch {
    return
  }
  try {
    await deletePoster({ type: 'index', id: item.id })
    ElMessage.success('删除成功')
    currentId.value = 0
    await loadList()
  } catch {
    // 拦截器已提示
  }
}

onMounted(() => loadList())
</script>

<template>
  <el-card shadow="never">
    <div class="page-title">分享海报设置</div>

    <el-tabs :model-value="platform" class="platform-tabs" @tab-change="onPlatformChange">
      <el-tab-pane v-for="pf in PLATFORMS" :key="pf.key" :label="pf.name" :name="pf.key">
        <div v-if="platform === pf.key" v-loading="listLoading">
          <!-- 当前平台的海报子 tab：海报1 / 海报2 / ... / 增加+ -->
          <div class="subtabs">
            <div
              v-for="(item, idx) in list"
              :key="item.id"
              class="subtab"
              :class="{ active: item.id === currentId }"
              @click="currentId = item.id"
            >
              海报{{ idx + 1 }}
            </div>
            <div class="subtab add" @click="onAdd">增加+</div>
            <el-button
              v-if="list.length > 1"
              link
              type="danger"
              size="small"
              class="del-current"
              @click="onDeleteCurrent"
            >
              删除当前海报
            </el-button>
          </div>

          <PosterEditor
            v-if="currentId > 0"
            :key="`${pf.key}-${currentId}`"
            type="index"
            :platform="pf.key"
            :poster-id="currentId"
            show-guize
            @saved="() => loadList(true)"
          />
          <el-empty v-else description="暂无海报，请点击“增加+”新建" :image-size="80" />
        </div>
      </el-tab-pane>
    </el-tabs>
  </el-card>
</template>

<style scoped>
.page-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 6px;
}
.platform-tabs :deep(.el-tabs__header) {
  margin-bottom: 10px;
}

/* 海报子 tab */
.subtabs {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 3px;
  margin-bottom: 10px;
  border-bottom: 1px solid #e4e7ed;
  padding-bottom: 0;
}
.subtab {
  padding: 5px 16px;
  border: 1px solid #dcdfe6;
  border-bottom: none;
  border-radius: 4px 4px 0 0;
  cursor: pointer;
  font-size: 13px;
  color: #606266;
  background: #fafafa;
  user-select: none;
  margin-bottom: -1px;
}
.subtab:hover {
  color: #409eff;
}
.subtab.active {
  background: #fff;
  color: #409eff;
  border-color: #409eff;
  font-weight: 600;
}
.subtab.add {
  color: #67c23a;
  border-color: #c2e7b0;
  background: #fff;
}
.subtab.add:hover {
  color: #56ab2f;
  border-color: #67c23a;
}
.del-current {
  margin-left: auto;
}
</style>
