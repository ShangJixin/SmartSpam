<?php
/**
 * 智能评论过滤器，让机器人彻底远离你！
 * 
 * @package SmartSpam
 * @author YoviSun
 * @version 2.10.0-beta.1
 * @link http://www.yovisun.com
 *
 * 历史版本
 * 
 * version 2.10.0-beta.1 at 2026-11-02
 * 统一加权判定：行为特征与历史样本合并为一条规则（单一阈值 + 双信号开关），
 * 样本学习支持 URL 域名 / IP 段 / 邮箱 / 昵称 / 内容近似重复，并提供学习时间窗与条目上限；
 * 新增博主评论豁免、代理 IP 还原（CDN/反代）、调试日志；移除手工广告关键词配置；
 * 安全加固：头像源 SSRF 收敛、全局 fail-open、重建加锁。by 尚寂新
 * 弱信号来源：无 Gravatar 头像、保留邮箱域名、同域名重复推广；头像检测支持多镜像按序降级，
 * 结果本地缓存 7 天并记住上次可用镜像；check_in / check_ip 兼容真实换行与字面 \n 两种存量格式。by 尚寂新
 * 
 * version 2.8.2 at 2024-09-19
 * 解决高版本php8.2报错strpos(): Passing null to parameter #1 ($haystack) of type string is deprecated的问题。by 泽泽
 * 
 * version 2.8.1 at 2023-12-16
 * 修复当禁止IP列表里出现空行，会导致所有IP都被报告为禁止IP；修复当禁止词汇列表里出现空行，会导致所有内容都被报告为禁止词汇
 * version 2.8.0 at 2023-12-16
 * 修复“不设置某项拦截项，而导致该项审核无法通过”的问题。by 尚寂新
 * version 2.7.0 at 2021-03-08
 * 添加对游客评论的处理；新增部分代码提升同接口插件间的兼容性。 by 泽泽
 * version 2.6.0 at 2014-10-18
 * 添加对网址的检测
 * version 2.5.0 at 2014-08-30
 * 添加检测评论内容中是否包含文章标题
 * version 2.4.0 at 2014-08-27
 * 添加对于昵称关键词的检测，若昵称中含有某关键词，则评论失败
 * 添加对于邮箱地址的检测
 * version 2.3.0 at 2013-12-18
 * 添加对于昵称的长度检测，昵称的日文检测，网址判断操作
 * version 2.2.1 at 2013-12-04
 * 修改开启插件的默认选项为评论失败
 * version 2.2.0 at 2013-12-01
 * 添加禁止日文昵称的检测 by www.yovisun.com
 * version 2.1.0 at 2013-11-06
 * 添加禁止日文评论的检测 by www.yovisun.com
 * version 2.0.0 at 2013-06-02
 * 添加评论字符长度的检测 by www.yovisun.com
 * version 1.0.2 at 2010-05-16
 * 修正发表评论成功后，评论内容Cookie不清空的Bug
 * version 1.0.1 at 2009-11-29
 * 增加IP段过滤功能
 * version 1.0.0 at 2009-11-14
 * 实现评论内容按屏蔽词过滤功能
 * 实现过滤非主文评论功能
 *
 */

class SmartSpam_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     * 
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {    
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('SmartSpam_Plugin', 'filter');
		return _t('评论过滤器启用成功，请配置需要过滤的内容');
    }
    
    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     * 
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        // 禁用时不清理任何数据：学习库保留
    }
    
    /**
     * 获取插件配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
	{
	    // 博主评论直接放行
	    $bypass_owner = new Typecho_Widget_Helper_Form_Element_Radio('bypass_owner', array(
	        "1" => "开启（博主评论直接放行）",
	        "0" => "关闭（博主评论也走规则）"
	    ), "1", _t('博主评论无视所有规则'), "开启后，已登录管理员的评论不经过任何过滤直接通过");
	    $form->addInput($bypass_owner);

	    // 真实 IP 来源
	    $proxy_ip = new Typecho_Widget_Helper_Form_Element_Radio('proxy_ip', array(
	        "0" => "不信任代理头（默认，直连站点）",
	        "1" => "信任 X-Forwarded-For（CDN / 反代）",
	        "2" => "信任 X-Real-IP（Nginx 反代）"
	    ), "0", _t('访客真实 IP 来源'), "只有在站点确实位于可信的 CDN / 反向代理之后才开启；否则客户端可伪造该头绕过 IP 规则");
	    $form->addInput($proxy_ip);

	    // 管理动作：必须带有效 token，避免 GET 型 CSRF
	    $actionMsg = '';
	    $secToken = '';
	    try {
	        $sec = Typecho_Widget::widget('Widget_Security');
	        if ($sec) { $secToken = (string)$sec->getToken('smartspam'); }
	    } catch (Exception $e) {
	        $secToken = '';
	    }
	    $req = array_merge($_GET, $_POST);   // POST 优先
	    $validToken = ($secToken !== '' && isset($req['_']) && hash_equals($secToken, (string)$req['_']));

	    if ($validToken && isset($req['smartspam_learn']) && $req['smartspam_learn'] === '1') {
	        $actionMsg = self::learn_force_rebuild() ? '已按当前评论数据重新学习，预览已更新。' : '重新学习失败，请稍后再试。';
	    } elseif ($validToken && isset($req['smartspam_clear']) && $req['smartspam_clear'] === '1') {
	        $actionMsg = self::learn_reset() ? '学习库已清空，将在下一条评论提交时重建。' : '清空失败。';
	    }

	    self::add_section($form, '① 直接拦截规则',
	        '命中即按各自「操作」执行，不参与打分；一旦某条命中，后面的加权规则不会再运行。', '#d9534f');

	    $opt_visitor = new Typecho_Widget_Helper_Form_Element_Radio('opt_visitor', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "none",
			_t('游客评论'), "如果评论发布者的未登录网站以游客身份评论，将执行该操作");
        $form->addInput($opt_visitor);
	    
	    
	    
        $opt_ip = new Typecho_Widget_Helper_Form_Element_Radio('opt_ip', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('屏蔽IP操作'), "如果评论发布者的IP在屏蔽IP段，将执行该操作");
        $form->addInput($opt_ip);
        $words_ip = new Typecho_Widget_Helper_Form_Element_Textarea('words_ip', NULL, "0.0.0.0",
			_t('屏蔽IP'), _t('多条IP请用换行符隔开<br />支持用*号匹配IP段，如：192.168.*.*'));
        $form->addInput($words_ip);
         
        
        $opt_mail = new Typecho_Widget_Helper_Form_Element_Radio('opt_mail', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('屏蔽邮箱操作'), "如果评论发布者的邮箱与禁止的一致，将执行该操作");
        $form->addInput($opt_mail);
        $words_mail = new Typecho_Widget_Helper_Form_Element_Textarea('words_mail', NULL, "",
			_t('邮箱关键词'), _t('多个邮箱请用换行符隔开<br />可以是邮箱的全部，或者邮箱部分关键词'));
        $form->addInput($words_mail);        
        
        
        
        $opt_url = new Typecho_Widget_Helper_Form_Element_Radio('opt_url', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('屏蔽网址操作'), "如果评论发布者的网址与禁止的一致，将执行该操作。如果网址为空，该项不会起作用。");
        $form->addInput($opt_url);
        $words_url = new Typecho_Widget_Helper_Form_Element_Textarea('words_url', NULL, "",
			_t('网址关键词'), _t('多个网址请用换行符隔开<br />可以是网址的全部，或者网址部分关键词。如果网址为空，该项不会起作用。'));
        $form->addInput($words_url);
        
        
        $opt_title = new Typecho_Widget_Helper_Form_Element_Radio('opt_title', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('内容含有文章标题'), "如果评论内容中含有本页面的文章标题，则强行按该操作执行");
        $form->addInput($opt_title);
        
        
        
        $opt_au = new Typecho_Widget_Helper_Form_Element_Radio('opt_au', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('屏蔽昵称关键词操作'), "如果评论发布者的昵称含有该关键词，将执行该操作");
        $form->addInput($opt_au);

        $words_au = new Typecho_Widget_Helper_Form_Element_Textarea('words_au', NULL, "",
			_t('屏蔽昵称关键词'), _t('多个关键词请用换行符隔开'));
        $form->addInput($words_au);
        
        
        
        $au_length_min = new Typecho_Widget_Helper_Form_Element_Text('au_length_min', NULL, '1', '昵称最短字符数', '昵称允许的最短字符数。');
        $au_length_min->input->setAttribute('class', 'mini');
        $form->addInput($au_length_min);
        $au_length_max = new Typecho_Widget_Helper_Form_Element_Text('au_length_max', NULL, '15', '昵称最长字符数', '昵称允许的最长字符数');
        $au_length_max->input->setAttribute('class', 'mini');
        $form->addInput($au_length_max);
        $opt_au_length = new Typecho_Widget_Helper_Form_Element_Radio('opt_au_length', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('昵称字符长度操作'), "如果昵称长度不符合条件，则强行按该操作执行。如果选择[无动作]，将忽略下面长度的设置");
        $form->addInput($opt_au_length);   
        
        
        
        
        $opt_nojp_au = new Typecho_Widget_Helper_Form_Element_Radio('opt_nojp_au', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('昵称日文操作'), "如果用户昵称中包含日文，则强行按该操作执行");
        $form->addInput($opt_nojp_au);
        
        $opt_nourl_au = new Typecho_Widget_Helper_Form_Element_Radio('opt_nourl_au', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('昵称网址操作'), "如果用户昵称是网址，则强行按该操作执行");
        $form->addInput($opt_nourl_au);
        

        
        $opt_nojp = new Typecho_Widget_Helper_Form_Element_Radio('opt_nojp', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('日文评论操作'), "如果评论中包含日文，则强行按该操作执行");
        $form->addInput($opt_nojp);
        
       
        
        
        $opt_nocn = new Typecho_Widget_Helper_Form_Element_Radio('opt_nocn', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('非中文评论操作'), "如果评论中不包含中文，则强行按该操作执行");
        $form->addInput($opt_nocn);
        
        
        $length_min = new Typecho_Widget_Helper_Form_Element_Text('length_min', NULL, '5', '评论最短字符数', '允许评论的最短字符数。');
        $length_min->input->setAttribute('class', 'mini');
        $form->addInput($length_min);
        $length_max = new Typecho_Widget_Helper_Form_Element_Text('length_max', NULL, '200', '评论最长字符数', '允许评论的最长字符数');
        $length_max->input->setAttribute('class', 'mini');
        $form->addInput($length_max);
        $opt_length = new Typecho_Widget_Helper_Form_Element_Radio('opt_length', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('评论字符长度操作'), "如果评论中长度不符合条件，则强行按该操作执行。如果选择[无动作]，将忽略下面长度的设置");
        $form->addInput($opt_length);        
        

        $opt_ban = new Typecho_Widget_Helper_Form_Element_Radio('opt_ban', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('禁止词汇操作'), "如果评论中包含禁止词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_ban);

        $words_ban = new Typecho_Widget_Helper_Form_Element_Textarea('words_ban', NULL, "fuck\n操你妈\n[url\n[/url]",
			_t('禁止词汇'), _t('多条词汇请用换行符隔开'));
        $form->addInput($words_ban);

        $opt_chk = new Typecho_Widget_Helper_Form_Element_Radio('opt_chk', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "abandon",
			_t('敏感词汇操作'), "如果评论中包含敏感词汇列表中的词汇，将执行该操作");
        $form->addInput($opt_chk);

        $words_chk = new Typecho_Widget_Helper_Form_Element_Textarea('words_chk', NULL, "http://",
			_t('敏感词汇'), _t('多条词汇请用换行符隔开<br />注意：如果词汇同时出现于禁止词汇，则执行禁止词汇操作'));
        $form->addInput($words_chk);

        self::add_section($form, '② 加权判定规则',
            '多个弱信号累计打分，达到阈值才执行；只有前面的直接规则全部未命中时才会运行。', '#337ab7');

        // ==================== 统一加权判定 ====================
        $opt_weight = new Typecho_Widget_Helper_Form_Element_Radio('opt_weight', array("none" => "无动作", "waiting" => "标记为待审核", "spam" => "标记为垃圾", "abandon" => "评论失败"), "spam",
			_t('加权判定操作'), "「行为特征」与「历史样本」的弱信号累计成总分，达到阈值时执行该操作");
        $form->addInput($opt_weight);

        $weight_threshold = new Typecho_Widget_Helper_Form_Element_Text('weight_threshold', NULL, '8', '加权判定阈值', '各信号累计达到该分值才触发；合并后建议 8（原两条规则各为 6）');
        $weight_threshold->input->setAttribute('class', 'mini');
        $form->addInput($weight_threshold);

        $weight_sources = new Typecho_Widget_Helper_Form_Element_Checkbox('weight_sources', array(
            'score' => '行为特征（保留邮箱域名 / 无头像 / 同域垃圾）',
            'learn' => '历史样本（域名 / IP段 / 邮箱 / 昵称 / 内容近似）'
        ), array('score', 'learn'), '参与判定的信号', '至少选一项；取消勾选的信号不参与累计');
        $form->addInput($weight_sources);


        $gravatar_hosts = new Typecho_Widget_Helper_Form_Element_Textarea('gravatar_hosts', NULL, "gravatar.loli.net\nsdn.geekzu.org\ncravatar.cn\nwww.gravatar.com",
			_t('头像镜像源'), _t('每行一个，按顺序尝试，任一可用即可（无头像 +3）；全部失败时不做扣分判断。留空则不检测头像'));
        $form->addInput($gravatar_hosts);

        self::add_section($form, '历史样本参数',
            '从已标记的垃圾评论中学习重复特征（URL 域名 / IP 段 / 邮箱 / 昵称 / 内容近似重复），结果参与上面的加权判定；纯本地学习，不联网、不上传、不共享。', '#5cb85c');

        // ==================== 样本学习（子参数） ====================

        $learn_min_count = new Typecho_Widget_Helper_Form_Element_Text('learn_min_count', NULL, '2', '样本最少出现次数', '某特征至少在该数量的垃圾评论中出现过、且从未出现在正常评论中，才会被学习');
        $learn_min_count->input->setAttribute('class', 'mini');
        $form->addInput($learn_min_count);
        $learn_ip = new Typecho_Widget_Helper_Form_Element_Radio('learn_ip', array(
            "1" => "参与（默认）",
            "0" => "不参与"
        ), "1", _t('IP 段参与学习'), "站点使用 CDN 或反向代理时建议关闭：否则会把 CDN 节点 IP 段学成垃圾段，误伤同节点的正常访客");
        $form->addInput($learn_ip);

        $learn_max_items = new Typecho_Widget_Helper_Form_Element_Text('learn_max_items', NULL, '200', '学习条目上限', '每个维度（域名 / IP段 / 邮箱 / 昵称）按出现次数取前 N 条保留，越大越准但越慢；建议 100~300');
        $learn_max_items->input->setAttribute('class', 'mini');
        $form->addInput($learn_max_items);

        $learn_max_age = new Typecho_Widget_Helper_Form_Element_Text('learn_max_age', NULL, '0', '学习时间窗（天）', '只学习最近 N 天的垃圾评论，0 表示不限；正常评论始终全量参与排除。低频站点建议 0，高频站点建议 365~730');
        $learn_max_age->input->setAttribute('class', 'mini');
        $form->addInput($learn_max_age);

        $debug_log = new Typecho_Widget_Helper_Form_Element_Radio('debug_log', array(
            "0" => "关闭（默认）",
            "1" => "开启"
        ), "0", _t('调试日志'), "开启后把每次判定结果写入 PHP 错误日志（只记录动作、分值与规则名，不含邮箱、IP、昵称、评论内容）");
        $form->addInput($debug_log);

        // 只读预览 + 管理按钮（均带 token）
        $self = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        $self = preg_replace('/[&?](smartspam_learn|smartspam_clear|_)=[^&]*/', '', $self);
        $sep = (strpos($self, '?') === false) ? '?' : '&';
        $learnUrl = htmlspecialchars($self . $sep . 'smartspam_learn=1&_=' . urlencode($secToken), ENT_QUOTES, 'UTF-8');
        $clearUrl = htmlspecialchars($self . $sep . 'smartspam_clear=1&_=' . urlencode($secToken), ENT_QUOTES, 'UTF-8');

        $box = new Typecho_Widget_Helper_Layout('div', array(
            'style' => 'margin:18px 0 6px;padding:10px 14px;background:#fcfcfc;border:1px solid #e5e5e5;border-radius:3px;'
        ));
        $box->html(
            '<strong style="font-size:13px;">当前学习库</strong>'
            . ($actionMsg !== '' ? '<div style="color:#3c763d;margin-top:4px;">' . htmlspecialchars($actionMsg, ENT_QUOTES, 'UTF-8') . '</div>' : '')
            . '<div style="margin-top:6px;color:#555;font-size:12px;line-height:1.8;">' . self::learn_preview_html() . '</div>'
            . '<div style="margin-top:10px;">'
            . '<button type="submit" class="btn btn-s" formaction="' . $learnUrl . '" formmethod="post" onclick="return confirm(\'将按当前评论数据重新学习，确定继续？\');">立即学习</button>'
            . '<button type="submit" class="btn btn-s" style="margin-left:8px;" formaction="' . $clearUrl . '" formmethod="post" onclick="return confirm(\'清空学习库？下一条评论提交时会自动重建。\');">清空</button>'
            . '</div>'
        );
        $form->addItem($box);

        // 兼容：历史上保存过、但新表单已移除的配置键。
        // Typecho 核心会用这些键回填表单（getInput($key)->value()），
        // 找不到对应 input 会抛致命错误导致后台 500，这里补隐藏占位。
        $saved = self::all_settings();
        $current = $form->getInputs();
        foreach (array_keys($saved) as $legacyKey) {
            if (!array_key_exists($legacyKey, $current)) {
                $val = $saved[$legacyKey];
                $form->addInput(new Typecho_Widget_Helper_Form_Element_Hidden(
                    $legacyKey, null, is_scalar($val) ? (string)$val : ''
                ));
            }
        }
	}
    
    /**
     * 个人用户的配置面板
     * 
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    /**
     * 在配置页插入分组标题（纯展示，不参与提交）
     */
    private static function add_section(Typecho_Widget_Helper_Form $form, $title, $desc, $color)
    {
        $box = new Typecho_Widget_Helper_Layout('div', array(
            'style' => 'margin:26px 0 8px;padding:10px 14px;background:#f7f9fb;border-left:4px solid '
                . $color . ';border-radius:3px;'
        ));
        $box->html(
            '<strong style="font-size:14px;color:#333;">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</strong>'
            . '<div style="margin-top:4px;color:#777;font-size:12px;line-height:1.7;">'
            . htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') . '</div>'
        );
        $form->addItem($box);
    }

    /**
     * 立即学习：同步按当前评论数据重建学习库
     */
    private static function learn_force_rebuild()
    {
        try {
            $cap = intval(self::get_setting('learn_max_items', 200));
            if ($cap < 20) { $cap = 20; }
            if ($cap > 1000) { $cap = 1000; }
            self::learn_rebuild($cap);
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 读取插件已保存的全部配置项
     */
    private static function all_settings()
    {
        try {
            $db = Typecho_Db::get();
            $row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', 'plugin:SmartSpam'));
            if (!empty($row['value'])) {
                $v = json_decode($row['value'], true);
                if (is_array($v)) { return $v; }
            }
        } catch (Exception $e) {
            // 读取失败返回空
        }
        return array();
    }

    /**
     * 读取插件已保存的配置项（不依赖 Widget_Options）
     */
    private static function get_setting($key, $default = '')
    {
        $v = self::all_settings();
        return isset($v[$key]) ? $v[$key] : $default;
    }

    /**
     * 清空学习库缓存（配置行保留，下次评论时自动重建）
     */
    private static function learn_reset()
    {
        // 不删除数据行，只把值清空；空值会被视为无效，下一条评论提交时自动重建
        try {
            $db = Typecho_Db::get();
            $db->query($db->update('table.options')->rows(array('value' => ''))
                ->where('name = ?', self::LEARN_OPTION));
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * 学习库只读预览（不触发重建，不写入任何数据）
     */
    private static function learn_preview_html()
    {
        $raw = self::learn_db_read();
        $L = self::learn_decode($raw);
        if (!is_array($L) || empty($L['v'])) {
            return '尚未建立（提交一条评论后自动生成）';
        }

        $lines = array();
        $lines[] = '生成时间：' . date('Y-m-d H:i:s', (int)$L['ts'])
            . '（' . self::human_age(time() - (int)$L['ts']) . '前）';
        $lines[] = '样本：垃圾 ' . (int)$L['spam'] . ' 条 / 正常 ' . (int)$L['ham'] . ' 条';

        $map = array(
            'hosts' => '垃圾域名', 'ips' => '垃圾IP段', 'mails' => '垃圾邮箱',
            'mail_domains' => '垃圾邮箱域名', 'authors' => '垃圾昵称'
        );
        foreach ($map as $k => $label) {
            $arr = (isset($L[$k]) && is_array($L[$k])) ? $L[$k] : array();
            $n = count($arr);
            $sample = '';
            if ($n > 0) {
                $keys = array_slice(array_keys($arr), 0, 3);
                if ($k === 'mails') {
                    $masked = array();
                    foreach ($keys as $m) { $masked[] = self::mask_mail($m); }
                    $keys = $masked;
                }
                $sample = '（例：' . implode('、', $keys) . '）';
            }
            $lines[] = $label . '：' . $n . ' 条' . $sample;
        }
        $lines[] = '近似重复样本：' . (isset($L['texts']) ? count($L['texts']) : 0) . ' 条';
        $lines[] = '存储大小：' . strlen($raw) . ' 字节';

        $out = array();
        foreach ($lines as $s) { $out[] = htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
        return implode('<br>', $out);
    }

    private static function mask_mail($mail)
    {
        $mail = (string)$mail;
        $p = strpos($mail, '@');
        if ($p === false) { return $mail === '' ? '' : substr($mail, 0, 1) . '***'; }
        $name = substr($mail, 0, $p);
        return (strlen($name) > 1 ? substr($name, 0, 1) . '***' : '***') . '@' . substr($mail, $p + 1);
    }

    private static function human_age($sec)
    {
        $sec = max(0, (int)$sec);
        if ($sec < 60) { return $sec . ' 秒'; }
        if ($sec < 3600) { return floor($sec / 60) . ' 分钟'; }
        if ($sec < 86400) { return floor($sec / 3600) . ' 小时'; }
        return floor($sec / 86400) . ' 天';
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 评论过滤器
     * 
     */
    public static function filter($comments, $post, $last)
    {
        try {
            return self::filter_impl($comments, $post, $last);
        } catch (Typecho_Widget_Exception $e) {
            throw $e;   // 主动拦截（评论失败），继续抛给 Typecho
        } catch (Throwable $e) {
            // 任何未预期错误都 fail-open：绝不因为插件问题让评论提交 500
            $comment = empty($last) ? $comments : $last;
            try { Typecho_Cookie::delete('__typecho_remember_text'); } catch (Throwable $e2) {}
            return $comment;
        }
    }

    private static function filter_impl($comments, $post, $last)
    {
        $comment = empty($last)?$comments:$last;  //提升同接口插件间的兼容性
        $options = Typecho_Widget::widget('Widget_Options');
        $user = Typecho_Widget::widget('Widget_User');
		$filter_set = $options->plugin('SmartSpam');
		$opt = "none";
		$error = "";

		//博主评论无视所有规则（仅对已登录的管理员生效；配置缺失时默认开启）
		$bypassOwner = !isset($filter_set->bypass_owner) ? '1' : (string)$filter_set->bypass_owner;
		if ($bypassOwner !== '0') {
			try {
				if ($user && $user->hasLogin() && $user->pass('administrator', true)) {
					Typecho_Cookie::delete('__typecho_remember_text');
					return $comment;
				}
			} catch (Exception $e) {
				// 判断失败则继续走规则
			}
		}

		//真实 IP：站点位于 CDN / 反向代理之后时，用代理头还原访客 IP（同时影响入库值）
		$proxyMode = isset($filter_set->proxy_ip) ? (string)$filter_set->proxy_ip : '0';
		if ($proxyMode === '1' || $proxyMode === '2') {
			$realIp = self::proxy_real_ip($proxyMode);
			if ($realIp !== '') { $comment['ip'] = $realIp; }
		}
        

		//游客进行评论进行权限处理
		if($opt == "none" && $filter_set->opt_visitor != "none" && !$user->hasLogin()){
			 $error = "对不起，本站暂时禁止游客进行评论！";
			 $opt = $filter_set->opt_visitor;
		}

        //屏蔽评论内容包含文章标题
		if ($opt == "none" && $filter_set->opt_title != "none") {
			 $db = Typecho_Db::get();
            // 获取评论所在文章
            $po = $db->fetchRow($db->select('title')->from('table.contents')->where('cid = ?', $comment['cid']));
            // 文章可能已删除：取不到标题时跳过该规则，避免 strstr($text, '') 恒为真导致全部拦截
            $postTitle = (is_array($po) && isset($po['title'])) ? trim((string)$po['title']) : '';
            if ($postTitle !== '' && false !== strstr((string)$comment['text'], $postTitle)) {
                $error = "对不起，评论内容不允许包含文章标题";
				$opt = $filter_set->opt_title;
            }        
		}
        

		//屏蔽IP段处理
		if ($opt == "none" && $filter_set->opt_ip != "none") {
			if (SmartSpam_Plugin::check_ip($filter_set->words_ip, $comment['ip'])) {
				$error = "评论发布者的IP已被管理员屏蔽";
				$opt = $filter_set->opt_ip;
			}			
		}       
        
        
        //屏蔽邮箱处理
		if ($opt == "none" && $filter_set->opt_mail != "none") {
			if (SmartSpam_Plugin::check_in($filter_set->words_mail, $comment['mail'])) {
				$error = "评论发布者的邮箱地址被管理员屏蔽";
				$opt = $filter_set->opt_mail;
			}			
		}  
        
        //屏蔽网址处理
        if(!empty($filter_set->words_url)){
            if ($opt == "none" && $filter_set->opt_url != "none") {
                if (SmartSpam_Plugin::check_in($filter_set->words_url, $comment['url'])) {
                    $error = "评论发布者的网址被管理员屏蔽";
                    $opt = $filter_set->opt_url;
                }			
            }
        }        
        
        
        //屏蔽昵称关键词处理
		if ($opt == "none" && $filter_set->opt_au != "none") {
			if (SmartSpam_Plugin::check_in($filter_set->words_au, $comment['author'])) {
				$error = "对不起，昵称的部分字符已经被管理员屏蔽，请更换";
				$opt = $filter_set->opt_au;
			}			
		}
        
        
        //日文评论处理
		if ($opt == "none" && $filter_set->opt_nojp != "none") {
			if (preg_match("/[\x{3040}-\x{31ff}]/u", $comment['text']) > 0) {
				$error = "禁止使用日文";
				$opt = $filter_set->opt_nojp;
			}
		}
        
        
        //日文用户昵称处理
		if ($opt == "none" && $filter_set->opt_nojp_au != "none") {
			if (preg_match("/[\x{3040}-\x{31ff}]/u", $comment['author']) > 0) {
				$error = "用户昵称禁止使用日文";
				$opt = $filter_set->opt_nojp_au;
			}
		}
        
        
        //昵称长度检测
		if ($opt == "none" && $filter_set->opt_au_length != "none") {            
            if(SmartSpam_Plugin::strLength($comment['author']) < $filter_set->au_length_min){           	
           		$error = "昵称请不得少于".$filter_set->au_length_min."个字符";
				$opt = $filter_set->opt_au_length;
            }else 
            if(SmartSpam_Plugin::strLength($comment['author']) >  $filter_set->au_length_max){           	
            	$error = "昵称请不得多于".$filter_set->au_length_max."个字符";
				$opt = $filter_set->opt_au_length;
            }
             
		}
        
        //用户昵称网址判断处理
		if ($opt == "none" && $filter_set->opt_nourl_au != "none") {
            if (preg_match(" /^((https?|ftp|news):\/\/)?([a-z]([a-z0-9\-]*[\.。])+([a-z]{2}|aero|arpa|biz|com|coop|edu|gov|info|int|jobs|mil|museum|name|nato|net|org|pro|travel)|(([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5])\.){3}([0-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-5]))(\/[a-z0-9_\-\.~]+)*(\/([a-z0-9_\-\.]*)(\?[a-z0-9+_\-\.%=&]*)?)?(#[a-z][a-z0-9_]*)?$/ ", $comment['author']) > 0) {
				$error = "用户昵称不允许为网址";
				$opt = $filter_set->opt_nourl_au;
			}
		}
            
        
		//纯中文评论处理
		if ($opt == "none" && $filter_set->opt_nocn != "none") {
			if (preg_match("/[\x{4e00}-\x{9fa5}]/u", $comment['text']) == 0) {
				$error = "评论内容请不少于一个中文汉字";
				$opt = $filter_set->opt_nocn;
			}
		}
        
        
        //字符长度检测
		if ($opt == "none" && $filter_set->opt_length != "none") {            
            if(SmartSpam_Plugin::strLength($comment['text']) < $filter_set->length_min){           	
           		$error = "评论内容请不得少于".$filter_set->length_min."个字符";
				$opt = $filter_set->opt_length;
            }else 
            if(SmartSpam_Plugin::strLength($comment['text']) >  $filter_set->length_max){           	
            	$error = "评论内容请不得多于".$filter_set->length_max."个字符";
				$opt = $filter_set->opt_length;
            }
             
		}
        
		//检查禁止词汇
		if ($opt == "none" && $filter_set->opt_ban != "none") {
			if (SmartSpam_Plugin::check_in($filter_set->words_ban, $comment['text'])) {
				$error = "评论内容中包含禁止词汇";
				$opt = $filter_set->opt_ban;
			}
		}
		//检查敏感词汇
		if ($opt == "none" && $filter_set->opt_chk != "none") {
			if (SmartSpam_Plugin::check_in($filter_set->words_chk, $comment['text'])) {
				$error = "评论内容中包含敏感词汇";
				$opt = $filter_set->opt_chk;
			}
		}



		//加权判定（合并版）：行为特征 + 历史样本累计打分，达到阈值才执行
		$wAction = !empty($filter_set->opt_weight) ? $filter_set->opt_weight
			: (!empty($filter_set->opt_score) ? $filter_set->opt_score : 'none');
		if ($opt == "none" && $wAction !== 'none') {
			$th = intval(!empty($filter_set->weight_threshold) ? $filter_set->weight_threshold
				: (!empty($filter_set->score_threshold) ? $filter_set->score_threshold : 8));
			if ($th <= 0) { $th = 8; }

			$sources = isset($filter_set->weight_sources) ? $filter_set->weight_sources : null;
			if (!is_array($sources)) { $sources = array('score', 'learn'); }   // 老配置：两组都参与
			$useScore = in_array('score', $sources);
			$useLearn = in_array('learn', $sources);

			$detail = array();
			$total = 0;
			if ($useScore) { $total += SmartSpam_Plugin::calc_score($comment, $filter_set, $detail); }
			if ($useLearn) { $total += SmartSpam_Plugin::learn_match($comment, $filter_set, $detail); }

			// 头像这一项只在「+3 恰好可能改变结论」时才发起请求
			if ($useScore && $total < $th && $total + 3 >= $th) {
				$mail  = strtolower(trim((string)(isset($comment['mail']) ? $comment['mail'] : '')));
				$hosts = isset($filter_set->gravatar_hosts) ? $filter_set->gravatar_hosts : '';
				if (SmartSpam_Plugin::gravatar_exists($mail, $hosts) === false) {
					$total += 3;
					$detail[] = '无Gravatar头像';
				}
			}

			if ($total >= $th) {
				$error = "评论命中加权判定（" . $total . " 分：" . implode("、", $detail) . "）";
				$opt = $wAction;
			}
		}

		//调试日志：仅记录动作、分值与规则名，不含任何个人信息
		if (!empty($filter_set->debug_log) && (string)$filter_set->debug_log !== '0' && $opt !== 'none') {
			@error_log('[SmartSpam] action=' . $opt . ' ' . $error);
		}

		//执行操作
		if ($opt == "abandon") {
			Typecho_Cookie::set('__typecho_remember_text', $comment['text']);
            throw new Typecho_Widget_Exception($error);
		}
		else if ($opt == "spam") {
			$comment['status'] = 'spam';
		}
		else if ($opt == "waiting") {
			$comment['status'] = 'waiting';
		}
		Typecho_Cookie::delete('__typecho_remember_text');
        return $comment;
    }
    
    /**
    * PHP获取字符串中英文混合长度 
    */
    private static function strLength($str){        
        preg_match_all('/./us', $str, $match);
        return count($match[0]);  // 输出9
    }
        

    /**
     * 检查$str中是否含有$words_str中的词汇
     * 
     */
	private static function check_in($words_str, $str)
	{
        // 如果未设置屏蔽词，就不检测，直接通过
        $words_str = trim($words_str); // 修复当禁止词汇列表里出现空行，会导致所有内容都被报告为禁止词汇
        if ($words_str == NULL || $words_str == "") return false;

		$words = explode("\n", self::normalize_list($words_str));
		if (empty($words)||empty($str)) {
			return false;
		}
		foreach ($words as $word) {
            if (false !== strpos($str, trim($word))) {
                return true;
            }
		}
		return false;
	}

    /**
     * 检查$ip中是否在$words_ip的IP段中
     * 
     */
	private static function check_ip($words_ip, $ip)
	{
        $words_ip = trim($words_ip); // 修复当禁止IP列表里出现空行，会导致所有IP都被报告为禁止IP
		$words = explode("\n", self::normalize_list($words_ip));
		if (empty($words)) {
			return false;
		}
		foreach ($words as $word) {
			$word = trim($word);
			if (false !== strpos($word, '*')) {
				$word = "/^".str_replace('*', '\d{1,3}', $word)."$/";
				if (preg_match($word, $ip)) {
					return true;
				}
			} else {
				if (false !== strpos($ip, $word)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * 归一化列表配置：兼容“真实换行”与“字面 \n / \r\n”两种存量格式
	 */
	private static function normalize_list($str)
	{
		$str = str_replace(array("\\r\\n", "\\r", "\\n", "\r\n", "\r"), "\n", (string)$str);
		return trim($str);
	}

	/**
	 * 综合评分：只使用弱信号，避免单一条件误伤
	 * 保留邮箱域名 +6 ｜ 无头像 +3 ｜ 昵称广告词 +4 ｜ 网址广告词 +4 ｜ 内容广告词 +3 ｜ 同域名已有垃圾 +6
	 */
	private static function calc_score($comment, $filter_set, &$detail)
	{
		$score  = 0;
		$mail   = strtolower(trim((string)($comment['mail'] ?? '')));
		$author = (string)($comment['author'] ?? '');
		$url    = (string)($comment['url'] ?? '');
		$text   = (string)($comment['text'] ?? '');

		// 1. 保留/无效邮箱域名：必然造假
		$at = strrpos($mail, '@');
		if ($at !== false) {
			$domain = substr($mail, $at + 1);
			$reserved = array('example.com', 'example.org', 'example.net', 'example', 'test.com', 'localhost', 'invalid', 'test');
			if (in_array($domain, $reserved, true)) {
				$score += 6;
				$detail[] = '保留邮箱域名';
			}
		}

		// 2. 广告词已改为由学习库自动维护，见 learn_match()

		// 3. 同一网址域名近期已有垃圾评论（批量推广特征）
		if ($url !== '') {
			$n = self::url_domain_spam_count($url);
			if ($n >= 2) {
				$score += 6;
				$detail[] = '同域名已有' . $n . '条垃圾';
			}
		}

		// 头像信号由调用方在「可能改变结论」时统一处理，这里不再发起外部请求

		return $score;
	}

	/**
	 * 解析镜像域名列表：支持 host、host/path、https://host，去重去空、忽略 # 注释
	 */
	private static function parse_hosts($hosts)
	{
		$out = array();
		$lines = preg_split('/[\r\n]+/', (string)$hosts);
		if (empty($lines)) { return $out; }
		foreach ($lines as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] === '#') { continue; }
			$line = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $line);   // 去协议
			$line = preg_replace('~[/?\#].*$~', '', $line);                 // 去路径 / 查询 / 锚点
			$line = strtolower(rtrim($line, '.'));

			// 安全校验：只接受合法域名，拒绝 IP 字面量、端口、IPv6、过长域名
			if ($line === '' || strlen($line) > 253) { continue; }
			if (strpos($line, ':') !== false) { continue; }                          // 含端口 / IPv6
			if (preg_match('/^\d{1,3}(\.\d{1,3}){3}$/', $line)) { continue; }        // IPv4
			if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $line)) {
				continue;                                                            // 非法域名
			}
			if (in_array($line, $out, true)) { continue; }
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * 判断邮箱是否注册过 Gravatar 头像
	 * 返回 true=有头像, false=无头像, null=无法判断（网络失败，调用方不扣分）
	 */
	private static function gravatar_exists($mail, $hosts)
	{
		$mail = strtolower(trim((string)$mail));
		if ($mail === '' || !filter_var($mail, FILTER_VALIDATE_EMAIL)) { return null; }

		$list = self::parse_hosts($hosts);
		if (empty($list)) { return null; }

		$hash = md5($mail);
		$cacheDir  = rtrim(sys_get_temp_dir(), '/\\') . '/smartspam_gravatar';
		$cacheFile = $cacheDir . '/g_' . $hash . '.cache';
		$prefFile  = $cacheDir . '/host.cache';

		// 邮箱级缓存，7 天
		if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 604800) {
			$v = trim((string)@file_get_contents($cacheFile));
			if ($v === '1') { return true; }
			if ($v === '0') { return false; }
		}

		// 记住上次可用的镜像（1 小时），优先尝试以降低延迟
		$pref = '';
		if (is_file($prefFile) && (time() - filemtime($prefFile)) < 3600) {
			$pref = trim((string)@file_get_contents($prefFile));
		}
		if ($pref !== '' && in_array($pref, $list, true)) {
			$list = array_merge(array($pref), array_values(array_diff($list, array($pref))));
		}

		$result = null;
		$deadline = microtime(true) + 4.0;   // 总耗时预算，避免镜像全挂时拖慢评论提交
		foreach ($list as $host) {
			if (microtime(true) > $deadline) { break; }
			$code = self::http_get_avatar($host, $hash);
			if ($code === 200) { $result = true;  self::cache_write($prefFile, $host); break; }
			if ($code === 404) { $result = false; self::cache_write($prefFile, $host); break; }
			// 其余情况（超时 / 5xx / 被墙）换下一个镜像
		}

		if ($result !== null) {
			self::cache_write($cacheFile, $result ? '1' : '0');
		}
		return $result;
	}

	/**
	 * 请求 <host>/avatar/<hash>?d=404，返回 HTTP 状态码，失败返回 0
	 */
	private static function http_get_avatar($host, $hash)
	{
		$url = 'https://' . $host . '/avatar/' . $hash . '?d=404&s=80';
		if (function_exists('curl_init')) {
			$ch = curl_init($url);
			curl_setopt_array($ch, array(
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_TIMEOUT        => 2,
				CURLOPT_CONNECTTIMEOUT => 1,
				CURLOPT_FOLLOWLOCATION => false,          // 不跟随重定向：防镜像 302 到内网
				CURLOPT_MAXREDIRS      => 0,
				CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_USERAGENT      => 'SmartSpam/2.10 (+https://github.com/ShangJixin/SmartSpam)',
			));
			curl_exec($ch);
			$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
			curl_close($ch);
			return $code;
		}

		// 无 curl 扩展时退化为流封装（同样禁止跟随重定向）
		$ctx = stream_context_create(array('http' => array(
			'method' => 'GET', 'timeout' => 2, 'ignore_errors' => true,
			'follow_location' => 0, 'max_redirects' => 0,
		)));
		@file_get_contents($url, false, $ctx);
		if (isset($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
			return (int)$m[1];
		}
		return 0;
	}

	private static function cache_write($file, $content)
	{
		$dir = dirname($file);
		if (!is_dir($dir)) { @mkdir($dir, 0755, true); }
		@file_put_contents($file, $content);
	}

	/**
	 * 统计同一网址域名近期已有的垃圾/待审核评论数（批量推广特征）
	 */
	private static function url_domain_spam_count($url)
	{
		$host = self::url_host($url);
		if ($host === '' || strpos($host, '.') === false) { return 0; }

		try {
			$db = Typecho_Db::get();
			// 只统计近 90 天的垃圾/待审核，且用精确 host 比对（避免 LIKE 子串误匹配）
			$rows = $db->fetchAll(
				$db->select('url')->from('table.comments')
				   ->where('status = ? OR status = ?', 'spam', 'waiting')
				   ->where('created > ?', time() - 90 * 86400)
				   ->order('coid', Typecho_Db::SORT_DESC)
				   ->limit(1000)
			);
			$n = 0;
			if (is_array($rows)) {
				foreach ($rows as $r) {
					if (isset($r['url']) && self::url_host($r['url']) === $host) { $n++; }
				}
			}
			return $n;
		} catch (Exception $e) {
			return 0;
		}
	}

	// ==================== 样本学习 ====================

	/**
	 * 学习库存储：直接写 Typecho options 表，不依赖任何文件系统权限
	 * （虚拟主机 / Windows / 容器等环境同样可用，且随站点备份）
	 */
	const LEARN_OPTION = 'plugin:SmartSpam:learned';
	const LEARN_LOCK   = 'plugin:SmartSpam:lock';

	/**
	 * 重建锁：用一行 option 做「条件更新」抢占，避免缓存过期瞬间并发重建
	 */
	private static function learn_lock_acquire()
	{
		try {
			$db = Typecho_Db::get();
			$row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', self::LEARN_LOCK));
			if (empty($row)) {
				try {
					$db->query($db->insert('table.options')->rows(array(
						'name' => self::LEARN_LOCK, 'user' => 0, 'value' => ''
					)));
				} catch (Exception $e) {
					// 并发插入冲突，忽略
				}
			}
			$now = time();
			$affected = $db->query(
				$db->update('table.options')->rows(array('value' => (string)$now))
				   ->where('name = ?', self::LEARN_LOCK)
				   ->where('value = ? OR value < ?', '', $now - 120)
			);
			return (int)$affected > 0;
		} catch (Exception $e) {
			return true;   // 锁机制本身出错时不阻塞重建
		}
	}

	private static function learn_lock_release()
	{
		try {
			$db = Typecho_Db::get();
			$db->query($db->update('table.options')->rows(array('value' => ''))->where('name = ?', self::LEARN_LOCK));
		} catch (Exception $e) {
			// 释放失败交给 120 秒超时兜底
		}
	}

	/**
	 * 载入学习库：内存缓存 → 数据库 → 24 小时过期则重建
	 */
	private static function learn_load($cap = 200)
	{
		static $memo = null;
		if ($memo !== null) { return $memo; }

		$j = self::learn_decode(self::learn_db_read());
		if (is_array($j) && isset($j['v']) && !empty($j['ts']) && (time() - (int)$j['ts']) < 86400) {
			$memo = $j;
			return $j;
		}

		// 已过期：抢到锁的请求才重建，其余用旧缓存兜底
		if (self::learn_lock_acquire()) {
			try {
				$memo = self::learn_rebuild($cap);
			} catch (Throwable $e) {
				$memo = (is_array($j) && isset($j['v'])) ? $j : array();
			}
			self::learn_lock_release();
			return $memo;
		}

		$memo = (is_array($j) && isset($j['v'])) ? $j : array();
		return $memo;
	}

	private static function learn_db_read()
	{
		try {
			$db = Typecho_Db::get();
			$row = $db->fetchRow($db->select('value')->from('table.options')->where('name = ?', self::LEARN_OPTION));
			return (is_array($row) && isset($row['value'])) ? (string)$row['value'] : '';
		} catch (Exception $e) {
			return '';
		}
	}

	private static function learn_db_write($payload)
	{
		if ($payload === '') { return false; }
		try {
			$db = Typecho_Db::get();
			$row = $db->fetchRow($db->select('name')->from('table.options')->where('name = ?', self::LEARN_OPTION));
			if (is_array($row) && isset($row['name'])) {
				$db->query($db->update('table.options')->rows(array('value' => $payload))->where('name = ?', self::LEARN_OPTION));
			} else {
				$db->query($db->insert('table.options')->rows(array(
					'name' => self::LEARN_OPTION, 'user' => 0, 'value' => $payload
				)));
			}
			return true;
		} catch (Exception $e) {
			return false;
		}
	}

	/** 序列化：优先 gzip+base64，压不下再退回原始 JSON */
	private static function learn_encode($data)
	{
		$json = json_encode($data, JSON_UNESCAPED_UNICODE);
		if ($json === false) { return ''; }
		if (function_exists('gzcompress')) {
			$bin = @gzcompress($json, 6);
			if ($bin !== false) { return 'gz:' . base64_encode($bin); }
		}
		return 'js:' . $json;
	}

	private static function learn_decode($raw)
	{
		$raw = (string)$raw;
		if (strncmp($raw, 'gz:', 3) === 0) {
			if (!function_exists('gzuncompress')) { return null; }
			$bin = @base64_decode(substr($raw, 3), true);
			if ($bin === false) { return null; }
			$json = @gzuncompress($bin);
			return $json === false ? null : json_decode($json, true);
		}
		if (strncmp($raw, 'js:', 3) === 0) {
			return json_decode(substr($raw, 3), true);
		}
		return null;
	}

	/**
	 * 重建学习库：只收录「出现在垃圾评论、且从未出现在正常评论」的特征
	 */
	private static function learn_rebuild($cap = 200)
	{
		$cap = (int)$cap;
		if ($cap < 20) { $cap = 20; }
		if ($cap > 1000) { $cap = 1000; }
		$out = array('v' => 1, 'ts' => time(), 'spam' => 0, 'ham' => 0,
			'hosts' => array(), 'ips' => array(), 'mails' => array(),
			'mail_domains' => array(), 'authors' => array(), 'texts' => array());

		try {
			$db = Typecho_Db::get();
			$rows = $db->fetchAll($db->select('coid', 'author', 'mail', 'url', 'ip', 'text', 'created', 'status')
				->from('table.comments')->order('coid', Typecho_Db::SORT_DESC)->limit(8000));
		} catch (Exception $e) {
			return $out;
		}
		if (!is_array($rows)) { return $out; }

		// 学习时间窗：只学最近 N 天的垃圾；正常评论始终全量参与「排除」。
		// N=0 表示不限，避免低频站点因样本太少而学不到东西。
		$maxAge = (int)self::get_setting('learn_max_age', 0);
		$cut = $maxAge > 0 ? time() - $maxAge * 86400 : 0;

		$spam = array(); $ham = array();
		foreach ($rows as $r) {
			if (!isset($r['status'])) { continue; }
			if ($r['status'] === 'spam') {
				if ($cut > 0 && isset($r['created']) && (int)$r['created'] < $cut) { continue; }
				$spam[] = $r;
			} elseif ($r['status'] === 'approved') {
				$ham[] = $r;
			}
		}
		$out['spam'] = count($spam);
		$out['ham']  = count($ham);

		$dims = array('hosts', 'ips', 'mails', 'mail_domains', 'authors');
		$sp = array(); $hm = array();
		foreach ($dims as $d) { $sp[$d] = array(); $hm[$d] = array(); }

		foreach ($spam as $r) {
			$k = self::learn_keys($r);
			foreach ($dims as $d) {
				$v = $k[$d];
				if ($v === '') { continue; }
				// 公共邮箱域名 / 公共平台域名属于共享命名空间，学进去会误伤大量正常用户
				if ($d === 'mail_domains' && self::is_shared_mail_domain($v)) { continue; }
				if ($d === 'hosts' && self::is_shared_host($v)) { continue; }
				$sp[$d][$v] = isset($sp[$d][$v]) ? $sp[$d][$v] + 1 : 1;
			}
		}
		foreach ($ham as $r) {
			$k = self::learn_keys($r);
			foreach ($dims as $d) {
				$v = $k[$d];
				if ($v === '') { continue; }
				$hm[$d][$v] = isset($hm[$d][$v]) ? $hm[$d][$v] + 1 : 1;
			}
		}

		// 只保留正常评论中从未出现的特征；不同维度所需的最小出现次数不同
		foreach ($dims as $d) {
			$need = self::learn_dim_min($d);
			foreach ($sp[$d] as $v => $n) {
				if ($n < $need) { continue; }
				if (empty($hm[$d][$v])) { $out[$d][$v] = $n; }
			}
		}

		// 性能优先：每个维度按出现次数取前 N，丢弃低频特征
		foreach ($dims as $d) {
			if (count($out[$d]) > $cap) {
				arsort($out[$d], SORT_NUMERIC);
				$out[$d] = array_slice($out[$d], 0, $cap, true);
			}
		}

		// 内容维度防污染：先收集「正常评论里出现过的 shingle」。
		// 垃圾评论中那些同样出现在正常评论里的片段（如“写得很好”“感谢分享”）一律剔除，
		// 只保留垃圾特有的表达。这样「内容本身没问题、只是昵称/网址是垃圾」的评论不会污染文本库。
		$hamGrams = array();
		$hgLimit = 60000;   // 内存上限：只取最近的正常评论片段，约 6MB 峰值
		$hgCount = 0;
		foreach ($ham as $r) {
			foreach (self::shingles(isset($r['text']) ? $r['text'] : '') as $x) {
				if (!isset($hamGrams[$x])) {
					$hamGrams[$x] = 1;
					if (++$hgCount >= $hgLimit) { break 2; }
				}
			}
		}

		// 内容近似重复：保存每条垃圾评论「独有」的 shingle 集合（样本数同样受上限约束）
		$maxTexts = min(100, $cap);
		foreach ($spam as $r) {
			if (count($out['texts']) >= $maxTexts) { break; }
			$t = isset($r['text']) ? $r['text'] : '';
			if (trim((string)$t) === '') { continue; }
			$g = self::shingles($t);
			if (count($g) < 5) { continue; }

			$keep = array();
			foreach ($g as $x) {
				if (!isset($hamGrams[$x])) { $keep[] = $x; }
			}
			// 超过 70% 的片段在正常评论里出现过 → 这段文字本身没有垃圾特征，整条跳过；
			// 通过则保留完整片段集，避免剔除常用词后相似度被稀释
			if (count($keep) < 5 || count($keep) < count($g) * 0.3) { continue; }

			$out['texts'][] = array('c' => isset($r['coid']) ? (int)$r['coid'] : 0, 'g' => array_slice($g, 0, 200));
		}

		// 体积兜底：压缩后仍超 32KB 时按半数裁剪（最多 log2 次编码，避免逐条重编码）
		$payload = self::learn_encode($out);
		while (strlen($payload) > 32000 && count($out['texts']) > 1) {
			$out['texts'] = array_slice($out['texts'], 0, (int)(count($out['texts']) / 2));
			$payload = self::learn_encode($out);
		}
		if (strlen($payload) > 32000) {
			$out['texts'] = array();
			$payload = self::learn_encode($out);
		}
		self::learn_db_write($payload);
		return $out;
	}

	private static function learn_keys($r)
	{
		return array(
			'hosts'        => self::url_host(isset($r['url']) ? $r['url'] : ''),
			'ips'          => self::ip_seg(isset($r['ip']) ? $r['ip'] : ''),
			'mails'        => strtolower(trim((string)(isset($r['mail']) ? $r['mail'] : ''))),
			'mail_domains' => self::mail_domain(isset($r['mail']) ? $r['mail'] : ''),
			'authors'      => trim((string)(isset($r['author']) ? $r['author'] : '')),
		);
	}

	/** 公共邮箱域名：由海量正常用户共用，永远不作为垃圾特征学习 */
	private static function shared_mail_domains()
	{
		static $m = null;
		if ($m === null) {
			$m = array();
			// 只维护热门邮箱服务商；冷门 / ISP / 企业邮箱不列入，让它们仍可作为垃圾信号被学习
			$list = array(
				'qq.com','foxmail.com','163.com','126.com','sina.com','sohu.com','139.com','aliyun.com',
				'gmail.com','googlemail.com','outlook.com','hotmail.com','live.com','msn.com',
				'icloud.com','yahoo.com'
			);
			foreach ($list as $d) { $m[$d] = 1; }
		}
		return $m;
	}

	private static function is_shared_mail_domain($domain)
	{
		$domain = strtolower(trim((string)$domain));
		if ($domain === '') { return false; }
		$map = self::shared_mail_domains();
		if (isset($map[$domain])) { return true; }
		foreach ($map as $d => $x) {                       // 子域（mail.qq.com 之类）同样视为公共邮箱
			if (substr($domain, -strlen($d) - 1) === '.' . $d) { return true; }
		}
		return false;
	}

	/** 公共平台域名：正常用户也会在网址里引用，不作为垃圾特征 */
	private static function shared_hosts()
	{
		static $m = null;
		if ($m === null) {
			$m = array();
			$list = array(
				'github.com','github.io','gitlab.com','gitee.com','youtube.com','youtu.be','b23.tv',
				'bilibili.com','zhihu.com','weibo.com','t.me','telegram.org','twitter.com','x.com',
				'facebook.com','instagram.com','linkedin.com','medium.com','blogspot.com','wordpress.com',
				'tumblr.com','pinterest.com','douyin.com','xiaohongshu.com','baidu.com','sohu.com',
				'163.com','qq.com','weixin.qq.com','steamcommunity.com','notion.so','figma.com'
			);
			foreach ($list as $d) { $m[$d] = 1; }
		}
		return $m;
	}

	private static function is_shared_host($host)
	{
		$host = strtolower(trim((string)$host));
		if ($host === '') { return false; }
		$map = self::shared_hosts();
		if (isset($map[$host])) { return true; }
		foreach ($map as $d => $x) {
			if (substr($host, -strlen($d) - 1) === '.' . $d) { return true; }
		}
		return false;
	}

	/** 各维度的最小出现次数：共享命名空间要求更强证据 */
	private static function learn_dim_min($dim)
	{
		switch ($dim) {
			case 'mail_domains': return 3;
			case 'hosts':        return 2;
			case 'authors':      return 2;
			case 'ips':          return 3;   // 同段可能是运营商/CDN，要求更强证据
			default:             return 1;   // 精确邮箱：完整地址，1 次即可
		}
	}

	/**
	 * 用学习库判定一条评论，返回累计分值
	 */
	private static function learn_match($comment, $filter_set, &$detail)
	{
		$cap = intval($filter_set->learn_max_items);
		if ($cap < 20) { $cap = 20; }
		if ($cap > 1000) { $cap = 1000; }
		$L = self::learn_load($cap);
		if (!is_array($L) || !isset($L['v'])) { return 0; }

		$min = intval($filter_set->learn_min_count);
		if ($min < 1) { $min = 2; }
		$score = 0;

		$host = self::url_host(isset($comment['url']) ? $comment['url'] : '');
		if ($host !== '' && isset($L['hosts'][$host]) && $L['hosts'][$host] >= $min) {
			$score += 6; $detail[] = '已知垃圾域名 ' . $host;
		}

		$useIp = !isset($filter_set->learn_ip) || (string)$filter_set->learn_ip !== '0';
		$seg = self::ip_seg(isset($comment['ip']) ? $comment['ip'] : '');
		if ($useIp && $seg !== '' && isset($L['ips'][$seg]) && $L['ips'][$seg] >= $min) {
			$score += 6; $detail[] = '已知垃圾IP段 ' . $seg . '.x';
		}

		$mail = strtolower(trim((string)(isset($comment['mail']) ? $comment['mail'] : '')));
		if ($mail !== '' && isset($L['mails'][$mail]) && $L['mails'][$mail] >= $min) {
			$score += 6; $detail[] = '已知垃圾邮箱';
		}

		$dom = self::mail_domain($mail);
		if ($dom !== '' && isset($L['mail_domains'][$dom]) && $L['mail_domains'][$dom] >= $min) {
			$score += 4; $detail[] = '已知垃圾邮箱域名 ' . $dom;
		}

		$author = trim((string)(isset($comment['author']) ? $comment['author'] : ''));
		if ($author !== '' && isset($L['authors'][$author]) && $L['authors'][$author] >= $min) {
			$score += 4; $detail[] = '已知垃圾昵称';
		}

		if (!empty($L['texts'])) {
			$g = self::shingles(isset($comment['text']) ? $comment['text'] : '');
			if (count($g) >= 5) {
				$set = array_flip($g);
				foreach ($L['texts'] as $row) {
					if (empty($row['g'])) { continue; }
					$inter = 0;
					foreach ($row['g'] as $x) { if (isset($set[$x])) { $inter++; } }
					$union = count($g) + count($row['g']) - $inter;
					if ($union > 0 && ($inter / $union) >= 0.7) {
						$score += 8; $detail[] = '与历史垃圾评论高度相似'; break;
					}
				}
			}
		}

		return $score;
	}

	private static function url_host($url)
	{
		$url = trim((string)$url);
		if ($url === '') { return ''; }
		if (strpos($url, '://') === false) { $url = 'http://' . $url; }
		$h = parse_url($url, PHP_URL_HOST);
		if (empty($h)) { return ''; }
		return strtolower(preg_replace('#^www\.#i', '', (string)$h));
	}

	private static function ip_seg($ip)
	{
		$ip = trim((string)$ip);
		if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.\d{1,3}$/', $ip, $m)) { return ''; }
		if ((int)$m[1] > 255 || (int)$m[2] > 255 || (int)$m[3] > 255) { return ''; }
		return $m[1] . '.' . $m[2] . '.' . $m[3];
	}

	/**
	 * 从代理头还原访客真实 IP
	 * X-Forwarded-For 形如 "client, proxy1, proxy2"：最左边可被客户端伪造，
	 * 最右边是离本站最近的可信代理追加的，因此从右往左取第一个合法 IP。
	 */
	private static function proxy_real_ip($mode)
	{
		if ($mode === '2') {
			$v = isset($_SERVER['HTTP_X_REAL_IP']) ? trim((string)$_SERVER['HTTP_X_REAL_IP']) : '';
			return filter_var($v, FILTER_VALIDATE_IP) ? $v : '';
		}

		$v = isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? (string)$_SERVER['HTTP_X_FORWARDED_FOR'] : '';
		if ($v === '') { return ''; }
		$parts = explode(',', $v);
		for ($i = count($parts) - 1; $i >= 0; $i--) {
			$ip = trim($parts[$i]);
			if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) { return $ip; }
		}
		return '';
	}

	private static function mail_domain($mail)
	{
		$mail = strtolower(trim((string)$mail));
		$p = strrpos($mail, '@');
		return $p === false ? '' : substr($mail, $p + 1);
	}

	/**
	 * 文本 shingle：中文 3-gram + 英文长单词，用于近似重复判定
	 */
	private static function shingles($text)
	{
		if (!function_exists('mb_strlen')) { return array(); }
		$text = mb_substr((string)$text, 0, 2000, 'UTF-8');   // 限制长度，防超长评论拖慢匹配
		$t = preg_replace('#<[^>]+>#', ' ', $text);
		$t = preg_replace('#https?://\S+#i', ' ', $t);
		$t = mb_strtolower($t, 'UTF-8');

		$grams = array();
		$zh = preg_replace('/[^\x{4e00}-\x{9fa5}]/u', '', $t);
		$len = mb_strlen($zh, 'UTF-8');
		for ($i = 0; $i + 3 <= $len; $i++) {
			$grams['z:' . mb_substr($zh, $i, 3, 'UTF-8')] = true;
		}
		foreach (preg_split('/[^a-z0-9]+/', $t) as $w) {
			if (strlen($w) >= 4) { $grams['w:' . $w] = true; }
		}
		return array_keys($grams);
	}
}
