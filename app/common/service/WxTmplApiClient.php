<?php

declare(strict_types=1);

namespace app\common\service;

/**
 * 模板消息专用 API 客户端（批次7-3 任务211）
 *
 * 继承 WxApiClient 仅做方法扩展（不改动原文件）：
 * - apiAddTemplateNew：类目模板添加（api_add_template 附 keyword_name_list，
 *   对标 canzhao Mptmpl::gettmplidNew）
 * - templateGetIndustry / templateSetIndustry：模板消息行业设置
 *   （对标 canzhao Mptmpl::mytmpl 的 get_industry / api_set_industry）
 */
class WxTmplApiClient extends WxApiClient
{
    /** 类目模板添加（template_id_short + keyword_name_list，返回 template_id） */
    public function apiAddTemplateNew(string $authorizerAccessToken, string $templateIdShort, array $keywordNameList): array
    {
        $data = ['template_id_short' => $templateIdShort];
        if (!empty($keywordNameList)) {
            $data['keyword_name_list'] = array_values($keywordNameList);
        }

        return $this->postJson('/cgi-bin/template/api_add_template?access_token=' . urlencode($authorizerAccessToken), $data);
    }

    /** 获取已设置的行业信息（get_industry） */
    public function templateGetIndustry(string $authorizerAccessToken): array
    {
        return $this->getJson('/cgi-bin/template/get_industry?access_token=' . urlencode($authorizerAccessToken));
    }

    /** 设置行业（api_set_industry；industry_id1 主营 industry_id2 副营） */
    public function templateSetIndustry(string $authorizerAccessToken, int $industryId1, int $industryId2): array
    {
        return $this->postJson('/cgi-bin/template/api_set_industry?access_token=' . urlencode($authorizerAccessToken), [
            'industry_id1' => $industryId1,
            'industry_id2' => $industryId2,
        ]);
    }
}
