<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { getMyTmplList, delMyTmpl, type MyTmplRow } from '../../api/tmplset'

/**
 * 已添加的模板（mp_tmplset + mp_tmplset_new 两表聚合）
 * 对标 canzhao mptmpl/mytmpl.html：列表展示本租户已配置的模板ID，
 * 删除操作即清空对应配置字段。
 */

const list = ref<MyTmplRow[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const rs = await getMyTmplList()
    list.value = rs.list || []
  } catch {
    // 错误提示由 axios 拦截器统一处理
  } finally {
    loading.value = false
  }
}

async function handleDel(row: MyTmplRow) {
  try {
    await ElMessageBox.confirm(`确定删除【${row.label}】的模板配置吗？`, '操作确认', {
      type: 'warning',
      confirmButtonText: '确定',
      cancelButtonText: '取消',
    })
  } catch {
    return
  }

  try {
    await delMyTmpl(row.source, row.field)
    ElMessage.success('删除成功')
    await load()
  } catch {
    // 错误提示由 axios 拦截器统一处理
  }
}

onMounted(load)
</script>

<template>
  <div class="page-wrap" v-loading="loading">
    <el-card shadow="never">
      <template #header><span class="card-title">已添加的模板</span></template>

      <el-alert type="info" :closable="false" class="quote-alert">
        此处汇总展示【模板消息设置】与【类目模板消息】中已配置的模板，删除操作将清空对应配置项。
      </el-alert>

      <el-table :data="list" border stripe class="mytmpl-table">
        <el-table-column type="index" label="#" width="60" align="center" />
        <el-table-column prop="source_label" label="来源" width="140" align="center" />
        <el-table-column prop="label" label="业务场景" min-width="180" />
        <el-table-column prop="field" label="配置字段" min-width="200">
          <template #default="{ row }">
            <span class="field-name">{{ row.field }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="template_id" label="模板ID" min-width="280" show-overflow-tooltip />
        <el-table-column label="操作" width="100" align="center" fixed="right">
          <template #default="{ row }">
            <el-button type="danger" link @click="handleDel(row)">删除</el-button>
          </template>
        </el-table-column>
        <template #empty>
          <el-empty description="暂无已配置的模板" :image-size="80" />
        </template>
      </el-table>
    </el-card>
  </div>
</template>

<style scoped>
.page-wrap {
  padding: 4px;
}

.card-title {
  font-weight: 600;
}

.quote-alert {
  margin-bottom: 10px;
}

.mytmpl-table {
  width: 100%;
}

.field-name {
  color: #909399;
  font-size: 12px;
}
</style>
