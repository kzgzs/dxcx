/**
 * 商户账号权限定义（严格照搬参考系统菜单结构：app/common/Menu.php::getdata、
 * app/custom/Restaurant.php::getmenu、app/view/public/user_auth.html）
 *
 * - 后台权限按 商城 / 财务 / 营销 / 扩展 / 餐饮 / 设计 / 系统 分组，
 *   分组下既可能是「标签分组」（标题不可勾，下挂可勾叶子），也可能是直接可勾叶子。
 * - value 取参考系统菜单 path，作为权限标识存储（auth_data）。
 * - 手机端权限三组（查看 / 接收通知 / 核销）的 value 与参考系统 user_auth.html 完全一致。
 * 仅包含参考截图中出现的基础菜单，不含各类增值插件、隐藏(hide)项。
 */

/** 可勾叶子菜单项 */
export interface AuthLeaf {
  /** 菜单名称 */
  name: string
  /** 权限标识（参考系统 path） */
  value: string
}

/**
 * 二级节点：
 * - 带 leaves：标签分组（标题仅展示、不可勾选），leaves 为可勾叶子；
 * - 带 value：本身即可勾选的叶子。
 */
export interface AuthSub {
  name: string
  /** 标签分组下的叶子 */
  leaves?: AuthLeaf[]
  /** 直接可勾叶子时的权限标识 */
  value?: string
}

/** 一级权限分组（标题复选框可整组全选） */
export interface AuthGroup {
  name: string
  children: AuthSub[]
}

// ============================ 后台权限 ============================

export const businessAuthGroups: AuthGroup[] = [
  {
    name: '商城',
    children: [
      { name: '商品管理', value: 'ShopProduct/index' },
      { name: '商品审核', value: 'ShopProduct/check' },
      { name: '订单管理', value: 'ShopOrder/index' },
      { name: '退款申请', value: 'ShopRefundOrder/index' },
      { name: '评价管理', value: 'ShopComment/index' },
      { name: '商品分类', value: 'ShopCategory2/index' },
      { name: '商品参数', value: 'ShopParam/index' },
      { name: '商品服务', value: 'ShopFuwu/index' },
      { name: '商品采集', value: 'ShopTaobao/index' },
      { name: '销售统计', value: 'ShopOrder/tongji' },
      { name: '商品执照', value: 'ShopLicense/index' },
    ],
  },
  {
    name: '财务',
    children: [
      { name: '余额明细', value: 'BusinessMoney/moneylog' },
      { name: '余额提现', value: 'BusinessMoney/withdraw' },
      { name: '提现记录', value: 'BusinessMoney/withdrawlog' },
      {
        name: '买单收款',
        leaves: [
          { name: '买单扣费', value: 'BusinessMaidan/add' },
          { name: '买单记录', value: 'BusinessMaidan/index' },
          { name: '收款码', value: 'BusinessMaidan/set' },
        ],
      },
      { name: '核销记录', value: 'Hexiao/index' },
      { name: '发票管理', value: 'Invoice/index' },
    ],
  },
  {
    name: '营销',
    children: [
      { name: '优惠券', value: 'Coupon/index' },
      { name: '商品促销', value: 'Cuxiao/index' },
      {
        name: '多人拼团',
        leaves: [
          { name: '商品管理', value: 'CollageProduct/index' },
          { name: '订单管理', value: 'CollageOrder/index' },
          { name: '拼团管理', value: 'CollageTeam/index' },
          { name: '评价管理', value: 'CollageComment/index' },
        ],
      },
      {
        name: '幸运拼团',
        leaves: [
          { name: '商品管理', value: 'LuckyCollageProduct/index' },
          { name: '订单管理', value: 'LuckyCollageOrder/index' },
          { name: '拼团管理', value: 'LuckyCollageTeam/index' },
          { name: '评价管理', value: 'LuckyCollageComment/index' },
        ],
      },
      {
        name: '砍价活动',
        leaves: [
          { name: '商品管理', value: 'KanjiaProduct/index' },
          { name: '订单管理', value: 'KanjiaOrder/index' },
        ],
      },
      {
        name: '整点秒杀',
        leaves: [
          { name: '商品列表', value: 'SeckillProduct/index' },
          { name: '订单列表', value: 'SeckillOrder/index' },
          { name: '用户评价', value: 'SeckillComment/index' },
        ],
      },
      {
        name: '团购活动',
        leaves: [
          { name: '商品管理', value: 'TuangouProduct/index' },
          { name: '订单管理', value: 'TuangouOrder/index' },
          { name: '评价管理', value: 'TuangouComment/index' },
          { name: '商品分类', value: 'TuangouCategory/index' },
        ],
      },
      {
        name: '短视频',
        leaves: [
          { name: '分类列表', value: 'ShortvideoCategory/index' },
          { name: '视频列表', value: 'Shortvideo/index' },
          { name: '评论列表', value: 'ShortvideoComment/index' },
          { name: '回评列表', value: 'ShortvideoCommentReply/index' },
        ],
      },
      { name: '数据统计', value: 'DataStatistics/index' },
    ],
  },
  {
    name: '扩展',
    children: [
      {
        name: '文章管理',
        leaves: [
          { name: '文章列表', value: 'Article/index' },
          { name: '文章分类', value: 'ArticleCategory/index' },
          { name: '评论列表', value: 'ArticlePinglun/index' },
          { name: '回评列表', value: 'ArticlePlreply/index' },
          { name: '系统设置', value: 'ArticleSet/set' },
        ],
      },
      {
        name: '预约服务',
        leaves: [
          { name: '服务类目', value: 'Yuyue/index' },
          { name: '服务商品', value: 'YuyueList/index' },
          { name: '服务订单', value: 'YuyueOrder/index' },
          { name: '商品服务', value: 'YuyueFuwu/index' },
          { name: '商品评价', value: 'YuyueComment/index' },
          { name: '人员类型', value: 'YuyueWorkerCategory/index' },
          { name: '人员列表', value: 'YuyueWorker/index' },
          { name: '人员评价', value: 'YuyueWorkerComment/index' },
          { name: '提成明细', value: 'YuyueMoney/moneylog' },
          { name: '提现记录', value: 'YuyueMoney/withdrawlog' },
          { name: '系统设置', value: 'YuyueSet/set' },
        ],
      },
      {
        name: '知识付费',
        leaves: [
          { name: '课程分类', value: 'KechengCategory/index' },
          { name: '课程列表', value: 'KechengList/index' },
          { name: '课程章节', value: 'KechengChapter/index' },
          { name: '题库管理', value: 'KechengTiku/index' },
          { name: '课程订单', value: 'KechengOrder/index' },
          { name: '学习记录', value: 'KechengStudylog/index' },
        ],
      },
      // 视频管理本身即可勾选（参考 Menu.php：VideoList/index），无导出子项
      { name: '视频管理', value: 'VideoList/index' },
      {
        name: '电子面单',
        leaves: [
          { name: '面单记录', value: 'Ewaybill/index' },
          { name: '导出', value: 'EwaybillExcel' },
          { name: '删除', value: 'EwaybillDel' },
          { name: '面单设置', value: 'EwaybillSet/index' },
        ],
      },
      {
        name: '快递鸟配送',
        leaves: [
          { name: '快递鸟设置', value: 'Peisong/kdniaoset' },
          { name: '快递鸟门店', value: 'Peisong/kdniaostore' },
          { name: '快递鸟记录', value: 'PeisongOrder/index' },
        ],
      },
      {
        name: '周期购',
        leaves: [
          { name: '商品管理', value: 'CycleProduct/index' },
          { name: '订单管理', value: 'CycleOrder/index' },
          { name: '配送管理', value: 'CycleOrder/cycle_list' },
          { name: '评价管理', value: 'CycleComment/index' },
        ],
      },
      {
        name: '收银台',
        leaves: [
          { name: '收银设置', value: 'CashierSet/index' },
          { name: '收银台操作', value: 'Cashier/index' },
          { name: '收银订单', value: 'CashierOrder/index' },
          { name: '订单统计', value: 'CashierOrder/tongji' },
        ],
      },
      { name: '自定义表单', value: 'Form/index' },
    ],
  },
  {
    name: '餐饮',
    children: [
      { name: '首页', value: 'Restaurant/index' },
      {
        name: '菜品',
        leaves: [
          { name: '菜品列表', value: 'RestaurantProduct/index' },
          { name: '菜品分类', value: 'RestaurantProductCategory/index' },
          { name: '菜品采集', value: 'RestaurantTaobao/index' },
        ],
      },
      {
        name: '外卖',
        leaves: [
          { name: '外卖订单', value: 'RestaurantTakeawayOrder/index' },
          { name: '评价管理', value: 'RestaurantTakeawayComment/index' },
          { name: '配送方式', value: 'RestaurantTakeawayFreight/index' },
          { name: '外卖设置', value: 'RestaurantTakeawaySet/index' },
        ],
      },
      {
        name: '点餐',
        leaves: [
          { name: '点餐订单', value: 'RestaurantShopOrder/index' },
          { name: '评价管理', value: 'RestaurantShopComment/index' },
          { name: '点餐设置', value: 'RestaurantShopSet/index' },
        ],
      },
      {
        name: '预定',
        leaves: [
          { name: '预定订单', value: 'RestaurantBookingOrder/index' },
          { name: '预定设置', value: 'RestaurantBookingSet/index' },
        ],
      },
      {
        name: '排队',
        leaves: [
          { name: '排队队列', value: 'RestaurantQueue/index' },
          { name: '队列设置', value: 'RestaurantQueueCategory/index' },
          { name: '排队设置', value: 'RestaurantQueueSet/index' },
        ],
      },
      {
        name: '寄存',
        leaves: [
          { name: '寄存管理', value: 'RestaurantDeposit/index' },
          { name: '添加寄存', value: 'RestaurantDeposit/edit' },
          { name: '寄存设置', value: 'RestaurantDepositSet/index' },
        ],
      },
      {
        name: '餐桌',
        leaves: [
          { name: '餐桌列表', value: 'RestaurantTable/index' },
          { name: '餐桌分类', value: 'RestaurantTableCategory/index' },
        ],
      },
      {
        name: '营销',
        leaves: [
          { name: '优惠券', value: 'RestaurantCoupon/index' },
          { name: '促销', value: 'RestaurantCuxiao/index' },
        ],
      },
      { name: '餐厅区域打印', value: 'RestaurantPrint/index' },
    ],
  },
  {
    name: '设计',
    children: [
      { name: '页面设计', value: 'DesignerPage/index' },
      { name: '底部导航', value: 'DesignerMenu/menu2' },
      { name: '内页导航', value: 'DesignerPage/neiye' },
      { name: '商品详情', value: 'DesignerMenuShopdetail/shopdetail' },
      { name: '分享设置', value: 'DesignerShare/index' },
      { name: '链接地址', value: 'DesignerPage/chooseurl' },
      { name: '页面模板', value: 'DesignerPage/template' },
    ],
  },
  {
    name: '系统',
    children: [
      { name: '系统设置', value: 'Backstage/sysset' },
      { name: '门店管理', value: 'Mendian/index' },
      { name: '管理员列表', value: 'User/index' },
      { name: '配送方式', value: 'Freight/index' },
      { name: '快递设置', value: 'ExpressData/index' },
      { name: '送货单设置', value: 'ShdSet/index' },
      { name: '小票打印机', value: 'Wifiprint/index' },
      { name: '店铺评价', value: 'BusinessComment/index' },
      { name: '操作日志', value: 'Backstage/plog' },
      { name: '快捷菜单', value: 'ShortcutMenu/index' },
      { name: '清理缓存', value: 'Backstage/clearcache' },
      { name: '定制短信通道', value: 'SmsChannel/index' },
    ],
  },
]

// ============================ 手机端权限 ============================

/** 手机端 - 查看权限（wxauth_data） */
export const wxViewOptions: AuthLeaf[] = [
  { name: '会员', value: 'member' },
  { name: '商品', value: 'product' },
  { name: '订单', value: 'order' },
  { name: '财务', value: 'finance' },
  { name: '咨询', value: 'zixun' },
  { name: '首页-店铺二维码', value: 'qrcode_shop' },
]

/** 手机端 - 接收通知权限（notice_auth_data） */
export const noticeOptions: AuthLeaf[] = [
  { name: '订单提交通知', value: 'tmpl_orderconfirm' },
  { name: '订单支付通知', value: 'tmpl_orderpay' },
  { name: '订单收货通知', value: 'tmpl_ordershouhuo' },
  { name: '退款申请通知', value: 'tmpl_ordertui' },
  { name: '提现申请通知', value: 'tmpl_withdraw' },
  { name: '升级申请通知', value: 'tmpl_uplv' },
  { name: '表单提交通知', value: 'tmpl_formsub' },
  { name: '用户咨询通知', value: 'tmpl_kehuzixun' },
  { name: '买单付款通知', value: 'tmpl_maidanpay' },
]

/** 手机端 - 核销权限（hexiao_auth_data） */
export const hexiaoOptions: AuthLeaf[] = [
  { name: '商城订单', value: 'shop' },
  { name: '拼团订单', value: 'collage' },
  { name: '幸运拼团订单', value: 'lucky_collage' },
  { name: '周期购', value: 'cycle' },
  { name: '砍价订单', value: 'kanjia' },
  { name: '秒杀订单', value: 'seckill' },
  { name: '预约订单', value: 'yuyue' },
  { name: '积分兑换', value: 'scoreshop' },
  { name: '优惠券', value: 'coupon' },
  { name: '点餐订单', value: 'restaurant_shop' },
  { name: '外卖订单', value: 'restaurant_takeaway' },
  { name: '团购订单', value: 'tuangou' },
]

// ============================ 工具方法 ============================

/** 收集一个一级分组下全部叶子 value */
export function groupLeafValues(group: AuthGroup): string[] {
  const out: string[] = []
  for (const sub of group.children) {
    if (sub.leaves) {
      for (const leaf of sub.leaves) out.push(leaf.value)
    } else if (sub.value) {
      out.push(sub.value)
    }
  }
  return out
}

/** 收集后台权限树全部叶子 value */
export function allAuthLeafValues(groups: AuthGroup[] = businessAuthGroups): string[] {
  const out: string[] = []
  for (const g of groups) out.push(...groupLeafValues(g))
  return out
}
