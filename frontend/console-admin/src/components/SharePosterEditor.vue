<script setup lang="ts">
import { computed, ref } from 'vue'
import PosterEditor from './PosterEditor.vue'

// 营销活动分享海报统一外壳：顶部「分享海报设置/参团海报设置」× 8 平台子 tab
// 以活动种类 kind 注入两套海报 type（分享/参团）与默认平台，视觉与按钮风格全站统一。
// 后续新活动只需在 KIND_CONFIG 增加一项映射即可复用本外壳。
type PosterKind = 'collage' | 'lucky_collage' | 'kanjia' | 'tuangou' | 'scoreshop' | 'yuyue' | 'kecheng' | 'cycle'

type EditorType =
  | 'collage'
  | 'collageteam'
  | 'kanjia'
  | 'kanjiateam'
  | 'lucky_collage'
  | 'lucky_collageteam'
  | 'tuangou'
  | 'tuangouteam'
  | 'scoreshop'
  | 'yuyue'
  | 'kecheng'
  | 'cycle'

type PosterPlatform = 'mp' | 'wx' | 'alipay' | 'baidu' | 'toutiao' | 'qq' | 'h5' | 'app'

const props = defineProps<{ kind: PosterKind; single?: boolean }>()

// 各活动种类：分享海报 type / 参团海报 type / 参团 tab 文案 / 打开时默认平台
const KIND_CONFIG: Record<PosterKind, { share: EditorType; team: EditorType; teamLabel: string; defaultPlatform: PosterPlatform }> = {
  collage: { share: 'collage', team: 'collageteam', teamLabel: '参团海报设置', defaultPlatform: 'wx' },
  lucky_collage: { share: 'lucky_collage', team: 'lucky_collageteam', teamLabel: '参团海报设置', defaultPlatform: 'mp' },
  kanjia: { share: 'kanjia', team: 'kanjiateam', teamLabel: '邀请帮砍海报设置', defaultPlatform: 'mp' },
  tuangou: { share: 'tuangou', team: 'tuangouteam', teamLabel: '参团海报设置', defaultPlatform: 'wx' },
  scoreshop: { share: 'scoreshop', team: 'scoreshop', teamLabel: '分享海报设置', defaultPlatform: 'wx' },
  yuyue: { share: 'yuyue', team: 'yuyue', teamLabel: '预约海报设置', defaultPlatform: 'wx' },
  kecheng: { share: 'kecheng', team: 'kecheng', teamLabel: '课程海报设置', defaultPlatform: 'wx' },
  // 周期购：仅一种分享海报（无参团海报），用 single 模式，8 平台子 tab
  cycle: { share: 'cycle', team: 'cycle', teamLabel: '分享海报设置', defaultPlatform: 'wx' },
}

const config = computed(() => KIND_CONFIG[props.kind])

// 顶部：分享海报 / 参团海报
const activeTab = ref<'share' | 'team'>('share')
const posterType = computed<EditorType>(() =>
  activeTab.value === 'share' ? config.value.share : config.value.team,
)

// 8 个平台子 tab
const platform = ref<PosterPlatform>(config.value.defaultPlatform)

const platforms: { value: PosterPlatform; label: string }[] = [
  { value: 'mp', label: '微信公众号' },
  { value: 'wx', label: '微信小程序' },
  { value: 'alipay', label: '支付宝小程序' },
  { value: 'baidu', label: '百度小程序' },
  { value: 'toutiao', label: '抖音小程序' },
  { value: 'qq', label: 'QQ小程序' },
  { value: 'h5', label: '手机H5' },
  { value: 'app', label: '手机APP' },
]
</script>

<template>
  <div class="share-poster">
    <el-card shadow="never">
      <div v-if="!single" class="top-tabs">
        <div
          class="top-tab"
          :class="{ active: activeTab === 'share' }"
          @click="activeTab = 'share'"
        >
          <i class="el-icon-setting" /> 分享海报设置
        </div>
        <div
          class="top-tab"
          :class="{ active: activeTab === 'team' }"
          @click="activeTab = 'team'"
        >
          <i class="el-icon-setting" /> {{ config.teamLabel }}
        </div>
      </div>
      <div v-else class="single-title">分享海报设置</div>
      <div class="platform-tabs">
        <div
          v-for="p in platforms"
          :key="p.value"
          class="platform-tab"
          :class="{ active: platform === p.value }"
          @click="platform = p.value"
        >
          {{ p.label }}
        </div>
      </div>
      <PosterEditor
        :type="posterType"
        :platform="platform"
        :show-product-image="true"
        :show-all-qr="true"
        :key="posterType + platform"
      />
    </el-card>
  </div>
</template>

<style scoped>
.share-poster {
  margin: 3px;
}
.top-tabs {
  display: flex;
  gap: 24px;
  border-bottom: 1px solid #e4e7ed;
  margin-bottom: 12px;
}
.top-tab {
  padding: 8px 4px;
  font-size: 14px;
  color: #606266;
  cursor: pointer;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
}
.top-tab.active {
  color: #409eff;
  border-bottom-color: #409eff;
  font-weight: 600;
}
.single-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
  margin-bottom: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #e4e7ed;
}
.platform-tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 20px;
  margin-bottom: 16px;
}
.platform-tab {
  font-size: 13px;
  color: #606266;
  cursor: pointer;
  padding: 4px 0;
}
.platform-tab.active {
  color: #409eff;
  font-weight: 600;
  border-bottom: 2px solid #409eff;
}
</style>
