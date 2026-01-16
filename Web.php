<?php

//declare(strict_types=1);

namespace cryodrift\files;

use cryodrift\files\db\Repository;
use cryodrift\fw\Response;
use cryodrift\shared\ui\search\Cmp as SearchComponent;
use cryodrift\fw\Config;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\FakeFileInfo;
use cryodrift\fw\FileHandler;
use cryodrift\fw\HtmlUi;
use cryodrift\fw\tool\image\Thumb;
use cryodrift\fw\trait\WebHandler;

class Web
{
    use WebHandler;

    protected string $rootdir;
    public string $templatedir = __DIR__;
    public string $templatedir_shared = __DIR__;

    public function __construct(Context $ctx, protected Repository $db, string $storagedir, protected int $duration, protected int $maxthumbsize, Config $config)
    {
        $this->templatedir_shared = Core::getValue('templatedir_shared', $config, __DIR__);
        $this->rootdir = $storagedir . $ctx->user() . '/uploads/';
        $this->outHelperAttributes([
          'ROUTE' => $ctx->request()->route(),
          'PATH' => '/' . $ctx->request()->path()->getString(),
          'loading' => '<h2>Loading...</h2>'
        ]);
    }

    public function handle(Context $ctx): Context
    {
        return $this->handleWeb($ctx);
    }

    /**
     * get a file
     * @web
     */
    public function file(Context $ctx): Context
    {
        $file_id = $ctx->request()->vars('file_id', '');
        $format = $ctx->request()->vars('format');
        $mode = $ctx->request()->vars('mode');
        $data = $this->db->getOne($file_id);
        $file = new FakeFileInfo(-1);
        $path = Core::getValue('path', $data);
        if ($path) {
            $path .= '/';
        }
        $pathname = $this->rootdir . $path . Core::getValue('name', $data);
//        Core::echo(__METHOD__, $pathname);
        switch ($format) {
            case 'thumb':
                try {
                    if (file_exists($pathname)) {
                        $bin = $this->getThumb(new \SplFileObject($pathname), 1000);
                    }
                    break;
                } catch (\RuntimeException $ex) {
                    Core::echo(__METHOD__, $ex);
                }
                break;
            default:
                if (file_exists($pathname)) {
//                    Core::echo(__METHOD__, $pathname);
                    $bin = file_get_contents($pathname);
                }
        }

        if ($bin) {
            $file->fwrite($bin);
            $fext = Core::getValue('fext', $data);
            $types = FileHandler::mimetypes();
            $type = Core::getValue($fext, $types);
            $filename = basename(Core::getValue('name', $data));
            $file->setFextension($fext);
            $headers = FileHandler::getHeaders($file, $this->duration);
            $ctx->response()->setHeaders([...$headers]);
            if ($mode !== 'inline') {
                $h = FileHandler::getDownloadHeader($type, $filename);
                $ctx->response()->setHeaders([...$ctx->response()->getHeaders(), ...$h]);
            }
            $ctx->response()->setContent($bin);
        }
        return $ctx;
    }

    /**
     * @web files search form HtmlUi
     */
    public function search(Context $ctx): HtmlUi
    {
        return new SearchComponent($ctx, 'files_search', 'files', ['files_search', 'memo_id', 'files_menu'], array_map(fn($a) => $a, ['fext:jpg', 'fext:pdf', 'name:']));
    }

    /**
     * @web shows a list of files
     */
    protected function filelist(Context $ctx): HtmlUi
    {
        $files_page = $ctx->request()->vars('files_page', 0);
        $files_menu = $ctx->request()->vars('files_menu');
        $data = $this->db->getAll($files_page, $files_menu === 'deleted');
        if (count($data)) {
            return $this->render_files($ctx, $data);
        } else {
            return new HtmlUi();
        }
    }

    /**
     * @web mode dropdown filter
     */
    protected function modefilter(Context $ctx): HtmlUi
    {
        $data = [
          ['value' => 'Normal', 'name' => 'normal', 'data-click' => 'remclass|button[name=files_delete]|g-dh addclass|button[name=files_undelete]|g-dh'],
          ['value' => 'Deleted', 'name' => 'deleted', 'data-click' => 'addclass|button[name=files_delete]|g-dh remclass|button[name=files_undelete]|g-dh'],
          ['value' => 'Attached', 'name' => 'attached', 'data-click' => 'addclass|button[name=files_delete]|g-dh addclass|button[name=files_undelete]|g-dh'],
//          ['value' => 'Versions', 'name' => $this->db::MODE_VERSIONS]
        ];
        $files_menu = $ctx->request()->vars('files_menu', 'normal', true);
        $files_menu = array_reduce($data, fn($carry, $value) => $carry .= Core::getValue('name', $value) === $files_menu ? Core::getValue('value', $value) : '');
        $data = HtmlUi::addQuery($ctx, $data, ['name' => 'files_menu'], ['file_id', 'files_menu']);
        $data = HtmlUi::makeActive($data, $ctx->request()->vars('files_menu'), 'name');
        return HtmlUi::fromFile($this->templatedir_shared . 'menu.html', 'files_menu')->fromBlock('files_menu')->setAttributes([
            'data-loader' => 'files_menu||outer files files_search|files_search',
            'id' => 'files_menu',
            'selected' => $files_menu,
            'list' => $data
          ]
        );
    }

    /**
     * @web file viewer iframe
     */
    protected function fileviewer(Context $ctx, Repository $db): HtmlUi
    {
        $file = $db->getOne($ctx->request()->vars('file_id'));
        $data = HtmlUi::addQuery($ctx, $file, [], ['file_id'], false);
        $img = HtmlUi::fromString('<iframe class="g-h g-w8 {{g-box}}" data-click="hide|#filemodal" src="/files/file/{{name}}{{query}}&mode=inline" ></iframe>')->setAttributes($data);
        return $img;
    }

    /**
     * @web dirs
     */
    protected function dirs(Repository $db): HtmlUi
    {
        $dirlist = [];
        foreach ($db->getAllPath() as $value) {
            $path = Core::getValue('path', $value);
            if ($path) {
                $dirlist[] = ['dir' => $path];
            }
        }
        return HtmlUi::fromString('<option value="{{dir}}">{{dir}}</option>', 'dirlist')
          ->setAttributes([
            'dirlist' => $dirlist
          ]);
    }


    private function getThumb(\SplFileObject $file, int $width = 1000): string
    {
        $pathname = $file->getPathname();
        $fsize = $file->getSize();
        $thumb = exif_thumbnail($pathname);

        if (!$thumb && $fsize >= $this->maxthumbsize) {
            $thumb = $this->createThumb($file, $width);
        }
        return $thumb;
    }

    private function createThumb(\SplFileObject $file, int $w): string
    {
        try {
            $t = new Thumb($file);
            $t->setWidth($w);
            return $t->generateThumb($file->getPathname());
        } catch (\Exception $ex) {
            if ($ex->getCode() == 100) {
                return file_get_contents($file->getPathname());
            } else {
                Core::echo(__METHOD__, $ex);
                return '';
            }
        }
    }

    /**
     * @return HtmlUi
     */
    public function render_files(Context $ctx, array $data): HtmlUi
    {
//        Core::echo(__METHOD__,$data);
        $data = Core::addData($data, function ($v) use ($ctx) {
            //            Core::echo(__METHOD__,$links);
            if (Core::getValue('width', $v) || $v['fext'] === 'png') {
                $v['file_typ'] = 'thumb';
                $d = HtmlUi::addQuery($ctx, $v, ['id' => 'file_id', 'file_typ' => 'file_typ'], ['file_id', 'file_typ'], false);
                $v['file_img'] = HtmlUi::fromFile('files/ui/files/img.html')->setAttributes($d);
            } else {
                $v['file_img'] = $v['fext'] . ' icon here!';
            }
            $v['date'] = Core::getValue('filedate', $v);
            $uplpath = $this->rootdir . $v['path'] . $v['name'];
            $tmppath = realpath($uplpath);
//            Core::echo(__METHOD__, $tmppath, $uplpath);
            if ($tmppath) {
                $v['upload_id'] = md5($tmppath);
            }

            return $v;
        });

        $data = HtmlUi::addQuery($ctx, $data, ['id' => 'file_id'], ['file_id', 'memo_id', 'files_page', 'files_search', 'files_menu']);
        return HtmlUi::fromFile('files/ui/files/row.html', 'files')->fromBlock('files')
          ->setAttributes(['files_block' => $data, ...$ctx->request()->vars()]);
    }

}
