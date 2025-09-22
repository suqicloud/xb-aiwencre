<?php
/**
 * Plugin Name: 小半WP AI文章创作
 * Plugin URI: https://www.jingxialai.com
 * Description: 基于AI接口的WordPress文章创作插件，支持多平台、多模型等。
 * Version: 1.0.1
 * Author: summer
 * License: GPL v2 or later
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

// 定义插件常量
define('XB_AIWENCRE_VERSION', '1.0.1');
define('XB_AIWENCRE_PLUGIN_URL', plugin_dir_url(__FILE__));
define('XB_AIWENCRE_PLUGIN_PATH', plugin_dir_path(__FILE__));

/**
 * 主插件类
 */
class XB_AIWenCre_Plugin {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'xb_aiwencre_settings';
        
        // 注册钩子
        register_activation_hook(__FILE__, array($this, 'xb_aiwencre_activate'));
        register_deactivation_hook(__FILE__, array($this, 'xb_aiwencre_deactivate'));
        
        add_action('init', array($this, 'xb_aiwencre_init'));
        add_action('admin_menu', array($this, 'xb_aiwencre_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'xb_aiwencre_admin_scripts'));
        add_action('rest_api_init', array($this, 'xb_aiwencre_register_rest_routes'));
        
        // 添加AJAX处理
        add_action('wp_ajax_xb_aiwencre_generate_article', array($this, 'xb_aiwencre_ajax_generate_article'));
        add_action('wp_ajax_xb_aiwencre_stream_generate', array($this, 'xb_aiwencre_stream_generate_ajax'));
        add_action('wp_ajax_xb_aiwencre_test_connection', array($this, 'xb_aiwencre_test_connection_callback'));
        add_action('wp_ajax_xb_aiwencre_publish_post', array($this, 'xb_aiwencre_publish_post_callback'));
        add_action('wp_ajax_xb_aiwencre_save_draft', array($this, 'xb_aiwencre_save_draft_callback'));
        
        // 插件设置链接
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'xb_aiwencre_add_settings_link'));
    }
    
    /**
     * 插件激活时执行
     */
    public function xb_aiwencre_activate() {
        $this->xb_aiwencre_create_tables();
        flush_rewrite_rules();
    }
    
    /**
     * 插件停用时执行
     */
    public function xb_aiwencre_deactivate() {
        flush_rewrite_rules();
    }
    
    /**
     * 创建数据表
     */
    private function xb_aiwencre_create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            platform_name varchar(100) NOT NULL COMMENT 'AI平台名称',
            api_endpoint varchar(500) NOT NULL COMMENT 'API接口地址',
            api_models text NOT NULL COMMENT 'AI模型列表，逗号分隔',
            api_keys text NOT NULL COMMENT 'API密钥列表，逗号分隔',
            is_active tinyint(1) DEFAULT 1 COMMENT '是否启用',
            created_time datetime DEFAULT CURRENT_TIMESTAMP,
            updated_time datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    

    
    /**
     * 初始化 - 仅后台功能
     */
    public function xb_aiwencre_init() {
        // 仅在后台加载功能
        if (is_admin()) {
            // 后台初始化代码
            $this->xb_aiwencre_admin_init();
        }
    }
    
    /**
     * 后台初始化
     */
    private function xb_aiwencre_admin_init() {
        // 后台专用初始化代码
        // 可以在这里添加后台特定的功能
    }
    
    /**
     * 添加后台菜单
     */
    public function xb_aiwencre_admin_menu() {
        add_menu_page(
            'AI文章创作',
            'AI文章创作',
            'manage_options',
            'xb-aiwencre',
            array($this, 'xb_aiwencre_admin_page'),
            'dashicons-edit-large',
            30
        );
        
        add_submenu_page(
            'xb-aiwencre',
            '基础配置',
            '基础配置',
            'manage_options',
            'xb-aiwencre',
            array($this, 'xb_aiwencre_admin_page')
        );
        
        add_submenu_page(
            'xb-aiwencre',
            '文章创作',
            '文章创作',
            'manage_options',
            'xb-aiwencre-create',
            array($this, 'xb_aiwencre_create_page_callback')
        );
    }
    
    /**
     * 加载后台脚本和样式
     */
    public function xb_aiwencre_admin_scripts($hook) {
        if (strpos($hook, 'xb-aiwencre') === false) {
            return;
        }
        
        wp_enqueue_script('jquery');
        
        // 传递数据到JavaScript
        wp_localize_script('jquery', 'xb_aiwencre_ajax', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'rest_url' => rest_url('xb-aiwencre/v1/'),
            'nonce' => wp_create_nonce('xb_aiwencre_nonce')
        ));
    }
    
    /**
     * 基础配置页面
     */
    public function xb_aiwencre_admin_page() {
        global $wpdb;
        
        // 处理表单提交
        if (isset($_POST['xb_aiwencre_save_config'])) {
            $this->xb_aiwencre_save_config();
        }
        
        if (isset($_POST['xb_aiwencre_update_config'])) {
            $this->xb_aiwencre_update_config();
        }
        
        if (isset($_POST['xb_aiwencre_delete_platform'])) {
            $platform_id = intval($_POST['platform_id']);
            $wpdb->delete($this->table_name, array('id' => $platform_id));
            echo '<div class="notice notice-success"><p>平台删除成功！</p></div>';
        }
        
        // 获取所有平台配置
        $platforms = $wpdb->get_results("SELECT * FROM {$this->table_name} ORDER BY id DESC");
        
        // 处理编辑请求
        $edit_platform = null;
        if (isset($_GET['edit']) && intval($_GET['edit'])) {
            $edit_platform = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE id = %d",
                intval($_GET['edit'])
            ));
        }
        
        ?>
        <div class="wrap">
            <h1>小半WP AI文章创作 - 基础配置</h1>
            
            <?php if ($edit_platform): ?>
                <h2>编辑AI平台配置</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('xb_aiwencre_config', 'xb_aiwencre_config_nonce'); ?>
                    <input type="hidden" name="platform_id" value="<?php echo $edit_platform->id; ?>" />
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">平台名称</th>
                            <td><input type="text" name="platform_name" class="regular-text" value="<?php echo esc_attr($edit_platform->platform_name); ?>" required /></td>
                        </tr>
                        <tr>
                            <th scope="row">API接口地址</th>
                            <td><input type="url" name="api_endpoint" class="regular-text" value="<?php echo esc_attr($edit_platform->api_endpoint); ?>" required />
                            <p class="description">OpenAI 兼容模式</p>
                        </td>
                        </tr>
                        <tr>
                            <th scope="row">AI模型</th>
                            <td>
                                <input type="text" name="api_models" class="regular-text" value="<?php echo esc_attr($edit_platform->api_models); ?>" required />
                                <p class="description">多个模型用英文逗号分隔，默认使用第一个</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API密钥</th>
                            <td>
                                <textarea name="api_keys" class="large-text" rows="3" required><?php echo esc_textarea($edit_platform->api_keys); ?></textarea>
                                <p class="description">多个密钥用英文逗号分隔，默认使用第一个，失败时自动切换</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">是否启用</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1" <?php checked($edit_platform->is_active, 1); ?> />
                                    启用此平台
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="xb_aiwencre_update_config" class="button-primary" value="更新配置" />
                        <a href="<?php echo admin_url('admin.php?page=xb-aiwencre'); ?>" class="button">取消编辑</a>
                    </p>
                </form>
            <?php else: ?>
                <h2>添加新的AI平台</h2>
                <form method="post" action="">
                    <?php wp_nonce_field('xb_aiwencre_config', 'xb_aiwencre_config_nonce'); ?>
                    
                    <table class="form-table">
                        <tr>
                            <th scope="row">平台名称</th>
                            <td><input type="text" name="platform_name" class="regular-text" placeholder="例如：通义千问" required /></td>
                        </tr>
                        <tr>
                            <th scope="row">API接口地址</th>
                            <td><input type="url" name="api_endpoint" class="regular-text" placeholder="https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions" required /></td>
                        </tr>
                        <tr>
                            <th scope="row">AI模型</th>
                            <td>
                                <input type="text" name="api_models" class="regular-text" placeholder="qwen-plus,qwen-turbo,qwen-max" required />
                                <p class="description">多个模型用英文逗号分隔，默认使用第一个</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">API密钥</th>
                            <td>
                                <textarea name="api_keys" class="large-text" rows="3" placeholder="sk-xxx,sk-yyy" required></textarea>
                                <p class="description">多个密钥用英文逗号分隔，默认使用第一个，失败时自动切换</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">是否启用</th>
                            <td>
                                <label>
                                    <input type="checkbox" name="is_active" value="1" checked />
                                    启用此平台
                                </label>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="submit" name="xb_aiwencre_save_config" class="button-primary" value="保存配置" />
                    </p>
                </form>
            <?php endif; ?>
            
            <hr />
            
            <h2>已配置的AI平台</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>平台名称</th>
                        <th>API接口</th>
                        <th>模型数量</th>
                        <th>密钥数量</th>
                        <th>状态</th>
                        <th>创建时间</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($platforms): ?>
                        <?php foreach ($platforms as $platform): ?>
                            <tr>
                                <td><?php echo esc_html($platform->platform_name); ?></td>
                                <td><?php echo esc_html(substr($platform->api_endpoint, 0, 50)) . '...'; ?></td>
                                <td><?php echo count(array_filter(explode(',', $platform->api_models))); ?></td>
                                <td><?php echo count(array_filter(explode(',', $platform->api_keys))); ?></td>
                                <td>
                                    <span class="<?php echo $platform->is_active ? 'xb-status-active' : 'xb-status-inactive'; ?>">
                                        <?php echo $platform->is_active ? '启用' : '禁用'; ?>
                                    </span>
                                </td>
                                <td><?php echo esc_html($platform->created_time); ?></td>
                                <td>
                                    <a href="<?php echo admin_url('admin.php?page=xb-aiwencre&edit=' . $platform->id); ?>" class="button button-small">编辑</a>
                                    <button type="button" class="button button-small xb-test-connection" data-platform-id="<?php echo $platform->id; ?>">测试</button>
                                    <form method="post" style="display:inline;">
                                        <input type="hidden" name="platform_id" value="<?php echo $platform->id; ?>" />
                                        <input type="submit" name="xb_aiwencre_delete_platform" class="button button-small" value="删除" 
                                               onclick="return confirm('确定要删除这个平台配置吗？')" />
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7">暂无配置的AI平台，请添加新的AI平台配置</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
            
            <div id="xb-test-result" class="notice" style="display:none; margin-top: 20px;">
                <p id="xb-test-message"></p>
            </div>
        </div>
        
        <style>
        .xb-status-active { color: #46b450; font-weight: bold; }
        .xb-status-inactive { color: #dc3232; }
        .form-table th { width: 150px; }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // 通知函数
            function showNotification(message, type = 'success') {
                // 移除已存在的通知
                $('.xb-notification').remove();
                
                // 创建新通知
                var notification = $('<div class="xb-notification ' + type + '">' + message + '</div>');
                $('body').append(notification);
                
                // 显示通知
                setTimeout(function() {
                    notification.addClass('show');
                }, 100);
                
                // 3秒后自动隐藏
                setTimeout(function() {
                    notification.removeClass('show');
                    setTimeout(function() {
                        notification.remove();
                    }, 300);
                }, 3000);
            }
            
            // 自动隐藏通知
            function autoHideNotice(element, delay = 3000) {
                setTimeout(function() {
                    element.fadeOut(500);
                }, delay);
            }
            
            // 测试连接功能
            $('.xb-test-connection').click(function() {
                var platformId = $(this).data('platform-id');
                var button = $(this);
                
                button.prop('disabled', true).text('测试中...');
                $('#xb-test-result').hide();
                
                $.post(xb_aiwencre_ajax.ajax_url, {
                    action: 'xb_aiwencre_test_connection',
                    platform_id: platformId,
                    nonce: xb_aiwencre_ajax.nonce
                }, function(response) {
                    button.prop('disabled', false).text('测试');
                    
                    if (response.success) {
                        $('#xb-test-result').removeClass('notice-error').addClass('notice-success').show();
                        $('#xb-test-message').text('✓ ' + response.data.message);
                        autoHideNotice($('#xb-test-result'));
                    } else {
                        $('#xb-test-result').removeClass('notice-success').addClass('notice-error').show();
                        $('#xb-test-message').text('✗ ' + response.data.message);
                        autoHideNotice($('#xb-test-result'), 5000);
                    }
                }).fail(function() {
                    button.prop('disabled', false).text('测试');
                    $('#xb-test-result').removeClass('notice-success').addClass('notice-error').show();
                    $('#xb-test-message').text('✗ 测试请求失败');
                    autoHideNotice($('#xb-test-result'), 5000);
                });
            });
            
            // 自动隐藏页面顶部的通知
            $('.notice').each(function() {
                autoHideNotice($(this));
            });
        });
        
        // 流式生成文章函数
        function xbAiwencreStreamGenerate(params) {
            // 构建EventSource URL
            var url = xb_aiwencre_ajax.ajax_url + '?action=xb_aiwencre_stream_generate';
            for (var key in params) {
                url += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
            }
            
            console.log('开始流式生成，URL:', url);
            $('#xb-debug-log').append('<div>[开始] 连接URL: ' + url + '</div>');
            
            var eventSource = new EventSource(url);
            var articleContent = '';
            var titleSet = false;
            
            eventSource.onopen = function(event) {
                console.log('EventSource连接已建立');
                $('#xb-generation-status').removeClass('error success').text('已连接AI服务，开始生成...');
                $('#xb-debug-log').append('<div style="color: green;">[连接] EventSource连接已建立</div>');
                var debugDiv = $('#xb-debug-log')[0];
                debugDiv.scrollTop = debugDiv.scrollHeight;
            };
            
            eventSource.onmessage = function(event) {
                console.log('收到数据:', event.data);
                try {
                    var data = JSON.parse(event.data);
                    
                    if (data.error) {
                        console.error('生成错误:', data.error);
                        $('#xb-generation-status').addClass('error').text('生成失败：' + data.error);
                        $('#xb-debug-log').append('<div style="color: red;">[错误] ' + data.error + '</div>');
                        eventSource.close();
                        resetGenerateButton();
                        return;
                    }
                    
                    if (data.debug) {
                        $('#xb-debug-log').append('<div style="color: blue;">[调试] ' + data.debug + '</div>');
                        var debugDiv = $('#xb-debug-log')[0];
                        debugDiv.scrollTop = debugDiv.scrollHeight;
                    }
                    
                    if (data.title && !titleSet) {
                        console.log('设置标题:', data.title);
                        $('#xb-article-title').val(data.title);
                        titleSet = true;
                        $('#xb-generation-status').text('正在生成文章内容...');
                        $('#xb-debug-log').append('<div style="color: green;">[标题] ' + data.title + '</div>');
                    }
                    
                    if (data.content) {
                        articleContent += data.content;
                        console.log('更新内容，当前长度:', articleContent.length);
                        
                        // 更新编辑器内容
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                            tinyMCE.get('xb-article-content').setContent(articleContent);
                        } else {
                            $('#xb-article-content').val(articleContent);
                        }
                        
                        // 启用发布按钮
                        if (articleContent.length > 50) {
                            $('#xb-publish-article, #xb-save-draft').prop('disabled', false);
                        }
                    }
                    
                    if (data.message && data.message === '文章生成完成') {
                        console.log('文章生成完成');
                        $('#xb-generation-status').addClass('success').text('文章生成完成！');
                        $('#xb-debug-log').append('<div style="color: green;">[完成] 文章生成成功，总字数: ' + articleContent.length + '</div>');
                        eventSource.close();
                        resetGenerateButton();
                    }
                    
                } catch (e) {
                    console.error('解析响应数据失败：', e, '原始数据:', event.data);
                    $('#xb-debug-log').append('<div style="color: red;">[错误] 解析响应失败: ' + e.message + '</div>');
                }
            };
            
            eventSource.addEventListener('done', function(event) {
                console.log('收到完成事件');
                $('#xb-generation-status').addClass('success').text('文章生成完成！');
                $('#xb-debug-log').append('<div style="color: green;">[完成] 文章生成成功</div>');
                eventSource.close();
                resetGenerateButton();
            });
            
            eventSource.onerror = function(event) {
                console.error('EventSource连接错误：', event);
                $('#xb-generation-status').addClass('error').text('连接中断，请重试');
                $('#xb-debug-log').append('<div style="color: red;">[错误] EventSource连接中断</div>');
                eventSource.close();
                resetGenerateButton();
            };
            
            function resetGenerateButton() {
                $('#xb-generate-article').prop('disabled', false).text('生成文章');
                $('#xb-stop-generate').hide();
            }
            
            // 停止生成按钮功能
            $('#xb-stop-generate').off('click').on('click', function() {
                console.log('用户停止生成');
                eventSource.close();
                resetGenerateButton();
                $('#xb-generation-status').addClass('error').text('生成已停止');
                $('#xb-debug-log').append('<div style="color: orange;">[停止] 用户手动停止生成</div>');
            });
        }
        </script>
        <?php
    }
    
    /**
     * 文章创作页面
     */
    public function xb_aiwencre_create_page_callback() {
        global $wpdb;
        
        // 获取启用的平台
        $platforms = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE is_active = 1 ORDER BY id DESC");
        
        ?>
        <div class="wrap">
            <h1>AI文章创作</h1>
            
            <div class="xb-aiwencre-create-container">
                <div class="xb-create-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">选择AI平台</th>
                            <td>
                                <select id="xb-platform-select" class="regular-text">
                                    <option value="">请选择AI平台</option>
                                    <?php foreach ($platforms as $platform): ?>
                                        <option value="<?php echo $platform->id; ?>" 
                                                data-models="<?php echo esc_attr($platform->api_models); ?>">
                                            <?php echo esc_html($platform->platform_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">选择AI模型</th>
                            <td>
                                <select id="xb-model-select" class="regular-text" disabled>
                                    <option value="">请先选择AI平台</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">联网搜索</th>
                            <td>
                                <label>
                                    <input type="checkbox" id="xb-enable-search" />
                                    启用联网搜索（AI将先搜索相关信息再创作文章）
                                </label>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">文章关键词</th>
                            <td>
                                <input type="text" id="xb-keywords" class="regular-text" placeholder="关键词1,关键词2,关键词3" />
                                <p class="description">多个关键词用英文逗号分隔</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">网站名称</th>
                            <td>
                                <input type="text" id="xb-site-name" class="regular-text" placeholder="可选，文章中会提及此网站名称" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">指定内链</th>
                            <td>
                                <input type="url" id="xb-internal-link" class="regular-text" placeholder="可选，文章中会包含此链接" />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">文章语言</th>
                            <td>
                                <select id="xb-article-language" class="regular-text">
                                    <option value="中文">中文</option>
                                    <option value="英文">英文</option>
                                    <option value="日文">日文</option>
                                    <option value="韩文">韩文</option>
                                    <option value="法文">法文</option>
                                    <option value="德文">德文</option>
                                    <option value="西班牙文">西班牙文</option>
                                    <option value="俄文">俄文</option>
                                </select>
                                <p class="description">选择AI创作文章时使用的语言</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">文章字数</th>
                            <td>
                                <input type="number" id="xb-word-count" class="small-text" value="800" min="100" max="5000" />
                                <span>字</span>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="button" id="xb-generate-article" class="button-primary">生成文章</button>
                        <button type="button" id="xb-stop-generate" class="button" style="display:none;">停止生成</button>
                    </p>

                    <!-- 使用说明板块 -->
                    <div class="xb-usage-notice">
                        <h4>使用说明</h4>
                        <ul>
                            <li><strong>准确率：</strong>AI有时候可能也会理解错误</li>
                            <li><strong>多语言：</strong>取决于你的AI模型是否支持</li>
                            <li><strong>联网搜索：</strong>取决于你的AI模型是否支持，个人是不建议用的</li>
                            <li>有问题到QQ群(16966111)里面问</li>
                        </ul>
                    </div>

                </div>
                
                <div class="xb-article-editor">
                    <h3>文章标题</h3>
                    <input type="text" id="xb-article-title" class="regular-text" placeholder="AI生成的文章标题将显示在这里..." style="width: 100%; margin-bottom: 15px;" />
                    
                    <h3>文章内容</h3>
                    <div id="xb-generation-status" class="xb-status-info" style="display:none;"></div>
                    
                    <?php
                    // 使用WordPress经典编辑器
                    wp_editor('', 'xb-article-content', array(
                        'textarea_name' => 'article_content',
                        'textarea_rows' => 20,
                        'media_buttons' => true,
                        'teeny' => false,
                        'dfw' => false,
                        'tinymce' => array(
                            'resize' => false,
                            'wp_autoresize_on' => true,
                        )
                    ));
                    ?>
                    
                    <h3>发布设置</h3>
                    <table class="form-table">
                        <tr>
                            <th scope="row">文章分类</th>
                            <td>
                                <div class="xb-category-selector">
                                    <div class="xb-category-list">
                                        <?php
                                        $categories = get_categories(array('hide_empty' => false));
                                        if (empty($categories)) {
                                            echo '<p class="description">暂无分类，请先在WordPress后台创建分类</p>';
                                        } else {
                                            foreach ($categories as $category) {
                                                echo '<label class="xb-category-item">';
                                                echo '<input type="checkbox" name="xb-article-categories[]" value="' . $category->term_id . '" />';
                                                echo '<span>' . esc_html($category->name) . '</span>';
                                                echo '</label>';
                                            }
                                        }
                                        ?>
                                    </div>
                                </div>
                                <p class="description">可选择多个分类</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">文章标签</th>
                            <td>
                                <input type="text" id="xb-article-tags" class="regular-text" placeholder="标签1，标签2 标签3；标签4" />
                                <p class="description">多个标签可用：中文逗号（，）、英文逗号（,）、分号（；;）分隔</p>
                            </td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <button type="button" id="xb-publish-article" class="button-primary" disabled>发布文章</button>
                        <button type="button" id="xb-save-draft" class="button" disabled>保存草稿</button>
                    </p>
                </div>
                
                <div class="xb-debug-info">
                    <h3>日志信息</h3>
                    <div id="xb-debug-log" class="xb-debug-content"></div>
                </div>
            </div>
        </div>
        
        <style>
        .xb-aiwencre-create-container {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 20px;
            margin-top: 20px;
        }
        .xb-create-form {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .xb-article-editor {
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .xb-debug-info {
            grid-column: 1 / -1;
            background: #fff;
            padding: 20px;
            border: 1px solid #ccd0d4;
            box-shadow: 0 1px 1px rgba(0,0,0,.04);
        }
        .xb-debug-content {
            background: #f1f1f1;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
            max-height: 200px;
            overflow-y: auto;
        }
        .xb-status-info {
            padding: 10px;
            margin: 10px 0;
            border-radius: 4px;
            background: #e7f3ff;
            border-left: 4px solid #0073aa;
        }
        .xb-status-info.error {
            background: #ffebe7;
            border-left-color: #dc3232;
        }
        .xb-status-info.success {
            background: #e7ffe7;
            border-left-color: #46b450;
        }
        .xb-category-selector {
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fff;
        }
        .xb-category-list {
            max-height: 150px;
            overflow-y: auto;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: #fafafa;
        }
        .xb-category-item {
            display: block;
            padding: 4px 8px;
            margin: 2px 0;
            cursor: pointer;
            border-radius: 3px;
            transition: background-color 0.2s;
        }
        .xb-category-item:hover {
            background-color: #e8f4fd;
        }
        .xb-category-item input[type="checkbox"] {
            margin-right: 8px;
        }
        .xb-category-item span {
            font-size: 13px;
        }
        .xb-category-selector {
            max-width: 400px;
        }
        .xb-category-list {
            max-height: 150px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 8px;
            background: #fff;
        }
        .xb-category-item {
            display: block;
            padding: 4px 0;
            margin: 0;
            cursor: pointer;
            font-weight: normal;
        }
        .xb-category-item:hover {
            background: #f0f0f0;
            border-radius: 2px;
        }
        .xb-category-item input[type="checkbox"] {
            margin-right: 8px;
            vertical-align: middle;
        }
        .xb-category-item span {
            vertical-align: middle;
        }
        
        /* 通知样式 */
        .xb-notification {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%) scale(0.8);
            padding: 16px 24px;
            border-radius: 6px;
            color: #fff;
            font-weight: 500;
            font-size: 16px;
            z-index: 999999;
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
            opacity: 0;
            transition: all 0.3s ease;
            min-width: 200px;
            text-align: center;
        }
        
        .xb-notification.show {
            opacity: 1;
            transform: translate(-50%, -50%) scale(1);
        }
        
        .xb-notification.success {
            background-color: #46b450;
        }
        
        .xb-notification.error {
            background-color: #dc3232;
        }

        /* 使用说明板块样式 */
        .xb-usage-notice {
            background: #f8f9fa;
            border: 1px solid #e1e5e9;
            border-radius: 6px;
            padding: 16px;
            margin-top: 15px;
            font-size: 13px;
            line-height: 1.5;
        }
        
        .xb-usage-notice h4 {
            margin: 0 0 12px 0;
            color: #2c3e50;
            font-size: 14px;
            font-weight: 600;
        }
        
        .xb-usage-notice ul {
            margin: 0 0 12px 0;
            padding-left: 20px;
        }
        
        .xb-usage-notice li {
            margin-bottom: 6px;
            color: #555;
        }
        
        .xb-usage-notice li strong {
            color: #2c3e50;
        }        
        </style>
        
        <script>
        // 通知函数 - 全局定义
        function showNotification(message, type = 'success') {
            // 移除已存在的通知
            jQuery('.xb-notification').remove();
            
            // 创建新通知
            var notification = jQuery('<div class="xb-notification ' + type + '">' + message + '</div>');
            jQuery('body').append(notification);
            
            // 显示通知
            setTimeout(function() {
                notification.addClass('show');
            }, 100);
            
            // 3秒后自动隐藏
            setTimeout(function() {
                notification.removeClass('show');
                setTimeout(function() {
                    notification.remove();
                }, 300);
            }, 3000);
        }
        
        jQuery(document).ready(function($) {
            // 平台选择变化时更新模型列表
            $('#xb-platform-select').change(function() {
                var selectedOption = $(this).find('option:selected');
                var models = selectedOption.data('models');
                var modelSelect = $('#xb-model-select');
                
                modelSelect.empty().prop('disabled', false);
                
                if (models) {
                    var modelArray = models.split(',');
                    $.each(modelArray, function(index, model) {
                        modelSelect.append('<option value="' + model.trim() + '">' + model.trim() + '</option>');
                    });
                } else {
                    modelSelect.append('<option value="">无可用模型</option>').prop('disabled', true);
                }
            });
            
            // 生成文章 - 先测试基本AJAX连接
            $('#xb-generate-article').click(function() {
                var platformId = $('#xb-platform-select').val();
                var model = $('#xb-model-select').val();
                var keywords = $('#xb-keywords').val();
                var enableSearch = $('#xb-enable-search').is(':checked');
                var siteName = $('#xb-site-name').val();
                var internalLink = $('#xb-internal-link').val();
                var wordCount = $('#xb-word-count').val();
                var articleLanguage = $('#xb-article-language').val();
                
                if (!platformId || !model || !keywords) {
                    showNotification('请填写必要的信息：AI平台、模型和关键词', 'error');
                    return;
                }
                
                // 开始生成
                $(this).prop('disabled', true).text('测试中...');
                $('#xb-generation-status').show().removeClass('error success').text('正在测试AJAX连接...');
                
                // 清空调试信息
                $('#xb-debug-log').empty();
                $('#xb-debug-log').append('<div>[开始] 测试AJAX连接...</div>');
                
                console.log('发送AJAX请求，参数:', {
                    platform_id: platformId,
                    model: model,
                    keywords: keywords,
                    enable_search: enableSearch ? 1 : 0,
                    site_name: siteName,
                    internal_link: internalLink,
                    word_count: wordCount || 800,
                    article_language: articleLanguage
                });
                
                // 清空标题、编辑器和调试信息
                $('#xb-article-title').val('');
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                    tinyMCE.get('xb-article-content').setContent('');
                } else {
                    $('#xb-article-content').val('');
                }
                
                // 构建EventSource URL
                var sseUrl = xb_aiwencre_ajax.ajax_url + '?action=xb_aiwencre_stream_generate'
                    + '&platform_id=' + encodeURIComponent(platformId)
                    + '&model=' + encodeURIComponent(model)
                    + '&keywords=' + encodeURIComponent(keywords)
                    + '&enable_search=' + encodeURIComponent(enableSearch ? 1 : 0)
                    + '&site_name=' + encodeURIComponent(siteName)
                    + '&internal_link=' + encodeURIComponent(internalLink)
                    + '&word_count=' + encodeURIComponent(wordCount || 800)
                    + '&article_language=' + encodeURIComponent(articleLanguage)
                    + '&nonce=' + encodeURIComponent(xb_aiwencre_ajax.nonce);
                
                console.log('开始流式生成，URL:', sseUrl);
                $('#xb-debug-log').append('<div>[开始] 连接URL: ' + sseUrl + '</div>');
                
                // 创建EventSource连接
                var eventSource = new EventSource(sseUrl);
                
                eventSource.onopen = function(event) {
                    console.log('EventSource连接已建立');
                    $('#xb-generation-status').removeClass('error success').text('已连接AI服务，开始生成...');
                    $('#xb-debug-log').append('<div style="color: green;">[连接] EventSource连接已建立</div>');
                    var debugDiv = $('#xb-debug-log')[0];
                    debugDiv.scrollTop = debugDiv.scrollHeight;
                };
                
                eventSource.onmessage = function(event) {
                    console.log('收到SSE数据:', event.data);
                    
                    try {
                        var data = JSON.parse(event.data);
                        
                        if (data.type === 'error') {
                            console.error('生成错误:', data.content);
                            $('#xb-generation-status').addClass('error').text('生成失败：' + data.content);
                            $('#xb-debug-log').append('<div style="color: red;">[错误] ' + data.content + '</div>');
                            eventSource.close();
                            $('#xb-generate-article').prop('disabled', false).text('生成文章');
                            $('#xb-stop-generate').hide();
                            return;
                        }
                        
                        if (data.type === 'debug') {
                            $('#xb-debug-log').append('<div style="color: blue;">[调试] ' + data.content + '</div>');
                            var debugDiv = $('#xb-debug-log')[0];
                            debugDiv.scrollTop = debugDiv.scrollHeight;
                        }
                        
                        if (data.type === 'title') {
                            $('#xb-article-title').val(data.content);
                            $('#xb-debug-log').append('<div style="color: green;">[标题] ' + data.content + '</div>');
                        }
                        
                        if (data.type === 'content') {
                            // 更新编辑器内容
                            if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                                var currentContent = tinyMCE.get('xb-article-content').getContent();
                                tinyMCE.get('xb-article-content').setContent(currentContent + data.content);
                            } else {
                                var currentContent = $('#xb-article-content').val();
                                $('#xb-article-content').val(currentContent + data.content);
                            }
                        }
                        
                        if (data.type === 'complete') {
                            $('#xb-generation-status').removeClass('error').addClass('success').text('文章生成完成！');
                            $('#xb-debug-log').append('<div style="color: green;">[完成] ' + data.content + '</div>');
                            $('#xb-publish-article, #xb-save-draft').prop('disabled', false);
                            eventSource.close();
                            $('#xb-generate-article').prop('disabled', false).text('生成文章');
                            $('#xb-stop-generate').hide();
                        }
                        
                    } catch (e) {
                        console.error('解析SSE数据错误:', e);
                        $('#xb-debug-log').append('<div style="color: red;">[错误] 数据解析失败: ' + e.message + '</div>');
                    }
                };
                
                eventSource.onerror = function(event) {
                    console.error('EventSource错误:', event);
                    $('#xb-generation-status').addClass('error').text('连接错误，请检查网络或配置');
                    $('#xb-debug-log').append('<div style="color: red;">[错误] EventSource连接失败</div>');
                    eventSource.close();
                    $('#xb-generate-article').prop('disabled', false).text('生成文章');
                    $('#xb-stop-generate').hide();
                };
                
                // 停止生成按钮事件
                $('#xb-stop-generate').off('click').on('click', function() {
                    eventSource.close();
                    $('#xb-generation-status').text('生成已停止');
                    $('#xb-generate-article').prop('disabled', false).text('生成文章');
                    $(this).hide();
                });
            });
            
            // 停止生成
            $('#xb-stop-generate').click(function() {
                $('#xb-generate-article').prop('disabled', false).text('生成文章');
                $(this).hide();
                $('#xb-generation-status').addClass('error').text('生成已停止');
            });
            
            // 发布文章
            $('#xb-publish-article').click(function() {
                var title = $('#xb-article-title').val().trim();
                var content = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                    content = tinyMCE.get('xb-article-content').getContent();
                } else {
                    content = $('#xb-article-content').val();
                }
                
                // 获取选中的分类
                var categories = [];
                $('input[name="xb-article-categories[]"]:checked').each(function() {
                    categories.push($(this).val());
                });
                var tags = $('#xb-article-tags').val().trim();
                
                if (!title) {
                    showNotification('文章标题不能为空', 'error');
                    return;
                }
                
                if (!content.trim()) {
                    showNotification('文章内容不能为空', 'error');
                    return;
                }
                
                // 发布文章
                $.post(xb_aiwencre_ajax.ajax_url, {
                    action: 'xb_aiwencre_publish_post',
                    title: title,
                    content: content,
                    categories: categories,
                    tags: tags,
                    nonce: xb_aiwencre_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        showNotification('文章发布成功！', 'success');
                        // 清空所有字段
                        $('#xb-article-title').val('');
                        $('input[name="xb-article-categories[]"]').prop('checked', false);
                        $('#xb-article-tags').val('');
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                            tinyMCE.get('xb-article-content').setContent('');
                        } else {
                            $('#xb-article-content').val('');
                        }
                        // 清除状态提示信息
                        $('#xb-generation-status').hide().removeClass('success error').text('');
                        $('#xb-publish-article, #xb-save-draft').prop('disabled', true);
                    } else {
                        showNotification('发布失败：' + response.data, 'error');
                    }
                });
            });
            
            // 保存草稿
            $('#xb-save-draft').click(function() {
                var title = $('#xb-article-title').val().trim();
                var content = '';
                if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                    content = tinyMCE.get('xb-article-content').getContent();
                } else {
                    content = $('#xb-article-content').val();
                }
                
                // 获取选中的分类
                var selectedCategories = [];
                $('input[name="xb-article-categories[]"]:checked').each(function() {
                    selectedCategories.push($(this).val());
                });
                
                var tags = $('#xb-article-tags').val().trim();
                
                if (!content.trim()) {
                    showNotification('文章内容不能为空', 'error');
                    return;
                }
                
                // 如果没有标题，使用关键词作为标题
                if (!title) {
                    var keywords = $('#xb-keywords').val();
                    title = keywords.split(',')[0] || '未命名草稿';
                }
                
                // 保存草稿
                $.post(xb_aiwencre_ajax.ajax_url, {
                    action: 'xb_aiwencre_save_draft',
                    title: title,
                    content: content,
                    categories: selectedCategories,
                    tags: tags,
                    nonce: xb_aiwencre_ajax.nonce
                }, function(response) {
                    if (response.success) {
                        showNotification('草稿保存成功！', 'success');
                        // 清空所有字段
                        $('#xb-article-title').val('');
                        $('input[name="xb-article-categories[]"]').prop('checked', false);
                        $('#xb-article-tags').val('');
                        if (typeof tinyMCE !== 'undefined' && tinyMCE.get('xb-article-content')) {
                            tinyMCE.get('xb-article-content').setContent('');
                        } else {
                            $('#xb-article-content').val('');
                        }
                        // 清除状态提示信息
                        $('#xb-generation-status').hide().removeClass('success error').text('');
                        $('#xb-publish-article, #xb-save-draft').prop('disabled', true);
                    } else {
                        showNotification('保存失败：' + response.data, 'error');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * AJAX生成文章处理
     */
    public function xb_aiwencre_ajax_generate_article() {
        // 验证nonce
        if (!wp_verify_nonce($_POST['nonce'], 'xb_aiwencre_nonce')) {
            wp_send_json_error('安全验证失败');
        }
        
        // 检查权限
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        
        // 获取参数
        $platform_id = intval($_POST['platform_id']);
        $model = sanitize_text_field($_POST['model']);
        $keywords = sanitize_text_field($_POST['keywords']);
        $enable_search = isset($_POST['enable_search']) && $_POST['enable_search'] === 'true';
        $site_name = sanitize_text_field($_POST['site_name']);
        $internal_link = esc_url_raw($_POST['internal_link']);
        $word_count = intval($_POST['word_count']) ?: 800;
        
        // 验证必要参数
        if (empty($platform_id) || empty($model) || empty($keywords)) {
            wp_send_json_error('缺少必要参数：平台ID、模型或关键词');
        }
        
        $debug_log = array();
        $debug_log[] = '[开始] 处理文章生成请求';
        $debug_log[] = '[参数] 平台ID: ' . $platform_id . ', 模型: ' . $model . ', 关键词: ' . $keywords;
        
        // 获取平台配置
        global $wpdb;
        $platform = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d AND is_active = 1",
            $platform_id
        ));
        
        if (!$platform) {
            wp_send_json_error('平台配置不存在或已禁用');
        }
        
        $debug_log[] = '[平台] ' . $platform->platform_name . ' - ' . $platform->api_endpoint;
        
        // 准备API密钥列表
        $api_keys = array_filter(array_map('trim', explode(',', $platform->api_keys)));
        if (empty($api_keys)) {
            wp_send_json_error('未配置API密钥');
        }
        
        $debug_log[] = '[密钥] 共 ' . count($api_keys) . ' 个密钥可用';
        
        // 构建AI提示词
        $prompt = $this->xb_aiwencre_build_prompt($keywords, $site_name, $internal_link, $word_count);
        $debug_log[] = '[提示词] 长度: ' . strlen($prompt) . ' 字符';
        
        // 构建请求数据
        $request_data = array(
            'model' => $model,
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => '你是一位资深的中文内容创作专家，擅长撰写高质量、有价值的原创文章。请根据用户要求创作符合SEO优化标准的专业文章。'
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'temperature' => 0.7,
            'max_tokens' => $word_count * 2
        );
        
        // 如果启用联网搜索
        if ($enable_search) {
            $request_data['enable_search'] = true;
            $debug_log[] = '[搜索] 已启用联网搜索功能';
        }
        
        // 尝试调用API（支持多密钥重试）
        $article_content = '';
        $success = false;
        
        foreach ($api_keys as $index => $api_key) {
            $debug_log[] = '[尝试] 使用API密钥 ' . ($index + 1) . '/' . count($api_keys);
            
            $result = $this->xb_aiwencre_call_ai_api($platform->api_endpoint, $api_key, $request_data);
            
            if ($result['success']) {
                $article_content = $result['content'];
                $success = true;
                $debug_log[] = '[成功] 文章生成完成，长度: ' . strlen($article_content) . ' 字符';
                break;
            } else {
                $debug_log[] = '[失败] ' . $result['error'];
                if ($index < count($api_keys) - 1) {
                    $debug_log[] = '[重试] 尝试下一个API密钥';
                }
            }
        }
        
        if (!$success) {
            $debug_log[] = '[错误] 所有API密钥都无法正常工作';
            wp_send_json_error('所有API密钥都无法正常工作，请检查配置');
        }
        
        // 返回成功结果
        wp_send_json_success(array(
            'content' => $article_content,
            'debug' => implode('<br>', $debug_log)
        ));
    }
    
    
    /**
     * 构建AI提示词
     */
    private function xb_aiwencre_build_prompt($keywords, $site_name, $internal_link, $word_count) {
        $keywords_array = array_map('trim', explode(',', $keywords));
        $main_keyword = $keywords_array[0];
        
        $prompt = "请根据以下要求创作一篇高质量的中文文章：\n\n";
        $prompt .= "主要关键词：{$main_keyword}\n";
        
        if (count($keywords_array) > 1) {
            $secondary_keywords = array_slice($keywords_array, 1);
            $prompt .= "相关关键词：" . implode('、', $secondary_keywords) . "\n";
        }
        
        $prompt .= "文章字数：约{$word_count}字\n\n";
        
        if (!empty($site_name)) {
            $prompt .= "请在文章中自然提及网站名称：{$site_name}\n";
        }
        
        if (!empty($internal_link)) {
            $prompt .= "请在文章中合适位置包含此链接：{$internal_link}\n";
        }
        
        $prompt .= "\n文章要求：\n";
        $prompt .= "1. 内容原创且有价值，信息准确\n";
        $prompt .= "2. 结构清晰，包含标题、导语、正文、结尾\n";
        $prompt .= "3. 语言流畅自然，符合中文表达习惯\n";
        $prompt .= "4. 自然融入关键词，避免堆砌\n";
        $prompt .= "5. 使用HTML格式输出，包含适当的标签\n\n";
        
        $prompt .= "请开始创作：";
        
        return $prompt;
    }
    
    /**
     * 调用AI API
     */
    private function xb_aiwencre_call_ai_api($endpoint, $api_key, $request_data) {
        $headers = array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json'
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request_data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        // 记录到调试日志
        $this->xb_aiwencre_write_debug_log('API调用', array(
            'endpoint' => $endpoint,
            'http_code' => $http_code,
            'curl_error' => $curl_error,
            'response_length' => strlen($response)
        ));
        
        if ($curl_error) {
            return array('success' => false, 'error' => '网络错误: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            return array('success' => false, 'error' => 'HTTP错误: ' . $http_code);
        }
        
        $decoded = json_decode($response, true);
        if (!$decoded) {
            return array('success' => false, 'error' => '响应解析失败');
        }
        
        if (isset($decoded['error'])) {
            return array('success' => false, 'error' => 'API错误: ' . $decoded['error']['message']);
        }
        
        if (isset($decoded['choices'][0]['message']['content'])) {
            return array('success' => true, 'content' => $decoded['choices'][0]['message']['content']);
        }
        
        return array('success' => false, 'error' => '未找到有效内容');
    }
    
    /**
     * 写入调试日志
     */
    private function xb_aiwencre_write_debug_log($message, $data = null) {
        $log_entry = array(
            'timestamp' => current_time('Y-m-d H:i:s'),
            'message' => $message,
            'data' => $data
        );
        
        $log_content = '[XB AI文章创作] ' . json_encode($log_entry, JSON_UNESCAPED_UNICODE) . "\n";
        
        // 写入WordPress调试日志
        error_log($log_content);
        
        // 写入自定义日志文件
        $log_file = WP_CONTENT_DIR . '/debug.log';
        if (!file_exists($log_file)) {
            touch($log_file);
        }
        if (is_writable($log_file)) {
            file_put_contents($log_file, $log_content, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * 保存配置
     */
    private function xb_aiwencre_save_config() {
        if (!wp_verify_nonce($_POST['xb_aiwencre_config_nonce'], 'xb_aiwencre_config')) {
            wp_die('安全验证失败');
        }
        
        global $wpdb;
        
        $platform_name = sanitize_text_field($_POST['platform_name']);
        $api_endpoint = esc_url_raw($_POST['api_endpoint']);
        $api_models = sanitize_text_field($_POST['api_models']);
        $api_keys = sanitize_textarea_field($_POST['api_keys']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'platform_name' => $platform_name,
                'api_endpoint' => $api_endpoint,
                'api_models' => $api_models,
                'api_keys' => $api_keys,
                'is_active' => $is_active
            )
        );
        
        if ($result) {
            echo '<div class="notice notice-success"><p>配置保存成功！</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>配置保存失败！</p></div>';
        }
    }
    
    /**
     * 更新配置
     */
    private function xb_aiwencre_update_config() {
        if (!wp_verify_nonce($_POST['xb_aiwencre_config_nonce'], 'xb_aiwencre_config')) {
            wp_die('安全验证失败');
        }
        
        global $wpdb;
        
        $platform_id = intval($_POST['platform_id']);
        $platform_name = sanitize_text_field($_POST['platform_name']);
        $api_endpoint = esc_url_raw($_POST['api_endpoint']);
        $api_models = sanitize_text_field($_POST['api_models']);
        $api_keys = sanitize_textarea_field($_POST['api_keys']);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        $result = $wpdb->update(
            $this->table_name,
            array(
                'platform_name' => $platform_name,
                'api_endpoint' => $api_endpoint,
                'api_models' => $api_models,
                'api_keys' => $api_keys,
                'is_active' => $is_active
            ),
            array('id' => $platform_id)
        );
        
        if ($result !== false) {
            echo '<div class="notice notice-success"><p>配置更新成功！</p></div>';
            echo '<script>window.location.href = "' . admin_url('admin.php?page=xb-aiwencre') . '";</script>';
        } else {
            echo '<div class="notice notice-error"><p>配置更新失败！</p></div>';
        }
    }
    
    /**
     * 注册REST API路由
     */
    public function xb_aiwencre_register_rest_routes() {
        register_rest_route('xb-aiwencre/v1', '/stream-generate', array(
            'methods' => 'GET',
            'callback' => array($this, 'xb_aiwencre_rest_stream_generate'),
            'permission_callback' => array($this, 'xb_aiwencre_check_rest_permission'),
            'args' => array(
                'platform_id' => array('required' => true),
                'model' => array('required' => true),
                'keywords' => array('required' => true),
                'nonce' => array('required' => true)
            )
        ));
    }
    
    /**
     * REST API流式生成处理
     */
    public function xb_aiwencre_rest_stream_generate($request) {
        // 验证nonce
        $nonce = $request->get_param('nonce');
        if (!wp_verify_nonce($nonce, 'xb_aiwencre_nonce')) {
            return new WP_Error('invalid_nonce', '安全验证失败', array('status' => 403));
        }
        
        // 获取参数
        $params = array(
            'platform_id' => intval($request->get_param('platform_id')),
            'model' => sanitize_text_field($request->get_param('model')),
            'keywords' => sanitize_text_field($request->get_param('keywords')),
            'enable_search' => $request->get_param('enable_search') === 'true',
            'site_name' => sanitize_text_field($request->get_param('site_name')),
            'internal_link' => esc_url_raw($request->get_param('internal_link')),
            'word_count' => intval($request->get_param('word_count')) ?: 800
        );
        
        // 引入API处理文件
        require_once(plugin_dir_path(__FILE__) . 'xb-aiwencre-api.php');
        
        // 创建API处理器实例
        $api_handler = new XB_AIWenCre_API_Handler();
        
        // 执行流式生成
        $api_handler->xb_aiwencre_stream_generate($params);
        
        // 这里不会执行到，因为stream_generate会exit
        exit;
    }
    
    /**
     * 检查REST API权限
     */
    public function xb_aiwencre_check_rest_permission($request) {
        return current_user_can('manage_options');
    }
    
    /**
     * AJAX流式生成处理函数
     */
    public function xb_aiwencre_stream_generate_ajax() {
        // 验证nonce
        if (!wp_verify_nonce($_GET['nonce'], 'xb_aiwencre_nonce')) {
            echo "data: " . json_encode(['type' => 'error', 'content' => '安全验证失败'], JSON_UNESCAPED_UNICODE) . "

";
            exit;
        }
        
        // 验证权限
        if (!current_user_can('manage_options')) {
            echo "data: " . json_encode(['type' => 'error', 'content' => '权限不足'], JSON_UNESCAPED_UNICODE) . "

";
            exit;
        }
        
        // 设置SSE头部
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');
        
        // 禁用输出缓冲
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 发送初始调试信息
        echo "data: " . json_encode(['type' => 'debug', 'content' => '开始处理AJAX请求...'], JSON_UNESCAPED_UNICODE) . "

";
        flush();
        
        // 获取参数
        $params = array(
            'platform_id' => sanitize_text_field($_GET['platform_id']),
            'model' => sanitize_text_field($_GET['model']),
            'keywords' => sanitize_text_field($_GET['keywords']),
            'enable_search' => sanitize_text_field($_GET['enable_search']),
            'site_name' => sanitize_text_field($_GET['site_name']),
            'internal_link' => sanitize_text_field($_GET['internal_link']),
            'word_count' => intval($_GET['word_count']) ?: 800,
            'article_language' => sanitize_text_field($_GET['article_language']) ?: '中文'
        );
        
        echo "data: " . json_encode(['type' => 'debug', 'content' => '参数获取完成: ' . json_encode($params)], JSON_UNESCAPED_UNICODE) . "

";
        flush();
        
        // 引入API处理文件
        require_once(plugin_dir_path(__FILE__) . 'xb-aiwencre-api.php');
        
        echo "data: " . json_encode(['type' => 'debug', 'content' => 'API处理文件加载完成'], JSON_UNESCAPED_UNICODE) . "

";
        flush();
        
        // 创建API处理器实例
        $api_handler = new XB_AIWenCre_API_Handler();
        
        echo "data: " . json_encode(['type' => 'debug', 'content' => 'API处理器实例创建完成'], JSON_UNESCAPED_UNICODE) . "

";
        flush();
        
        // 调用流式生成
        $api_handler->xb_aiwencre_stream_generate($params);
        
        exit;
    }
    
    /**
     * 发布文章回调
     */
    public function xb_aiwencre_publish_post_callback() {
        if (!wp_verify_nonce($_POST['nonce'], 'xb_aiwencre_nonce')) {
            wp_send_json_error('安全验证失败');
        }
        
        if (!current_user_can('publish_posts')) {
            wp_send_json_error('权限不足');
        }
        
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        $categories = isset($_POST['categories']) ? $_POST['categories'] : array();
        $tags = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';
        
        if (empty($title) || empty($content)) {
            wp_send_json_error('标题和内容不能为空');
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'publish',
            'post_type' => 'post',
            'post_author' => get_current_user_id()
        );
        
        // 设置分类 - 支持多选
        if (!empty($categories) && is_array($categories)) {
            $category_ids = array_map('intval', $categories);
            $category_ids = array_filter($category_ids, function($id) { return $id > 0; });
            if (!empty($category_ids)) {
                $post_data['post_category'] = $category_ids;
            }
        }
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            // 设置标签 - 支持多种分隔符
            if (!empty($tags)) {
                // 将各种分隔符替换为英文逗号
                $tags = str_replace(array('，', '；', ';'), ',', $tags);
                // 将多个空格替换为逗号
                $tags = preg_replace('/\s+/', ',', $tags);
                // 清理多余的逗号
                $tags = preg_replace('/,+/', ',', $tags);
                $tags = trim($tags, ',');
                
                // 分割并过滤空标签
                $tags_array = array_filter(array_map('trim', explode(',', $tags)));
                
                if (!empty($tags_array)) {
                    $result = wp_set_post_tags($post_id, $tags_array);
                    // 调试信息
                    error_log('XB AI文章创作 - 设置标签: ' . print_r($tags_array, true) . ' 结果: ' . ($result ? '成功' : '失败'));
                }
            }
            
            wp_send_json_success('文章发布成功，ID: ' . $post_id);
        } else {
            $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : '文章发布失败';
            wp_send_json_error($error_msg);
        }
    }
    
    /**
     * 保存草稿回调
     */
    public function xb_aiwencre_save_draft_callback() {
        if (!wp_verify_nonce($_POST['nonce'], 'xb_aiwencre_nonce')) {
            wp_send_json_error('安全验证失败');
        }
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('权限不足');
        }
        
        $title = sanitize_text_field($_POST['title']);
        $content = wp_kses_post($_POST['content']);
        $categories = isset($_POST['categories']) ? $_POST['categories'] : array();
        $tags = isset($_POST['tags']) ? sanitize_text_field($_POST['tags']) : '';
        
        if (empty($title) || empty($content)) {
            wp_send_json_error('标题和内容不能为空');
        }
        
        $post_data = array(
            'post_title' => $title,
            'post_content' => $content,
            'post_status' => 'draft',
            'post_type' => 'post',
            'post_author' => get_current_user_id()
        );
        
        // 设置分类 - 支持多选
        if (!empty($categories) && is_array($categories)) {
            $category_ids = array_map('intval', $categories);
            $category_ids = array_filter($category_ids, function($id) { return $id > 0; });
            if (!empty($category_ids)) {
                $post_data['post_category'] = $category_ids;
            }
        }
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            // 设置标签 - 支持多种分隔符
            if (!empty($tags)) {
                // 将各种分隔符替换为英文逗号
                $tags = str_replace(array('，', '；', ';'), ',', $tags);
                // 将多个空格替换为逗号
                $tags = preg_replace('/\s+/', ',', $tags);
                // 清理多余的逗号
                $tags = preg_replace('/,+/', ',', $tags);
                $tags = trim($tags, ',');
                
                // 分割并过滤空标签
                $tags_array = array_filter(array_map('trim', explode(',', $tags)));
                
                if (!empty($tags_array)) {
                    wp_set_post_tags($post_id, $tags_array);
                }
            }
            
            wp_send_json_success('草稿保存成功，ID: ' . $post_id);
        } else {
            $error_msg = is_wp_error($post_id) ? $post_id->get_error_message() : '草稿保存失败';
            wp_send_json_error($error_msg);
        }
    }
    
    /**
     * 添加插件设置链接
     */
    public function xb_aiwencre_add_settings_link($links) {
        $settings_link = '<a href="' . admin_url('admin.php?page=xb-aiwencre') . '">设置</a>';
        array_unshift($links, $settings_link);
        return $links;
    }
    
    /**
     * 测试连接回调
     */
    public function xb_aiwencre_test_connection_callback() {
        if (!wp_verify_nonce($_POST['nonce'], 'xb_aiwencre_nonce')) {
            wp_send_json_error('安全验证失败');
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('权限不足');
        }
        
        $platform_id = intval($_POST['platform_id']);
        
        // 引入API处理文件
        require_once(plugin_dir_path(__FILE__) . 'xb-aiwencre-api.php');
        
        // 创建API处理器实例
        $api_handler = new XB_AIWenCre_API_Handler();
        
        // 测试连接
        $result = $api_handler->xb_aiwencre_test_api_connection($platform_id);
        
        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}

// 初始化插件
new XB_AIWenCre_Plugin();

?>