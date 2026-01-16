<?php

//declare(strict_types=1);

namespace cryodrift\files;


use PDO;
use cryodrift\files\db\Repository;
use cryodrift\fw\cli\CliUi;
use cryodrift\fw\cli\ParamMulti;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\interface\Handler;
use cryodrift\fw\interface\Installable;
use cryodrift\fw\trait\CliHandler;

class Cli implements Handler, Installable
{
    use CliHandler;

    protected string $rootdir;

    public function __construct(Context $ctx, protected Repository $db, string $storagedir)
    {
        $this->rootdir = $storagedir . $ctx->user() . '/uploads/';
    }

    public function handle(Context $ctx): Context
    {
        return $this->handleCli($ctx);
    }

    /**
     * @cli create schema
     * @cli params: [-s] (schema)
     * @cli params: [-i] (indexes)
     * @cli params: [-t] (triggers)
     * @cli params: [-a] (all)
     */
    protected function createdb(bool $a = false, bool $s = false, bool $i = false, bool $t = false): string
    {
        if ($a) {
            $s = $i = $t = true;
        }
        Core::echo(__METHOD__, $s, $i, $t);
        $out = '';
        if ($s) {
            $out .= $this->db->migrate();
        }
        if ($i) {
            $out .= $this->db->migrate('c_indexes.sql');
        }
        if ($t) {
//            $out .= $this->db->migrate('c_triggers.sql','--END;');
            $out .= $this->db->triggerCreate([Repository::TABLE]);
        }

        return $out;
    }

    /**
     * @cli recreate search db
     */
    protected function initsearch(Context $ctx): string
    {
        $this->db->ftsRecreate();
        return 'Done';
    }

    /**
     * @cli list files
     * @cli param: [-page=(int)]
     */
    protected function list(int $page = 0): array
    {
        return $this->db->getAll($page);
    }

    /**
     * @cli save file(s) to db
     * @cli params: -path="" (dir or file)
     * @cli params: [-skip] (skip if exists in db)
     * @cli params: [-pattern=""] (filter pattern "*.jpg|*.gif")
     */
    protected function import(Api $api, string $path, ?ParamMulti $pattern = null, bool $skip = false): string
    {
        if ($path) {
            $this->db->transaction();

            if (is_dir($path)) {
                try {
                    $filter = function (\SplFileInfo $file) use ($pattern): bool {
                        if ($file->isFile() && $pattern) {
                            foreach ($pattern as $pat) {
                                if (str_starts_with($pat, '-')) {
                                    if (fnmatch(trim($pat, '-'), $file->getFilename(), FNM_CASEFOLD)) {
                                        return false;
                                    } else {
                                        return true;
                                    }
                                } elseif (fnmatch($pat, $file->getFilename(), FNM_CASEFOLD)) {
                                    return true;
                                }
                            }
                            return false;
                        } else {
                            return true;
                        }
                    };
                    $files = Core::dirList($path, $filter);

                    CliUi::withProgressBar($files, function (\SplFileInfo $file) use ($skip, $api) {
                        $fobj = null;
                        if (!$file->isDir()) {
                            $trydisk = 10;
                            do {
                                try {
                                    $api->save($file->getPathname(), $skip);
                                } catch (\Exception $ex) {
                                    Core::echo(__METHOD__, $file->getPathname(), $ex);
                                    $msg = $ex->getMessage();
                                    if ($trydisk < 0 || str_contains($msg, 'Permission denied')) {
                                        return;
                                    } else {
                                        // if disk is in sleepmode wait for it
                                        $trydisk--;
                                        usleep(250);
                                    }
                                }
                            } while (!$fobj);
                        }
                    });
                } catch (\Exception $hl) {
                    $this->db->rollback();
                    Core::echo(__METHOD__, $hl);
                }
            } elseif (is_file($path)) {
                try {
                    $api->save($path, $skip);
                } catch (\Exception $hl) {
                    $this->db->rollback();
                    Core::echo(__METHOD__, $hl);
                }
            } else {
                return '';
            }
            $this->db->commit();
            $this->db->ftsRecreate();
            return 'Done';
        }
        return '';
    }


    /**
     * @cli import from qmemo old files db
     * @cli param: -dbpath (pathname of sqlite file)
     *
     */
    protected function importdb(string $dbpath): void
    {
        $this->db->transaction();
        $pdo = new PDO('sqlite:' . $dbpath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $files = $pdo->query('select * from files')->fetchAll(PDO::FETCH_ASSOC);

        try {
            foreach ($files as $file) {
                $data = $this->insertdata($file);
                $this->db->runInsert('files', $this->db::COLUMNS, $data);
            }
            $this->db->commit();
        } catch (\Exception $hl) {
            $this->db->rollback();
            Core::echo(__METHOD__, $hl);
        }
    }

    /**
     * @cli test something
     * @cli param: [-mode](import)
     */
    protected function test(string $mode = '', string $id = '', string $query = ''): array
    {
        $out = [];
        $this->db->ftsAttach();
        if ($query) {
            $out[] = $this->db->ftsSearch($query);
        }
        if ($id) {
            $out[] = $this->db->ftsGetEntry($id);
        }
        if ($mode === 'import') {
            $rows = $this->db->getAll(0, false, 20000);
            foreach ($rows as $row) {
                $row['deleted'] = null;
                $this->db->skipexisting = true;
                $this->db->ftsSave($row);
            }
//            $out[] = $rows;
        }
        return $out;
    }

    private function insertdata(array $data): array
    {
        Core::echo(__METHOD__, $data['name']);
        $data['uid'] = md5($data['name']);
        $data['aratio'] = (max($data['width'], $data['height']) + 1) / (min($data['width'], $data['height']) + 1);
        return $data;
    }

    /**
     * @cli installer
     */
    public function install(Context $ctx): array
    {
        return [
          $this->createdb(true),
          $this->initsearch($ctx)
        ];
    }
}
