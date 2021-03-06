<?php
/**
 * Article module upload renderer
 *
 * You may not change or alter any portion of this comment or credits
 * of supporting developers from this source code or any supporting source code
 * which is considered copyrighted (c) material of the original comment or credit authors.
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @copyright       Copyright (c) http://www.eefocus.com
 * @license         http://www.xoopsengine.org/license New BSD License
 * @author          Lijun Dong <lijun@eefocus.com>
 * @author          Zongshu Lin <zongshu@eefocus.com>
 * @since           1.0
 * @package         Module\Article
 */

namespace Module\Article;
use Pi;
use Zend\File\Transfer\Transfer;
use Module\Article\Model\Asset;

class Upload
{
    const DEFAULT_IMAGE_TYPE    = IMAGETYPE_PNG;
    const PATTERN_HTML_IMAGE    = '|<img.+src="(http.+)".+/?>|isU';
    const FORMAT_IMAGE_ANCHOR   = '<a href="%s" target="_blank">%s</a>';

    protected static $module = 'article';
    
    public static $imageTypes = array(
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
    );

    public static function mkdir($dir)
    {
        $result = true;

        if (!file_exists($dir)) {
            $oldumask = umask(0);

            $result   = mkdir($dir, 0777, TRUE);

            umask($oldumask);
        }

        return $result;
    }

    public static function randomKey($algo = 'md5')
    {
        return uniqid();

        $result = microtime();

        if (0 === strcasecmp($algo, 'md5')) {
            $result = md5($result);
        } else if (0 === strcasecmp($algo, 'sha1')) {
            $result = sha1($result);
        }

        return $result;
    }

    public static function file_get_contents_timeout($url, $timeout = 30)
    {
        $opts = array(
            'http' => array(
                'method'  => 'GET',
                'timeout' => $timeout,
            ),
        );

        $context = stream_context_create($opts);

        return file_get_contents($url, null, $context);
    }

    public static function fromByteString($size)
    {
        if (is_numeric($size)) {
            return (integer) $size;
        }

        $type  = trim(substr($size, -2, 1));

        $value = substr($size, 0, -1);
        if (!is_numeric($value)) {
            $value = substr($value, 0, -1);
        }

        switch (strtoupper($type)) {
            case 'Y':
                $value *= (1024 * 1024 * 1024 * 1024 * 1024 * 1024 * 1024 * 1024);
                break;
            case 'Z':
                $value *= (1024 * 1024 * 1024 * 1024 * 1024 * 1024 * 1024);
                break;
            case 'E':
                $value *= (1024 * 1024 * 1024 * 1024 * 1024 * 1024);
                break;
            case 'P':
                $value *= (1024 * 1024 * 1024 * 1024 * 1024);
                break;
            case 'T':
                $value *= (1024 * 1024 * 1024 * 1024);
                break;
            case 'G':
                $value *= (1024 * 1024 * 1024);
                break;
            case 'M':
                $value *= (1024 * 1024);
                break;
            case 'K':
                $value *= 1024;
                break;
            default:
                break;
        }

        return $value;
    }

    public static function httpOutputFile(array $options)
    {
        if ((!isset($options['file']) && !isset($options['raw']))) {
            if (!$options['silent']) {
                header('HTTP/1.0 404 Not Found');
            }
            exit();
        }
        if (isset($options['file']) && !is_file($options['file'])) {
            if (!$options['silent']) {
                header('HTTP/1.0 403 Forbidden');
            }
            exit();
        }
        if (strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE') !== false) {
            $options['fileName'] = urlencode($options['fileName']);
        }
        $options['fileSize'] = isset($options['file']) ? filesize($options['file']) : strlen($options['raw']);

        if (ini_get('zlib.output_compression')) {
            ini_set('zlib.output_compression', 'Off');
        }

        header("Pragma: public");
        header('Content-Description: File Transfer');
        if (strstr($_SERVER['HTTP_USER_AGENT'], 'MSIE') === false) {
            header('Content-Type: application/force-download; charset=UTF-8');
        } else {
            header('Content-Type: application/octet-stream; charset=UTF-8');
        }
        header('Content-Disposition: attachment; filename="' . $options['fileName'] . '"');
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        header('Cache-Control: private', false);
        header('Pragma: public');
        header('Content-Length: ' . $options['fileSize']);
        ob_clean();
        flush();

        if (!empty($options['file'])) {
            readfile($options['file']);
            if (!empty($options['deleteFile'])) {
                @unlink($options['file']);
            }
        } else {
            echo $options['raw'];
            ob_flush();
            flush();
        }
        if (empty($options['notExit'])) {
            exit();
        }
    }

    public static function getUploadSession($module = null, $type = 'default')
    {
        $module = $module ?: self::$module;
        $ns     = sprintf('%s_%s_upload', $module, $type);

        return Pi::service('session')->$ns;
    }

    public static function getImageExt($imageType = null)
    {
        return array_key_exists($imageType, self::$imageTypes) ? self::$imageTypes[$imageType] : self::$imageTypes[self::DEFAULT_IMAGE_TYPE];
    }

    public static function getTargetDir($section, $module = null, $autoCreate = false, $autoSplit = true)
    {
        $module     = $module ?: self::$module;
        $config     = Pi::service('module')->config('', $module);
        $pathKey    = sprintf('path_%s', strtolower($section));
        $path       = isset($config[$pathKey]) ? $config[$pathKey] : '';

        if ($autoSplit && !empty($config['sub_dir_pattern'])) {
            $path .= '/' . date($config['sub_dir_pattern']);
        }

        if ($autoCreate) {
            self::mkdir(Pi::path($path));
        }

        return $path;
    }

    public static function getTargetFileName($id, $section, $module = null)
    {
        $targetDir = self::getTargetDir($section, $module, true);
        $ext       = self::getImageExt(self::DEFAULT_IMAGE_TYPE);

        return sprintf('%s/%s.%s', $targetDir, $id, $ext);
    }

    public static function getThumbFromOriginal($fileName)
    {
//        return preg_replace('/(.+)(\d+)(\.png)$/', '${1}${2}-thumb${3}', $fileName);
        $parts = pathinfo($fileName);
        return $parts['dirname'] . '/' . $parts['filename'] . '-thumb.' . $parts['extension'];
    }

//    public static function moveTmpToAsset($file, $module, $type = Asset::FIELD_TYPE_ATTACHMENT)
//    {
//        $result = false;
//
//        $basename   = pathinfo($file, PATHINFO_BASENAME);
//        $targetDir  = self::getTargetDir($type, $module, true);
//        $targetName = $targetDir . '/' . $basename;
//        $result     = rename(Pi::path($file), Pi::path($targetName));
//
//        return $result ? $targetName : false;
//    }
//
//    public static function copyAssetToTmp($file, $module)
//    {
//        $result = false;
//
//        $basename  = pathinfo($file, PATHINFO_BASENAME);
//        $targetDir  = self::getTargetDir('tmp', $module, true);
//        $targetName = $targetDir . '/' . $basename;
//        $result     = copy(Pi::path($file), Pi::path($targetName));
//
//        return $result ? $targetName : false;
//    }
//
//    public static function saveTmpImage($uploadInfo, $module = null)
//    {
//        $targetDir   = self::getTargetDir('tmp', $module, true);
//
//        $ext         = strtolower(pathinfo($uploadInfo['name'], PATHINFO_EXTENSION));
//        $tmpFileName = sprintf('%s/%s.%s', $targetDir, self::randomKey(), $ext);
//
//        $result = move_uploaded_file($uploadInfo['tmp_name'], Pi::path($tmpFileName));
//
//        $uploadInfo['tmp_name'] = $tmpFileName;
//
//        // Save info to session
//        $session = self::getUploadSession($module);
//        $session->$tmpFileName = $uploadInfo;
//
//        return $result ? $tmpFileName : false;
//    }
//
//    public static function saveTmpFile($uploadInfo, $module = null)
//    {
//        $targetDir   = self::getTargetDir('tmp', $module, true);
//
//        $tmpFileName = $targetDir . '/' . self::randomKey();
//        $ext         = strtolower(pathinfo($uploadInfo['name'], PATHINFO_EXTENSION));
//        if ($ext) {
//            $tmpFileName .= '.' . $ext;
//        }
//        $absolutePath = Pi::path($tmpFileName);
//        $result = move_uploaded_file($uploadInfo['tmp_name'], $absolutePath);
//
//        return $result ? $tmpFileName : false;
//    }
//
//    public static function getTmpFileName($fileName, $module = null)
//    {
//        $result    = '';
//
//        $targetDir = self::getTargetDir('tmp', $module, true);
//        $result    = $targetDir . '/' . self::randomKey();
//        $ext       = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
//        if ($ext) {
//            $result .= '.' . $ext;
//        }
//
//        return $result;
//    }

    public static function getAssetFileName($originalName, $type, $module = null)
    {
        $result = '';

        $targetDir = self::getTargetDir($type, $module, true);
        $result    = rtrim($targetDir, '/') . '/' . self::randomKey();
        $ext       = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext) {
            $result .= '.' . $ext;
        }

        return $result;
    }

    public static function saveAuthorPhoto($uploadInfo)
    {
        $result = false;
        $size   = array();

        $fileName       = $uploadInfo['tmp_name'];
        $absoluteName   = Pi::path($fileName);

        $size = array(
//            'x' => $uploadInfo['x'],
//            'y' => $uploadInfo['y'],
            'w' => $uploadInfo['w'],
            'h' => $uploadInfo['h'],
        );

        $image = new Image($absoluteName);

        if ($image->isValid()) {
            $result = $image->save($absoluteName, $size, Image::OP_TYPE_SCALE, $image->getType());
        }

        return $result ? $fileName : false;
    }

    public static function saveCategoryImage($uploadInfo)
    {
        $result = false;
        $size   = array();

        $fileName       = $uploadInfo['tmp_name'];
        $absoluteName   = Pi::path($fileName);

        $size = array(
//            'x' => $uploadInfo['x'],
//            'y' => $uploadInfo['y'],
            'w' => $uploadInfo['w'],
            'h' => $uploadInfo['h'],
        );

        $image = new Image($absoluteName);

        if ($image->isValid()) {
            $result = $image->save($absoluteName, $size, Image::OP_TYPE_SCALE, $image->getType());
        }

        return $result ? $fileName : false;
    }

    public static function saveFeatureImage($uploadInfo)
    {
        $result = false;
        $size   = $sizeThumb = array();

        $fileName       = $uploadInfo['tmp_name'];
        $absoluteName   = Pi::path($fileName);
        $thumbName      = self::getThumbFromOriginal($fileName);
        $absoluteThumb  = Pi::path($thumbName);

        $size = array(
//            'x' => $uploadInfo['x'],
//            'y' => $uploadInfo['y'],
            'w' => $uploadInfo['w'],
            'h' => $uploadInfo['h'],
        );

        $image = new Image($absoluteName);

        if ($image->isValid()) {
            $result = $image->save($absoluteName, $size, Image::OP_TYPE_SCALE, $image->getType());
        }

        // Create thumb
        if ($result) {
            $imageThumb = new Image($absoluteName);

            if ($imageThumb->isValid()) {
                $sizeThumb = array(
                    'w' => $uploadInfo['thumb_w'],
                    'h' => $uploadInfo['thumb_h'],
                );

                $imageThumb->save($absoluteThumb, $sizeThumb, Image::OP_TYPE_RESIZE, $imageThumb->getType());
            }
        }

        return $result ? $fileName : false;
    }

    public static function createThumb($uploadInfo)
    {
        $result = false;
        $size   = array();

        $fileName       = $uploadInfo['tmp_name'];
        $absoluteName   = Pi::path($fileName);
        $thumbName      = self::getThumbFromOriginal($fileName);
        $absoluteThumb  = Pi::path($thumbName);

        $size = array(
            'w' => $uploadInfo['thumb_w'],
            'h' => $uploadInfo['thumb_h'],
        );

        $image = new Image($absoluteName);
        if ($image->isValid()) {
            $result = $image->save($absoluteThumb, $size, Image::OP_TYPE_FIT, $image->getType());
        }

        return $result ? $thumbName : false;
    }

    public static function remoteToLocal($remote, $module)
    {
        $module = $module ?: self::$module;
        $result = $data = false;
        $dest   = $absolutePath = '';

        $data = self::file_get_contents_timeout($remote, 10);
        if ($data) {
            $dest         = self::getAssetFileName('', Asset::FIELD_TYPE_IMAGE, $module);
            $absolutePath = Pi::path($dest);
            $result       = file_put_contents($absolutePath, $data) !== false;

            if ($result) {
                $image = new Image($absolutePath);
                if ($image) {
                    // Rename
                    $type  = $image->getType();

                    if (!empty($type)) {
                        $ext   = self::getImageExt($type);
                        $dest .= '.' . $ext;
                        rename($absolutePath, Pi::path($dest));

                        // Create thumb
                        $config     = Pi::service('module')->config('', $module);
                        $uploadInfo = array(
                            'tmp_name'  => $dest,
                            'thumb_w'   => $config['content_thumb_w'],
                            'thumb_h'   => $config['content_thumb_h'],
                        );
                        self::createThumb($uploadInfo);
                    } else {
                        $result = false;
                    }
                } else {
                    $result = false;
                }
            }
        }

        return $result ? $dest : false;
    }
}
