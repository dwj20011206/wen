<?php
// 智能分类视频采集PHP脚本 - 支持远程加载和多URL配置
// 兼容Apple CMS API格式，支持智能无限分类功能

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

// 配置多个远程数据源
$remoteUrls = [
    '永乐影视' => 'http://127.0.0.1:9978/vod/api?site=永乐影视'
];

// 当前使用的远程源
$currentRemoteUrl = $remoteUrls['永乐影视'];

// 使用Apple CMS标准参数
$ac = $_GET['ac'] ?? 'detail';  // 操作类型
$t = $_GET['t'] ?? '';          // 类型ID
$pg = $_GET['pg'] ?? '1';       // 页码
$f = $_GET['f'] ?? '';          // 筛选条件JSON
$ids = $_GET['ids'] ?? '';      // 详情ID
$wd = $_GET['wd'] ?? '';        // 搜索关键词
$flag = $_GET['flag'] ?? '';    // 播放标识
$id = $_GET['id'] ?? '';        // 播放ID
$source = $_GET['source'] ?? ''; // 数据源选择

// 选择数据源
if (!empty($source) && isset($remoteUrls[$source])) {
    $currentRemoteUrl = $remoteUrls[$source];
}

// 远程数据获取函数
function fetchRemoteData($url, $params = []) {
    $ch = curl_init();
    
    // 构建完整URL
    if (!empty($params)) {
        $url .= (strpos($url, '?') === false ? '?' : '&') . http_build_query($params);
    }
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200 && !empty($response)) {
        return json_decode($response, true);
    }
    
    // 记录错误日志
    error_log("Remote API Error: $url - HTTP $httpCode - $error");
    return null;
}

// 智能分类生成器
class SmartCategoryGenerator {
    private $categories = [];
    private $contents = [];
    private $categoryIdCounter = 1;
    private $remoteData = [];
    
    public function __construct() {
        $this->initializeBaseCategories();
    }
    
    // 设置远程数据
    public function setRemoteData($data) {
        $this->remoteData = $data;
    }
    
    // 初始化基础分类
    private function initializeBaseCategories() {
        $baseCategories = [
            '1' => ['name' => '电影', 'level' => 1],
            '2' => ['name' => '电视剧', 'level' => 1],
            '3' => ['name' => '综艺', 'level' => 1],
            '4' => ['name' => '动漫', 'level' => 1],
            '5' => ['name' => '纪录片', 'level' => 1],
        ];
        
        foreach ($baseCategories as $id => $category) {
            $this->categories[$id] = array_merge($category, [
                'parent' => null,
                'has_children' => true,
                'path' => $category['name']
            ]);
        }
        
        $this->categoryIdCounter = 6;
    }
    
    // 智能生成子分类
    public function generateChildCategories($parentId, $depth = 0) {
        if ($depth >= 6) {
            return [];
        }
        
        $parent = $this->categories[$parentId] ?? null;
        if (!$parent) {
            return [];
        }
        
        $children = [];
        $classificationTypes = $this->getClassificationTypes($parent['name'], $depth);
        
        foreach ($classificationTypes as $type) {
            $childId = $this->generateCategoryId();
            $childName = $this->generateCategoryName($parent['name'], $type, $depth);
            
            $this->categories[$childId] = [
                'name' => $childName,
                'level' => $depth + 2,
                'parent' => $parentId,
                'has_children' => $depth < 3,
                'path' => $parent['path'] . ' > ' . $childName
            ];
            
            if (!$this->categories[$childId]['has_children']) {
                $this->generateContent($childId, $childName);
            }
            
            $children[] = [
                'id' => $childId,
                'name' => $childName,
                'level' => $depth + 2,
                'has_children' => $this->categories[$childId]['has_children']
            ];
        }
        
        return $children;
    }
    
    // 根据父分类名称和深度获取分类类型
    private function getClassificationTypes($parentName, $depth) {
        $types = [];
        
        switch ($depth) {
            case 0:
                $types = ['地区', '类型', '年份', '语言', '评分'];
                break;
            case 1:
                if (strpos($parentName, '地区') !== false) {
                    $types = $this->getRegions($parentName);
                } elseif (strpos($parentName, '类型') !== false) {
                    $types = $this->getGenres($parentName);
                } elseif (strpos($parentName, '年份') !== false) {
                    $types = $this->getYears();
                } elseif (strpos($parentName, '语言') !== false) {
                    $types = ['华语', '英语', '日语', '韩语', '法语', '德语'];
                } else {
                    $types = ['9分以上', '8-9分', '7-8分', '6-7分', '5-6分'];
                }
                break;
            case 2:
                $types = ['热门', '经典', '最新', '高分', '冷门'];
                break;
            case 3:
                $types = ['动作', '喜剧', '爱情', '科幻', '悬疑', '恐怖', '剧情'];
                break;
            default:
                $types = ['推荐', '精选', '热门', '最新上架'];
                break;
        }
        
        return array_slice($types, 0, 6);
    }
    
    // 获取地区分类
    private function getRegions($parentName) {
        if (strpos($parentName, '电影') !== false) {
            return ['华语电影', '欧美电影', '日韩电影', '印度电影', '其他地区'];
        } elseif (strpos($parentName, '电视剧') !== false) {
            return ['国产剧', '港台剧', '美剧', '韩剧', '日剧', '英剧'];
        } else {
            return ['中国大陆', '中国台湾', '韩国', '日本', '美国', '欧洲'];
        }
    }
    
    // 获取类型分类
    private function getGenres($parentName) {
        if (strpos($parentName, '电影') !== false) {
            return ['动作', '喜剧', '爱情', '科幻', '恐怖', '悬疑', '剧情', '动画'];
        } elseif (strpos($parentName, '电视剧') !== false) {
            return ['古装', '现代', '悬疑', '爱情', '家庭', '历史', '仙侠', '职场'];
        } elseif (strpos($parentName, '综艺') !== false) {
            return ['真人秀', '脱口秀', '选秀', '美食', '旅游', '音乐', '竞技'];
        } else {
            return ['教育', '探索', '自然', '历史', '科技', '社会', '人物'];
        }
    }
    
    // 获取年份分类
    private function getYears() {
        $currentYear = date('Y');
        $years = [];
        for ($i = 0; $i < 6; $i++) {
            $year = $currentYear - $i;
            $years[] = $year . '年';
        }
        $years[] = '更早';
        return $years;
    }
    
    // 生成分类名称
    private function generateCategoryName($parentName, $type, $depth) {
        if ($depth <= 1) {
            return $type;
        }
        return $parentName . $type;
    }
    
    // 生成分类ID
    private function generateCategoryId() {
        return 'cat_' . $this->categoryIdCounter++;
    }
    
    // 为分类生成内容
    private function generateContent($categoryId, $categoryName) {
        // 优先使用远程数据
        if (!empty($this->remoteData['list'])) {
            $this->contents[$categoryId] = $this->remoteData['list'];
            return;
        }
        
        // 如果没有远程数据，使用模拟数据
        $contentCount = rand(8, 15);
        $contents = [];
        
        for ($i = 1; $i <= $contentCount; $i++) {
            $videoName = $this->generateVideoName($categoryName, $i);
            $score = $this->generateScore();
            
            $contents[] = [
                'vod_id' => $categoryId . '_video_' . $i,
                'vod_name' => $videoName,
                'vod_pic' => $this->getRandomImage(),
                'vod_remarks' => $score
            ];
        }
        
        $this->contents[$categoryId] = $contents;
    }
    
    // 生成视频名称
    private function generateVideoName($categoryName, $index) {
        $prefixes = ['热门', '经典', '最新', '高分', '精选', '必看'];
        $suffixes = ['作品', '大片', '剧集', '节目', '电影', '电视剧'];
        
        $prefix = $prefixes[array_rand($prefixes)];
        $suffix = $suffixes[array_rand($suffixes)];
        
        return $prefix . $categoryName . $suffix . $index;
    }
    
    // 生成随机评分
    private function generateScore() {
        return sprintf('%.1f', 5 + (mt_rand(0, 50) / 10));
    }
    
    // 获取随机图片
    private function getRandomImage() {
        $images = [
            'https://img9.doubanio.com/view/photo/m_ratio_poster/public/p2578045524.jpg',
            'https://img3.doubanio.com/view/photo/m_ratio_poster/public/p2921303452.jpg',
            'https://img1.doubanio.com/view/photo/m_ratio_poster/public/p2886937141.jpg',
            'https://2uspicc12tche.hitv.app/350/upload/vod/20240415-1/2636d5210e5cf7a6f0cff5c737e6c7b5.webp',
            'https://img9.doubanio.com/view/photo/m_ratio_poster/public/p2494701965.jpg',
            'https://img3.doubanio.com/view/photo/m_ratio_poster/public/p2514119443.jpg'
        ];
        return $images[array_rand($images)];
    }
    
    // 获取分类信息
    public function getCategory($categoryId) {
        return $this->categories[$categoryId] ?? null;
    }
    
    // 获取子分类
    public function getChildren($parentId) {
        $parent = $this->categories[$parentId] ?? null;
        if (!$parent) {
            return [];
        }
        
        $children = [];
        foreach ($this->categories as $id => $category) {
            if (isset($category['parent']) && $category['parent'] === $parentId) {
                $children[] = [
                    'id' => $id,
                    'name' => $category['name'],
                    'level' => $category['level'],
                    'has_children' => $category['has_children']
                ];
            }
        }
        
        if (empty($children) && $parent['has_children']) {
            $depth = $parent['level'] - 1;
            $children = $this->generateChildCategories($parentId, $depth);
        }
        
        return $children;
    }
    
    // 获取分类内容
    public function getContent($categoryId) {
        if (!isset($this->contents[$categoryId])) {
            $category = $this->categories[$categoryId] ?? null;
            if ($category && !$category['has_children']) {
                $this->generateContent($categoryId, $category['name']);
            }
        }
        
        return $this->contents[$categoryId] ?? [];
    }
    
    // 搜索分类
    public function searchCategories($keyword) {
        $results = [];
        foreach ($this->categories as $id => $category) {
            if (stripos($category['name'], $keyword) !== false || 
                stripos($category['path'], $keyword) !== false) {
                $results[] = $category;
            }
        }
        return $results;
    }
}

// 初始化分类生成器
$categoryGenerator = new SmartCategoryGenerator();

// 获取指定父级的子分类
function getChildCategories($parentId) {
    global $categoryGenerator;
    return $categoryGenerator->getChildren($parentId);
}

// 获取分类的显示名称
function getCategoryName($categoryId) {
    global $categoryGenerator;
    $category = $categoryGenerator->getCategory($categoryId);
    return $category ? $category['name'] : '未知分类';
}

// 检查分类是否有子分类
function hasChildren($categoryId) {
    global $categoryGenerator;
    $category = $categoryGenerator->getCategory($categoryId);
    return $category ? $category['has_children'] : false;
}

// 处理不同操作类型
switch ($ac) {
    case 'detail':
        if (!empty($ids)) {
            // 尝试从远程获取详情
            $remoteData = fetchRemoteData($currentRemoteUrl, ['ac' => 'detail', 'ids' => $ids]);
            
            if ($remoteData && !empty($remoteData['list'])) {
                echo json_encode($remoteData, JSON_UNESCAPED_UNICODE);
            } else {
                // 本地详情
                $data = [
                    'list' => [
                        [
                            'vod_id' => $ids,
                            'vod_name' => '视频详情：' . $ids,
                            'vod_pic' => 'https://2uspicc12tche.hitv.app/350/upload/vod/20240415-1/2636d5210e5cf7a6f0cff5c737e6c7b5.webp',
                            'vod_content' => '这是一个智能生成的视频详情页面，支持无限分类系统。',
                            'vod_play_from' => '智能线路1$$$智能线路2',
                            'vod_play_url' => '第1集$video_1_1#第2集$video_1_2$$$第1集$video_2_1#第2集$video_2_2'
                        ]
                    ]
                ];
                echo json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        } elseif (!empty($t)) {
            // 尝试从远程获取分类数据
            $remoteData = fetchRemoteData($currentRemoteUrl, ['ac' => 'detail', 't' => $t, 'pg' => $pg]);
            
            if ($remoteData && !empty($remoteData['list'])) {
                // 使用远程数据
                $categoryGenerator->setRemoteData($remoteData);
                $filters = !empty($f) ? json_decode($f, true) : [];
                $isSubRequest = isset($filters['is_sub']) && $filters['is_sub'] === 'true';
                $currentLevel = $filters['level'] ?? 1;
                $categoryId = $filters['category_id'] ?? $t;
                
                $hasChildren = hasChildren($categoryId);
                
                if ($isSubRequest && !$hasChildren) {
                    $content = $categoryGenerator->getContent($categoryId);
                    
                    $data = [
                        'list' => $content,
                        'page' => intval($pg),
                        'pagecount' => 3,
                        'limit' => 20,
                        'total' => count($content),
                        'current_level' => $currentLevel,
                        'current_category' => getCategoryName($categoryId),
                        'category_path' => $categoryGenerator->getCategory($categoryId)['path'] ?? '',
                        'data_source' => 'remote',
                        'style' => [
                            'type' => 'rect',
                            'ratio' => 0.75
                        ]
                    ];
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                } else {
                    echo json_encode($remoteData, JSON_UNESCAPED_UNICODE);
                }
            } else {
                // 使用本地分类系统
                $filters = !empty($f) ? json_decode($f, true) : [];
                $isSubRequest = isset($filters['is_sub']) && $filters['is_sub'] === 'true';
                $currentLevel = $filters['level'] ?? 1;
                $categoryId = $filters['category_id'] ?? $t;
                
                $hasChildren = hasChildren($categoryId);
                
                if ($isSubRequest && !$hasChildren) {
                    $content = $categoryGenerator->getContent($categoryId);
                    
                    $data = [
                        'list' => $content,
                        'page' => intval($pg),
                        'pagecount' => 3,
                        'limit' => 20,
                        'total' => count($content),
                        'current_level' => $currentLevel,
                        'current_category' => getCategoryName($categoryId),
                        'category_path' => $categoryGenerator->getCategory($categoryId)['path'] ?? '',
                        'data_source' => 'local',
                        'style' => [
                            'type' => 'rect',
                            'ratio' => 0.75
                        ]
                    ];
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                } else {
                    $children = getChildCategories($t);
                    
                    if (empty($children)) {
                        $content = $categoryGenerator->getContent($t);
                        
                        $data = [
                            'list' => $content,
                            'page' => intval($pg),
                            'pagecount' => 3,
                            'limit' => 20,
                            'total' => count($content),
                            'current_level' => $currentLevel,
                            'current_category' => getCategoryName($t),
                            'category_path' => $categoryGenerator->getCategory($t)['path'] ?? '',
                            'data_source' => 'local',
                            'style' => [
                                'type' => 'rect',
                                'ratio' => 0.75
                            ]
                        ];
                    } else {
                        $subList = [];
                        
                        foreach ($children as $child) {
                            $subList[] = [
                                'vod_id' => $child['id'],
                                'vod_name' => $child['name'],
                                'vod_pic' => 'https://img9.doubanio.com/view/photo/m_ratio_poster/public/p2578045524.jpg',
                                'vod_remarks' => '分类'
                            ];
                        }
                        
                        $data = [
                            'is_sub' => true,
                            'list' => $subList,
                            'page' => intval($pg),
                            'pagecount' => 1,
                            'limit' => 20,
                            'total' => count($subList),
                            'current_level' => $currentLevel,
                            'parent_category' => getCategoryName($t),
                            'category_path' => $categoryGenerator->getCategory($t)['path'] ?? '',
                            'data_source' => 'local',
                            'style' => [
                                'type' => 'rect',
                                'ratio' => 1.5
                            ]
                        ];
                    }
                    echo json_encode($data, JSON_UNESCAPED_UNICODE);
                }
            }
        } else {
            // 首页 - 尝试从远程获取
            $remoteData = fetchRemoteData($currentRemoteUrl, ['ac' => 'detail']);
            
            if ($remoteData && (!empty($remoteData['class']) || !empty($remoteData['list']))) {
                // 添加数据源标识
                $remoteData['data_source'] = 'remote';
                $remoteData['available_sources'] = array_keys($GLOBALS['remoteUrls']);
                echo json_encode($remoteData, JSON_UNESCAPED_UNICODE);
            } else {
                // 本地首页
                $classList = [];
                $baseCategories = ['1', '2', '3', '4', '5'];
                
                foreach ($baseCategories as $id) {
                    $category = $categoryGenerator->getCategory($id);
                    if ($category) {
                        $classList[] = [
                            'type_id' => $id,
                            'type_name' => $category['name']
                        ];
                    }
                }
                
                $data = [
                    'class' => $classList,
                    'list' => [
                        ['vod_id' => 'home_1', 'vod_name' => '智能推荐视频1', 'vod_pic' => 'https://img9.doubanio.com/view/photo/m_ratio_poster/public/p2578045524.jpg', 'vod_remarks' => '9.1'],
                        ['vod_id' => 'home_2', 'vod_name' => '智能推荐视频2', 'vod_pic' => 'https://img3.doubanio.com/view/photo/m_ratio_poster/public/p2921303452.jpg', 'vod_remarks' => '8.7'],
                        ['vod_id' => 'home_3', 'vod_name' => '智能推荐视频3', 'vod_pic' => 'https://img1.doubanio.com/view/photo/m_ratio_poster/public/p2886937141.jpg', 'vod_remarks' => '8.9']
                    ],
                    'data_source' => 'local',
                    'available_sources' => array_keys($GLOBALS['remoteUrls']),
                    'style' => [
                        'type' => 'rect',
                        'ratio' => 1.33
                    ]
                ];
                echo json_encode($data, JSON_UNESCAPED_UNICODE);
            }
        }
        break;
    
    case 'search':
        // 尝试从远程搜索
        $remoteData = fetchRemoteData($currentRemoteUrl, ['ac' => 'search', 'wd' => $wd, 'pg' => $pg]);
        
        if ($remoteData && !empty($remoteData['list'])) {
            $remoteData['data_source'] = 'remote';
            echo json_encode($remoteData, JSON_UNESCAPED_UNICODE);
        } else {
            $data = [
                'list' => [
                    ['vod_id' => 's1', 'vod_name' => '搜索结果: ' . $wd, 'vod_pic' => 'https://2uspicc12tche.hitv.app/350/upload/vod/20240415-1/2636d5210e5cf7a6f0cff5c737e6c7b5.webp', 'vod_remarks' => '搜索']
                ],
                'page' => intval($pg),
                'pagecount' => 1,
                'limit' => 20,
                'total' => 1,
                'data_source' => 'local'
            ];
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        break;
        
    case 'play':
        // 尝试从远程获取播放信息
        $remoteData = fetchRemoteData($currentRemoteUrl, ['ac' => 'play', 'id' => $id, 'flag' => $flag]);
        
        if ($remoteData && isset($remoteData['url'])) {
            echo json_encode($remoteData, JSON_UNESCAPED_UNICODE);
        } else {
            $data = [
                'parse' => 1,
                'playUrl' => '',
                'url' => 'https://haokan.baidu.com/v?vid=' . $id,
                'data_source' => 'local'
            ];
            echo json_encode($data, JSON_UNESCAPED_UNICODE);
        }
        break;
    
    case 'sources':
        // 返回可用的数据源列表
        $data = [
            'sources' => $remoteUrls,
            'current_source' => array_search($currentRemoteUrl, $remoteUrls),
            'total_sources' => count($remoteUrls)
        ];
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;
    
    default:
        $data = [
            'error' => 'Unknown action: ' . $ac,
            'available_actions' => ['detail', 'search', 'play', 'sources'],
            'available_sources' => array_keys($remoteUrls)
        ];
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
}
?>