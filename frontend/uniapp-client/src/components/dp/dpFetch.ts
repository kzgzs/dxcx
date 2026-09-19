/**
 * dp 设计器组件统一数据取数（P27-3 任务247）
 * - GET /api/designerComponent/fetch?temp=xx&productfrom=&cid=&group=&ids=&limit=&sortby=
 * - 匿名白名单接口；组件侧仍走静默请求（不用 request 封装），失败不打扰首页
 * - 参数契约与 B 端对齐：productfrom(0手动选品)/cid/group/ids/limit/quanxian/platform
 *   （quanxian/platform 为展示侧过滤，取数不参与）
 */
import { num, str, type DpParams } from "./shared";
import { getApiBase, getTenantId } from "../../config/tenant";
import { useSessionStore } from "../../stores/session";

/** 组件商品/文章数据统一展示结构（后端 designerComponent/fetch 口径） */
export interface DpFetchItem {
  id: number;
  name: string;
  pic: string;
  price: number;
  market_price: number;
  sales: number;
  /** 砍价最低价 */
  min_price?: number;
  /** 积分商城：积分价 */
  score_price?: number;
  /** 积分商城：现金补差 */
  money_price?: number;
  /** 拼团/幸运拼团：成团人数 */
  teamnum?: number;
  /** 课程：学习人数 */
  join_num?: number;
  /** 文章副标题 */
  subname?: string;
  /** 文章阅读量 */
  readcount?: number;
  /** 文章发布时间（秒） */
  createtime?: number;
}

/** 响应信封（仅取所需字段） */
interface FetchEnvelope {
  code: number;
  msg?: string;
  data?: { list?: DpFetchItem[] };
}

/** 设计器静态 data 条目归一化（兼容参考系统旧口径 proid/pic/sell_price） */
export function normalizeFetchItem(raw: unknown): DpFetchItem | null {
  const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
  const id = num(it.id, num(it.proid));
  const name = str(it.name) || str(it.title);
  if (id <= 0 && !name) return null;
  return {
    id,
    name,
    pic: str(it.pic) || str(it.imgurl) || str(it.image),
    price: num(it.price, num(it.sell_price)),
    market_price: num(it.market_price),
    sales: num(it.sales),
    min_price: it.min_price === undefined ? undefined : num(it.min_price),
    score_price: it.score_price === undefined ? undefined : num(it.score_price),
    money_price: it.money_price === undefined ? undefined : num(it.money_price),
    teamnum: it.teamnum === undefined ? undefined : num(it.teamnum),
    join_num: it.join_num === undefined ? undefined : num(it.join_num),
    subname: it.subname === undefined ? undefined : str(it.subname),
    readcount: it.readcount === undefined ? undefined : num(it.readcount),
    createtime: it.createtime === undefined ? undefined : num(it.createtime),
  };
}

/** 从设计器静态 data（手动选品口径 [{proid}]）提取 id 列表 */
export function idsOfStatic(data: unknown): number[] {
  if (!Array.isArray(data)) return [];
  const ids: number[] = [];
  for (const raw of data) {
    const it = (raw && typeof raw === "object" ? raw : {}) as Record<string, unknown>;
    const id = num(it.id, num(it.proid));
    if (id > 0 && !ids.includes(id)) ids.push(id);
  }
  return ids;
}

/**
 * 拉取组件数据（静默，失败返回空数组）
 * @param temp   组件类型（collage/seckill/kanjia/tuangou/scoreshop/luckycollage/yuyue/kecheng/restaurant/article/product）
 * @param params 设计器参数（productfrom/cid/group/ids/limit|proshownum/sortby）
 */
export function fetchDesignerList(temp: string, params: DpParams): Promise<DpFetchItem[]> {
  const query: string[] = ["temp=" + encodeURIComponent(temp)];
  const productfrom = str(params.productfrom, "1");
  query.push("productfrom=" + encodeURIComponent(productfrom));

  const cid = num(params.cid);
  if (cid > 0) query.push("cid=" + cid);

  const group = num(params.group);
  if (group > 0) query.push("group=" + group);

  const ids = str(params.ids);
  if (ids) query.push("ids=" + encodeURIComponent(ids));

  const limit = Math.max(1, Math.min(50, num(params.limit, num(params.proshownum, 6))));
  query.push("limit=" + limit);

  const sortby = str(params.sortby);
  if (sortby) query.push("sortby=" + encodeURIComponent(sortby));

  const session = useSessionStore();
  const header: Record<string, string> = { "X-Tenant-Id": String(getTenantId()) };
  if (session.token) header.Authorization = "Bearer " + session.token;

  return new Promise<DpFetchItem[]>((resolve) => {
    uni.request({
      url: getApiBase() + "/api/designerComponent/fetch?" + query.join("&"),
      method: "GET",
      header,
      timeout: 10000,
      success: (res) => {
        const body = res.data as unknown as FetchEnvelope;
        if (body && body.code === 0 && body.data && Array.isArray(body.data.list)) {
          resolve(body.data.list);
        } else {
          resolve([]);
        }
      },
      fail: () => resolve([]),
    });
  });
}
