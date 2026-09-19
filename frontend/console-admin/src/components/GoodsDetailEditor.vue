<script setup lang="ts">
/**
 * 商品详情装修器（添加商品 - 商品详情页签）
 * 与「设计-页面设计」同源：复用 designerModules 的组件定义/默认值，
 * 复用 DesignerPageRender 做真实手机预览；右侧组件属性直接复用共享的
 * ModulePropPanel（与页面设计器逐组件、逐字段完全一致，不再另写一套）。
 * 通过 v-model 双向绑定组件数组（DesignerModule[]），提交时由父组件序列化进商品 detail 字段。
 */
import { computed, ref, watch } from 'vue'
import type { DesignerModule } from '../api/designer'
import {
  MODULE_MENUS,
  MODULE_CATEGORY_ORDER,
  MODULE_CATEGORY_LABELS,
  defaultModule,
  defaultTopbar,
  type ModuleCategory,
} from '../views/admin/designerModules'
import DesignerPageRender from './DesignerPageRender.vue'
import ModulePropPanel from './ModulePropPanel.vue'

const props = withDefaults(defineProps<{
  modelValue: DesignerModule[]
  /** 手机预览顶栏标题（商品详情默认“商品详情”，文章详情传“文章详情”） */
  title?: string
}>(), {
  title: '商品详情',
})
const emit = defineEmits<{ (e: 'update:modelValue', v: DesignerModule[]): void }>()

// ============ 模块数组（本地唯一真源，初始化深拷贝一次） ============
// 编辑器每次挂载都是全新实例（列表/编辑 v-if），父级在挂载前已备好初始模块，
// 故这里仅深拷贝一次；之后只靠本地变更驱动并向上 emit。切勿再 watch props 重建，
// 否则 emit -> 父级写回 -> 克隆重建 -> emit 会形成无限更新回环。
function cloneModules(arr: DesignerModule[] | undefined | null): DesignerModule[] {
  return (arr || []).map((m) => JSON.parse(JSON.stringify(m)))
}
const modules = ref<DesignerModule[]>(cloneModules(props.modelValue))
watch(modules, () => {
  emit('update:modelValue', cloneModules(modules.value))
}, { deep: true })

const selectedId = ref(modules.value.length ? modules.value[0].id : '')
const selected = computed<DesignerModule | null>(
  () => modules.value.find((m) => m.id === selectedId.value) || null,
)
const topbar = computed(() => defaultTopbar(props.title || '商品详情'))

// ============ 组件增删 / 排序 / 选中 ============
function addModule(temp: string) {
  const m = defaultModule(temp)
  modules.value.push(m)
  selectedId.value = m.id
}
function selectModule(id: string) {
  selectedId.value = id
}
function removeModule(index: number) {
  const [removed] = modules.value.splice(index, 1)
  if (removed && removed.id === selectedId.value) selectedId.value = ''
}
function removeModuleById(id: string) {
  const index = modules.value.findIndex((m) => m.id === id)
  if (index >= 0) removeModule(index)
}
function moveModule(from: number, to: number) {
  const arr = modules.value
  if (from < 0 || from >= arr.length || to < 0 || to >= arr.length || from === to) return
  const [moved] = arr.splice(from, 1)
  arr.splice(to, 0, moved)
}
function moduleName(temp: string) {
  return MODULE_MENUS.find((m) => m.temp === temp)?.name || temp
}
function menusByCategory(cat: ModuleCategory) {
  return MODULE_MENUS.filter((m) => m.category === cat)
}
</script>

<template>
  <div class="gde">
    <!-- 左：手机预览 -->
    <div class="gde-left">
      <div class="gde-phone">
        <div class="gde-phone-frame">
          <div class="gde-phone-screen">
            <div class="gde-statusbar">
              <span class="gde-statusbar-left">
                <span class="gde-signal"><i></i><i></i><i></i><i></i></span>
                <span class="gde-statusbar-brand">wechat</span>
                <span class="gde-wifi"></span>
              </span>
              <span class="gde-statusbar-time">12:00</span>
              <span class="gde-statusbar-right">
                <span class="gde-statusbar-pct">100%</span>
                <span class="gde-battery"></span>
              </span>
            </div>
            <div class="gde-phone-body">
              <DesignerPageRender
                :topbar="topbar"
                :items="modules"
                editable
                :selected-id="selectedId"
                @select="selectModule"
                @remove="removeModuleById"
                @move="moveModule"
              />
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- 右：组件库 + 属性（属性与页面设计器共用 ModulePropPanel） -->
    <div class="gde-right">
      <div class="gde-lib">
        <div v-for="cat in MODULE_CATEGORY_ORDER" :key="cat" class="gde-lib-group">
          <div class="gde-lib-title">{{ MODULE_CATEGORY_LABELS[cat] }}</div>
          <div class="gde-lib-grid">
            <button
              v-for="m in menusByCategory(cat)"
              :key="m.temp"
              type="button"
              class="gde-lib-btn"
              @click="addModule(m.temp)"
            >{{ m.name }}</button>
          </div>
        </div>
      </div>

      <div v-if="selected" class="gde-prop-wrap">
        <div class="gde-prop-title">{{ moduleName(selected.temp) }} 属性</div>
        <ModulePropPanel :module="selected" />
      </div>

      <div v-else class="gde-noselect">点击下方组件库添加组件，或在左侧手机区选中已有组件进行设置</div>
    </div>
  </div>
</template>

<style scoped>
.gde {
  display: flex;
  gap: 16px;
  align-items: flex-start;
}
.gde-left {
  width: 396px;
  flex-shrink: 0;
}
.gde-phone {
  width: 396px;
}
.gde-phone-frame {
  border: 10px solid #26282c;
  border-radius: 30px;
  background: #26282c;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
}
.gde-phone-screen {
  width: 100%;
  background: #fff;
  border-radius: 20px;
  overflow: hidden;
}
.gde-statusbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 28px;
  padding: 0 14px;
  background: #000;
  color: #fff;
  font-size: 11px;
}
.gde-statusbar-time {
  font-weight: 500;
  letter-spacing: 0;
}
.gde-statusbar-left,
.gde-statusbar-right {
  display: flex;
  align-items: center;
  gap: 5px;
}
.gde-statusbar-brand {
  font-size: 10px;
}
.gde-statusbar-pct {
  font-size: 10px;
}
.gde-signal {
  display: inline-flex;
  align-items: flex-end;
  gap: 1px;
  height: 10px;
}
.gde-signal i {
  display: block;
  width: 2px;
  background: #fff;
  border-radius: 1px;
}
.gde-signal i:nth-child(1) { height: 4px; }
.gde-signal i:nth-child(2) { height: 6px; }
.gde-signal i:nth-child(3) { height: 8px; }
.gde-signal i:nth-child(4) { height: 10px; }
.gde-wifi {
  position: relative;
  width: 13px;
  height: 9px;
}
.gde-wifi::before {
  content: '';
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  width: 4px;
  height: 4px;
  background: #fff;
  border-radius: 50%;
}
.gde-wifi::after {
  content: '';
  position: absolute;
  left: 0;
  top: -2px;
  width: 13px;
  height: 13px;
  border: 1.5px solid #fff;
  border-left-color: transparent;
  border-bottom-color: transparent;
  border-radius: 50%;
}
.gde-battery {
  position: relative;
  width: 18px;
  height: 9px;
  border: 1px solid #fff;
  border-radius: 2px;
}
.gde-battery::before {
  content: '';
  position: absolute;
  left: 1px;
  top: 1px;
  bottom: 1px;
  width: 72%;
  background: #fff;
  border-radius: 1px;
}
.gde-battery::after {
  content: '';
  position: absolute;
  right: -3px;
  top: 2px;
  width: 2px;
  height: 4px;
  background: #fff;
  border-radius: 0 1px 1px 0;
}
.gde-phone-body {
  max-height: 560px;
  overflow-y: auto;
  background: #fff;
}
.gde-right {
  flex: 1;
  min-width: 0;
}
.gde-lib {
  padding: 4px 2px 8px;
  margin-bottom: 12px;
  background: #fff;
}
.gde-lib-title {
  font-size: 12px;
  color: #909399;
  margin: 6px 0 8px;
  padding-left: 8px;
  border-left: 3px solid #409eff;
  line-height: 14px;
}
.gde-lib-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}
.gde-lib-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 70px;
  height: 26px;
  padding: 0 10px;
  margin: 0;
  border: 1px solid #ccc;
  background: #fff;
  border-radius: 3px;
  color: #666;
  font-size: 12px;
  font-family: inherit;
  line-height: 1;
  cursor: pointer;
  appearance: none;
  transition: border-color 0.15s, color 0.15s, background-color 0.15s;
}
.gde-lib-btn:hover {
  border-color: #409eff;
  color: #409eff;
  background: #ecf5ff;
}
.gde-prop-wrap {
  border: 1px solid #e7e7eb;
  border-radius: 6px;
  background: #fff;
  overflow: hidden;
}
.gde-prop-title {
  font-weight: 600;
  font-size: 14px;
  color: #303133;
  padding: 10px 14px;
  border-bottom: 1px solid #e7e7eb;
  background: #fafbff;
}
.gde-noselect {
  border: 1px dashed #dcdfe6;
  border-radius: 6px;
  padding: 28px;
  text-align: center;
  color: #909399;
  font-size: 13px;
}
</style>
