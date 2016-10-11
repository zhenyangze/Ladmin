<?php

namespace App\Http\Controllers;

use EasyWeChat\Foundation\Application;
use EasyWeChat\Payment\Order;
use App\Repositories\OrderRepository;

class WechatController extends Controller
{

    /**
     * 图灵api地址
     * @var string
     */
    protected $api;
    private $key;

    public function __construct() 
    {
        $this->middleware("wechat.oauth",['only'=>'pay']);
        $this->api = env('TULING_API');
        $this->key = env('TULING_KEY');
    }


    

    /**
     * 处理微信的请求消息
     *
     * @return string
     */
    public function serve()
    {
        $wechat = app('wechat');
        $user = $wechat->user;
        $wechat->server->setMessageHandler(function($message) use ($user) {
              $fromUser = $user->get($message->FromUserName);
               if ($message->MsgType == 'event') {

                    switch ($message->Event) {
                        case 'subscribe':
                            return "{$fromUser->nickname} 欢迎关注 iadmin";
                            break;
                         case 'unsubscribe':
                            return "{$fromUser->nickname} 走好不送";
                        break;
                        default:
                            break;
                    }
                }
                if($message->MsgType == 'text') {
                     $post_data = json_encode(['key'=>$this->key,'info'=>$message->Content]);
                     $content = json_decode(curlRequest($this->api,$post_data),true);
                     return $content['text'];

                }

        });

        return $wechat->server->serve();
    }

    public function pay()
    {

    
        $options = [

            'app_id' => env('WECHAT_APPID'),
            // payment
            'payment' => [
                'merchant_id'        => env('WECHAT_PAYMENT_MERCHANT_ID'),
                'key'                => env('WECHAT_PAYMENT_KEY'),
                'cert_path'          => env('WECHAT_PAYMENT_CERT_PATH'), // XXX: 绝对路径！！！！
                'key_path'           => env('WECHAT_PAYMENT_KEY_PATH'),  // XXX: 绝对路径！！！！
                'notify_url'         => env('WECHAT_PAYMENT_NOTIFY_URL'),// 你也可以在下单时单独设置来想覆盖它
                'device_info'     => env('WECHAT_PAYMENT_DEVICE_INFO'),
            ],
        ];

       $user =  $user = session('wechat.oauth_user');

        $app = new Application($options);
     
        $payment = $app->payment;
        $order_number = date("YmdHis");

        $goods_name = request("goods_name","iPad mini 16G 白色");
        $detail = request("detail","iPad mini 16G 白色");
        $price = request("price",1);
        $company_name = request('company_name',"腾讯科技有限公司");
        //创建订单
        $attributes = [
            'body'             => $goods_name,
            'detail'           => $detail,
            'out_trade_no'     => $order_number,
            'total_fee'        => $price,
            'trade_type'       =>"JSAPI",
            'openid' => $user->id,
        ];

        $order = new Order($attributes);



        $result = $payment->prepare($order);
        
        $prepayId = $result->prepay_id;

        //创建数据库订单
        $data = [
            'goods_name' => $goods_name,
            'price' => $price,
             'order_number' => $order_number,
             'transaction_id' => $prepayId,
             'status' => 0,
            'openid' => $user->id,
        ];

        $orderRepository = new OrderRepository();
        $orderRepository->store($data);

        $json = $payment->configForPayment($prepayId);

        return view("web.wechat.pay",compact('json','goods_name','price','company_name'));
    }


    /**
     * 微信支付回调
     * @itas
     * @DateTime 2016-10-11
     * @return   function   [description]
     */
    public function callback()
    {
        $response = $app->payment->handleNotify(function($notify, $successful){
            $orderRepository = new OrderRepository();
            $order = $orderRepository->findOrderByTransId($notify->transaction_id);

            if (!$order) { // 如果订单不存在
                return 'Order not exist.'; // 告诉微信，我已经处理完了，订单没找到，别再通知我了
            }

            // 如果订单存在
            // 检查订单是否已经更新过支付状态
            if ($order->pay_at) { // 假设订单字段“支付时间”不为空代表已经支付
                return true; // 已经支付成功了就不再更新了
            }

            // 用户是否支付成功
            if ($successful) {
                // 不是已经支付状态则修改为已经支付状态
                $order->pay_at = time(); // 更新支付时间为当前时间
                $order->status = 1;
            } else { // 用户支付失败
                $order->status = -1;
            }

            $order->save(); // 保存订单

            return true; // 返回处理完成
        });

        return $response;
    }


   

}