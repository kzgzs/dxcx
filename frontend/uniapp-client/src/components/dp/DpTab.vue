<script setup lang="ts">
/**
 * dp-tab 选项卡（任务332）
 * params: style(1下划线/2胶囊)/fontsize1/color1/color2/bgcolor/bgcolor2/borderradius
 *         sticky/arrowshow/arrowcolor/unselectbgcolor/selectbgcolor/selectbordercolor
 * - active 默认 0，点击切换；样式一标题区背景 bgcolor + 选中项下划线 i；
 *   样式二胶囊：选中=selectbgcolor+selectbordercolor 边框+color2 文字，未选中=unselectbgcolor+color1
 * - 切换时按 tabid(组件id)+tabindexid(选项项id) 拉取子内容，内容区背景 bgcolor2，
 *   渲染 temp==='richtext' 项（rich-text 渲染 content）与 temp==='text' 项（params.content 简单渲染）
 * - 旧数据兼容：无 style 按 '1'；色值缺失回退默认口径
 */
import { computed, ref } from "vue";
import { fetchDesignerTabContent, type DpNode } from "../../api/designer";
import { flag, num, str, type DpParams } from "./shared";

const props = withDefaults(
  defineProps<{
    params?: DpParams;
    data?: unknown;
    /** 组件节点（DpRenderer 传入，取 id 作为 tabid 拉取子内容） */
    node?: DpNode | null;
  }>(),
  { params: () => ({}), data: () => [], node: null }
);

/** 选项项结构 {id,name}（口径同 B 端 designerModules tab.data） */
interface TabItem {
  id: string;
  name: string;
}

const items = computed<TabItem[]>(() =>
  (Array.isArray(props.data) ? props.data : []).map((raw) => {
    const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
    return { id: str(it.id), name: str(it.name) };
  })
);

/** 样式：1 下划线 / 2 胶囊（旧数据无 style 按 '1'） */
const isStyle2 = computed(() => str(props.params.style, "1") === "2");
const sticky = computed(() => flag(props.params.sticky) === 1);
const arrowShow = computed(() => flag(props.params.arrowshow) === 1);
const fontSize = computed(() => `${num(props.params.fontsize1, 14) * 2}rpx`);

const active = ref(0);

/** 已拉取子内容缓存（按选项项 id），避免重复请求 */
const contentCache = ref<Record<string, DpNode[]>>({});
const activeContent = ref<DpNode[]>([]);

async function loadContent(item: TabItem | undefined) {
  const tabid = str(props.node?.id);
  if (!item || !item.id || !tabid) {
    activeContent.value = [];
    return;
  }
  const cached = contentCache.value[item.id];
  if (cached) {
    activeContent.value = cached;
    return;
  }
  try {
    const res = await fetchDesignerTabContent(tabid, item.id);
    const list = Array.isArray(res?.content) ? res.content : [];
    contentCache.value[item.id] = list;
    activeContent.value = list;
  } catch {
    // 加载失败/无内容显示空
    activeContent.value = [];
  }
}

function onSwitch(di: number) {
  if (di === active.value) return;
  active.value = di;
  void loadContent(items.value[di]);
}

/** 子内容中渲染富文本项与文本项（任务333：选项内容改简单富文本，text 兼容保留） */
const bodyItems = computed(() => (activeContent.value || []).filter((n) => n && (n.temp === "text" || n.temp === "richtext")));

function textStyle(n: DpNode): Record<string, string> {
  const p = (n.params || {}) as Record<string, unknown>;
  return {
    color: str(p.color, "#000000"),
    fontSize: `${num(p.fontsize, 14) * 2}rpx`,
  };
}

/** 标题区样式：样式一背景 bgcolor；样式二透明（胶囊自带底色） */
const headStyle = computed<Record<string, string>>(() => {
  const s: Record<string, string> = {};
  if (!isStyle2.value) s.backgroundColor = str(props.params.bgcolor, "#F7F7F8");
  return s;
});

/** 选项项样式（口径对照 B 端 tabItemStyle，rpx 换算 *2） */
function itemStyle(di: number): Record<string, string> {
  const p = props.params;
  const fs = fontSize.value;
  if (isStyle2.value) {
    return di === active.value
      ? {
          fontSize: fs,
          color: str(p.color2, "#FF9900"),
          backgroundColor: str(p.selectbgcolor, "#399BE5"),
          border: `1px solid ${str(p.selectbordercolor, "#ffffff")}`,
        }
      : {
          fontSize: fs,
          color: str(p.color1, "#333333"),
          backgroundColor: str(p.unselectbgcolor, "#E9EAEC"),
          border: "1px solid transparent",
        };
  }
  return {
    fontSize: fs,
    color: di === active.value ? str(p.color2, "#FF9900") : str(p.color1, "#333333"),
  };
}

// 初始加载第 0 项子内容
void loadContent(items.value[0]);
</script>

<template>
  <view class="dp-tab" :style="{ borderRadius: `${num(params.borderradius) * 2}rpx`, overflow: 'hidden' }">
    <view class="dp-tab-head" :class="{ s2: isStyle2, sticky }" :style="headStyle">
      <view
        v-for="(d, di) in items"
        :key="di"
        class="dp-tab-item"
        :class="{ s2: isStyle2, on: di === active }"
        :style="itemStyle(di)"
        @click="onSwitch(di)"
      >
        <text>{{ d.name || `选项${di + 1}` }}</text>
        <view
          v-if="di === active && !isStyle2 && arrowShow"
          class="dp-tab-line"
          :style="{ backgroundColor: str(params.arrowcolor, '#FF9900') }"
        ></view>
      </view>
    </view>
    <view class="dp-tab-body" :style="{ backgroundColor: str(params.bgcolor2, '#FFFFFF') }">
      <template v-for="(n, ni) in bodyItems" :key="ni">
        <view v-if="n.temp === 'richtext'" class="dp-tab-body-richtext">
          <rich-text :nodes="str(n.content)"></rich-text>
        </view>
        <view v-else class="dp-tab-body-text" :style="textStyle(n)">
          <text>{{ str((n.params || {})["content"]) }}</text>
        </view>
      </template>
    </view>
  </view>
</template>

<style scoped>
.dp-tab {
  overflow: hidden;
}
.dp-tab-head {
  display: flex;
}
.dp-tab-head.s2 {
  justify-content: flex-start;
  gap: 20rpx;
  padding: 20rpx;
}
.dp-tab-head.sticky {
  position: sticky;
  top: 0;
  z-index: 5;
}
.dp-tab-item {
  flex: 1;
  text-align: center;
  padding: 20rpx 0;
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}
.dp-tab-item.s2 {
  flex: 1;
  text-align: center;
  padding: 8rpx 0;
  border-radius: 8rpx;
}
.dp-tab-line {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 56rpx;
  height: 6rpx;
  border-radius: 4rpx;
}
.dp-tab-body {
  min-height: 100rpx;
  padding: 20rpx;
  box-sizing: border-box;
}
.dp-tab-body-text {
  word-break: break-all;
}
.dp-tab-body-richtext {
  padding: 10rpx;
  word-break: break-all;
}
</style>
