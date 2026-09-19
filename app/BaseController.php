<?php
declare (strict_types = 1);

namespace app;

use think\App;
use think\exception\ValidateException;
use think\Validate;
use app\common\service\Result;

/**
 * 控制器基础类
 *
 * 纯 API 项目：控制器统一通过 success()/error() 返回 JSON 响应，
 * 避免依赖客户端 Accept 头判断响应类型
 */
abstract class BaseController
{
    /**
     * Request实例
     * @var \think\Request
     */
    protected $request;

    /**
     * 应用实例
     * @var \think\App
     */
    protected $app;

    /**
     * 是否批量验证
     * @var bool
     */
    protected $batchValidate = false;

    /**
     * 控制器中间件
     * @var array
     */
    protected $middleware = [];

    /**
     * 构造方法
     * @access public
     * @param  App  $app  应用对象
     */
    public function __construct(App $app)
    {
        $this->app     = $app;
        $this->request = $this->app->request;

        // 控制器初始化
        $this->initialize();
    }

    // 初始化
    protected function initialize()
    {}

    /**
     * 成功响应（统一 JSON 结构）
     * @param  mixed  $data 响应数据
     * @param  string $msg  提示信息
     * @return \think\response\Json
     */
    protected function success(mixed $data = [], string $msg = 'success')
    {
        return json(Result::success($data, $msg));
    }

    /**
     * 失败响应（统一 JSON 结构）
     * @param  string $msg  错误信息
     * @param  int    $code 错误码
     * @param  mixed  $data 附加数据
     * @return \think\response\Json
     */
    protected function error(string $msg = 'error', int $code = 1, mixed $data = [])
    {
        return json(Result::error($msg, $code, $data));
    }

    /**
     * 验证数据
     * @access protected
     * @param  array        $data     数据
     * @param  string|array $validate 验证器名或者验证规则数组
     * @param  array        $message  提示信息
     * @param  bool         $batch    是否批量验证
     * @return array|string|true
     * @throws ValidateException
     */
    protected function validate(array $data, string|array $validate, array $message = [], bool $batch = false)
    {
        if (is_array($validate)) {
            $v = new Validate();
            $v->rule($validate);
        } else {
            if (strpos($validate, '.')) {
                // 支持场景
                [$validate, $scene] = explode('.', $validate);
            }
            $class = false !== strpos($validate, '\\') ? $validate : $this->app->parseClass('validate', $validate);
            $v     = new $class();
            if (!empty($scene)) {
                $v->scene($scene);
            }
        }

        $v->message($message);

        // 是否批量验证
        if ($batch || $this->batchValidate) {
            $v->batch(true);
        }

        return $v->failException(true)->check($data);
    }

}
