<script setup lang="ts">
/**
 * 手机端后台-会员（底部 Tab 页，对照参考项目 admin/member/index 的列表职责）
 * - GET /api/adminidx/memberlist?page= 会员列表
 * - 触底加载；接口失败/无数据显示空态不白屏；字段多候选容错
 */
import { onLoad, onReachBottom } from "@dcloudio/uni-app";
import { ref } from "vue";
import AdminTabbar from "../components/admin-tabbar.vue";
import { fetchAdminMemberList, fieldText, guardAdmin, normalizePage } from "../api";

/** 会员行（字段容错） */
interface MemberRow {
  id: string;
  name: string;
  mobile: string;
  extra: string;
}

const page = ref(1);
const list = ref<MemberRow[]>([]);
const total = ref(0);
const loading = ref(false);
const loadError = ref(false);
const finished = ref(false);

onLoad(() => {
  if (!guardAdmin()) return;
  refresh();
});

/** 重置加载第一页 */
function refresh() {
  page.value = 1;
  list.value = [];
  total.value = 0;
  finished.value = false;
  loadError.value = false;
  void loadPage();
}

/** 归一化行数据 */
function toRow(item: Record<string, unknown>): MemberRow {
  return {
    id: fieldText(item, ["mid", "id", "member_id"], ""),
    name: fieldText(item, ["nickname", "username", "name"], "未命名会员"),
    mobile: fieldText(item, ["mobile", "tel", "phone"], ""),
    // 附加信息：余额/积分等择有展示
    extra: fieldText(item, ["money", "score", "createtime"], ""),
  };
}

/** 加载当前页 */
async function loadPage() {
  if (loading.value || finished.value) return;
  loading.value = true;
  try {
    const res = await fetchAdminMemberList(page.value);
    const { list: rows, total: t } = normalizePage(res);
    const mapped = rows.map(toRow);
    list.value = page.value === 1 ? mapped : list.value.concat(mapped);
    total.value = t;
    if (rows.length === 0 || list.value.length >= t) finished.value = true;
    else page.value += 1;
  } catch {
    if (page.value === 1) loadError.value = true;
  } finally {
    loading.value = false;
  }
}

/** 触底加载下一页 */
onReachBottom(() => {
  void loadPage();
});
</script>

<template>
  <view class="page">
    <!-- 会员列表 -->
    <view v-if="list.length > 0" class="list">
      <view v-for="row in list" :key="row.id || row.name" class="member-card">
        <view class="avatar"><text>{{ row.name.charAt(0) }}</text></view>
        <view class="meta">
          <text class="name">{{ row.name }}</text>
          <text class="sub">{{ row.mobile || row.extra || "--" }}</text>
        </view>
        <text v-if="row.id" class="mid">ID: {{ row.id }}</text>
      </view>
      <view class="load-tip">
        <text v-if="loading">加载中...</text>
        <text v-else-if="finished">没有更多了</text>
      </view>
    </view>

    <!-- 空态（接口未就绪/无数据均展示，不白屏） -->
    <view v-else-if="!loading" class="empty">
      <text class="empty-icon">👥</text>
      <text class="empty-text">{{ loadError ? "数据加载失败，请稍后重试" : "暂无会员数据" }}</text>
    </view>

    <!-- 底部占位 + 自绘 Tab -->
    <view class="tabbar-holder"></view>
    <admin-tabbar current="member" />
  </view>
</template>

<style scoped>
.page {
  min-height: 100vh;
  background: #f8f8f8;
  padding: 20rpx 24rpx 0;
}
.member-card {
  background: #ffffff;
  border-radius: 16rpx;
  padding: 24rpx 28rpx;
  margin-bottom: 20rpx;
  display: flex;
  align-items: center;
}
.avatar {
  width: 80rpx;
  height: 80rpx;
  border-radius: 50%;
  background: #eef0ff;
  color: #7b7de5;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 34rpx;
  font-weight: 600;
  flex-shrink: 0;
}
.meta {
  flex: 1;
  margin-left: 24rpx;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.name {
  font-size: 28rpx;
  font-weight: 600;
  color: #333333;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.sub {
  margin-top: 8rpx;
  font-size: 24rpx;
  color: #999999;
}
.mid {
  font-size: 22rpx;
  color: #bbbbbb;
  margin-left: 16rpx;
}
.load-tip {
  text-align: center;
  font-size: 24rpx;
  color: #999999;
  padding: 20rpx 0 30rpx;
}
.empty {
  margin-top: 200rpx;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16rpx;
}
.empty-icon {
  font-size: 72rpx;
}
.empty-text {
  font-size: 28rpx;
  color: #999999;
}
.tabbar-holder {
  height: 140rpx;
}
</style>
