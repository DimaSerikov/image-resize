<?php

namespace Alexantr\ImageResize;

/**
 * Create image from generated by Image path
 */
class Creator
{
    const BLANK_IMAGE_NAME = 'b.gif';
    const DEFAULT_IMAGE_NAME = 'no-image.png';
    const DEFAULT_SILHOUETTE_NAME = 'no-image-person.png';

    /**
     * @var string base directory where resized images are saving
     */
    public static $resizedBaseDir = '/resized';

    /**
     * @var int min image resolution size
     */
    public static $minSize = 8;

    /**
     * @var int max image resolution size
     */
    public static $maxSize = 3072;

    /**
     * @var int min jpeg quality
     */
    public static $minQuality = 10;

    /**
     * @var int max jpeg quality
     */
    public static $maxQuality = 100;

    /**
     * @var int default jpeg quality
     */
    public static $defaultQuality = 90;

    /**
     * @var string default background color
     */
    public static $defaultBgColor = 'fff';

    /**
     * @var array allowed mime types
     */
    public static $mimeTypes = array('image/gif', 'image/jpeg', 'image/png');

    /**
     * @var array allowed methods
     */
    public static $methods = array('crop', 'fit', 'fitw', 'fith', 'place');

    /**
     * @var string custom path to default image
     */
    public static $defaultImagePath;

    /**
     * @var string custom path to default silhouette image
     */
    public static $defaultSilhouettePath;

    /**
     * @var bool generate progressive jpegs
     */
    public static $enableProgressiveJpeg = false;

    /**
     * @var int|null mode for imagescale()
     * @see https://www.php.net/manual/en/function.imagescale.php
     * @see https://www.php.net/manual/ru/function.imagesetinterpolation.php
     * Set to false to disable usage of imagescale()
     */
    public static $imagescaleMode = null;

    /**
     * @var int PNG compression level (0..9)
     */
    public static $pngCompressionLevel = 9;

    /**
     * Create image based on $path
     * @param string $webroot
     * @param string $path
     * @throws \Exception
     */
    public static function create($webroot, $path)
    {
        // show blank image
        if ($path == self::BLANK_IMAGE_NAME) {
            self::showBlankImage();
        }

        // get params from path
        $params = Helper::parsePath($path);
        if (!is_array($params)) {
            self::showBlankImage();
        }

        $dir_name = $params['dir_name'];
        $width = $params['width'];
        $height = $params['height'];
        $method = $params['method'];
        $quality = $params['quality'];
        $bg_color = $params['bg_color'];
        $silhouette = $params['silhouette'];
        $disable_alpha = $params['disable_alpha'];
        $as_jpeg = $params['as_jpeg'];
        $place_upper = $params['place_upper'];
        $no_top_offset = $params['no_top_offset'];
        $no_bottom_offset = $params['no_bottom_offset'];
        $disable_copy = $params['disable_copy'];
        $skip_small = $params['skip_small'];
        $image_url = $params['image_url'];

        // wrong params
        if (
            empty($image_url) ||
            $width < self::$minSize || $height < self::$minSize ||
            $width > self::$maxSize || $height > self::$maxSize ||
            !in_array($method, self::$methods)
        ) {
            self::showBlankImage();
        }

        // clean url
        $image_url = Helper::cleanImageUrl($image_url);
        if (empty($image_url)) {
            self::showBlankImage();
        }

        // if image already exists
        $dest_path = $webroot . self::$resizedBaseDir . '/' . $dir_name . '/' . $image_url;
        if (is_file($dest_path)) {
            self::showImage($dest_path);
        }

        // original image abs path
        $orig_path = $webroot . '/' . $image_url;

        // try to deal with jpeg forcing
        if (!is_file($orig_path) && $as_jpeg) {
            $orig_dirname = dirname($orig_path);
            $filename = pathinfo($orig_path, PATHINFO_FILENAME);
            $orig_ext = pathinfo($filename, PATHINFO_EXTENSION);
            if (!empty($orig_ext) && in_array($orig_ext, array('jpeg', 'jpg', 'png', 'gif')) && is_file($orig_dirname . '/' . $filename)) {
                $orig_path = $orig_dirname . '/' . $filename;
            }
        }

        // create paths for missing image
        if (!is_file($orig_path)) {
            if ($silhouette) {
                if (self::$defaultSilhouettePath !== null && is_file(self::$defaultSilhouettePath)) {
                    $image_name = basename(self::$defaultSilhouettePath);
                    $orig_path = self::$defaultSilhouettePath;
                    $dest_path = $webroot . self::$resizedBaseDir . '/' . $dir_name . '/custom-' . $image_name;
                } else {
                    $image_name = self::DEFAULT_SILHOUETTE_NAME;
                    $orig_path = __DIR__ . '/images/' . $image_name;
                    $dest_path = $webroot . self::$resizedBaseDir . '/' . $dir_name . '/' . $image_name;
                }
            } else {
                if (self::$defaultImagePath !== null && is_file(self::$defaultImagePath)) {
                    $image_name = basename(self::$defaultImagePath);
                    $orig_path = self::$defaultImagePath;
                    $dest_path = $webroot . self::$resizedBaseDir . '/' . $dir_name . '/custom-' . $image_name;
                } else {
                    $image_name = self::DEFAULT_IMAGE_NAME;
                    $orig_path = __DIR__ . '/images/' . $image_name;
                    $dest_path = $webroot . self::$resizedBaseDir . '/' . $dir_name . '/' . $image_name;
                }
            }
            // already exists - with php each time
            if (is_file($dest_path)) {
                self::showImage($dest_path);
            }
        }

        // can't find default image
        if (!is_file($orig_path)) {
            self::showBlankImage();
        }

        // check sizes
        $size = getimagesize($orig_path);
        if (!$size) {
            self::showBlankImage();
        }
        $src_w = $size[0];
        $src_h = $size[1];
        if ($src_w == 0 || $src_h == 0 || empty($size['mime']) || !in_array($size['mime'], self::$mimeTypes)) {
            self::showBlankImage();
        }

        $mime_type = $size['mime'];

        // create dir
        $dir_path = dirname($dest_path);
        if (!is_dir($dir_path)) {
            if (!@mkdir($dir_path, 0775, true)) {
                self::showBlankImage();
            }
        }

        $rotate = 0;

        // try to read exif orientation
        if (function_exists('exif_read_data') && $mime_type == 'image/jpeg') {
            $exif = @exif_read_data($orig_path);
            if (!empty($exif['Orientation'])) {
                switch ($exif['Orientation']) {
                    case 3:
                        $rotate = 180;
                        break;
                    case 6:
                        $rotate = -90;
                        break;
                    case 8:
                        $rotate = 90;
                        break;
                }
            }
        }

        // switch width & height
        if ($rotate == 90 || $rotate == -90) {
            $old_w = $src_w;
            $src_w = $src_h;
            $src_h = $old_w;
        }

        if (!$disable_copy) {
            // copy with identical sizes
            if (
                ($method == 'fitw' && $width == $src_w) ||
                ($method == 'fith' && $height == $src_h) ||
                ($method != 'fitw' && $method != 'fith' && $width == $src_w && $height == $src_h)
            ) {
                copy($orig_path, $dest_path);
                if (is_file($dest_path)) {
                    self::showImage($dest_path);
                }
            }

            // copy smaller
            if ($skip_small) {
                if (
                    ($method == 'fitw' && $width >= $src_w) ||
                    ($method == 'fith' && $height >= $src_h) ||
                    ($method != 'fitw' && $method != 'fith' && $width >= $src_w && $height >= $src_h)
                ) {
                    copy($orig_path, $dest_path);
                    if (is_file($dest_path)) {
                        self::showImage($dest_path);
                    }
                }
            }
        }

        if ($mime_type == 'image/gif') {
            $im = imagecreatefromgif($orig_path);
        } elseif ($mime_type == 'image/png') {
            $im = imagecreatefrompng($orig_path);
        } elseif ($mime_type == 'image/jpeg') {
            $im = imagecreatefromjpeg($orig_path);
        } else {
            $im = false;
        }

        if ($im === false) {
            self::showBlankImage();
        }

        // rotate original
        if ($rotate != 0) {
            $im = imagerotate($im, $rotate, 0);
        }

        $dst_x = 0;
        $dst_y = 0;
        $x = 0;
        $y = 0;

        if ($method == 'crop') {
            $ratio = max($width / $src_w, $height / $src_h);
            $new_w = round($src_w * $ratio);
            $new_h = round($src_h * $ratio);
            $x = floor(($src_w - $width / $ratio) / 2);
            if ($no_top_offset) {
                $y = 0;
            } elseif ($no_bottom_offset) {
                $y = floor($src_h - $height / $ratio);
            } else {
                $y = round(($src_h - $height / $ratio) / 2);
            }
            // place upper
            if ($y > 0 && $place_upper) {
                $y = round($y / 3 * 2);
            }
        } elseif ($method == 'fitw') {
            $new_w = $width;
            $new_h = $new_w / $src_w * $src_h;
            $width = $new_w;
            $height = $new_h;
        } elseif ($method == 'fith') {
            $new_h = $height;
            $new_w = $new_h * $src_w / $src_h;
            $width = $new_w;
            $height = $new_h;
        } elseif ($method == 'place') {
            $ratio = min($width / $src_w, $height / $src_h);
            $new_w = round($src_w * $ratio);
            $new_h = round($src_h * $ratio);
            $dst_x = round(($width - $new_w) / 2);
            $dst_y = round(($height - $new_h) / 2);
        } else {
            $ratio = min($width / $src_w, $height / $src_h);
            $new_w = round($src_w * $ratio);
            $new_h = round($src_h * $ratio);
            $width = $new_w;
            $height = $new_h;
        }

        // copying
        $rgb = Helper::hex2rgb($bg_color);
        $new_im = imagecreatetruecolor($width, $height);
        if (!$disable_alpha && !$as_jpeg && $mime_type == 'image/png') {
            imagealphablending($new_im, false);
            imagesavealpha($new_im, true);
            $color = imagecolorallocatealpha($new_im, $rgb['r'], $rgb['g'], $rgb['b'], 127);
            imagefilledrectangle($new_im, 0, 0, $width, $height, $color);
        } else {
            $color = imagecolorallocate($new_im, $rgb['r'], $rgb['g'], $rgb['b']);
            imagefill($new_im, 0, 0, $color);
        }
        $mode = self::$imagescaleMode !== null ? self::$imagescaleMode : IMG_MITCHELL;
        if (function_exists('imagescale') && $mode !== false) {
            $cropped = imagecrop($im, array('x' => $x, 'y' => $y, 'width' => $src_w, 'height' => $src_h));
            $scaled = imagescale($cropped, $new_w, $new_h, $mode);
            imagecopy($new_im, $scaled, $dst_x, $dst_y, 0, 0, $new_w, $new_h);
        } else {
            imagecopyresampled($new_im, $im, $dst_x, $dst_y, $x, $y, $new_w, $new_h, $src_w, $src_h);
        }

        // saving
        if ($mime_type == 'image/png' && !$as_jpeg) {
            $level = self::$pngCompressionLevel;
            imagepng($new_im, $dest_path, $level);
        } elseif ($mime_type == 'image/gif' && !$as_jpeg) {
            imagegif($new_im, $dest_path);
        } else {
            if (self::$enableProgressiveJpeg) {
                imageinterlace($new_im, 1);
            }
            imagejpeg($new_im, $dest_path, $quality);
        }

        imagedestroy($im);
        imagedestroy($new_im);

        if (is_file($dest_path)) {
            self::showImage($dest_path, $mime_type);
        } else {
            self::showBlankImage();
        }
    }

    /**
     * Show Image
     * @param string $image_path
     * @param null|string $mime_type
     */
    public static function showImage($image_path, $mime_type = null)
    {
        if ($mime_type === null) {
            $size = getimagesize($image_path);
            if (!$size || $size[0] == 0 || $size[1] == 0 || empty($size['mime']) || !in_array($size['mime'], self::$mimeTypes)) {
                self::showBlankImage();
            }
            $mime_type = $size['mime'];
        }
        if (!in_array($mime_type, self::$mimeTypes)) {
            self::showBlankImage();
        }
        header('Content-Type: ' . $mime_type);
        header('Content-Length: ' . filesize($image_path));
        readfile($image_path);
        exit;
    }

    /**
     * Show Blank Image (1x1 transparent)
     */
    public static function showBlankImage()
    {
        $image = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
        header('Content-Type: image/gif');
        header('Content-Length: ' . mb_strlen($image, '8bit'));
        echo $image;
        exit;
    }
}
