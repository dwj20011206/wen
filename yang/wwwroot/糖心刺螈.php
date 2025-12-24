<?php
/**
 * TVBox PHP 爬虫脚本 - 糖心次元版
 * 支持的 action: home, category, detail, search, play
 */

// 获取请求参数
$action = $_GET['action'] ?? '';

// 设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');

// 根据不同 action 返回数据
switch ($action) {
    case 'home':
        echo json_encode(getHome());
        break;
    
    case 'category':
        $tid = $_GET['t'] ?? '';
        $page = $_GET['pg'] ?? '1';
        echo json_encode(getCategory($tid, $page));
        break;
    
    case 'detail':
        $ids = $_GET['ids'] ?? '';
        echo json_encode(getDetail($ids));
        break;
    
    case 'search':
        $keyword = $_GET['wd'] ?? '';
        $page = $_GET['pg'] ?? '1';
        echo json_encode(search($keyword, $page));
        break;
    
    case 'play':
        $flag = $_GET['flag'] ?? '';
        $id = $_GET['id'] ?? '';
        echo json_encode(getPlay($flag, $id));
        break;
    
    default:
        echo json_encode(['error' => 'Invalid action']);
}

class TangXinSpider {
    private $base = "https://www.txsp.my";
    private $img_base = "https://img1.souavzy.org";
    private $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
        'Accept-Language: zh-CN,zh;q=0.9',
        'Referer: https://www.txsp.my/',
        'Origin: https://www.txsp.my'
    ];
    
    private $category_map = [
        "1" => "传媒系列",
        "2" => "AV系列",
        "5" => "麻豆传媒",
        "6" => "糖心传媒",
        "7" => "精东影业",
        "8" => "蜜桃传媒",
        "9" => "果冻传媒",
        "10" => "星空无限",
        "11" => "天美传媒",
        "12" => "抠抠传媒",
        "13" => "星杏吧传媒",
        "14" => "性视界传媒",
        "15" => "SA国际传媒",
        "16" => "其他传媒",
        "17" => "国产-自拍-偷拍",
        "18" => "探花-主播-网红",
        "19" => "日本-中文字幕",
        "20" => "日本-无码流出",
        "21" => "日本-高清有码",
        "22" => "日本-东京热",
        "23" => "动漫-番中字",
        "24" => "变态-暗网-同恋",
        "25" => "欧美高清无码",
        "27" => "韩国av"
    ];
    
    private $session;

    public function __construct() {
        $this->session = $this->createSession();
    }

    private function createSession() {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $this->headers,
            CURLOPT_ENCODING => 'gzip, deflate'
        ]);
        return $ch;
    }

    public function fetch($url) {
        curl_setopt($this->session, CURLOPT_URL, $url);
        $response = curl_exec($this->session);
        $httpCode = curl_getinfo($this->session, CURLINFO_HTTP_CODE);
        
        if ($response === false || $httpCode !== 200) {
            return null;
        }
        
        return $response;
    }

    /**
     * 去除韩国AV标题前缀 (例如: kbj-23010421标题 -> 标题)
     */
    private function cleanTitle($name) {
        return preg_replace('/^[a-zA-Z]{2,}\-\d+\s*/', '', trim($name));
    }

    /**
     * 处理图片URL
     */
    private function processImageUrl($img) {
        if (empty($img)) {
            return '';
        }
        
        if (strpos($img, 'http') === 0) {
            return $img;
        }
        
        if (strpos($img, '//') === 0) {
            return 'https:' . $img;
        }
        
        return $this->img_base . $img;
    }

    /**
     * 处理链接URL
     */
    private function processLinkUrl($link) {
        if (empty($link)) {
            return '';
        }
        
        if (strpos($link, 'http') === 0) {
            return $link;
        }
        
        return $this->base . $link;
    }

    /**
     * 通用解析视频列表
     */
    private function parseVideoList($html) {
        $videos = [];
        
        if (empty($html)) {
            return $videos;
        }
        
        // 使用 DOMDocument 解析 HTML
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();
        
        $xpath = new DOMXPath($dom);
        
        // 查找所有包含视频的 li 元素
        $items = $xpath->query('//li[contains(@class, "mb15") and .//a[contains(@href, "/vod/play/")]]');
        
        foreach ($items as $item) {
            // 提取标题
            $titleNodes = $xpath->query('.//h2/a/@title | .//h3/a/@title | .//p[contains(@class, "txt-ov")]/text()', $item);
            $name = $titleNodes->length > 0 ? trim($titleNodes->item(0)->nodeValue) : '';
            $name = $this->cleanTitle($name);
            
            // 提取图片
            $imgNodes = $xpath->query('.//img/@src', $item);
            $img = $imgNodes->length > 0 ? $imgNodes->item(0)->nodeValue : '';
            $img = $this->processImageUrl($img);
            
            // 提取链接
            $linkNodes = $xpath->query('.//a[contains(@href, "/vod/play/")]/@href', $item);
            $link = $linkNodes->length > 0 ? $linkNodes->item(0)->nodeValue : '';
            $link = $this->processLinkUrl($link);
            
            // 提取备注信息
            $remarksNodes = $xpath->query('.//span[contains(@class, "ico-left")]/text()', $item);
            $remarks = $remarksNodes->length > 0 ? trim($remarksNodes->item(0)->nodeValue) : '';
            
            if (!empty($link) && !empty($name)) {
                $videos[] = [
                    'vod_id' => $link,
                    'vod_name' => $name ?: '未知标题',
                    'vod_pic' => $img,
                    'vod_remarks' => $remarks
                ];
            }
        }
        
        return $videos;
    }

    /**
     * 提取播放地址
     */
    private function extractPlayUrl($html) {
        // 修复转义的斜杠
        $html = str_replace('\\/', '/', $html);
        
        // 尝试多种正则模式提取 player_aaaa 或 player_data
        $patterns = [
            '/var\s+player_aaaa\s*=\s*(\{[^\}]+\})/',
            '/player_aaaa\s*=\s*(\{[^\}]+\})/',
            '/var\s+player_data\s*=\s*(\{[^\}]+\})/'
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $html, $matches)) {
                try {
                    $jsonStr = $matches[1];
                    $data = json_decode($jsonStr, true);
                    
                    if (isset($data['url']) && !empty($data['url'])) {
                        return $data['url'];
                    }
                } catch (Exception $e) {
                    continue;
                }
            }
        }
        
        // 尝试从 iframe src 提取
        if (preg_match('/<iframe[^>]+src="([^"]+souavzy[^"]+)"/i', $html, $matches)) {
            $src = $matches[1];
            if (preg_match('/url=([^&]+)/', $src, $urlMatch)) {
                return urldecode($urlMatch[1]);
            }
        }
        
        // 尝试直接匹配 m3u8 URL
        if (preg_match_all('/"(https?:\/\/[^"]+\.m3u8[^"]*)"/', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'souavzy') !== false || strpos($url, 'qrtuv') !== false) {
                    return $url;
                }
            }
        }
        
        return '';
    }

    /**
     * 首页内容
     */
    public function getHomeContent() {
        try {
            $categories = [];
            foreach ($this->category_map as $id => $name) {
                $categories[] = [
                    'type_id' => $id,
                    'type_name' => $name
                ];
            }
            
            return [
                'class' => $categories,
                'list' => []
            ];
        } catch (Exception $e) {
            return ['class' => [], 'list' => []];
        }
    }

    /**
     * 分类内容
     */
    public function getCategoryContent($tid, $pg) {
        try {
            $url = $pg == '1' 
                ? "{$this->base}/index.php/vod/type/id/{$tid}.html"
                : "{$this->base}/index.php/vod/type/id/{$tid}/page/{$pg}.html";
            
            $html = $this->fetch($url);
            if (!$html) {
                return [
                    'list' => [],
                    'page' => intval($pg),
                    'pagecount' => 1,
                    'limit' => 0,
                    'total' => 0
                ];
            }
            
            $videos = $this->parseVideoList($html);
            
            // 提取总页数
            preg_match_all('/\/page\/(\d+)/', $html, $pageMatches);
            $pagecount = !empty($pageMatches[1]) ? max(array_map('intval', $pageMatches[1])) : 1;
            
            return [
                'list' => $videos,
                'page' => intval($pg),
                'pagecount' => $pagecount,
                'limit' => count($videos),
                'total' => 999999
            ];
        } catch (Exception $e) {
            return [
                'list' => [],
                'page' => intval($pg),
                'pagecount' => 1,
                'limit' => 0,
                'total' => 0
            ];
        }
    }

    /**
     * 详情内容
     */
    public function getDetailContent($ids) {
        $result = ['list' => []];
        
        foreach ($ids as $id) {
            try {
                $url = strpos($id, 'http') === 0 ? $id : $this->base . $id;
                
                $html = $this->fetch($url);
                if (!$html) {
                    $result['list'][] = [
                        'vod_id' => $id,
                        'vod_name' => '获取失败',
                        'vod_pic' => '',
                        'vod_content' => '网络请求失败'
                    ];
                    continue;
                }
                
                libxml_use_internal_errors(true);
                $dom = new DOMDocument();
                $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
                libxml_clear_errors();
                
                $xpath = new DOMXPath($dom);
                
                // 提取标题
                $titleNodes = $xpath->query('//h1/text()');
                $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->nodeValue) : '未知标题';
                
                // 提取封面
                $picNodes = $xpath->query('//meta[@property="og:image"]/@content | //img[contains(@src, "upload/vod")]/@src');
                $pic = $picNodes->length > 0 ? $picNodes->item(0)->nodeValue : '';
                $pic = $this->processImageUrl($pic);
                
                // 提取播放地址
                $playUrl = $this->extractPlayUrl($html);
                
                $result['list'][] = [
                    'vod_id' => $id,
                    'vod_name' => $title,
                    'vod_pic' => $pic,
                    'vod_content' => $title,
                    'vod_play_from' => '糖心次元',
                    'vod_play_url' => $playUrl ? '播放$' . $playUrl : '播放$暂无播放地址'
                ];
                
            } catch (Exception $e) {
                $result['list'][] = [
                    'vod_id' => $id,
                    'vod_name' => '获取失败',
                    'vod_pic' => '',
                    'vod_content' => '获取详情失败: ' . $e->getMessage()
                ];
            }
        }
        
        return $result;
    }

    /**
     * 搜索内容
     */
    public function getSearchContent($keyword, $pg = '1') {
        try {
            $encodedKeyword = urlencode($keyword);
            $url = "{$this->base}/index.php/vod/search/page/{$pg}/wd/{$encodedKeyword}.html";
            
            $html = $this->fetch($url);
            if (!$html) {
                return [
                    'list' => [],
                    'page' => intval($pg),
                    'pagecount' => 1,
                    'limit' => 0,
                    'total' => 0
                ];
            }
            
            $videos = $this->parseVideoList($html);
            
            return [
                'list' => $videos,
                'page' => intval($pg),
                'pagecount' => 999,
                'limit' => count($videos),
                'total' => 999999
            ];
        } catch (Exception $e) {
            return [
                'list' => [],
                'page' => intval($pg),
                'pagecount' => 1,
                'limit' => 0,
                'total' => 0
            ];
        }
    }

    /**
     * 播放内容
     */
    public function getPlayerContent($flag, $id) {
        try {
            // 如果不是糖心次元线路,返回空
            if ($flag !== '糖心次元') {
                return ['parse' => 0, 'playUrl' => '', 'url' => ''];
            }
            
            // 如果id已经是完整的播放URL
            if (strpos($id, 'http') === 0 && (strpos($id, '.m3u8') !== false || strpos($id, 'souavzy') !== false)) {
                return [
                    'parse' => 0,
                    'playUrl' => '',
                    'url' => $id,
                    'header' => [
                        'User-Agent' => 'Mozilla/5.0',
                        'Referer' => 'https://www.txsp.my/',
                        'Origin' => 'https://www.txsp.my'
                    ]
                ];
            }
            
            // 否则需要获取详情页提取播放地址
            $url = strpos($id, 'http') === 0 ? $id : $this->base . $id;
            $html = $this->fetch($url);
            
            if ($html) {
                $playUrl = $this->extractPlayUrl($html);
                
                if ($playUrl) {
                    return [
                        'parse' => 0,
                        'playUrl' => '',
                        'url' => $playUrl,
                        'header' => [
                            'User-Agent' => 'Mozilla/5.0',
                            'Referer' => 'https://www.txsp.my/',
                            'Origin' => 'https://www.txsp.my'
                        ]
                    ];
                }
            }
            
            // 如果提取失败,尝试使用解析
            return [
                'parse' => 1,
                'playUrl' => '',
                'url' => $id,
                'header' => [
                    'User-Agent' => 'Mozilla/5.0',
                    'Referer' => 'https://www.txsp.my/',
                    'Origin' => 'https://www.txsp.my'
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'parse' => 0,
                'playUrl' => '',
                'url' => '',
                'header' => []
            ];
        }
    }

    public function __destruct() {
        if ($this->session) {
            curl_close($this->session);
        }
    }
}

/**
 * 首页数据
 */
function getHome() {
    $spider = new TangXinSpider();
    return $spider->getHomeContent();
}

/**
 * 分类列表
 */
function getCategory($tid, $page) {
    $spider = new TangXinSpider();
    return $spider->getCategoryContent($tid, $page);
}

/**
 * 视频详情
 */
function getDetail($ids) {
    $spider = new TangXinSpider();
    return $spider->getDetailContent(explode(',', $ids));
}

/**
 * 搜索
 */
function search($keyword, $page) {
    $spider = new TangXinSpider();
    return $spider->getSearchContent($keyword, $page);
}

/**
 * 获取播放地址
 */
function getPlay($flag, $id) {
    $spider = new TangXinSpider();
    return $spider->getPlayerContent($flag, $id);
}
?>