<?php
/**
 * 小半WP AI文章创作 - API处理文件
 * 专门处理AI接口调用和流式输出
 */

// 防止直接访问
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AI API处理类
 */
class XB_AIWenCre_API_Handler {
    
    private $table_name;
    private $current_title = '';
    private $current_content = '';
    private $title_extracted = false;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'xb_aiwencre_settings';
    }
    
    /**
     * 流式生成文章
     */
    public function xb_aiwencre_stream_generate($params) {
        // 设置SSE头部
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: *');
        header('X-Accel-Buffering: no');
        
        // 禁用输出缓冲
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        // 验证参数
        if (empty($params['platform_id']) || empty($params['model']) || empty($params['keywords'])) {
            $this->xb_aiwencre_send_sse_error('缺少必要参数：平台ID、模型或关键词');
            exit;
        }
        
        $this->xb_aiwencre_send_sse_debug('开始处理文章生成请求...');
        
        // 获取平台配置
        $platform = $this->xb_aiwencre_get_platform_config($params['platform_id']);
        if (!$platform) {
            $this->xb_aiwencre_send_sse_error('平台配置不存在或已禁用');
            exit;
        }
        
        $this->xb_aiwencre_send_sse_debug('平台: ' . $platform->platform_name);
        
        // 准备API密钥
        $api_keys = array_filter(array_map('trim', explode(',', $platform->api_keys)));
        if (empty($api_keys)) {
            $this->xb_aiwencre_send_sse_error('未配置API密钥');
            exit;
        }
        
        $this->xb_aiwencre_send_sse_debug('可用密钥数量: ' . count($api_keys));
        
        // 构建AI提示词
        $prompt = $this->xb_aiwencre_build_enhanced_prompt($params);
        
        // 根据语言设置系统提示词
        $article_language = isset($params['article_language']) ? $params['article_language'] : '中文';
        $system_prompt = $this->xb_aiwencre_get_system_prompt($article_language);
        
        // 构建请求数据
        $request_data = array(
            'model' => $params['model'],
            'messages' => array(
                array(
                    'role' => 'system',
                    'content' => $system_prompt
                ),
                array(
                    'role' => 'user',
                    'content' => $prompt
                )
            ),
            'stream' => true,
            'temperature' => 0.7,
            'max_tokens' => intval($params['word_count']) * 3
        );
        
        // 如果启用联网搜索
        if (isset($params['enable_search']) && $params['enable_search']) {
            $request_data['enable_search'] = true;
            $this->xb_aiwencre_send_sse_debug('已启用联网搜索功能');
        }
        
        // 尝试调用API（支持多密钥重试）
        $success = false;
        foreach ($api_keys as $index => $api_key) {
            $this->xb_aiwencre_send_sse_debug('尝试使用API密钥 ' . ($index + 1) . '/' . count($api_keys));
            
            if ($this->xb_aiwencre_make_stream_api_call($platform->api_endpoint, $api_key, $request_data)) {
                $success = true;
                break;
            }
            
            if ($index < count($api_keys) - 1) {
                $this->xb_aiwencre_send_sse_debug('API密钥失效，尝试下一个...');
            }
        }
        
        if (!$success) {
            $this->xb_aiwencre_send_sse_error('所有API密钥都无法正常工作，请检查配置');
        }
        
        exit;
    }
    
    /**
     * 构建AI提示词
     */
    private function xb_aiwencre_build_enhanced_prompt($params) {
        $keywords_array = array_map('trim', explode(',', $params['keywords']));
        $main_keyword = $keywords_array[0];
        $word_count = intval($params['word_count']) ?: 800;
        $article_language = isset($params['article_language']) ? $params['article_language'] : '中文';
        
        // 根据语言设置不同的提示词
        $language_instructions = $this->xb_aiwencre_get_language_instructions($article_language);
        
        $prompt = "请创作一篇高质量的{$article_language}文章，严格按照以下格式输出：

";
        $prompt .= "===TITLE_START===
";
        $prompt .= "[在这里写{$article_language}文章标题]
";
        $prompt .= "===TITLE_END===

";
        $prompt .= "===CONTENT_START===
";
        $prompt .= "[在这里写{$article_language}文章正文内容]
";
        $prompt .= "===CONTENT_END===

";
        
        $prompt .= "## 创作要求：
";
        $prompt .= "**语言要求**：必须使用{$article_language}进行创作，{$language_instructions['style_guide']}
";
        $prompt .= "**主关键词**：{$main_keyword}
";
        
        if (count($keywords_array) > 1) {
            $secondary_keywords = array_slice($keywords_array, 1);
            $prompt .= "**相关关键词**：" . implode('、', $secondary_keywords) . "
";
        }
        
        $prompt .= "**目标字数**：{$word_count}{$language_instructions['word_unit']}
";
        
        if (!empty($params['site_name'])) {
            $prompt .= "**网站名称**：请在文章中自然提及 \"{$params['site_name']}\"
";
        }
        
        if (!empty($params['internal_link'])) {
            $prompt .= "**内链要求**：请在文章中包含链接 {$params['internal_link']}
";
        }
        
        $prompt .= "
## 内容要求：
";
        $prompt .= "1. 标题要吸引人，包含主关键词，符合{$article_language}表达习惯
";
        $prompt .= "2. 文章结构清晰，包含引言、主体、结论
";
        $prompt .= "3. 内容原创有价值，语言流畅自然，{$language_instructions['content_style']}
";
        $prompt .= "4. 自然融入关键词，避免堆砌
";
        $prompt .= "5. 使用HTML格式，包含适当的标签
";
        $prompt .= "6. 必须严格按照上述格式输出，便于程序分离标题和内容
";
        $prompt .= "7. {$language_instructions['special_requirements']}

";
        
        $prompt .= "现在请开始创作：";
        
        return $prompt;
    }
    
    /**
     * 获取不同语言的指导说明
     */
    private function xb_aiwencre_get_language_instructions($language) {
        $instructions = array(
            '中文' => array(
                'style_guide' => '使用标准的现代汉语，语法正确，表达地道',
                'word_unit' => '字',
                'content_style' => '符合中文表达习惯和阅读习惯',
                'special_requirements' => '注意中文标点符号的正确使用，段落层次分明'
            ),
            '英文' => array(
                'style_guide' => 'Use proper English grammar, vocabulary, and sentence structure',
                'word_unit' => ' words',
                'content_style' => 'follow English writing conventions and readability standards',
                'special_requirements' => 'Use proper English punctuation and maintain consistent tense throughout'
            ),
            '日文' => array(
                'style_guide' => '正しい日本語の文法と敬語を使用し、自然な表現を心がける',
                'word_unit' => '文字',
                'content_style' => '日本語の表現習慣と読みやすさを重視する',
                'special_requirements' => '適切な敬語レベルを保ち、日本語特有の表現を活用する'
            ),
            '韩文' => array(
                'style_guide' => '올바른 한국어 문법과 존댓말을 사용하여 자연스러운 표현을 구사',
                'word_unit' => '글자',
                'content_style' => '한국어 표현 습관과 가독성을 중시',
                'special_requirements' => '적절한 존댓말 수준을 유지하고 한국어 특유의 표현을 활용'
            ),
            '法文' => array(
                'style_guide' => 'Utilisez une grammaire française correcte et un vocabulaire approprié',
                'word_unit' => ' mots',
                'content_style' => 'respectez les conventions d\'écriture française et la lisibilité',
                'special_requirements' => 'Maintenez la concordance des temps et utilisez la ponctuation française appropriée'
            ),
            '德文' => array(
                'style_guide' => 'Verwenden Sie korrekte deutsche Grammatik und angemessenes Vokabular',
                'word_unit' => ' Wörter',
                'content_style' => 'befolgen Sie deutsche Schreibkonventionen und Lesbarkeitsstandards',
                'special_requirements' => 'Achten Sie auf korrekte Groß- und Kleinschreibung sowie deutsche Satzzeichen'
            ),
            '西班牙文' => array(
                'style_guide' => 'Use gramática española correcta y vocabulario apropiado',
                'word_unit' => ' palabras',
                'content_style' => 'siga las convenciones de escritura española y estándares de legibilidad',
                'special_requirements' => 'Mantenga la concordancia verbal y use la puntuación española apropiada'
            ),
            '俄文' => array(
                'style_guide' => 'Используйте правильную русскую грамматику и подходящую лексику',
                'word_unit' => ' слов',
                'content_style' => 'следуйте русским письменным конвенциям и стандартам читаемости',
                'special_requirements' => 'Поддерживайте правильные падежные окончания и используйте русскую пунктуацию'
            )
        );
        
        return isset($instructions[$language]) ? $instructions[$language] : $instructions['中文'];
    }
    
    /**
     * 获取不同语言的系统提示词
     */
    private function xb_aiwencre_get_system_prompt($language) {
        $system_prompts = array(
            '中文' => '你是一位资深的中文内容创作专家。请严格按照用户要求的格式输出文章，确保标题和内容能够被正确分离。使用标准的现代汉语，语法正确，表达地道自然。',
            '英文' => 'You are an experienced English content creation expert. Please strictly follow the user\'s required format to output articles, ensuring that titles and content can be correctly separated. Use proper English grammar, vocabulary, and natural expression.',
            '日文' => 'あなたは経験豊富な日本語コンテンツ作成の専門家です。ユーザーの要求する形式に厳密に従って記事を出力し、タイトルと内容が正しく分離できるようにしてください。正しい日本語の文法と自然な表現を使用してください。',
            '韩文' => '당신은 경험이 풍부한 한국어 콘텐츠 제작 전문가입니다. 사용자가 요구하는 형식에 엄격히 따라 기사를 출력하고, 제목과 내용이 올바르게 분리될 수 있도록 해주세요. 올바른 한국어 문법과 자연스러운 표현을 사용해주세요.',
            '法文' => 'Vous êtes un expert expérimenté en création de contenu français. Veuillez suivre strictement le format requis par l\'utilisateur pour produire des articles, en vous assurant que les titres et le contenu peuvent être correctement séparés. Utilisez une grammaire française correcte et une expression naturelle.',
            '德文' => 'Sie sind ein erfahrener Experte für deutsche Inhaltserstellung. Bitte folgen Sie strikt dem vom Benutzer geforderten Format zur Ausgabe von Artikeln und stellen Sie sicher, dass Titel und Inhalt korrekt getrennt werden können. Verwenden Sie korrekte deutsche Grammatik und natürlichen Ausdruck.',
            '西班牙文' => 'Eres un experto experimentado en creación de contenido en español. Por favor, sigue estrictamente el formato requerido por el usuario para generar artículos, asegurándote de que los títulos y el contenido puedan ser separados correctamente. Usa gramática española correcta y expresión natural.',
            '俄文' => 'Вы опытный эксперт по созданию русского контента. Пожалуйста, строго следуйте формату, требуемому пользователем, для вывода статей, обеспечивая правильное разделение заголовков и содержания. Используйте правильную русскую грамматику и естественное выражение.'
        );
        
        return isset($system_prompts[$language]) ? $system_prompts[$language] : $system_prompts['中文'];
    }
    
    /**
     * 执行流式API调用
     */
    private function xb_aiwencre_make_stream_api_call($endpoint, $api_key, $request_data) {
        $headers = array(
            'Authorization: Bearer ' . $api_key,
            'Content-Type: application/json',
            'Accept: text/event-stream'
        );
        
        $this->xb_aiwencre_send_sse_debug('开始流式API调用...');
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($request_data, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_WRITEFUNCTION => array($this, 'xb_aiwencre_stream_callback'),
            CURLOPT_TIMEOUT => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true
        ));
        
        $result = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            $this->xb_aiwencre_send_sse_error('网络错误: ' . $curl_error);
            return false;
        }
        
        if ($http_code !== 200) {
            $this->xb_aiwencre_send_sse_error('HTTP错误: ' . $http_code);
            return false;
        }
        
        return $result !== false;
    }
    
    /**
     * 流式响应回调函数
     */
    public function xb_aiwencre_stream_callback($ch, $data) {
        $lines = explode("
", $data);
        
        foreach ($lines as $line) {
            $line = trim($line);
            
            if (empty($line) || $line === 'data: [DONE]') {
                continue;
            }
            
            if (strpos($line, 'data: ') === 0) {
                $json_data = substr($line, 6);
                $this->xb_aiwencre_process_stream_data($json_data);
            }
        }
        
        return strlen($data);
    }
    
    /**
     * 处理流式数据
     */
    private function xb_aiwencre_process_stream_data($json_data) {
        $decoded = json_decode($json_data, true);
        
        if (!$decoded) {
            return;
        }
        
        // 检查错误
        if (isset($decoded['error'])) {
            $error_msg = isset($decoded['error']['message']) ? $decoded['error']['message'] : '未知错误';
            $this->xb_aiwencre_send_sse_error('API返回错误: ' . $error_msg);
            return;
        }
        
        // 处理内容数据
        if (isset($decoded['choices'][0]['delta']['content'])) {
            $content = $decoded['choices'][0]['delta']['content'];
            if (!empty($content)) {
                $this->current_content .= $content;
                $this->xb_aiwencre_process_content_chunk($content);
            }
        }
        
        // 检查是否完成
        if (isset($decoded['choices'][0]['finish_reason']) && $decoded['choices'][0]['finish_reason'] === 'stop') {
            $this->xb_aiwencre_finalize_content();
        }
    }
    
    /**
     * 处理内容块 - 只收集，不立即发送
     */
    private function xb_aiwencre_process_content_chunk($chunk) {
        // 只发送调试信息，不发送内容片段
        $this->xb_aiwencre_send_sse_debug('收到内容片段: ' . mb_strlen($chunk, 'UTF-8') . ' 字符');
        
        // 检查是否包含标题标记
        if (!$this->title_extracted && strpos($this->current_content, '===TITLE_START===') !== false) {
            if (strpos($this->current_content, '===TITLE_END===') !== false) {
                // 提取标题
                $title_start = strpos($this->current_content, '===TITLE_START===') + strlen('===TITLE_START===');
                $title_end = strpos($this->current_content, '===TITLE_END===');
                $this->current_title = trim(substr($this->current_content, $title_start, $title_end - $title_start));
                
                // 发送标题
                $this->xb_aiwencre_send_sse_title($this->current_title);
                $this->title_extracted = true;
            }
        }
        
        // 不在这里发送内容，等到完成时再处理
    }
    
    /**
     * 完成内容处理 - 一次性发送完整格式的文章
     */
    private function xb_aiwencre_finalize_content() {
        $this->xb_aiwencre_send_sse_debug('AI生成完成，开始处理完整内容...');
        
        // 处理完整的内容
        $full_content = $this->current_content;
        
        // 提取标题和内容
        if (strpos($full_content, '===TITLE_START===') !== false && strpos($full_content, '===TITLE_END===') !== false) {
            // 有标记的情况
            $title_start = strpos($full_content, '===TITLE_START===') + strlen('===TITLE_START===');
            $title_end = strpos($full_content, '===TITLE_END===');
            $title = trim(substr($full_content, $title_start, $title_end - $title_start));
            
            if (strpos($full_content, '===CONTENT_START===') !== false) {
                $content_start = strpos($full_content, '===CONTENT_START===') + strlen('===CONTENT_START===');
                $content = substr($full_content, $content_start);
                $content = str_replace('===CONTENT_END===', '', $content);
            } else {
                // 如果没有内容标记，取标题后的所有内容
                $content = substr($full_content, strpos($full_content, '===TITLE_END===') + strlen('===TITLE_END==='));
            }
        } else {
            // 没有标记的情况，第一行作为标题
            $lines = explode("
", trim($full_content));
            $title = !empty($lines[0]) ? trim($lines[0]) : '无标题';
            array_shift($lines);
            $content = implode("
", $lines);
        }
        
        // 清理和格式化内容
        $title = $this->xb_aiwencre_clean_title($title);
        $content = $this->xb_aiwencre_clean_and_format_content($content);
        
        // 发送标题（如果还没发送过）
        if (!$this->title_extracted && !empty($title)) {
            $this->xb_aiwencre_send_sse_title($title);
            $this->title_extracted = true;
        }
        
        // 一次性发送完整的格式化内容
        if (!empty($content)) {
            $this->xb_aiwencre_send_sse_debug('发送完整文章内容，长度: ' . mb_strlen($content, 'UTF-8') . ' 字符');
            $this->xb_aiwencre_send_sse_content($content);
        }
        
        $this->xb_aiwencre_send_sse_complete();
    }
    
    /**
     * 清理标题
     */
    private function xb_aiwencre_clean_title($title) {
        // 移除标记符号和多余空白
        $title = str_replace(['===TITLE_START===', '===TITLE_END===', '#'], '', $title);
        $title = trim($title);
        
        // 移除标题前的序号
        $title = preg_replace('/^[\d\s\.\-\*]+/', '', $title);
        
        return trim($title);
    }
    
    /**
     * 清理和格式化完整内容
     */
    private function xb_aiwencre_clean_and_format_content($content) {
        // 移除所有标记符号
        $content = str_replace([
            '===TITLE_START===', '===TITLE_END===', 
            '===CONTENT_START===', '===CONTENT_END==='
        ], '', $content);
        
        // 移除多余的空行（超过2个连续换行）
        $content = preg_replace('/
{3,}/', "

", $content);
        
        // 确保段落之间有适当间距
        $content = preg_replace('/([。！？])\s*
+([^。！？
\s])/', "$1

$2", $content);
        
        // 移除行首行尾多余空白
        $lines = explode("
", $content);
        $lines = array_map('trim', $lines);
        $content = implode("
", $lines);
        
        // 移除开头和结尾的空行
        $content = trim($content);
        
        // 添加基本的HTML段落标签
        $paragraphs = explode("

", $content);
        $paragraphs = array_filter($paragraphs, function($p) { return !empty(trim($p)); });
        $formatted_content = '';
        
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (!empty($paragraph)) {
                // 如果段落包含HTML标签，保持原样
                if (strpos($paragraph, '<') !== false) {
                    $formatted_content .= $paragraph . "

";
                } else {
                    // 否则添加段落标签
                    $formatted_content .= "<p>" . $paragraph . "</p>

";
                }
            }
        }
        
        return trim($formatted_content);
    }
    
    /**
     * 清理内容格式
     */
    private function xb_aiwencre_clean_content_format($content) {
        // 移除多余的换行符
        $content = preg_replace('/
{3,}/', "

", $content);
        
        // 移除标记符号
        $content = str_replace(['===TITLE_START===', '===TITLE_END===', '===CONTENT_START===', '===CONTENT_END==='], '', $content);
        
        // 确保段落之间有适当的间距
        $content = preg_replace('/([。！？])\s*
\s*([^。！？
])/', "$1

$2", $content);
        
        return trim($content);
    }
    
    /**
     * 发送SSE标题数据
     */
    private function xb_aiwencre_send_sse_title($title) {
        $this->xb_aiwencre_send_sse_data('title', $title);
    }
    
    /**
     * 发送SSE内容数据
     */
    private function xb_aiwencre_send_sse_content($content) {
        $this->xb_aiwencre_send_sse_data('content', $content);
    }
    
    /**
     * 发送SSE错误信息
     */
    private function xb_aiwencre_send_sse_error($message) {
        $this->xb_aiwencre_send_sse_data('error', $message);
    }
    
    /**
     * 发送SSE调试信息
     */
    private function xb_aiwencre_send_sse_debug($message) {
        $this->xb_aiwencre_send_sse_data('debug', $message);
    }
    
    /**
     * 发送SSE完成信号
     */
    private function xb_aiwencre_send_sse_complete() {
        $this->xb_aiwencre_send_sse_data('complete', '文章生成完成');
    }
    
    /**
     * 发送SSE数据
     */
    private function xb_aiwencre_send_sse_data($type, $content) {
        $data = array(
            'type' => $type,
            'content' => $content,
            'timestamp' => current_time('timestamp')
        );
        
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "

";
        
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    /**
     * 测试API连接
     */
    public function xb_aiwencre_test_api_connection($platform_id) {
        $platform = $this->xb_aiwencre_get_platform_config($platform_id);
        if (!$platform) {
            return array('success' => false, 'message' => '平台配置不存在');
        }
        
        $api_keys = array_filter(array_map('trim', explode(',', $platform->api_keys)));
        if (empty($api_keys)) {
            return array('success' => false, 'message' => '未配置API密钥');
        }
        
        $models = array_filter(array_map('trim', explode(',', $platform->api_models)));
        if (empty($models)) {
            return array('success' => false, 'message' => '未配置AI模型');
        }
        
        // 构建测试请求
        $test_data = array(
            'model' => $models[0],
            'messages' => array(
                array(
                    'role' => 'user',
                    'content' => '请回复"连接测试成功"'
                )
            ),
            'max_tokens' => 50
        );
        
        $headers = array(
            'Authorization: Bearer ' . $api_keys[0],
            'Content-Type: application/json'
        );
        
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $platform->api_endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($test_data),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false
        ));
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        if ($curl_error) {
            return array('success' => false, 'message' => '网络错误: ' . $curl_error);
        }
        
        if ($http_code !== 200) {
            return array('success' => false, 'message' => 'HTTP错误: ' . $http_code);
        }
        
        $decoded = json_decode($response, true);
        if (!$decoded) {
            return array('success' => false, 'message' => '响应解析失败');
        }
        
        if (isset($decoded['error'])) {
            return array('success' => false, 'message' => 'API错误: ' . $decoded['error']['message']);
        }
        
        return array('success' => true, 'message' => '连接测试成功');
    }
    
    /**
     * 获取平台配置
     */
    private function xb_aiwencre_get_platform_config($platform_id) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d AND is_active = 1",
            intval($platform_id)
        ));
    }
    
    /**
     * 获取平台统计信息
     */
    public function xb_aiwencre_get_platform_stats($platform_id) {
        global $wpdb;
        
        $platform = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            intval($platform_id)
        ));
        
        if (!$platform) {
            return false;
        }
        
        $stats = array(
            'platform_name' => $platform->platform_name,
            'api_endpoint' => $platform->api_endpoint,
            'model_count' => count(array_filter(explode(',', $platform->api_models))),
            'key_count' => count(array_filter(explode(',', $platform->api_keys))),
            'is_active' => $platform->is_active,
            'created_time' => $platform->created_time,
            'updated_time' => $platform->updated_time
        );
        
        return $stats;
    }
    
    /**
     * 清理资源
     */
    public function xb_aiwencre_cleanup() {
        // 强制垃圾回收
        if (function_exists('gc_collect_cycles')) {
            gc_collect_cycles();
        }
    }
}

// 实例化API处理器
$xb_aiwencre_api_handler = new XB_AIWenCre_API_Handler();

// 注册清理钩子
register_shutdown_function(array($xb_aiwencre_api_handler, 'xb_aiwencre_cleanup'));