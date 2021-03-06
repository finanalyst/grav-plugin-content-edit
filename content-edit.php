<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;
use Symfony\Component\Yaml\Yaml;
use Grav\Common\Utils;

/**
 * Class ContentEditPlugin
 * @package Grav\Plugin
 */
class ContentEditPlugin extends Plugin
{
    protected $logfile;
    protected $renderer;
    protected $edits_loc = 'content-edit';

    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0]
        ];
    }

    public function onPluginsInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->enable( [
                'onAdminTwigTemplatePaths' => ['onAdminTwigTemplatePaths', 0]
            ]);
            return;
        }

        // Enable events we are interested in
        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0 ],
            'onPageInitialized' => ['onPageInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths',0]
        ]);
        $path = DATA_DIR . $this->edits_loc . DS . 'editing_for_' . date('Y-m_M_Y') . '.yaml';
        $this->logfile = File::instance($path);
        require_once __DIR__ . '/embedded/php-diff-master/lib/Diff.php';
        switch ( $this->config->get('plugins.content-edit.editReport') ) {
            case 'html_side_side':
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Html/SideBySide.php';
                $this->renderer = new \Diff_Renderer_Html_SideBySide;
                break;
            case 'html_inline':
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Html/Inline.php';
                $this->renderer = new \Diff_Renderer_Html_Inline;
                break;
            case 'txt_unified':
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Text/Unified.php';
                $this->renderer = new \Diff_Renderer_Text_Unified;
                break;
            case 'txt_context':
            default:
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Text/Context.php';
                $this->renderer = new \Diff_Renderer_Text_Context;
        }
        # verify data directory exists with correct permissions
        if (!file_exists(DATA_DIR . $this->edits_loc )) {
            mkdir(DATA_DIR . $this->edits_loc , 0775, true);
        }
    }

    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/templates';
        $assets = $this->grav['assets'];
        // Add SimpleMDE Markdown Editor
        $assets->addCss('//cdn.jsdelivr.net/simplemde/latest/simplemde.min.css', 1);
        $assets->addJs('//cdn.jsdelivr.net/simplemde/latest/simplemde.min.js', 1);

        $assets->addCss('plugin://content-edit/css/content-edit.css', 2);
        $assets->addJs('plugin://content-edit/embedded/simpleUpload.min.js',0);
    }

    public function onAdminTwigTemplatePaths($event)
    {
        $event['paths'] = [__DIR__ . '/templates'];
        $assets = $this->grav['assets'];
        $assets->addCss('plugin://content-edit/css/content-edit.css');
    }

    public function onPageInitialized() {
        // only needed if processing a content-editing template;
        $page = $this->grav['page'];
        if ( $page->template() !== 'content-edit-review' ) return;
        $items = [];
        $fileIterator = new \FilesystemIterator(DATA_DIR . $this->edits_loc, \FilesystemIterator::SKIP_DOTS);
        foreach ($fileIterator as $entry) {
            $file = $entry->getFilename();
            if (!$entry->isFile() || !preg_match('/\d\d\d\d\-\d\d\_(.+)\_(.+)\.yaml$/', $file,$matches) ) {
                // Is not file or not .yaml
                continue;
            }
            $items[] = [
                'name' => $matches[1] . ' ' . $matches[2],
                'route' => $file,
                'count' => count(CompiledYamlFile::instance(DATA_DIR . $this->edits_loc . DS . $file)->content())
            ];
        }
        $this->grav['twig']->items = $items;
    //    sortArrayByKey($items, 'name', SORT_DESC, SORT_NATURAL);
    }

    /**
     * Pass valid actions (via AJAX requests) on to the editor resource to handle
     *
     * @return the output of the editor resource
     */
    public function onPagesInitialized()
    {
        // Don't proceed if we are in the admin plugin
        if ($this->isAdmin()) {
            $this->active = false;
            return;
        }
        // Do not act upon empty POST payload or any POST not for this plugin
        $post = $_POST;
        if (!$post || ! isset($post['ce_data_post']) ) {
            return;
        }
        // Preemtively handle review edits ajax call
        if ($post['action'] == 'ceReviewEdits') {
            $output = $this->grav['twig']->processTemplate(
                'partials/data-manager/content-edit/item.html.twig',
                [ 'itemData' => CompiledYamlFile::instance(DATA_DIR . $this->edits_loc . DS . $post['file'])->content() ]
            );
        } else {
            $pages = $this->grav['pages'];
            $page = $pages->dispatch($post['page'] , true);
            if ($post['language'] && $post['language'] != 'Default' ) {
                $fn = preg_match('/^(.+)\..+?\.md$/', $page->name(), $matches);
                if ($fn) {
                    $fileP = $page->path() . DS . $matches[1] . '.' . $post['language'] . '.md';
                    $route = $page->route();
                    $page = new Page();
                    $page->init(new \SplFileInfo($fileP), $post['language'] . '.md');
                }
            }
            switch ($post['action']) {
                case 'ceTransferContent': // Transfer the md for the page
                    $hd = $page->header();
                    $menuExists = property_exists($hd,'menu') && $hd->menu !== null;
                    $output = [ 'data' => $page->rawMarkdown(), 'menu' => $page->menu(), 'menuexists' => $menuExists ];
                    break;
                case 'ceSaveContent': // Save markdown content
                    $output = $this->saveContent($post, $page);
                    break;
                case 'cePreviewContent': // Preview a route
                    $r =  ( $post['language'] == 'Default' ? '' : $post['language'] ) . $post['page'] ;
                    $this->grav->redirect($r);
                    break;
                case 'ceFileUpload': // Handle a file (or image) upload
                    $output = $this->saveFile($post, $page);
                    break;
                case 'ceFileDelete': // Deleting a file or images
                    $output = $this->deleteFile($post, $page);
                    break;
                default:
                    return;
            }
        }
        $this->setHeaders();
        echo json_encode($output);
        exit;
    }

    public function deleteFile( $params, $page ) {
        $fn = $params['file'];
        if ( preg_match('/\//',$fn)) {
            return 'NotLocal';
        }
        $path = $page->path() . '/' . $fn;
        if ( ! file_exists($path) ) return 'NotExist';
        if ( ! is_file($path) ) return 'NotFile';
        if ( ! is_writeable( $path) ) return 'NotWriteable';
        try {
            unlink($path);
        } catch (\Exception $e  ) {
            $this->grav['dd']->dump($e);
            return 'CantDelete';
        }
        $record = [ 'date' => date('D d H:i:s'), 'user' => $this->grav['user']->username , 'route' => $params['page'], 'deleted' => $fn ];
        $this->logfile->save($this->logfile->content() . Yaml::dump( [ $record ] ) );
        return 'Ok';
    }
    // Nearly all from editable-simplemde
    public function saveFile($params, $page) {
        $config = $this->grav['config'];
        if (!isset($_FILES['file']['error']) || is_array($_FILES['file']['error'])) {
            return 'ERR::Unknown Errors';
        }

        // Check $_FILES['file']['error'] value.
        switch ( $_FILES['file']['error'] ) {
            case UPLOAD_ERR_OK: $message ='' ;
                break;
            case UPLOAD_ERR_INI_SIZE:
                $message = "ERR::The uploaded file exceeds the upload_max_filesize directive in php.ini";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $message = "ERR::The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form";
                break;
            case UPLOAD_ERR_PARTIAL:
                $message = "ERR::The uploaded file was only partially uploaded";
                break;
            case UPLOAD_ERR_NO_FILE:
                $message = "ERR::No file was uploaded";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $message = "ERR::Missing a temporary folder";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $message = "ERR::Failed to write file to disk";
                break;
            case UPLOAD_ERR_EXTENSION:
                $message = "ERR::File upload stopped by extension";
                break;
            default:
                $message = "ERR::Unknown upload error";
        }
        if ($message) return $message;

        $grav_limit = $config->get('system.media.upload_limit', 0);
        // You should also check filesize here.
        if ($grav_limit > 0 && $_FILES['file']['size'] > $grav_limit) {
            return 'ERR::Site defined file limit exceeded';
        }
        // Check extension
        $fileParts = pathinfo($_FILES['file']['name']);
        $fileExt = '';
        if (isset($fileParts['extension'])) {
            $fileExt = strtolower($fileParts['extension']);
        }

        // If not a supported type, return
        if (!$fileExt || !$config->get("media.types.{$fileExt}")) {
            return 'ERR::Unsupported media type';
        }

        // Upload it
        $safeName = $this->sanitize($_FILES['file']['name']); // which can only be lower case, so ERR:: not possible
        if ( ! move_uploaded_file($_FILES['file']['tmp_name'], $page->path() . DS . $safeName)) {
            return 'ERR::Cannot move file to page directory';
        }

        $record = [ 'date' => date('D d H:i:s'), 'user' => $this->grav['user']->username , 'route' => $params['page'], 'upload' => $safeName ];
        $this->logfile->save($this->logfile->content() . Yaml::dump( [ $record ] ) );
        // All is well, return uploaded filename, which cannot start with ERR::
        return $safeName;
    }

    function saveContent($params, $page) {
        $new_content = $params['content'];
        $newMenu = $params['menu'];
        $old=$page->rawMarkdown();
        $oldMenu = $page->menu();
        // log user save
        // Options for generating the diff
        $dif_options = array();
        $taint = false;
        $record = [];
        if ( $old != $new_content ) {
            $taint = true;
            $page->rawMarkdown($new_content );
            $diff = new \Diff(explode("\n", $old), explode("\n",$new_content ), $dif_options);
            $rendered=$diff->render($this->renderer) ;
            $record[] = [
                'date' => date('D d H:i:s'),
                'user' => $this->grav['user']->username ,
                'route' => $params['page'],
                'lang' => $params['language'],
                'diff' => $rendered
            ];
        }
        $hd = $page->header();
        $menuExists = property_exists($hd,'menu') && $hd->menu !== null;
        if ( $menuExists && $newMenu != $oldMenu ) { // Cannot use p->menu() to replace if no menu originally set.
            $taint = true;
            $page->modifyHeader('menu', $newMenu );
            $record[] = [
                'date' => date('D d H:i:s'),
                'user' => $this->grav['user']->username ,
                'route' => $params['page'],
                'lang' => $params['language'],
                'menu' => [ 'old' => $oldMenu, 'new' => $newMenu ]
            ];
        }
        if ( $taint ) {
            $page->save();
            $this->logfile->save($this->logfile->content() . Yaml::dump( $record ) );
        }
        return 'ok';
    }
    /* Next two functions copied directly from editable-simplemd plugin.
        Qudos to authors.
    */

    function setHeaders()
    {
        header('Content-type: application/json');

        // Calculate Expires Headers if set to > 0
        $expires = $this->grav['config']->get('system.pages.expires');
        if ($expires > 0) {
            $expires_date = gmdate('D, d M Y H:i:s', time() + $expires) . ' GMT';
            header('Cache-Control: max-age=' . $expires);
            header('Expires: '. $expires_date);
        }
    }

    /**
     * Sanitize a string into a safe filename or slug
     *
     * @param string $f
     *
     * @return string
     */
    public function sanitize($f, $type = 'file') {
        /*  A combination of various methods to sanitize a string while retaining
            the "essence" of the original file name as much as possible.
            Note: unsuitable for file paths as '/' and '\' are filtered out.
            Sources:
                http://www.house6.com/blog/?p=83
            and
                http://stackoverflow.com/a/24984010
        */
        $replace_chars = array(
            '&amp;' => '-and-', '@' => '-at-', '©' => 'c', '®' => 'r', 'À' => 'a',
            'Á' => 'a', 'Â' => 'a', 'Ä' => 'a', 'Å' => 'a', 'Æ' => 'ae','Ç' => 'c',
            'È' => 'e', 'É' => 'e', 'Ë' => 'e', 'Ì' => 'i', 'Í' => 'i', 'Î' => 'i',
            'Ï' => 'i', 'Ò' => 'o', 'Ó' => 'o', 'Ô' => 'o', 'Õ' => 'o', 'Ö' => 'o',
            'Ø' => 'o', 'Ù' => 'u', 'Ú' => 'u', 'Û' => 'u', 'Ü' => 'u', 'Ý' => 'y',
            'ß' => 'ss','à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
            'æ' => 'ae','ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ò' => 'o', 'ó' => 'o',
            'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ù' => 'u', 'ú' => 'u',
            'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'þ' => 'p', 'ÿ' => 'y', 'Ā' => 'a',
            'ā' => 'a', 'Ă' => 'a', 'ă' => 'a', 'Ą' => 'a', 'ą' => 'a', 'Ć' => 'c',
            'ć' => 'c', 'Ĉ' => 'c', 'ĉ' => 'c', 'Ċ' => 'c', 'ċ' => 'c', 'Č' => 'c',
            'č' => 'c', 'Ď' => 'd', 'ď' => 'd', 'Đ' => 'd', 'đ' => 'd', 'Ē' => 'e',
            'ē' => 'e', 'Ĕ' => 'e', 'ĕ' => 'e', 'Ė' => 'e', 'ė' => 'e', 'Ę' => 'e',
            'ę' => 'e', 'Ě' => 'e', 'ě' => 'e', 'Ĝ' => 'g', 'ĝ' => 'g', 'Ğ' => 'g',
            'ğ' => 'g', 'Ġ' => 'g', 'ġ' => 'g', 'Ģ' => 'g', 'ģ' => 'g', 'Ĥ' => 'h',
            'ĥ' => 'h', 'Ħ' => 'h', 'ħ' => 'h', 'Ĩ' => 'i', 'ĩ' => 'i', 'Ī' => 'i',
            'ī' => 'i', 'Ĭ' => 'i', 'ĭ' => 'i', 'Į' => 'i', 'į' => 'i', 'İ' => 'i',
            'ı' => 'i', 'Ĳ' => 'ij','ĳ' => 'ij','Ĵ' => 'j', 'ĵ' => 'j', 'Ķ' => 'k',
            'ķ' => 'k', 'ĸ' => 'k', 'Ĺ' => 'l', 'ĺ' => 'l', 'Ļ' => 'l', 'ļ' => 'l',
            'Ľ' => 'l', 'ľ' => 'l', 'Ŀ' => 'l', 'ŀ' => 'l', 'Ł' => 'l', 'ł' => 'l',
            'Ń' => 'n', 'ń' => 'n', 'Ņ' => 'n', 'ņ' => 'n', 'Ň' => 'n', 'ň' => 'n',
            'ŉ' => 'n', 'Ŋ' => 'n', 'ŋ' => 'n', 'Ō' => 'o', 'ō' => 'o', 'Ŏ' => 'o',
            'ŏ' => 'o', 'Ő' => 'o', 'ő' => 'o', 'Œ' => 'oe','œ' => 'oe','Ŕ' => 'r',
            'ŕ' => 'r', 'Ŗ' => 'r', 'ŗ' => 'r', 'Ř' => 'r', 'ř' => 'r', 'Ś' => 's',
            'ś' => 's', 'Ŝ' => 's', 'ŝ' => 's', 'Ş' => 's', 'ş' => 's', 'Š' => 's',
            'š' => 's', 'Ţ' => 't', 'ţ' => 't', 'Ť' => 't', 'ť' => 't', 'Ŧ' => 't',
            'ŧ' => 't', 'Ũ' => 'u', 'ũ' => 'u', 'Ū' => 'u', 'ū' => 'u', 'Ŭ' => 'u',
            'ŭ' => 'u', 'Ů' => 'u', 'ů' => 'u', 'Ű' => 'u', 'ű' => 'u', 'Ų' => 'u',
            'ų' => 'u', 'Ŵ' => 'w', 'ŵ' => 'w', 'Ŷ' => 'y', 'ŷ' => 'y', 'Ÿ' => 'y',
            'Ź' => 'z', 'ź' => 'z', 'Ż' => 'z', 'ż' => 'z', 'Ž' => 'z', 'ž' => 'z',
            'ſ' => 'z', 'Ə' => 'e', 'ƒ' => 'f', 'Ơ' => 'o', 'ơ' => 'o', 'Ư' => 'u',
            'ư' => 'u', 'Ǎ' => 'a', 'ǎ' => 'a', 'Ǐ' => 'i', 'ǐ' => 'i', 'Ǒ' => 'o',
            'ǒ' => 'o', 'Ǔ' => 'u', 'ǔ' => 'u', 'Ǖ' => 'u', 'ǖ' => 'u', 'Ǘ' => 'u',
            'ǘ' => 'u', 'Ǚ' => 'u', 'ǚ' => 'u', 'Ǜ' => 'u', 'ǜ' => 'u', 'Ǻ' => 'a',
            'ǻ' => 'a', 'Ǽ' => 'ae','ǽ' => 'ae','Ǿ' => 'o', 'ǿ' => 'o', 'ə' => 'e',
            'Ё' => 'jo','Є' => 'e', 'І' => 'i', 'Ї' => 'i', 'А' => 'a', 'Б' => 'b',
            'В' => 'v', 'Г' => 'g', 'Д' => 'd', 'Е' => 'e', 'Ж' => 'zh','З' => 'z',
            'И' => 'i', 'Й' => 'j', 'К' => 'k', 'Л' => 'l', 'М' => 'm', 'Н' => 'n',
            'О' => 'o', 'П' => 'p', 'Р' => 'r', 'С' => 's', 'Т' => 't', 'У' => 'u',
            'Ф' => 'f', 'Х' => 'h', 'Ц' => 'c', 'Ч' => 'ch','Ш' => 'sh','Щ' => 'sch',
            'Ъ' => '-', 'Ы' => 'y', 'Ь' => '-', 'Э' => 'je','Ю' => 'ju','Я' => 'ja',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
            'ж' => 'zh','з' => 'z', 'и' => 'i', 'й' => 'j', 'к' => 'k', 'л' => 'l',
            'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's',
            'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'c', 'ч' => 'ch',
            'ш' => 'sh','щ' => 'sch','ъ' => '-','ы' => 'y', 'ь' => '-', 'э' => 'je',
            'ю' => 'ju','я' => 'ja','ё' => 'jo','є' => 'e', 'і' => 'i', 'ї' => 'i',
            'Ґ' => 'g', 'ґ' => 'g', 'א' => 'a', 'ב' => 'b', 'ג' => 'g', 'ד' => 'd',
            'ה' => 'h', 'ו' => 'v', 'ז' => 'z', 'ח' => 'h', 'ט' => 't', 'י' => 'i',
            'ך' => 'k', 'כ' => 'k', 'ל' => 'l', 'ם' => 'm', 'מ' => 'm', 'ן' => 'n',
            'נ' => 'n', 'ס' => 's', 'ע' => 'e', 'ף' => 'p', 'פ' => 'p', 'ץ' => 'C',
            'צ' => 'c', 'ק' => 'q', 'ר' => 'r', 'ש' => 'w', 'ת' => 't', '™' => 'tm',
            'Ã' => 'A', 'Ð' => 'Dj', 'Ê' => 'E', 'Ñ' => 'N', 'Þ' => 'B', 'ã' => 'a',
            'ð' => 'o', 'ñ' => 'n', '#' => '-nr-' );
        // "Translate" multi byte characters to 'corresponding' ASCII characters
        $f = strtr($f, $replace_chars);
        // Convert special characters to a hyphen
        $f = str_replace(array(
            ' ', '!', '\\', '/', '\'', '`', '"', '~', '%', '|',
            '*', '$', '^', '(' ,')', '[', ']', '{', '}',
            '+', ',', ':' ,';', '<', '=', '>', '?', '|'), '-', $f);
        // Remove any non ASCII characters
        $f = preg_replace('/[^(\x20-\x7F)]*/','', $f);
        if ($type == 'file') {
            // Remove non-word chars (leaving hyphens and periods)
            $f = preg_replace('/[^\w\-\.]+/', '', $f);
            // Convert multiple adjacent dots into a single one
            $f = preg_replace('/[\.]+/', '.', $f);
        }
        else { // Do not allow periods, for instance for a Grav slug
            // Convert period to hyphen
            $f = str_replace('.', '-', $f);
            // Remove non-word chars (leaving hyphens)
            $f = preg_replace('/[^\w\-]+/', '', $f);
        }
        // Convert multiple adjacent hyphens into a single one
        $f = preg_replace('/[\-]+/', '-', $f);
        // Change into a lowercase string; BTW no need to use mb_strtolower() here ;)
        $f = strtolower($f);
        return $f;
    }
}
