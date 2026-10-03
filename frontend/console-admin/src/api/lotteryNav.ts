import { reactive } from 'vue'

/**
 * 抽奖活动模块跨页带参跳转共享状态。
 * 活动列表行内「抽奖记录」写入 hid → AdminLayout 切到 choujiang-record 叶子 →
 * 抽奖记录页 onMounted/watch 读取并按活动 id 过滤。
 */
export const lotteryNav = reactive({
  hid: 0 as number,
  /** 自增令牌：记录页据此感知「从列表带参跳来」，即使 hid 不变也重新拉取 */
  token: 0 as number,
})

export function gotoLotteryRecord(hid: number) {
  lotteryNav.hid = hid
  lotteryNav.token += 1
}
