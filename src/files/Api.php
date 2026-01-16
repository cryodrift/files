<?php

//declare(strict_types=1);

namespace cryodrift\files;

use cryodrift\files\db\Repository;
use cryodrift\fw\cli\ParamJson;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\FileHandler;
use cryodrift\fw\interface\Handler;
use cryodrift\fw\trait\WebHandler;

class Api implements Handler
{
    use WebHandler;

    protected string $rootdir;
    protected string $trashdir = 'trashbin/';

    public function __construct(Context $ctx, protected Repository $db, string $storagedir)
    {
        $this->rootdir = Core::normalizePath($storagedir . $ctx->user() . '/uploads/');
        $this->methodname = 'command';
        $this->db->skipexisting = true;
    }


    public function handle(Context $ctx): Context
    {
        return $this->handleWeb($ctx);
    }

    /**
     * @web delete files
     */
    protected function delete(Context $ctx, ParamJson $id): array
    {
        $out = [];
        $req = $ctx->request();
        if ($req->isPost()) {
            $ids = $id->column('id');
            Core::echo(__METHOD__, $ids);
            foreach ($ids as $id) {
                $uid = Core::pop(explode('_', $id, 3));
                $fid = $this->db->getId($uid);
                if ($fid) {
                    $dbfile = $this->db->getOne($fid);
                    if ($dbfile['deleted'] !== 'y') {
                        Core::echo(__METHOD__, $dbfile);
                        $currentpath = $this->rootdir . $dbfile['path'] . '/' . $dbfile['name'];
                        $destpath = $this->trashdir . $dbfile['path'];
                        $trashpath = $this->rootdir . $destpath . '/' . $dbfile['name'];
                        Core::dirCreate($trashpath);
                        rename($currentpath, $trashpath);
                        if (file_exists($trashpath)) {
                            $out[] = ['id' => $uid];
                            $data = ['path' => $destpath];
                            $this->db->runUpdate($fid, $this->db::TABLE, array_keys($data), $data);
                            $this->db->runvDelete($fid,$this->db::TABLE);
                            $this->db->ftsSave($this->db->getOne($fid));
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * @web undelete some files
     */
    protected function undelete(Context $ctx, ParamJson $id): array
    {
        $out = [];
        $req = $ctx->request();
        if ($req->isPost()) {
            $ids = $id->column('id');
            foreach ($ids as $id) {
                $uid = Core::pop(explode('_', $id, 3));
                $fid = $this->db->getId($uid);
                if ($fid) {
                    $dbfile = $this->db->getOne($fid);
                    if ($dbfile['deleted'] === 'y') {
                        $currentpath = $this->rootdir . $dbfile['path'] . '/' . $dbfile['name'];
                        $destpath = trim(str_replace($this->trashdir, '', $dbfile['path']), '/');
                        $oldpath = $this->rootdir . $destpath . '/' . $dbfile['name'];
                        Core::dirCreate($oldpath);
                        rename($currentpath, $oldpath);
                        if (file_exists($oldpath)) {
                            $out[] = ['id' => $uid];
                            $data = ['path' => $destpath];
                            $this->db->runUpdate($fid, $this->db::TABLE, array_keys($data), $data);
                            $this->db->runvUndelete($fid,$this->db::TABLE);
                            $this->db->ftsSave($this->db->getOne($fid));
                        }
                    }
                }
            }
        }
        return $out;
    }


    /**
     * @web move files to folder
     */
    protected function move(Context $ctx, ParamJson $id, ParamJson $value): array
    {
        $out = [];
        $req = $ctx->request();
        if ($req->isPost()) {
            $ids = $id->column('id');
            $dest = $value->multi(['move_path', 'move_dir']);
            $dir = Core::getValue('move_path', $dest, Core::getValue('move_dir', $dest));
            $dir = Core::cleanFilename($dir, true);
            foreach ($ids as $id) {
                $uid = Core::pop(explode('_', $id, 3));
                $fid = $this->db->getId($uid);
                if ($fid) {
                    $dbfile = $this->db->getOne($fid);
                    $filename = Core::getValue('name', $dbfile);
                    if ($filename) {
                        $path = trim($dbfile['path'], '/');
                        $currentpath = $this->rootdir . $path . '/' . $dbfile['name'];
                        $destpathname = $this->rootdir . $dir . '/' . $dbfile['name'];
                        Core::dirCreate($destpathname);
                        rename($currentpath, $destpathname);
                        Core::echo(__METHOD__, $currentpath, $destpathname);
                        if (file_exists($destpathname)) {
                            $data = ['path' => $dir];
                            $this->db->runUpdate($fid, $this->db::TABLE, array_keys($data), $data);
                            $this->db->ftsSave($this->db->getOne($fid));
                            $out[] = ['id' => $uid];
                        }
                    }
                }
            }
        }
        return $out;
    }


    /**
     * @eventhandler
     * @param string $eventdata pathname
     * @param bool $skip if exists in database
     * @return void
     */
    public function save(string $eventdata, bool $skip = true): void
    {
        $file = new \SplFileObject($eventdata);
        if ($skip && $this->db->getId($this::getUid($file))) {
            Core::echo(__METHOD__, 'file skipped', $file->getPathname());
        } else {
            try {
                $pathname = $file->getPathname();
                $fsize = $file->getSize();
                $data = [];
                $data['uid'] = $this::getUid($file);
                $data['name'] = $file->getFilename();
                $path = Core::normalizePath(dirname($pathname));
                $data['path'] = str_replace($this->rootdir, '', $path);
//                Core::echo(__METHOD__, $data,$this->rootdir, $path);
                $data['fext'] = strtolower($file->getExtension());
                $data['size'] = $fsize;
                $types = FileHandler::mimetypes();

                if (strpos(Core::getValue($data['fext'], $types), 'image/') !== false) {
                    $exif = $this->getExif($file);
                    if (!empty($exif)) {
                        $w = $exif['COMPUTED']['Width'];
                        $h = $exif['COMPUTED']['Height'];
                        $data['orientation'] = Core::getValue('Orientation', $exif);
                        $data['width'] = $w;
                        $data['height'] = $h;
                        $data['aratio'] = (max($w, $h) + 1) / (min($w, $h) + 1);

                        foreach ($exif as $key => $value) {
                            try {
                                json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                            } catch (\Exception $ex) {
                                unset($exif[$key]);
                            }
                        }

                        $exif = Core::removeKeys(['UndefinedTag:0x9AAA'], $exif);
//                    Core::echo(__METHOD__, $exif);
                        $data['exif'] = json_encode($exif, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
                    }
                }

                $data['filedate'] = date('Y-m-d H:i:s', filemtime($pathname));
                $id = $this->db->runInsert('files', $this->db::COLUMNS, $data);
                $this->db->ftsSave($this->db->getOne($id));
            } catch (\Exception $ex) {
                Core::echo(__METHOD__, $file->getPathname(), $ex);
            }
        }
    }

    public function getExif(\SplFileObject $file): array
    {
        $pathname = $file->getPathname();
        $exif = exif_read_data($pathname);
        if (is_array($exif)) {
            unset($exif['MakerNote']);
            return $exif;
        } else {
            return [];
        }
    }

    public static function getUid(\SplFileInfo $file): string
    {
//        return md5(Core::toLog([$file->getSize(), $file->getExtension(), $file->getBasename()]));
        return md5_file($file->getPathname());
    }


}
