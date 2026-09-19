import request from './request'

// ============ 充值赠送设置（批次4-A 任务186） ============

export interface GiveRow {
  money: number
  give: number
  give_score: number
}

export interface MoneyGivesetConfig {
  givedata: GiveRow[]
  status: number
  caninput: number
  shuoming: string
}

// 读取配置
export function getMoneyGivesetConfig() {
  return request.get<unknown, MoneyGivesetConfig>('/admin/moneyGiveset/config')
}

// 保存配置
export function saveMoneyGivesetConfig(data: Partial<MoneyGivesetConfig>) {
  return request.post<unknown, null>('/admin/moneyGiveset/save', data)
}
