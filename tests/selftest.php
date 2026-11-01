<?php
/**
 * SmartSpam 纯函数自检脚本（CLI）
 *
 * 用法：php tests/selftest.php
 * 不连接数据库、不发网络请求，只验证内部纯函数的行为。
 */

if (PHP_SAPI !== 'cli') {
    exit('CLI only');
}
if (!defined('__TYPECHO_ROOT_DIR__')) {
    define('__TYPECHO_ROOT_DIR__', dirname(__DIR__));
}
if (!interface_exists('Typecho_Plugin_Interface')) {
    interface Typecho_Plugin_Interface {}
}

require __DIR__ . '/../Plugin.php';

$pass = 0;
$fail = 0;

function check($name, $actual, $expected)
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  ok   %s\n", $name);
    } else {
        $fail++;
        printf("  FAIL %s\n        期望: %s\n        实际: %s\n",
            $name, var_export($expected, true), var_export($actual, true));
    }
}

function call($method)
{
    $args = array_slice(func_get_args(), 1);
    $m = new ReflectionMethod('SmartSpam_Plugin', $method);
    $m->setAccessible(true);
    return $m->invokeArgs(null, $args);
}

echo "== 域名解析 ==\n";
check('parse_hosts 只留合法域名',
    call('parse_hosts', "gravatar.loli.net\n127.0.0.1\n10.0.0.5:8080\nhttps://ok.example.com/a/b\n::1\nevil.com:443\n#c\nbad_host!.com"),
    array('gravatar.loli.net', 'ok.example.com'));
check('url_host 去 www 并取 host', call('url_host', 'https://www.Example.com/a?b=1'), 'example.com');
check('url_host 无协议也能解析', call('url_host', 'example.com/x'), 'example.com');

echo "== IP / 邮箱 ==\n";
check('ip_seg 取 /24', call('ip_seg', '85.90.208.132'), '85.90.208');
check('ip_seg 非法输入返回空', call('ip_seg', 'not-an-ip'), '');
check('mail_domain', call('mail_domain', 'A@QQ.com'), 'qq.com');
check('mask_mail 脱敏', call('mask_mail', 'someone@qq.com'), 's***@qq.com');

echo "== 配置分隔符兼容 ==\n";
check('normalize_list 字面 \\n', call('normalize_list', 'a\\nb'), "a\nb");
check('normalize_list 真实换行', call('normalize_list', "a\r\nb"), "a\nb");
check('check_in 字面 \\n 命中', call('check_in', 'fuck\\n操你妈', '你这操你妈'), true);
check('check_in 真实换行命中', call('check_in', "a\nb", 'xxbxx'), true);
check('check_in 空表不命中', call('check_in', '', 'anything'), false);

echo "== 学习库编解码 ==\n";
$data = array('v' => 1, 'ts' => 1700000000, 'spam' => 3, 'ham' => 9, 'hosts' => array('a.com' => 2));
$enc = call('learn_encode', $data);
check('编码前缀', substr($enc, 0, 3), 'gz:');
check('往返一致', call('learn_decode', $enc), $data);
check('坏数据解码为 null', call('learn_decode', 'gz:not-base64!!'), null);
check('空字符串解码为 null', call('learn_decode', ''), null);

echo "== 维度阈值 ==\n";
check('邮箱域名门槛 3', call('learn_dim_min', 'mail_domains'), 3);
check('IP 段门槛 3', call('learn_dim_min', 'ips'), 3);
check('精确邮箱门槛 1', call('learn_dim_min', 'mails'), 1);

echo "== 代理 IP 还原 ==\n";
$_SERVER['HTTP_X_REAL_IP'] = '8.8.8.8';
check('X-Real-IP', call('proxy_real_ip', '2'), '8.8.8.8');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '1.1.1.1, 2.2.2.2';
check('XFF 取最右侧（防伪造）', call('proxy_real_ip', '1'), '2.2.2.2');
$_SERVER['HTTP_X_FORWARDED_FOR'] = 'spoofed, 3.3.3.3';
check('XFF 跳过非法项', call('proxy_real_ip', '1'), '3.3.3.3');
$_SERVER['HTTP_X_FORWARDED_FOR'] = '';
check('XFF 为空返回空串', call('proxy_real_ip', '1'), '');
$_SERVER['HTTP_X_REAL_IP'] = 'not-an-ip';
check('X-Real-IP 非法返回空串', call('proxy_real_ip', '2'), '');
unset($_SERVER['HTTP_X_REAL_IP'], $_SERVER['HTTP_X_FORWARDED_FOR']);

echo "\n通过 $pass 项，失败 $fail 项\n";
exit($fail === 0 ? 0 : 1);
