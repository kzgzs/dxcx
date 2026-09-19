<script setup lang="ts">
/**
 * 独立设计页（占位框架）
 * - 独立入口，不走壳内菜单切换：路由 /designer/:type（hash 模式，需 token + admin 角色）
 * - 由设计列表页「编辑」按钮 window.open 新开标签进入（对齐参考系统行为）
 * - 本期为占位：顶部标题 + 手机预览框占位 + 建设中提示 + 返回/关闭
 */
import { computed, watchEffect } from 'vue'
import { useRoute } from 'vue-router'
import { ElMessage } from 'element-plus'
import { DESIGN_ITEMS } from './designTypes'

const route = useRoute()

const designKey = computed(() => String(route.params.type || ''))
const title = computed(() => DESIGN_ITEMS[designKey.value] || '页面设计')

// 独立标签页，标题按设计项名称同步
watchEffect(() => {
  document.title = `${title.value} - 鼎极多端·设计器`
})

// 关闭：新标签由 window.open 打开，可直接 window.close；被浏览器拦截时给提示
function handleClose() {
  window.close()
  setTimeout(() => {
    ElMessage.info('浏览器拦截了自动关闭，请手动关闭本标签页')
  }, 100)
}

// 返回：优先回上一页，无历史时回租户后台唯一入口
function handleBack() {
  if (window.history.length > 1) window.history.back()
  else location.hash = '#/Merchant/index'
}
</script>

<template>
  <div class="designer-page">
    <!-- 顶部工具栏 -->
    <div class="designer-topbar">
      <div class="designer-topbar-left">
        <span class="designer-logo">鼎</span>
        <span class="designer-title">{{ title }} · 鼎极多端设计器</span>
      </div>
      <div class="designer-topbar-right">
        <el-button @click="handleBack">返回</el-button>
        <el-button type="danger" plain @click="handleClose">关闭</el-button>
      </div>
    </div>

    <!-- 中间：手机预览框占位 -->
    <div class="designer-body">
      <div class="designer-phone">
        <div class="designer-phone-notch"></div>
        <div class="designer-phone-screen">
          <div class="designer-phone-tip">📱</div>
          <div class="designer-phone-title">设计器正在建设中，即将上线</div>
          <div class="designer-phone-desc">页面预览与编辑能力正在加紧赶工～</div>
        </div>
        <div class="designer-phone-home"></div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.designer-page {
  height: 100vh;
  display: flex;
  flex-direction: column;
  background-color: #f5f7fa;
}

.designer-topbar {
  height: 50px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 16px;
  background-color: #ffffff;
  border-bottom: 1px solid #ececf5;
}

.designer-topbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}

.designer-logo {
  width: 26px;
  height: 26px;
  border-radius: 8px;
  background: linear-gradient(135deg, #9a9cf0 0%, #7b7de5 100%);
  color: #ffffff;
  font-weight: 700;
  font-size: 14px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.designer-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.designer-topbar-right {
  display: flex;
  align-items: center;
  gap: 8px;
}

.designer-body {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: auto;
  padding: 24px 0;
}

/* 手机预览框占位（375 x 667 比例） */
.designer-phone {
  width: 375px;
  height: 667px;
  flex-shrink: 0;
  background-color: #ffffff;
  border: 8px solid #303133;
  border-radius: 36px;
  box-shadow: 0 12px 32px rgba(48, 49, 51, 0.18);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.designer-phone-notch {
  height: 24px;
  flex-shrink: 0;
  background-color: #303133;
  border-radius: 0 0 14px 14px;
  width: 150px;
  margin: 0 auto;
}

.designer-phone-screen {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 0 24px;
  text-align: center;
}

.designer-phone-tip {
  font-size: 40px;
}

.designer-phone-title {
  font-size: 15px;
  font-weight: 600;
  color: #303133;
}

.designer-phone-desc {
  font-size: 12px;
  color: #909399;
}

.designer-phone-home {
  height: 20px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
}

.designer-phone-home::after {
  content: '';
  width: 100px;
  height: 4px;
  border-radius: 2px;
  background-color: #dcdfe6;
}
</style>
