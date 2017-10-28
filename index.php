<?php

/**
 * 淘宝工具类
 *
 * Class TB_TOOL
 */
class TB_TOOL
{
    /**
     * 验证淘宝号信息
     */
    public function user_audit()
    {
        // 错误提示输出换行符
        echo "\r\n";

        // 错误提示信息出现时间
        echo '[' . date('Y-m-d H:i:s', time()) . ']';

        // 设置脚本超时时间为30秒
        set_time_limit(30);

        // 获取需要执行的脚本
        $path = __DIR__ . '/tb_tool/get_tb_user_info.js';

        // 转化脚本路径地址符
        $path = str_replace('\\', '/', $path);

        // 连接 Redis 服务
        $redis = new Redis();
        $redis->connect('127.0.0.1');

        // 获取上个或当前还在审核的用户 ID
        $last_user_id = $redis->get('LAST_TB_USER_ID');
        $last_user_id = $last_user_id ? intval($last_user_id) : 0;

        // 获取需手动审核的用户 ID
        $manual_user_id = $redis->get('MANUAL_DEAL_USER_ID');
        $manual_user_id = $manual_user_id ? json_decode($manual_user_id, true) : [];

        // 需要排除自动审核的用户 ID
        $not_user_id = [];

        if ($manual_user_id) {
            $not_user_id = $manual_user_id;
        }

        if ($last_user_id) {
            $not_user_id[] = $last_user_id;
        }

        // 获取一个未审核的试客淘宝账号，进行淘宝等级审核
        if ($not_user_id) {
            $not_user_id = implode(',', $not_user_id);

            $user = $this->db->query("select user_id,taobao_id from `user` WHERE taobao_status=1 AND taobao_id != '' AND user_id NOT IN({$not_user_id}) ORDER BY user_id ASC limit 1")->row_array();
        } else {
            $user = $this->db->query("select user_id,taobao_id from `user` WHERE taobao_status=1 AND taobao_id != '' ORDER BY user_id ASC limit 1")->row_array();
        }

        $last_user_id = $user ? $user['user_id'] : 0;

        // 设置当前审核的用户 ID 到 Redis 中
        $redis->set('LAST_TB_USER_ID', $last_user_id);

        // 没有需要审核的淘宝号则不做处理
        if (!$user) {
            echo '暂无需要审核的淘宝号...';
            $redis->close();
            exit;
        }

        // 写入需要审核的淘宝号到文件中
        file_put_contents(BASEPATH . '../TB_USER_NAME.txt', $user['taobao_id']);

        // 获取淘宝号审核连续失败数，初始失败数为 0
        $fail_num = $redis->get('TB_USER_AUDIT_FAIL_NUM');
        $fail_num = $fail_num ?: 0;

        // 判断是否连续审核出错20次，是则锁定处理
        if (20 <= $fail_num) {
            echo '淘宝号自动审核异常，审核连续出错20次，已封锁自动处理程序...';
            $redis->close();
            exit;
        }

        // 获取淘宝号信息获取连续失败数，初始失败数为 0
        $get_info_fail_num = $redis->get('TB_USER_GET_INFO_FAIL_NUM');
        $get_info_fail_num = $get_info_fail_num ?: 0;

        // 判断是否连续30次没有获取到淘宝号信息，是则锁定处理
        if (30 <= $get_info_fail_num) {
            echo '淘宝号信息获取失败率过高，已封锁自动处理程序...';
            $redis->close();
            exit;
        }

        // 获取淘宝号审核出错人
        $fail_users = $redis->get('TB_USER_AUDIT_FAIL_USER');
        $fail_users = $fail_users ? json_decode($fail_users, true) : [];

        // 定义保存淘宝号信息获取截图目录
        $img_path = __DIR__ . '/data/tb_user_audit';

        // 目录不存在则创建
        is_dir($img_path) or mkdir($img_path, 755, true);

        // 获取用户 ID
        $user_id = $user['user_id'];

        // 获取淘宝 ID
        $user_tb_id = $user['taobao_id'];

        // 获取淘宝号信息
        exec("casperjs {$path}", $output);

        // 获取返回的数据数
        $num = $output ? count($output) : 0;

        // 判断是否获取到淘宝信息
        if (6 != $num) {
            echo "淘宝号信息获取失败[user_id:{$user_id},taobao_id:{$user_tb_id},msg:" . ($output ? json_encode($output) : '') . "]...";
            $redis->set('TB_USER_GET_INFO_FAIL_NUM', $get_info_fail_num + 1);
            $redis->set('TB_USER_GET_INFO_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        // 字符编码转换
        $output = array_map(function ($tmp_output) {
            return $tmp_output ? iconv('gbk', 'utf-8', $tmp_output) : $tmp_output;
        }, $output);

        // 淘宝账号是否存在
        if (strchr($output[0], '不存在')) {
            $this->tb_check_fail($user_id, '您的淘宝账号不存在，请重新换号关联！', $user_tb_id);
            echo "淘宝号不存在[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[0]}]...";
            $redis->set('TB_USER_AUDIT_FAIL_NUM', $fail_num + 1);
            $fail_users[] = ['user_id' => $user_id, 'taobao_id' => $user_tb_id];
            $redis->set('TB_USER_AUDIT_FAIL_USER', json_encode($fail_users));
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        // 判断淘宝号信息获取网站是否异常
        if ($output[0]) {
            $manual_user_id[] = $user_id;
            $redis->set('MANUAL_DEAL_USER_ID', json_encode($manual_user_id));
            echo "该淘宝号无法自动审核，需人工处理[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[0]}]...";
            $redis->close();
            exit;
        }

        // 判断淘宝号是否安全
        if (strchr($output[5], '不安全') || strchr($output[5], '危险') || strchr($output[5], '风险')) {
            $this->tb_check_fail($user_id, '您的淘宝账号存在风险，请关联优质安全的淘宝账号！', $user_tb_id);
            echo "淘宝号存在风险[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[5]}]...";
            $redis->set('TB_USER_AUDIT_FAIL_NUM', $fail_num + 1);
            $fail_users[] = ['user_id' => $user_id, 'taobao_id' => $user_tb_id];
            $redis->set('TB_USER_AUDIT_FAIL_USER', json_encode($fail_users));
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        // 定义需要写入的淘宝信息
        $info = [
            'user_id' => $user_id,
            'tb_user_name' => $user_tb_id,
            'tb_user_from' => $output[3],
            'add_time' => date('Y-m-d H:i:s', time()),
            'tb_user_regtime' => $output[1],
        ];

        // 获取支付宝认证信息
        if (strchr($output[2], '支付宝') && strchr($output[2], '认证') && !strchr($output[2], '暂无')) {
            $info['is_zfb_auth'] = 1;
        } else {
            $this->tb_check_fail($user_id, '您的淘宝账号未通过实名认证；请重新换号关联，淘宝账号必须满足注册时间大于3个月，信誉等级大于等于2星且通过实名认证！', $user_tb_id);
            echo "淘宝号未通过实名认证[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[2]}]...";
            $redis->set('TB_USER_AUDIT_FAIL_NUM', $fail_num + 1);
            $fail_users[] = ['user_id' => $user_id, 'taobao_id' => $user_tb_id];
            $redis->set('TB_USER_AUDIT_FAIL_USER', json_encode($fail_users));
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        // 默认最低信誉等级
        $tb_buyer_level = 0;

        // 获取买家信誉值
        $buyer_level = explode('－', $output[4]);

        $min = $max = 0;

        // 获取信誉等级数
        if ($buyer_level) {
            $min = intval($buyer_level[0]);
            $max = isset($buyer_level[1]) ? intval($buyer_level[1]) : $min;
            $tb_buyer_level = $this->get_tb_buyer_level($min, $max);
        }

        // 判断买家等级是否符合要求
        if ($tb_buyer_level < 2) {
            if (!$output[4] && 0 != $output[4]) {
                echo "买家信誉等级信息获取失败，待重新审核[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[4]},min:{$min},max:{$max}]...";
                $redis->close();
                exit;
            }
            $this->tb_check_fail($user_id, '您的淘宝账号信誉等级不符；请重新换号关联，淘宝账号必须满足注册时间大于3个月，信誉等级大于等于2星且通过实名认证！', $user_tb_id);
            echo "买家信誉等级不符合要求[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$output[4]},min:{$min},max:{$max}]...";
            $redis->set('TB_USER_AUDIT_FAIL_NUM', $fail_num + 1);
            $fail_users[] = ['user_id' => $user_id, 'taobao_id' => $user_tb_id];
            $redis->set('TB_USER_AUDIT_FAIL_USER', json_encode($fail_users));
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        $info['tb_buyer_level'] = $tb_buyer_level;

        // 认证淘宝号注册时间
        if (strtotime('+3 month', strtotime($info['tb_user_regtime'])) > time() || empty($info['tb_user_regtime'])) {
            $this->tb_check_fail($user_id, '您的淘宝账号注册时间不符；请重新换号关联，淘宝账号必须满足注册时间大于3个月，信誉等级大于等于2星且通过实名认证！', $user_tb_id);
            echo "淘宝号注册时间不符合要求[user_id:{$user_id},taobao_id:{$user_tb_id},msg:{$info['tb_user_regtime']}]...";
            $redis->set('TB_USER_AUDIT_FAIL_NUM', $fail_num + 1);
            $fail_users[] = ['user_id' => $user_id, 'taobao_id' => $user_tb_id];
            $redis->set('TB_USER_AUDIT_FAIL_USER', json_encode($fail_users));
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 1);
            $redis->close();
            exit;
        }

        unset($output);

        // 如果淘宝号信息审核通过，则初始审核失败数
        if (0 != $fail_num) {
            $redis->set('TB_USER_AUDIT_FAIL_NUM', 0);
            $redis->set('TB_USER_AUDIT_FAIL_USER', '');
            $redis->set('TB_USER_AUDIT_FAIL_SHOW', 0);
            $redis->set('TB_USER_GET_INFO_FAIL_NUM', 0);
            $redis->set('TB_USER_GET_INFO_FAIL_SHOW', 0);
            $redis->close();
        }

        // 获取试客信息
        $user = $this->db->query("select taobao_status from `user` WHERE user_id={$user_id}")->row_array();

        // 获取当前试客淘宝号审核状态
        $current_taobao_status = $user ? $user['taobao_status'] : 0;

        // 判断是否因为定时任务重叠导致的淘宝号已审核
        if (2 == $current_taobao_status) {
            echo "该淘宝号审核成功且已完成处理，此次审核将忽略[user_id:{$user_id},taobao_id:{$user_tb_id}]...";
            exit;
        }

        // 审核通过处理
        $res = $this->db->update("user", array("taobao_status" => 2), array("user_id" => $user_id));

        // 审核通过后发送短信
        if ($res) {
            $this->db->insert('tb_user_info', $info);

            // 定义需要传递的参数信息
            $param = array('userId' => $user_id, 'app_id' => '201708171700003', 'app_secret' => 'cc40ca12f346c43e7d561e5c993eaa85');

            // 加载 Api 处理程序
            $this->load->helper('APIStore');

            // 发短信只在上线环境中开启，测试环境中不开启，发送提现申请已提现短信
            APIStore($param, true, $this->java_audit_usertb_sendmsg_url);

            echo "淘宝号审核处理成功[user_id:{$user_id},taobao_id:{$user_tb_id}]...";
        } else {
            echo "淘宝号审核处理失败,待重新审核[user_id:{$user_id},taobao_id:{$user_tb_id}]...";
        }
    }

    /**
     * 返回淘宝值对应的等级信息
     *
     * @param $min
     * @param $max
     * @return int
     */
    protected function get_tb_buyer_level($min, $max)
    {
        if ($max < 4) {
            $level = 0;
        } elseif ($min >= 4 && $max <= 10) {
            $level = 1;
        } elseif ($min >= 11 && $max <= 40) {
            $level = 2;
        } elseif ($min >= 41 && $max <= 90) {
            $level = 3;
        } elseif ($min >= 91 && $max <= 150) {
            $level = 4;
        } elseif ($min >= 151 && $max <= 250) {
            $level = 5;
        } elseif ($min >= 251 && $max <= 500) {
            $level = 6;
        } elseif ($min >= 501 && $max <= 1000) {
            $level = 7;
        } elseif ($min >= 1001 && $max <= 2000) {
            $level = 8;
        } elseif ($min >= 2001 && $max <= 5000) {
            $level = 9;
        } elseif ($min >= 5001 && $max <= 10000) {
            $level = 10;
        } elseif ($min >= 10001 && $max <= 20000) {
            $level = 11;
        } elseif ($min >= 20001 && $max <= 50000) {
            $level = 12;
        } elseif ($min >= 50001 && $max <= 100000) {
            $level = 13;
        } elseif ($min >= 100001 && $max <= 200000) {
            $level = 14;
        } elseif ($min >= 200001 && $max <= 500000) {
            $level = 15;
        } elseif ($min >= 500001 && $max <= 1000000) {
            $level = 16;
        } elseif ($min >= 1000001 && $max <= 2000000) {
            $level = 17;
        } elseif ($min >= 2000001 && $max <= 5000000) {
            $level = 18;
        } elseif ($min >= 5000001 && $max <= 10000000) {
            $level = 19;
        } else {
            $level = 20;
        }

        return $level;
    }

    /**
     * 淘宝号审核不通过处理
     *
     * @param $user_id
     * @param $reason
     * @param string $taobao_id
     */
    protected function tb_check_fail($user_id, $reason, $taobao_id = '')
    {
        //事务的手动模式
        $this->db->trans_strict(FALSE);
        $this->db->trans_begin();

        // 获取试客信息
        $user = $this->db->query("select taobao_status from `user` WHERE user_id={$user_id}")->row_array();

        // 获取当前试客淘宝号审核状态
        $current_taobao_status = $user ? $user['taobao_status'] : -1;

        // 判断是否因为定时任务重叠导致的淘宝号已审核
        if (0 == $current_taobao_status) {
            echo "该淘宝号审核失败且已完成处理，此次审核将忽略[user_id:{$user_id},taobao_id:{$taobao_id}]...";
            exit;
        }

        // 更新用户淘宝绑定状态
        $this->db->update("user", array("taobao_status" => 0, 'taobao_id' => ''), array("user_id" => $user_id));

        // 记录驳回记录信息
        $this->db->insert('rollback', [
            'rtype' => 8,
            'order_id' => $user_id,
            'content' => $reason,
            'add_time' => date('Y-m-d H:i:s'),
        ]);

        // 消息标题
        $title = '淘宝帐号审核不通过';

        // 消息内容
        $message = '<p>您的淘宝账号审核未通过，原因如下：</p><p>${reason}</p><p>请按要求重新绑定淘宝账号。</p><p style="text-align: right;">试客巴</p><p style="text-align: right;">${date}</p>';

        // 消息参数
        $params = [
            "reason" => $reason,
            "date" => date('Y年m月d日'),
        ];

        // Json 格式化
        $params = json_encode($params);

        $this->load->model('Message_model');

        // 添加消息信息
        $this->Message_model->addMsgInfo($message, date('Y-m-d H:i:s'), $user_id, 0, 1, $title, $params, 6);

        // 判断添加是否成功
        if ($this->db->trans_status() === TRUE) {
            $this->db->trans_commit();
        } else {
            $this->db->trans_rollback();
            echo "该淘宝号审核失败且处理异常，待重新审核处理[user_id:{$user_id},taobao_id:{$taobao_id}]...";
            exit;
        }
    }
}