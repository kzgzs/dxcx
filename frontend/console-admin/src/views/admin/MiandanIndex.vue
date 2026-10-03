<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  cancelMiandan,
  deleteMiandan,
  getMiandanList,
  getMiandanOrder,
  getMiandanPath,
} from '../../api/ewaybill'
import type { MiandanOrder, PathResult } from '../../api/ewaybill'
import MiandanAccountView from './MiandanAccountView.vue'
import MiandanPrinterView from './MiandanPrinterView.vue'
import MiandanSetView from './MiandanSetView.vue'

// 外链真实来源：canzhao/app/view/miandan/index.html
const GUIDE_URL = 'https://developers.weixin.qq.com/community/develop/article/doc/000ac4abfc85986fe7a8023ce5b413'
const DOWNLOAD_URL = 'https://developers.weixin.qq.com/miniprogram/dev/framework/open-ability/express-download.html'

// ===== 子视图切换（平铺主区+右上X） =====
const view = ref<'main' | 'accounts' | 'printers' | 'set'>('main')

// ===== 列表 =====
const loading = ref(false)
const tableData = ref<MiandanOrder[]>([])
const total = ref(0)
const query = ref({
  page: 1,
  pagesize: 10,
  order_id: '',
  waybill_id: '',
  name: '',
  sort_field: 'id',
  sort_order: 'desc' as 'asc' | 'desc',
})

async function fetchList() {
  loading.value = true
  try {
    const d = await getMiandanList({
      page: query.value.page,
      pagesize: query.value.pagesize,
      order_id: query.value.order_id,
      waybill_id: query.value.waybill_id,
      name: query.value.name,
      sort_field: query.value.sort_field,
      sort_order: query.value.sort_order,
    })
    tableData.value = d.list || []
    total.value = d.total || 0
  } catch {
    /* 拦截器已提示 */
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  query.value.page = 1
  fetchList()
}

function handleSort({ prop, order }: { prop?: string; order?: 'ascending' | 'descending' | null }) {
  query.value.sort_field = prop === 'createtime' ? 'createtime' : 'id'
  query.value.sort_order = order === 'ascending' ? 'asc' : 'desc'
  fetchList()
}

function cargoText(row: MiandanOrder): string {
  if (!row.cargo_detail_list) return row.shop_goods_name || ''
  try {
    const arr = typeof row.cargo_detail_list === 'string' ? JSON.parse(row.cargo_detail_list) : row.cargo_detail_list
    if (Array.isArray(arr) && arr.length) {
      return arr.map((c: any) => `${c.name} × ${c.count}`).join('，')
    }
  } catch {
    /* ignore */
  }
  return row.shop_goods_name || ''
}

// ===== 打印面单弹窗 =====
const printVisible = ref(false)
const printLoading = ref(false)
const printHtml = ref('')
const printTitle = ref('运单详情')

async function openPrint(row: MiandanOrder) {
  printVisible.value = true
  printLoading.value = true
  printHtml.value = ''
  printTitle.value = '运单详情'
  try {
    const d = await getMiandanOrder({
      order_id: row.order_id,
      delivery_id: row.delivery_id,
      waybill_id: row.waybill_id,
    })
    printHtml.value = d.print_html || ''
    if (d.order_status === 1) printTitle.value = '运单详情 - 该运单已取消'
  } catch {
    printVisible.value = false
  } finally {
    printLoading.value = false
  }
}

function doPrint() {
  const frame = document.getElementById('miandan-print-frame') as HTMLIFrameElement | null
  frame?.contentWindow?.focus()
  frame?.contentWindow?.print()
}

// ===== 查询轨迹弹窗 =====
const pathVisible = ref(false)
const pathLoading = ref(false)
const pathData = ref<PathResult | null>(null)

async function openPath(row: MiandanOrder) {
  pathVisible.value = true
  pathLoading.value = true
  pathData.value = null
  try {
    pathData.value = await getMiandanPath({
      order_id: row.order_id,
      delivery_id: row.delivery_id,
      waybill_id: row.waybill_id,
    })
  } catch {
    pathVisible.value = false
  } finally {
    pathLoading.value = false
  }
}

// ===== 取消 / 删除 =====
async function handleCancel(row: MiandanOrder) {
  try {
    await ElMessageBox.confirm('确定要取消吗？', '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await cancelMiandan({ order_id: row.order_id, delivery_id: row.delivery_id, waybill_id: row.waybill_id })
    ElMessage.success('取消成功')
    fetchList()
  } catch {
    /* ignore */
  }
}

async function handleDelete(row: MiandanOrder) {
  try {
    await ElMessageBox.confirm('确定要删除吗？删除后无法恢复！', '操作确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteMiandan([row.id])
    ElMessage.success('删除成功')
    fetchList()
  } catch {
    /* ignore */
  }
}

onMounted(fetchList)
</script>

<template>
  <div class="miandan-page">
    <!-- ============ 平铺子视图（账号/打印员/设置） ============ -->
    <MiandanAccountView v-if="view === 'accounts'" @close="view = 'main'" />
    <MiandanPrinterView v-else-if="view === 'printers'" @close="view = 'main'" />
    <MiandanSetView v-else-if="view === 'set'" @close="view = 'main'" />

    <!-- ============ 主页 ============ -->
    <template v-else>
      <div class="page-title">物流助手</div>

      <!-- 说明文字区 -->
      <div class="intro">
        <p>物流助手是微信官方为小程序提供的免费物流接口。接入后，你可使用本接口在多家快递公司获取电子面单单号等信息，再通过热敏打印机完成电子面单打印，即可将快件交给快递公司上门揽收</p>
        <p>接入物流助手有什么好处：（1）可生成电子面单；（2）用户可收到物流通知；（3）完全免费的官方接口；（4）用户可通过微信服务通知发送的物流消息，或快递单小程序码，回访小程序</p>
        <p>1、请前往微信公众平台（mp.weixin.qq.com），登录小程序账号，在[功能]中找到[物流助手]进行开通</p>
        <p>2、先点击[绑定物流账号]按钮绑定物流账号，然后在订单发货时选择[使用物流助手发货]生成运单</p>
        <p>3、用快递接口Api下单后，可选择以下任一方式打印电子面单</p>
        <p>（1）使用微信物流助手对接的第三方打单软件打印面单，当前已支持的第三方打单软件为：快递管家
          <a :href="GUIDE_URL" target="_blank" rel="noopener">点击获取对接指引</a>
        </p>
        <p>（2）使用物流公司接口或收件员上门打印电子面单；</p>
        <p>（3）点击下方列表中对应订单的[打印面单]使用热敏打印机打印</p>
        <p>（4）安装使用微信打单PC软件：目前支持 Windows XP 及以上版本。
          <a :href="DOWNLOAD_URL" target="_blank" rel="noopener">下载打单软件</a>
        </p>
        <p>4、小程序用户下单，必须是授权用户才可以接收物流消息；
          <a href="javascript:void(0)" @click="view = 'printers'">添加打印员</a>
        </p>
        <p>5、注意:微信物流助手物流查询仅支持通过微信物流助手发货的订单。</p>
      </div>

      <!-- 工具栏一行：左按钮，右搜索 -->
      <div class="toolbar">
        <div class="toolbar-left">
          <el-button @click="view = 'accounts'">绑定物流账号</el-button>
          <el-button @click="view = 'set'">设置</el-button>
        </div>
        <div class="toolbar-right">
          <span class="tb-label">订单号</span>
          <el-input v-model="query.order_id" class="tb-input" @keyup.enter="handleSearch" />
          <span class="tb-label">快递单号</span>
          <el-input v-model="query.waybill_id" class="tb-input" @keyup.enter="handleSearch" />
          <span class="tb-label">收件人</span>
          <el-input v-model="query.name" class="tb-input" placeholder="姓名或手机号" @keyup.enter="handleSearch" />
          <el-button class="search-btn" circle @click="handleSearch">
            <svg viewBox="0 0 1024 1024" width="16" height="16" fill="currentColor"><path d="M945.82578 870.125405l-179.064759-179.128198c37.633442-50.252111 60.226958-112.396119 60.226958-179.635713 0-165.019429-133.863717-298.820032-298.94691-298.820032S229.157273 346.342065 229.157273 511.361494s133.800278 298.883471 298.883471 298.883471c64.175523 0 123.67499-20.451714 172.925368-54.893622l180.206658 180.270097c8.639241 8.575802 19.883819 12.926817 31.191832 12.926817s22.552591-4.351015 31.128393-12.926817C962.978033 917.976954 962.978033 887.274231 945.82578 870.125405zM528.040744 710.308079c-109.760556 0-198.946585-89.122915-198.946585-198.946585s89.186354-198.946585 198.946585-198.946585 198.946585 89.122915 198.946585 198.946585S637.8013 710.308079 528.040744 710.308079z" /></svg>
          </el-button>
        </div>
      </div>

      <!-- 运单表格 -->
      <el-table v-loading="loading" :data="tableData" style="width: 100%" :header-cell-style="{ background: '#e6e6e6' }" @sort-change="handleSort">
        <el-table-column type="selection" width="40" />
        <el-table-column prop="id" label="ID" width="80" sortable="custom" align="left" />
        <el-table-column prop="order_id" label="订单号" min-width="160" align="left" />
        <el-table-column label="快递信息" min-width="160" align="left">
          <template #default="{ row }">
            <div>{{ row.delivery_name }}</div>
            <div class="sub">{{ row.waybill_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="收货信息" min-width="200" align="left">
          <template #default="{ row }">
            <div class="bold">{{ row.receiver_name }} {{ row.receiver_mobile || row.receiver_tel }}</div>
            <div class="sub">{{ row.receiver_province }}{{ row.receiver_city }}{{ row.receiver_area }} {{ row.receiver_address }}</div>
          </template>
        </el-table-column>
        <el-table-column label="商品信息" min-width="180" align="left">
          <template #default="{ row }">{{ cargoText(row) }}</template>
        </el-table-column>
        <el-table-column prop="createtime" label="创建时间" width="150" sortable="custom" align="left">
          <template #default="{ row }">{{ row.createtime_text }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90" align="left">
          <template #default="{ row }">
            <span :style="{ color: row.status === 1 ? 'green' : 'red' }">{{ row.status === 1 ? '正常' : '已取消' }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" min-width="120" align="left">
          <template #default="{ row }">
            <div class="ops">
              <el-button link type="primary" @click="openPrint(row)">打印面单</el-button>
              <el-button link type="primary" @click="openPath(row)">查询轨迹</el-button>
              <el-button v-if="row.status === 1" link type="primary" @click="handleCancel(row)">取消</el-button>
              <el-button v-else link type="primary" @click="handleDelete(row)">删除</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager">
        <el-pagination
          v-model:current-page="query.page"
          v-model:page-size="query.pagesize"
          :total="total"
          :page-sizes="[10, 20, 50]"
          layout="total, sizes, prev, pager, next"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </template>

    <!-- ============ 打印面单弹窗 ============ -->
    <el-dialog v-model="printVisible" :title="printTitle" width="720px">
      <div v-loading="printLoading" style="min-height: 300px">
        <iframe v-if="printHtml" id="miandan-print-frame" :srcdoc="printHtml" class="print-frame" />
      </div>
      <template #footer>
        <el-button type="primary" @click="doPrint">打印</el-button>
      </template>
    </el-dialog>

    <!-- ============ 查询轨迹弹窗 ============ -->
    <el-dialog v-model="pathVisible" title="" width="520px" :show-close="true">
      <div v-loading="pathLoading">
        <template v-if="pathData">
          <div v-if="!pathData.steps || pathData.steps.length === 0" class="empty-path">
            <i class="el-icon-warning-outline"></i> 暂未查到物流信息
          </div>
          <template v-else>
            <div class="path-head">
              <span>{{ pathData.delivery_name }}</span>
              <span>{{ pathData.waybill_id }}</span>
            </div>
            <el-timeline>
              <el-timeline-item v-for="(s, i) in pathData.steps" :key="i" :timestamp="s.time">
                {{ s.text }}
              </el-timeline-item>
            </el-timeline>
          </template>
        </template>
      </div>
      <template #footer>
        <el-button type="primary" @click="pathVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped>
.miandan-page {
  padding: 3px;
}
.page-title {
  font-size: 16px;
  font-weight: 600;
  padding: 3px 0;
}
.intro {
  background: #fafafa;
  border-left: 3px solid #d0d0d0;
  padding: 8px 12px;
  color: #666;
  font-size: 13px;
  line-height: 1.9;
  margin: 3px 0;
}
.intro p {
  margin: 0;
}
.intro a {
  color: #409eff;
}
/* 工具栏一行 */
.toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: nowrap;
  gap: 8px;
  margin: 3px 0;
}
.toolbar-left {
  flex-shrink: 0;
}
.toolbar-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: nowrap;
  overflow: hidden;
}
.tb-label {
  font-size: 13px;
  color: #606266;
  white-space: nowrap;
}
.tb-input {
  width: 130px;
  flex-shrink: 0;
}
.search-btn {
  background: #19a05f;
  border-color: #19a05f;
  color: #fff;
  flex-shrink: 0;
}
.sub {
  color: #909399;
  font-size: 12px;
}
.bold {
  font-weight: 600;
}
.ops {
  display: flex;
  flex-direction: column;
  line-height: 1.6;
}
.pager {
  display: flex;
  justify-content: flex-end;
  margin-top: 10px;
}
.print-frame {
  width: 100%;
  height: 460px;
  border: 1px solid #ebeef5;
}
.path-head {
  display: flex;
  justify-content: space-between;
  margin-bottom: 14px;
  font-weight: 600;
}
.empty-path {
  color: #f05555;
  font-size: 14px;
  padding: 10px 0;
}
</style>
