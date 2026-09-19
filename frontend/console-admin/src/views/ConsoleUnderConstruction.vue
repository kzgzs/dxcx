<script setup lang="ts">
// 超管控制台统一占位页：未实现的菜单项先挂这里，后续期次逐个替换为真实页面
</script>

<template>
  <div class="under-construction">
    <div class="uc-emoji">🚧</div>
    <div class="uc-title">该功能正在建设中，即将上线</div>
    <div class="uc-desc">我们正在加紧赶工，先看看其他功能吧～</div>
  </div>
</template>

<style scoped>
.under-construction {
  min-height: 420px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 10px;
  background-color: #ffffff;
  border-radius: 10px;
}

.uc-emoji {
  font-size: 44px;
}

.uc-title {
  font-size: 16px;
  font-weight: 600;
  color: #303133;
}

.uc-desc {
  font-size: 13px;
  color: #909399;
}
</style>
