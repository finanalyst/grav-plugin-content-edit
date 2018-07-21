<?php
namespace Grav\Plugin;

use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use Grav\Common\Markdown\Parsedown;
use Grav\Common\Markdown\ParsedownExtra;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ContentEditPlugin
 * @package Grav\Plugin
 */
class ContentEditPlugin extends Plugin
{
    protected $logfile;
    protected $renderer;

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
            'onTwigTemplatePaths' => ['onTwigTemplatePaths',0]
        ]);
        $path = DATA_DIR . 'content-edit' . DS . 'editing_' . date('Y-m-d') . '.yaml';
        $this->logfile = File::instance($path);
        require_once __DIR__ . '/embedded/php-diff-master/lib/Diff.php';
        switch ( $this->config->get('plugins.content-edit.editReport') ) {
            case 0:
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Html/SideBySide.php';
                $this->renderer = new \Diff_Renderer_Html_SideBySide;
                break;
            case 1:
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Html/Inline.php';
                $this->renderer = new \Diff_Renderer_Html_Inline;
                break;
            case 2:
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Text/Unified.php';
                $this->renderer = new \Diff_Renderer_Text_Unified;
                break;
            default:
                require_once __DIR__ . '/embedded/php-diff-master/lib/Diff/Renderer/Text/Context.php';
                $this->renderer = new \Diff_Renderer_Text_Context;
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

        $pages = $this->grav['pages'];
        $page = $pages->dispatch($post['page'], true);
        switch ($post['action']) {
            case 'ceTransferContent': // Transfer the md for the page
                $output = $page->rawMarkdown();
                break;
            case 'ceSaveContent': // Save markdown content
                $output = $this->saveContent($post, $page);
                break;
            case 'cePreviewContent': // Render received markdown and return HTML
                $output = $this->processMarkdown($post, $page);
                break;
            case 'ceFileUpload': // Handle a file (or image) upload
            $this->grav['dd']->dump($post);
                $output = $this->saveFile($post, $page);
                break;
            default:
                return;
        }
        $this->setHeaders();
        echo json_encode($output);
        exit;
    }

    public function saveFile($params, $post) {
            return 'stuffgggg';
    }

    /**
     * Process the Markdown content. Uses Parsedown or Parsedown Extra depending on configuration
     * Taken from Grav/Common/Page/Page.php and modified to process a supplied page
     *
     * @return string containing HTML
     */
    function processMarkdown($params, $page)
    {
        /** @var Config $config */
        $config = $this->grav['config'];
        $defaults = (array)$config->get('system.pages.markdown');

        if (isset($page->header()->markdown)) {
            $defaults = array_merge($defaults, $page->header()->markdown);
        }

        if (isset($this->header->markdown_extra)) {
            $markdown_extra = (bool)$this->header->markdown_extra;
        }

        // pages.markdown_extra is deprecated, but still check it...
        if (!isset($defaults['extra']) && (isset($markdown_extra) || $config->get('system.pages.markdown_extra') !== null)) {
            $defaults['extra'] = $markdown_extra ?: $config->get('system.pages.markdown_extra');
        }
        // Initialize the preferred variant of Parsedown
        if ($defaults['extra']) {
            $parsedown = new ParsedownExtra($this, $defaults);
        } else {
            $parsedown = new Parsedown($this, $defaults);
        }

//        $html = $page->processMarkdown();
        $html = $parsedown->text($page->content());
        return $html;
    }

    function saveContent($params, $page) {
        // save to page route
        $new = $params['content'];
        $old=$page->rawMarkdown();
        $page->rawMarkdown($new);
        $page->save();
        // log user save
        // Options for generating the diff
        $options = array(
            //'ignoreWhitespace' => true,
            //'ignoreCase' => true,
        );
        // Initialize the diff class
        $diff = new \Diff(explode("\n", $old), explode("\n",$new), $options);
        $rendered=$diff->render($this->renderer) ;
        $record = [ 'date' => date('D d H:i:s'), 'user' => $this->grav['user']->username , 'route' => $params['page'], 'diff' => $rendered ];
        $this->logfile->save($this->logfile->content() . Yaml::dump( [ $record ] ) );
        return 'ok';
    }

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

}
