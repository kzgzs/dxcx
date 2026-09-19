<script setup lang="ts">
/**
 * 设计器页面纯渲染组件（任务276）
 * - 自 DesignerEditor.vue 预览弹窗模板提取：把设计JSON渲染为真实UI
 * - 仅依赖 items 数组 + boxStyle，不耦合编辑器 store，可复用于列表卡片真实全貌预览
 * - 固定宽 375px（手机口径），外层用 DesignerPreviewFrame 做缩放与滚动
 */
import type { DesignerModule } from '../api/designer'
import { computed, ref } from 'vue'
import { loadSysTheme, resolveNavBg, resolveNavText, sysTheme } from '../utils/sysTheme'

const props = withDefaults(defineProps<{
  /** 顶栏组件（pageinfo[0]，含 title/bgcolor） */
  topbar?: DesignerModule | null
  /** 页面组件数组（content） */
  items?: DesignerModule[]
  /** 编辑模式：显示选中框/移除按钮并允许拖拽排序（商品详情装修器用） */
  editable?: boolean
  /** 当前选中组件 id（editable 模式高亮） */
  selectedId?: string
}>(), {
  topbar: null,
  items: () => [],
  editable: false,
  selectedId: '',
})

const emit = defineEmits<{
  (e: 'select', id: string): void
  (e: 'remove', id: string): void
  (e: 'move', from: number, to: number): void
}>()

/** 拖拽排序：记录拖起下标，drop 到目标下标时 emit move */
const dragIndex = ref(-1)
function onDragStart(index: number) {
  if (!props.editable) return
  dragIndex.value = index
}
function onDrop(index: number) {
  if (!props.editable) return
  if (dragIndex.value >= 0 && dragIndex.value !== index) {
    emit('move', dragIndex.value, index)
  }
  dragIndex.value = -1
}

/** 任务309：拉取系统主题段（缓存单次），导航「跟随系统设置」用系统色 */
void loadSysTheme()

/** 导航栏背景（任务309：自定义用页面值，跟随系统=系统 navbgmode?navbgcolor:主色调） */
const navBg = computed(() => resolveNavBg(sysTheme.value, (props.topbar?.params || {}) as Record<string, any>))

/** 导航栏文字色（任务309：1白/2黑；跟随系统=系统 navtextcolor 白/黑） */
const navText = computed(() => resolveNavText(sysTheme.value, (props.topbar?.params || {}) as Record<string, any>))

/** 主区域背景：纯色/渐变（bgdir 0上下/1左右，任务307） */
const pageBg = computed(() => {
  const p = (props.topbar?.params || {}) as Record<string, any>
  const c1 = String(p.bgcolor || '#F5F5F5')
  if (p.bgmode === '1') {
    const deg = p.bgdir === '1' ? '90deg' : '180deg'
    return `linear-gradient(${deg}, ${c1}, ${String(p.bgcolor2 || '#FFFFFF')})`
  }
  return c1
})

/** 悬浮按钮样式：top/left 百分比 + 宽高/圆角/边框 */
function covBtnStyle(p: Record<string, any>) {
  const s: Record<string, string> = {
    top: `${Math.min(Number(p.top ?? 80), 100)}%`,
    left: `${Math.min(Number(p.left ?? 80), 100)}%`,
    width: `${Number(p.width || 50)}px`,
    height: `${Number(p.height || 50)}px`,
    backgroundColor: p.bgcolor || '#FD4A46',
    borderRadius: `${Number(p.radius || 0)}px`,
  }
  const bw = Number(p.border || 0)
  if (bw > 0) s.border = `${bw}px solid ${p.bordercolor || '#FFFFFF'}`
  return s
}

/** 模块外盒样式（口径同编辑器预览 boxStyle） */
function boxStyle(params: Record<string, any>) {
  return {
    backgroundColor: params.bgcolor || 'transparent',
    marginLeft: `${Number(params.margin_x || 0)}px`,
    marginRight: `${Number(params.margin_x || 0)}px`,
    marginTop: `${Number(params.margin_y || 0)}px`,
    marginBottom: `${Number(params.margin_y || 0)}px`,
    paddingLeft: `${Number(params.padding_x || 0)}px`,
    paddingRight: `${Number(params.padding_x || 0)}px`,
    paddingTop: `${Number(params.padding_y || 0)}px`,
    paddingBottom: `${Number(params.padding_y || 0)}px`,
  }
}

/** 辅助线渲染样式（dotted 圆点用径向渐变实现真圆点） */
function lineStyle(p: Record<string, any>) {
  const h = Math.max(1, Number(p.height || 2))
  const c = String(p.color || '#666')
  const s = String(p.style || 'dashed')
  if (s === 'dotted') {
    const r = Math.max(h / 2, 1)
    return {
      height: h + 'px',
      backgroundImage: `radial-gradient(circle, ${c} ${r}px, transparent ${r}px)`,
      backgroundSize: `${h * 3}px ${h}px`,
      backgroundRepeat: 'repeat-x',
      backgroundPosition: '0 center',
    }
  }
  return { borderTop: `${h}px ${s} ${c}` }
}

/** 魔方 slots 口径（任务300 同步）：取最大跨高，单元格百分比定位 */
function cubeSlotsOf(mod: DesignerModule): Array<{ x: number; y: number; w: number; h: number; imgurl: string }> {
  return Array.isArray(mod.params?.slots) ? mod.params.slots : []
}
function cubeMaxH(mod: DesignerModule): number {
  return cubeSlotsOf(mod).reduce((m, s) => Math.max(m, s.y + s.h), 1)
}
function cubeCellStyle(mod: DesignerModule, s: { x: number; y: number; w: number; h: number }) {
  const maxH = cubeMaxH(mod)
  return {
    left: `${(s.x / 4) * 100}%`,
    top: `${(s.y / maxH) * 100}%`,
    width: `${(s.w / 4) * 100}%`,
    height: `${(s.h / maxH) * 100}%`,
  }
}
/** 多图布局（任务327：layout 优先，旧数据回退 style） */
function picsLay(mod: DesignerModule): string {
  const p = (mod.params || {}) as Record<string, any>
  const l = String(p.layout ?? '')
  if (l === 'showcase' || l === '2' || l === '3' || l === '4') return l
  const s = String(p.style ?? '2')
  return s === '3' || s === '4' ? s : '2'
}

/** 选项卡选项项样式（任务332：样式一=均分文字+下划线标识；样式二=胶囊块；口径同 DesignerEditor） */
function tabItemStyle(mod: DesignerModule, di: number): Record<string, string> {
  const p = (mod.params || {}) as Record<string, any>
  const fs = `${Number(p.fontsize1 || 14)}px`
  if (String(p.style ?? '1') === '2') {
    return di === 0
      ? { fontSize: fs, color: p.color2 || '#FF9900', backgroundColor: p.selectbgcolor || '#399BE5', border: `1px solid ${p.selectbordercolor || '#ffffff'}`, borderRadius: '4px', padding: '4px 0' }
      : { fontSize: fs, color: p.color1 || '#333333', backgroundColor: p.unselectbgcolor || '#E9EAEC', border: '1px solid transparent', borderRadius: '4px', padding: '4px 0' }
  }
  return { fontSize: fs, color: di === 0 ? (p.color2 || '#FF9900') : (p.color1 || '#333333') }
}
</script>

<template>
  <div class="dpr-root">
    <div class="dpr-topbar" :style="{ backgroundColor: navBg }">
      <span class="dpr-topbar-title" :style="{ color: navText }">{{ props.topbar?.params?.title || '页面标题' }}</span>
    </div>
    <!-- 主区域背景容器（任务307：页面背景色作用于内容区） -->
    <div class="dpr-body" :style="{ background: pageBg }">
    <div
      v-for="(mod, index) in props.items"
      :key="mod.id"
      class="dpr-item"
      :class="{ active: props.editable && props.selectedId === mod.id }"
      :draggable="props.editable"
      @click.stop="props.editable && emit('select', mod.id)"
      @dragstart="onDragStart(index)"
      @dragover.prevent
      @dragend="dragIndex = -1"
      @drop.prevent="onDrop(index)"
    >
      <div :style="boxStyle(mod.params || {})">
        <div v-if="mod.temp === 'banner'" class="pv-banner" :style="{ height: `${Number(mod.params.height || 200) / 2}px`, borderRadius: `${mod.params.borderradius || 0}px`, backgroundColor: mod.params.bgcolor || '#f2f3f5' }">
          <img v-if="mod.data?.length && mod.data[0].imgurl" :src="mod.data[0].imgurl" />
          <span v-else class="pv-placeholder">轮播图</span>
          <div v-if="mod.params.indicatordots === '1' && (mod.data || []).length" class="pv-banner-dots" :class="[`pos-${mod.params.dotposition || 'center'}`, `shape-${mod.params.dotshape || '0'}`]" :style="{ bottom: `${Number(mod.params.dotbottom || 8) / 2}px` }">
            <i v-for="(_d, di) in mod.data" :key="di" :style="{ backgroundColor: di === 0 ? (mod.params.dotactivecolor || '#3DB51E') : (mod.params.dotcolor || '#EDEEF0') }"></i>
          </div>
        </div>
        <div v-else-if="mod.temp === 'search'" class="pv-search" :style="{ backgroundColor: mod.params.bgcolor, borderRadius: `${mod.params.borderradius || 5}px` }">
          <span class="pv-search-icon">🔍</span><span :style="{ color: mod.params.color }">{{ mod.params.placeholder }}</span>
        </div>
        <div v-else-if="mod.temp === 'notice'" class="pv-notice" :style="{ color: mod.params.color, fontSize: `${mod.params.fontsize || 14}px` }">
          <img v-if="String(mod.params.showimg) === '1' && mod.params.img" class="pv-notice-img" :src="mod.params.img" />
          <img v-if="String(mod.params.showicon) === '1' && mod.params.icon" class="pv-notice-icon-img" :src="mod.params.icon" />
          <span v-else-if="String(mod.params.showicon) === '1'" class="pv-notice-icon">📢</span><span>{{ mod.data?.[0]?.title || '暂无公告' }}</span>
        </div>
        <div v-else-if="mod.temp === 'menu'" class="pv-menu" :style="{ gridTemplateColumns: `repeat(${mod.params.pernum || 5}, 1fr)` }">
          <div v-for="(d, di) in mod.data || []" :key="di" class="pv-menu-item">
            <img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }" />
            <span v-else class="pv-menu-icon-ph" :style="{ width: `${mod.params.iconsize || 40}px`, height: `${mod.params.iconsize || 40}px` }">{{ di + 1 }}</span>
            <em v-if="mod.params.showtitle === '1'">{{ d.title || '菜单' }}</em>
          </div>
        </div>
        <div v-else-if="mod.temp === 'picture'" class="pv-picture" :style="{ borderRadius: `${mod.params.borderradius || 0}px` }">
          <template v-for="(d, di) in mod.data || []" :key="di">
            <img v-if="d.imgurl" :src="d.imgurl" />
          </template>
          <span v-if="!(mod.data || []).some((d: any) => d && d.imgurl)" class="pv-placeholder">单图</span>
        </div>
        <div v-else-if="mod.temp === 'pictures'" class="pv-pictures" :class="[`lay-${picsLay(mod)}`]">
          <div v-for="(d, di) in mod.data || []" :key="di" class="pv-pictures-item" :style="{ borderRadius: `${mod.params.imageRadius || 0}px` }">
            <img v-if="d.imgurl" :src="d.imgurl" /><span v-else class="pv-placeholder sm">图{{ di + 1 }}</span>
          </div>
        </div>
                <div v-else-if="mod.temp === 'title'" class="pv-title" :style="{ color: mod.params.color || '#333', fontSize: `${mod.params.fontsize || 14}px`, backgroundColor: mod.params.bgcolor || '#F6F6F6', padding: `${mod.params.padding_y || 5}px ${mod.params.padding_x ?? 30}px`, margin: `${mod.params.margin_y || 0}px ${mod.params.margin_x || 0}px` }">
                  <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
                  <img v-if="String(mod.params.showicon ?? '1') === '1' && mod.params.imgurl" class="pv-title-icon" :src="mod.params.imgurl" :style="{ width: `${mod.params.iconsize || 14}px`, height: `${mod.params.iconsize || 14}px` }" />
                  <span>{{ mod.params.title || '标题' }}</span>
                  <i class="pv-title-line" :style="{ borderColor: mod.params.linecolor || '#DDDDDD' }"></i>
                </div>
        <div v-else-if="mod.temp === 'text'" class="pv-text" :style="{ color: mod.params.color || '#000', fontSize: `${mod.params.fontsize || 14}px`, lineHeight: `${mod.params.lineheight || 20}px`, letterSpacing: `${mod.params.letterspacing || 0}px`, fontWeight: String(mod.params.isbold ?? mod.params.fontbold ?? '0') !== '0' ? 'bold' : 'normal', backgroundImage: mod.params.bgimg ? `url(${mod.params.bgimg})` : '', backgroundSize: 'cover', textAlign: mod.params.align || 'left' }">{{ mod.params.content }}</div>
        <div v-else-if="mod.temp === 'richtext'" class="pv-richtext" v-html="mod.content || '富文本内容'"></div>
        <div v-else-if="mod.temp === 'blank'" class="pv-blank" :style="{ height: `${mod.params.height || 50}px` }"></div>
        <div v-else-if="mod.temp === 'line'" class="pv-line" :style="lineStyle(mod.params)"></div>
        <div v-else-if="mod.temp === 'product'" class="pv-product" :class="{ list: mod.params.style === '1' }">
          <div v-for="n in Math.min(Number(mod.params.proshownum || 6), 4)" :key="n" class="pv-product-item">
            <div class="pv-product-img"></div>
            <div class="pv-product-name">商品名称示例</div>
            <div class="pv-product-price" v-if="mod.params.showprice === '1'">￥99.00</div>
          </div>
        </div>
        <template v-else-if="mod.temp === 'coupon'">
          <div class="pv-coupon" v-for="n in Math.min(Number(mod.params.shownum || 6), 2)" :key="n">
            <div class="pv-coupon-left">￥<b>20</b></div>
            <div class="pv-coupon-right">
              <div class="pv-coupon-name">优惠券示例{{ n }}</div>
              <div class="pv-coupon-btn">领取</div>
            </div>
          </div>
        </template>
        <div v-else-if="mod.temp === 'tab'" class="pv-tab" :style="{ borderRadius: `${mod.params.borderradius || 0}px`, overflow: 'hidden' }">
          <div class="pv-tab-head" :class="{ s2: mod.params.style === '2' }" :style="mod.params.style === '2' ? { backgroundColor: 'transparent' } : { backgroundColor: mod.params.bgcolor || '#F7F7F8' }">
            <span
              v-for="(d, di) in mod.data || []"
              :key="di"
              class="pv-tab-item"
              :class="{ s2: mod.params.style === '2', on: di === 0 }"
              :style="tabItemStyle(mod, di)"
            >
              {{ d.name || `选项${di + 1}` }}
              <i v-if="di === 0 && mod.params.style !== '2' && mod.params.arrowshow === '1'" :style="{ backgroundColor: mod.params.arrowcolor || '#FF9900' }"></i>
            </span>
          </div>
          <div class="pv-tab-body" :style="{ backgroundColor: mod.params.bgcolor2 || '#FFFFFF' }">选项卡内容区域</div>
        </div>
        <div v-else-if="mod.temp === 'video'" class="pv-video" :style="{ height: Math.round(375 * 16 / 9 * (Math.min(100, Math.max(10, Number(mod.params.videoheight) || 30))) / 100) + 'px' }">
          <img v-if="mod.params.pic" :src="mod.params.pic" /><span class="pv-video-play">▶</span><span v-if="!mod.params.pic" class="pv-placeholder">视频</span>
        </div>
        <div v-else-if="mod.temp === 'button'" class="pv-button">
          <span :style="{ width: `${mod.params.btnwidth || 120}px`, height: `${mod.params.btnheight || 40}px`, backgroundColor: mod.params.btnbgcolor || '#FD4A46', color: mod.params.btncolor || '#FFFFFF', fontSize: `${mod.params.btnfontsize || 14}px`, borderRadius: `${mod.params.btnradius || 0}px` }">{{ mod.params.btntext || '按钮' }}</span>
        </div>
        <div v-else-if="mod.temp === 'dhlist'" class="pv-dhlist" :style="{ backgroundColor: mod.params.bgcolor || '#FFFFFF' }">
          <div v-for="(d, di) in mod.data || []" :key="di" class="pv-dhlist-item">
            <span v-if="d.showicon === '1'" class="pv-dhlist-icon" :style="{ width: `${d.iconsize || 24}px`, height: `${d.iconsize || 24}px` }"><img v-if="d.imgurl" :src="d.imgurl" /></span>
            <div class="pv-dhlist-txt"><b :style="{ color: mod.params.color1 || '#333333' }">{{ d.title1 || '标题' }}</b><em :style="{ color: mod.params.color2 || '#999999' }">{{ d.title2 || '' }}</em></div>
            <i v-if="mod.params.arrowshow === '1'" class="pv-dhlist-arrow">›</i>
          </div>
        </div>
        <div v-else-if="mod.temp === 'cover'" class="pv-cover">
          <div class="pv-cover-btn" :style="covBtnStyle(mod.params)">
            <img v-if="mod.params.style === '2' && mod.params.pic" :src="mod.params.pic" :style="{ width: `${mod.params.picwidth || 48}px`, height: `${mod.params.picheight || 48}px` }" />
            <span v-else :style="{ fontSize: `${mod.params.fontsize || 14}px`, color: mod.params.color || '#FFFFFF' }">{{ mod.params.text || '悬浮' }}</span>
          </div>
        </div>
        <div v-else-if="mod.temp === 'map'" class="pv-map" :style="{ height: `${mod.params.height || 200}px` }">
          <span class="pv-map-pin">📍</span><span class="pv-map-addr">{{ mod.params.address || '地图地址' }}</span>
        </div>
        <div v-else-if="mod.temp === 'hotspot'" class="pv-hotspot">
          <img v-if="mod.params.imgurl" :src="mod.params.imgurl" /><span v-else class="pv-placeholder">热区背景图</span>
          <div v-for="(s, si) in mod.data || []" :key="si" class="pv-hotspot-area" :style="{ width: `${s.width || 80}px`, height: `${s.height || 80}px`, left: `${s.left || 0}px`, top: `${s.top || 0}px` }"></div>
        </div>
        <div v-else-if="mod.temp === 'cube'" class="pv-cube-box" :style="{ aspectRatio: `4 / ${cubeMaxH(mod)}` }">
          <div v-for="(s, si) in cubeSlotsOf(mod)" :key="si" class="pv-cube-cell" :style="cubeCellStyle(mod, s)">
            <img v-if="s.imgurl" :src="s.imgurl" /><span v-else class="pv-placeholder sm">图{{ si + 1 }}</span>
          </div>
        </div>
        <div v-else-if="mod.temp === 'shop'" class="pv-shop" :class="{ s2: mod.params.style === '2' }">
          <img v-if="mod.params.bgimg" class="pv-shop-bg" :src="mod.params.bgimg" />
          <div v-else class="pv-shop-bg-ph"></div>
          <template v-if="mod.params.style === '2'">
            <div class="pv-shop-logo sq" :style="{ bottom: `${Number(mod.params.navheight || 34) / 2}px`, zIndex: '5' }"><img v-if="mod.params.logo === '1' && mod.params.logourl" :src="mod.params.logourl" /></div>
            <div v-if="mod.params.name === '1'" class="pv-shop-name s2" :style="{ bottom: `${Number(mod.params.navheight || 34) * 0.75 + 31}px`, transform: 'translateY(50%)' }">{{ mod.params.shopname || '店铺名称' }}</div>
            <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav row" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
              <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }"><img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}</span>
            </div>
          </template>
          <template v-else>
            <div v-if="mod.params.logo === '1'" class="pv-shop-logo" :style="{ bottom: `${Number(mod.params.navheight || 34) + 26}px` }"><img v-if="mod.params.logourl" :src="mod.params.logourl" /></div>
            <div v-if="mod.params.name === '1'" class="pv-shop-name" :style="{ bottom: `${Number(mod.params.navheight || 34) + 4}px` }">{{ mod.params.shopname || '店铺名称' }}</div>
            <div v-if="mod.params.menu === '1' && (mod.data || []).length" class="pv-shop-nav" :style="{ height: `${Number(mod.params.navheight || 34)}px` }">
              <span v-for="(d, di) in mod.data" :key="di" :style="{ color: mod.params.navcolor || '#FFFFFF', fontSize: `${Number(mod.params.navfontsize || 11)}px` }"><img v-if="d.imgurl" :src="d.imgurl" :style="{ width: `${Number(mod.params.naviconsize || 16)}px`, height: `${Number(mod.params.naviconsize || 16)}px` }" />{{ d.text || '按钮' }}</span>
            </div>
          </template>
        </div>
        <div v-else-if="mod.temp === 'featured'" class="pv-featured" :style="{ height: `${Number(mod.params.height || 249)}px`, gap: `${Number(mod.params.padding_x || 0)}px` }">
          <div class="pv-featured-left">
            <img v-if="(mod.data || [])[0]?.imgurl" :src="mod.data?.[0]?.imgurl" />
            <div v-if="mod.params.indicatordots === '1' && (mod.data || []).length" class="pv-featured-dots" :class="[`pos-${mod.params.dotposition || 'center'}`, `shape-${mod.params.dotshape || '0'}`]" :style="{ bottom: `${Number(mod.params.dotbottom || 8)}px` }">
              <i v-for="(_d, di) in mod.data" :key="di" :style="{ backgroundColor: di === 0 ? (mod.params.dotactivecolor || '#3DB51E') : (mod.params.dotcolor || '#ffffff') }"></i>
            </div>
          </div>
          <div class="pv-featured-right" :style="{ gap: `${Number(mod.params.padding_y || 0)}px` }">
            <div class="pv-featured-rimg"><img v-if="mod.params.imgtop" :src="mod.params.imgtop" /></div>
            <div class="pv-featured-rimg"><img v-if="mod.params.imgbot" :src="mod.params.imgbot" /></div>
          </div>
        </div>
        <div
          v-else-if="mod.temp === 'orderbroadcast'"
          class="pv-orderbroadcast"
          :style="{ height: `${Number(mod.params.height || 36)}px`, borderRadius: `${mod.params.borderradius || 18}px`, backgroundColor: mod.params.bgcolor || '#F5F5F5', color: mod.params.color || '#666666' }"
        >
          <span v-if="mod.params.showavatar === '1'" class="pv-orderbroadcast-avatar"></span>
          <span class="pv-orderbroadcast-text">用户** 刚刚购买了 商品</span>
        </div>
      </div>
      <button
        v-if="props.editable"
        type="button"
        class="dpr-remove"
        title="移除"
        @click.stop="emit('remove', mod.id)"
      >移除</button>
    </div>
    <div v-if="props.items.length === 0" class="dpr-empty">页面还没有添加任何组件</div>
    </div>
  </div>
</template>

<style scoped>
.dpr-root {
  width: 375px;
  position: relative;
  background-color: #ffffff;
}

.dpr-topbar {
  height: 44px;
  z-index: 30;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-bottom: 1px solid rgba(0, 0, 0, 0.06);
  position: relative;
}

.dpr-topbar-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

/* 主区域背景容器（任务307） */
.dpr-body {
  min-height: 400px;
}

.dpr-empty {
  padding: 60px 20px;
  text-align: center;
  font-size: 13px;
  color: #a8abb2;
}

/* 编辑模式交互（商品详情装修器）：选中高亮 + 悬停移除按钮 */
.dpr-item {
  position: relative;
  cursor: pointer;
}
.dpr-item:hover {
  outline: 2px dashed #00a0e9;
  outline-offset: -2px;
}
.dpr-item.active {
  outline: 2px solid #00a0e9;
  outline-offset: -2px;
}
.dpr-remove {
  position: absolute;
  right: 2px;
  bottom: 2px;
  z-index: 9;
  height: 20px;
  min-width: 40px;
  padding: 0 6px;
  border: none;
  background: rgba(0, 0, 0, 0.4);
  color: #fff;
  font-size: 12px;
  line-height: 20px;
  text-align: center;
  cursor: pointer;
  display: none;
}
.dpr-item:hover .dpr-remove,
.dpr-item.active .dpr-remove {
  display: block;
}

/* ===== 简版预览组件（口径同 DesignerEditor.vue pv-* 样式） ===== */
.pv-placeholder {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  color: #b1b3b8;
  font-size: 12px;
  background: repeating-linear-gradient(45deg, #f4f5f7, #f4f5f7 8px, #eceef1 8px, #eceef1 16px);
}

.pv-placeholder.sm {
  font-size: 11px;
}

.pv-banner {
  position: relative;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-banner img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-search {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  font-size: 12px;
  border: 1px solid #e6e7eb;
}

.pv-notice {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 4px;
  overflow: hidden;
  white-space: nowrap;
}

.pv-menu {
  display: grid;
  gap: 6px 0;
  padding: 6px 0;
}

.pv-menu-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 4px;
  min-width: 0;
}

.pv-menu-item img {
  object-fit: cover;
  border-radius: 8px;
}

.pv-menu-icon-ph {
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #eef0f4;
  border-radius: 8px;
  color: #b1b3b8;
  font-size: 12px;
}

.pv-menu-item em {
  font-style: normal;
  font-size: 12px;
  color: #606266;
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.pv-picture {
  overflow: hidden;
  background-color: #f2f3f5;
  min-height: 80px;
}

.pv-picture img {
  width: 100%;
  display: block;
}

.pv-pictures {
  display: grid;
  gap: 2px;
  grid-auto-rows: 70px;
}

.pv-pictures.lay-2 {
  grid-template-columns: repeat(2, 1fr);
}

.pv-pictures.lay-3 {
  grid-template-columns: repeat(3, 1fr);
}

.pv-pictures.lay-4 {
  grid-template-columns: repeat(4, 1fr);
}

.pv-pictures.lay-showcase {
  grid-template-columns: repeat(2, 1fr);
}

.pv-pictures.lay-showcase .pv-pictures-item:first-child {
  grid-row: span 2;
}

.pv-pictures-item {
  height: 70px;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-pictures-item img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-weight: 600;
}

.pv-title-line {
  flex: 1;
  height: 0;
  border-top: 1px solid #dddddd;
}

.pv-title-icon {
  flex: none;
  object-fit: contain;
}

.pv-text {
  word-break: break-all;
}

.pv-richtext {
  font-size: 13px;
  color: #606266;
  word-break: break-all;
}

.pv-blank {
  background: repeating-linear-gradient(45deg, #fafafa, #fafafa 6px, #f2f3f5 6px, #f2f3f5 12px);
}

.pv-line {
  width: 100%;
}

.pv-product {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.pv-product.list {
  grid-template-columns: 1fr;
}

.pv-product-item {
  border: 1px solid #f0f1f5;
  border-radius: 8px;
  overflow: hidden;
}

.pv-product-img {
  height: 80px;
  background: repeating-linear-gradient(45deg, #f4f5f7, #f4f5f7 8px, #eceef1 8px, #eceef1 16px);
}

.pv-product.list .pv-product-img {
  height: 60px;
}

.pv-product-name {
  padding: 4px 6px 0;
  font-size: 12px;
  color: #303133;
}

.pv-product-price {
  padding: 2px 6px 6px;
  font-size: 12px;
  color: #f56c6c;
  font-weight: 600;
}

.pv-coupon {
  display: flex;
  align-items: stretch;
  margin-bottom: 6px;
  border-radius: 8px;
  overflow: hidden;
  background: linear-gradient(90deg, #ff9a57, #f56c6c);
  color: #ffffff;
}

.pv-coupon-left {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 80px;
  font-size: 12px;
  border-right: 1px dashed rgba(255, 255, 255, 0.5);
}

.pv-coupon-left b {
  font-size: 20px;
}

.pv-coupon-right {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 8px 12px;
}

.pv-coupon-name {
  font-size: 12px;
}

.pv-coupon-btn {
  font-size: 11px;
  background-color: #ffffff;
  color: #f56c6c;
  border-radius: 10px;
  padding: 2px 10px;
}

.pv-tab {
  overflow: hidden;
}

.pv-tab-head {
  display: flex;
}

.pv-tab-head.s2 {
  justify-content: flex-start;
  gap: 10px;
  padding: 10px;
}

.pv-tab-item {
  flex: 1;
  text-align: center;
  padding: 10px 0;
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}

.pv-tab-item.s2 {
  flex: 1;
  text-align: center;
  padding: 4px 0;
  border-radius: 4px;
}

.pv-tab-item i {
  position: absolute;
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
  width: 28px;
  height: 3px;
  border-radius: 2px;
}

.pv-tab-body {
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  color: #c0c4cc;
  font-size: 13px;
}

.pv-video {
  position: relative;
  overflow: hidden;
  background-color: #1f2024;
}

.pv-video img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-video-play {
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background-color: rgba(0, 0, 0, 0.45);
  color: #ffffff;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
}

.pv-button {
  display: flex;
  justify-content: center;
  padding: 6px 0;
}

.pv-button span {
  display: inline-flex;
  align-items: center;
  justify-content: center;
}

.pv-dhlist-item {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 8px;
  border-bottom: 1px solid #f2f3f5;
}

.pv-dhlist-item:last-child {
  border-bottom: none;
}

.pv-dhlist-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  background-color: #eef0f4;
  border-radius: 6px;
  flex-shrink: 0;
}

.pv-dhlist-icon img {
  width: 70%;
  height: 70%;
  object-fit: contain;
}

.pv-dhlist-txt {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
}

.pv-dhlist-txt b {
  font-weight: 600;
}

.pv-dhlist-txt em {
  font-style: normal;
}

.pv-dhlist-arrow {
  font-style: normal;
  color: #c0c4cc;
  font-size: 16px;
}

.pv-cover {
  position: absolute;
  inset: 0;
  pointer-events: none;
  z-index: 20;
}

.pv-cover-btn {
  position: absolute;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.18);
}

.pv-cover-btn img {
  display: block;
}

.pv-map {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: linear-gradient(135deg, #e8f0e8 0%, #dce8f0 100%);
}

.pv-map-pin {
  font-size: 22px;
}

.pv-map-addr {
  font-size: 12px;
  color: #606266;
  padding: 0 10px;
  text-align: center;
}

.pv-hotspot {
  position: relative;
  min-height: 120px;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-hotspot img {
  width: 100%;
  display: block;
}

.pv-hotspot-area {
  position: absolute;
  border: 1px dashed #f56c6c;
  background-color: rgba(245, 108, 108, 0.12);
  box-sizing: border-box;
}

.pv-cube-box {
  position: relative;
  width: 100%;
  overflow: hidden;
}

.pv-cube-cell {
  position: absolute;
  overflow: hidden;
  background-color: #f2f3f5;
}

.pv-cube-cell img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

/* 店招/精选（任务302，口径同 DesignerEditor.vue） */
.pv-shop {
  position: relative;
  height: 170px;
  overflow: hidden;
  background: #e8e9ee;
}

.pv-shop-bg {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-shop-bg-ph {
  width: 100%;
  height: 100%;
}

.pv-shop-logo {
  position: absolute;
  left: 50%;
  bottom: 60px;
  transform: translateX(-50%);
  width: 62px;
  height: 62px;
  border-radius: 50%;
  background: rgba(255, 255, 255, 0.9);
  border: 2px solid #ffffff;
  overflow: hidden;
  box-sizing: border-box;
}

.pv-shop-logo img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-shop-logo.sq {
  left: 8px;
  bottom: 0;
  transform: none;
  border-radius: 4px;
  width: 62px;
  height: 62px;
  z-index: 5;
}

.pv-shop-name {
  position: absolute;
  left: 50%;
  bottom: 38px;
  transform: translateX(-50%);
  padding: 2px 12px;
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.25);
  color: #ffffff;
  font-size: 12px;
  white-space: nowrap;
  max-width: 70%;
  overflow: hidden;
  text-overflow: ellipsis;
}

.pv-shop-name.s2 {
  left: 78px;
  bottom: 56px;
  transform: none;
  background: transparent;
  padding: 0;
  border-radius: 0;
  text-shadow: 0 1px 2px rgba(0, 0, 0, 0.5);
  font-size: 13px;
  font-weight: 600;
  width: 64px;
  height: 39px;
  display: flex;
  align-items: center;
  justify-content: flex-start;
}

.pv-shop-nav {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 34px;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  align-items: center;
  justify-content: space-around;
}

.pv-shop-nav span {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1px;
  font-size: 11px;
  line-height: 1.1;
}

.pv-shop-nav span img {
  width: 16px;
  height: 16px;
  border-radius: 2px;
}

.pv-shop-nav.row {
  padding-left: 74px;
}

.pv-featured {
  display: flex;
  width: 100%;
  overflow: hidden;
}

.pv-featured-left {
  position: relative;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  background: #f2f3f5;
}

.pv-featured-left img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-featured-right {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}

.pv-featured-rimg {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  background: #f2f3f5;
}

.pv-featured-rimg img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.pv-featured-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 4px;
}

.pv-featured-dots.pos-center {
  justify-content: center;
}

.pv-featured-dots.pos-left {
  justify-content: flex-start;
  padding-left: 8px;
}

.pv-featured-dots.pos-right {
  justify-content: flex-end;
  padding-right: 8px;
}

.pv-featured-dots i {
  width: 14px;
  height: 3px;
  border-radius: 2px;
}

.pv-featured-dots.shape-1 i {
  width: 12px;
  height: 4px;
  border-radius: 1px;
}

.pv-featured-dots.shape-2 i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.pv-featured-dots.shape-3 i {
  width: 6px;
  height: 6px;
  border-radius: 0;
}

/* 轮播指示点（任务325，口径同 DesignerEditor.vue） */
.pv-banner-dots {
  position: absolute;
  left: 0;
  right: 0;
  display: flex;
  gap: 4px;
}

.pv-banner-dots.pos-center {
  justify-content: center;
}

.pv-banner-dots.pos-left {
  justify-content: flex-start;
  padding-left: 8px;
}

.pv-banner-dots.pos-right {
  justify-content: flex-end;
  padding-right: 8px;
}

.pv-banner-dots i {
  width: 6px;
  height: 6px;
  border-radius: 50%;
}

.pv-banner-dots.shape-1 i {
  width: 12px;
  height: 4px;
  border-radius: 1px;
}

.pv-banner-dots.shape-2 i {
  width: 7px;
  height: 7px;
  border-radius: 50%;
}

.pv-banner-dots.shape-3 i {
  width: 6px;
  height: 6px;
  border-radius: 0;
}

/* 订单播报（任务320，口径同 DesignerEditor.vue） */
.pv-orderbroadcast {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 0 10px;
  overflow: hidden;
  box-sizing: border-box;
}

.pv-orderbroadcast-avatar {
  flex: none;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  background: #d4d6da;
}

.pv-orderbroadcast-text {
  flex: 1;
  min-width: 0;
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
</style>
