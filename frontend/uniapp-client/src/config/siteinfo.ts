/**
 * siteinfo 兜底配置（语义对齐 canzhao/uniapp/siteinfo.js）
 *
 * 用途：小程序未经第三方平台模板发布（本地开发编译、H5）时，
 * uni.getExtConfigSync() 拿不到 ext.json 注入值，由此文件兜底。
 * 不同部署环境可各自修改此文件。
 */
export default {
  /** 租户 ID（uniacid） */
  uniacid: 1,
  /** 后端网关地址（H5 同域部署留空走代理/相对路径） */
  siteroot: "",
};
