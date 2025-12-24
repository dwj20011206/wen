<?php
/**
 * TVBox PHP 爬虫脚本 - 懒加载JSON版本
 * 包含🔥推荐分类，支持翻页
 */

// 获取请求参数
$ac = $_GET['ac'] ?? 'detail';
$t = $_GET['t'] ?? '';
$pg = $_GET['pg'] ?? '1';
$ids = $_GET['ids'] ?? '';
$wd = $_GET['wd'] ?? '';
$flag = $_GET['flag'] ?? '';
$id = $_GET['id'] ?? '';

// 设置响应头为 JSON
header('Content-Type: application/json; charset=utf-8');

// 性能优化
@set_time_limit(30);

// 根据不同 action 返回数据
switch ($ac) {
    case 'detail':
        if (!empty($ids)) {
            echo json_encode(getDetail($ids));
        } elseif (!empty($t)) {
            echo json_encode(getCategory($t, $pg));
        } else {
            echo json_encode(getHome());
        }
        break;
    
    case 'search':
        echo json_encode(search($wd, $pg));
        break;
        
    case 'play':
        echo json_encode(getPlay($flag, $id));
        break;
    
    default:
        echo json_encode(['error' => 'Unknown action: ' . $ac]);
}

/**
 * 快速扫描目录 - 只获取文件列表，不读取内容
 */
function scanDirectoryFast($dir, $maxDepth = 2, $currentDepth = 0) {
    $files = [];
    
    if (!is_dir($dir) || $currentDepth > $maxDepth) {
        return $files;
    }
    
    $items = @scandir($dir);
    if ($items === false) return $files;
    
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $path = $dir . $item;
        
        if (is_dir($path)) {
            $subFiles = scanDirectoryFast($path . '/', $maxDepth, $currentDepth + 1);
            $files = array_merge($files, $subFiles);
        } elseif (pathinfo($path, PATHINFO_EXTENSION) === 'json') {
            $files[] = [
                'path' => $path,
                'name' => $item,
                'filename' => pathinfo($item, PATHINFO_FILENAME),
                'size' => @filesize($path)
            ];
        }
    }
    
    return $files;
}

/**
 * 获取文件列表 - 快速版本，不读取文件内容
 */
function getAllJsonFilesFast() {
    static $allFiles = null;
    
    if ($allFiles === null) {
        $allFiles = scanDirectoryFast('/storage/emulated/0/江湖/json/');
    }
    
    return $allFiles;
}

/**
 * 获取分类列表 - 包含🔥推荐分类和文件分类
 */
function getCategoriesFast() {
    static $categories = null;
    
    if ($categories === null) {
        $allFiles = getAllJsonFilesFast();
        $categories = [];
        
        // 添加🔥推荐分类（可以翻页）
        $categories[] = [
            'type_id' => 'recommend',
            'type_name' => '🔥<JSON>热门推荐',
            'type_file' => 'recommend',
            'source_path' => 'recommend',
            'file_size' => '可翻页'
        ];
        
        // 添加原始JSON文件分类
        foreach ($allFiles as $index => $file) {
            $fileSize = $file['size'] ? round($file['size'] / 1024, 1) . 'KB' : '未知';
            
            $categories[] = [
                'type_id' => (string)($index + 1000), // 从1000开始避免冲突
                'type_name' => $file['filename'],
                'type_file' => $file['name'],
                'source_path' => $file['path'],
                'file_size' => $fileSize
            ];
        }
    }
    
    return $categories;
}

/**
 * 获取所有推荐视频 - 懒加载版本
 */
function getAllRecommendVideos() {
    static $allVideos = null;
    
    if ($allVideos === null) {
        $allVideos = [];
        $allFiles = getAllJsonFilesFast();
        
        // 处理所有JSON文件获取所有视频
        foreach ($allFiles as $file) {
            $videos = parseJsonFile($file['path']);
            if (!empty($videos)) {
                foreach ($videos as $video) {
                    $allVideos[] = formatVideoItem($video);
                }
            }
        }
        
        // 随机打乱视频顺序
        shuffle($allVideos);
    }
    
    return $allVideos;
}

/**
 * 首页数据 - 只显示分类，不显示推荐视频
 */
function getHome() {
    $categories = getCategoriesFast();
    
    if (empty($categories)) {
        return ['error' => 'No JSON files found'];
    }
    
    // 首页只显示分类，没有任何视频内容
    return [
        'class' => $categories
    ];
}

/**
 * 解析JSON文件内容
 */
function parseJsonFile($filePath) {
    if (!file_exists($filePath)) {
        return [];
    }
    
    $jsonContent = @file_get_contents($filePath);
    if ($jsonContent === false) {
        return [];
    }
    
    $data = json_decode($jsonContent, true);
    if (!$data || !isset($data['list']) || !is_array($data['list'])) {
        return [];
    }
    
    return $data['list'];
}

/**
 * 分类列表 - 支持🔥推荐分类和普通分类
 */
function getCategory($tid, $page) {
    $categories = getCategoriesFast();
    
    if (empty($categories)) {
        return ['error' => 'No categories found'];
    }
    
    // 如果是🔥推荐分类
    if ($tid === 'recommend') {
        return getRecommendCategory($page);
    }
    
    // 找到对应的普通分类
    $targetCategory = null;
    foreach ($categories as $category) {
        if ($category['type_id'] === $tid) {
            $targetCategory = $category;
            break;
        }
    }
    
    if (!$targetCategory) {
        return ['error' => 'Category not found'];
    }
    
    // 读取普通分类文件内容
    $categoryVideos = parseJsonFile($targetCategory['source_path']);
    
    if (empty($categoryVideos)) {
        return ['error' => 'No videos found in: ' . $targetCategory['type_name']];
    }
    
    // 分页处理
    $pageSize = 20;
    $total = count($categoryVideos);
    $pageCount = ceil($total / $pageSize);
    $currentPage = intval($page);
    
    if ($currentPage < 1) $currentPage = 1;
    if ($currentPage > $pageCount) $currentPage = $pageCount;
    
    $start = ($currentPage - 1) * $pageSize;
    $pagedVideos = array_slice($categoryVideos, $start, $pageSize);
    
    $formattedVideos = [];
    foreach ($pagedVideos as $video) {
        $formattedVideos[] = formatVideoItem($video);
    }
    
    return [
        'page' => $currentPage,
        'pagecount' => $pageCount,
        'limit' => $pageSize,
        'total' => $total,
        'list' => $formattedVideos,
        'style' => [
            'type' => 'rect',
            'ratio' => 1.33
        ]
    ];
}

/**
 * 🔥推荐分类处理 - 支持分页
 */
function getRecommendCategory($page) {
    $allRecommendVideos = getAllRecommendVideos();
    
    if (empty($allRecommendVideos)) {
        return ['error' => 'No videos found'];
    }
    
    // 分页处理
    $pageSize = 20;
    $total = count($allRecommendVideos);
    $pageCount = ceil($total / $pageSize);
    $currentPage = intval($page);
    
    if ($currentPage < 1) $currentPage = 1;
    if ($currentPage > $pageCount) $currentPage = $pageCount;
    
    $start = ($currentPage - 1) * $pageSize;
    $pagedVideos = array_slice($allRecommendVideos, $start, $pageSize);
    
    $formattedVideos = [];
    foreach ($pagedVideos as $video) {
        $formattedVideos[] = formatVideoItem($video);
    }
    
    return [
        'page' => $currentPage,
        'pagecount' => $pageCount,
        'limit' => $pageSize,
        'total' => $total,
        'list' => $formattedVideos,
        'style' => [
            'type' => 'rect',
            'ratio' => 1.33
        ]
    ];
}

/**
 * 视频详情 - 按需查找
 */
function getDetail($ids) {
    $idArray = explode(',', $ids);
    $result = [];
    
    foreach ($idArray as $id) {
        $video = findVideoByIdLazy($id);
        if ($video) {
            $result[] = formatVideoDetail($video);
        } else {
            // 默认详情
            $result[] = [
                'vod_id' => $id,
                'vod_name' => '视频 ' . $id,
                'vod_pic' => 'https://2uspicc12tche.hitv.app/350/upload/vod/20240415-1/2636d5210e5cf7a6f0cff5c737e6c7b5.webp',
                'vod_remarks' => 'HD',
                'vod_content' => '视频详情内容',
                'vod_play_from' => '在线播放',
                'vod_play_url' => '正片$https://example.com/video.m3u8'
            ];
        }
    }
    
    return ['list' => $result];
}

/**
 * 按ID查找视频 - 懒加载版本
 */
function findVideoByIdLazy($id) {
    $allFiles = getAllJsonFilesFast();
    
    // 在所有JSON文件中查找视频
    foreach ($allFiles as $file) {
        $videos = parseJsonFile($file['path']);
        foreach ($videos as $video) {
            if (isset($video['vod_id']) && $video['vod_id'] == $id) {
                return $video;
            }
        }
    }
    
    return null;
}

/**
 * 搜索 - 懒加载搜索
 */
function search($keyword, $page) {
    if (empty($keyword)) {
        return ['error' => 'Keyword is required'];
    }
    
    $searchResults = [];
    $allFiles = getAllJsonFilesFast();
    
    // 限制搜索的文件数量，提高性能
    $searchLimit = 5;
    $searchedFiles = 0;
    
    foreach ($allFiles as $file) {
        if ($searchedFiles >= $searchLimit) break;
        
        $videos = parseJsonFile($file['path']);
        foreach ($videos as $video) {
            if (stripos($video['vod_name'] ?? '', $keyword) !== false) {
                $searchResults[] = formatVideoItem($video);
                
                // 限制搜索结果总数
                if (count($searchResults) >= 50) break 2;
            }
        }
        
        $searchedFiles++;
    }
    
    if (empty($searchResults)) {
        return ['error' => 'No search results'];
    }
    
    // 分页处理
    $pageSize = 20;
    $total = count($searchResults);
    $pageCount = ceil($total / $pageSize);
    $currentPage = intval($page);
    
    if ($currentPage < 1) $currentPage = 1;
    if ($currentPage > $pageCount) $currentPage = $pageCount;
    
    $start = ($currentPage - 1) * $pageSize;
    $pagedResults = array_slice($searchResults, $start, $pageSize);
    
    return [
        'page' => $currentPage,
        'pagecount' => $pageCount,
        'limit' => $pageSize,
        'total' => $total,
        'list' => $pagedResults
    ];
}

/**
 * 格式化视频项（列表用）
 */
function formatVideoItem($video) {
    return [
        'vod_id' => $video['vod_id'] ?? '',
        'vod_name' => $video['vod_name'] ?? '',
        'vod_pic' => $video['vod_pic'] ?? '',
        'vod_remarks' => $video['vod_remarks'] ?? 'HD',
        'vod_year' => $video['vod_year'] ?? '',
        'vod_area' => $video['vod_area'] ?? ''
    ];
}

/**
 * 格式化视频详情
 */
function formatVideoDetail($video) {
    return [
        'vod_id' => $video['vod_id'] ?? '',
        'vod_name' => $video['vod_name'] ?? '',
        'vod_pic' => $video['vod_pic'] ?? '',
        'vod_remarks' => $video['vod_remarks'] ?? 'HD',
        'vod_year' => $video['vod_year'] ?? '',
        'vod_area' => $video['vod_area'] ?? '',
        'vod_director' => $video['vod_director'] ?? '',
        'vod_actor' => $video['vod_actor'] ?? '',
        'vod_content' => $video['vod_content'] ?? '',
        'vod_play_from' => $video['vod_play_from'] ?? 'default',
        'vod_play_url' => $video['vod_play_url'] ?? ''
    ];
}

/**
 * 获取播放地址
 */
function getPlay($flag, $id) {
    return [
        'parse' => 0,
        'playUrl' => '',
        'url' => $id
    ];
}