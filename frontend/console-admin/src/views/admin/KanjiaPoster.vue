<script setup lang="ts">
import { ref } from 'vue'
import PosterEditor from '../../components/PosterEditor.vue'

// 砍价海报（admin_set_poster type=kanjia，按 platform 单模板；批次4-D 任务189）
const platform = ref<'wx' | 'mp'>('wx')
</script>

<template>
  <div>
    <el-card shadow="never">
      <div class="page-head">
        <div class="list-title">砍价分享海报</div>
        <el-radio-group v-model="platform">
          <el-radio-button value="wx">小程序端</el-radio-button>
          <el-radio-button value="mp">公众号端</el-radio-button>
        </el-radio-group>
      </div>
      <el-alert
        class="tip"
        type="info"
        :closable="false"
        title="会员分享砍价商品时按此模板合成海报：拖动元素调整位置，拉右下角手柄缩放，保存后立即生效。文字元素支持占位符 [商品名称]/[商品销售价]/[商品市场价]，二维码元素在 C 端按分享会员实时生成。"
      />
      <PosterEditor :type="'kanjia'" :platform="platform" :key="platform" />
    </el-card>
  </div>
</template>

<style scoped>
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}
.list-title {
  font-size: 16px;
  font-weight: 600;
}
.tip {
  margin-bottom: 16px;
}
</style>
