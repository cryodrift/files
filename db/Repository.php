<?php

//declare(strict_types=1);

namespace cryodrift\files\db;


use cryodrift\fw\trait\DbHelper;
use PDO;
use cryodrift\fw\Context;
use cryodrift\fw\Core;
use cryodrift\fw\trait\DbHelperCreate;
use cryodrift\fw\trait\DbHelperFts;
use cryodrift\fw\trait\DbHelperMigrate;
use cryodrift\fw\trait\DbHelperTrigger;
use cryodrift\fw\trait\DbHelperVDelete;

class Repository
{
    use DbHelper;
    use DbHelperFts;
    use DbHelperVDelete;
    use DbHelperCreate;
    use DbHelperMigrate;
    use DbHelperTrigger;

    public array $datafiles = [];
    const string COLUMNS = 'id,uid,name,path,fext,size,exif,filedate,aratio,width,height,orientation,deleted,changed,created';
    const string SEARCHCOLUMNS = 'id,uid,name,path,fext,size,exif,filedate,aratio,width,height,orientation,deleted,changed,created';
    protected string $fts_query;
    const string TABLE = 'files';

    public function __construct(Context $ctx, string $storagedir)
    {
        $connectionstring = $storagedir . $ctx->user() . '/';
        $this->connect('sqlite:' . $connectionstring . 'files.sqlite');
        $this->ftsSetup($connectionstring, self::TABLE, 'fts'.self::TABLE, explode(',', self::SEARCHCOLUMNS));
        $this->ftsAttach();
    }

    public function getId(string $uid): string
    {
        $res = $this->query('select id from files where uid=:uid', ['uid' => $uid]);
        return Core::getValue('id', Core::pop($res));
    }

    public function getAll(int $page = 0, bool $withdeleted = false, int $limit = 20): array
    {
        $sql = Core::fileReadOnce(__DIR__ . '/s_files.sql');
        $stmt = $this->pdo->prepare($sql);
        $deleted = $withdeleted ? 'y' : 'n';
        $stmt->bindValue(':withdeleted', $deleted);
        self::bindPage($stmt, $page, $limit);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllPath(bool $withdeleted = false): array
    {
        $sql = Core::fileReadOnce(__DIR__ . '/s_pathes.sql');
        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':withdeleted', $withdeleted ? 'y' : 'n');
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOne(string $id): array
    {
        return Core::pop($this->query('select * from files where id=:id', ['id' => $id]));
    }

    public function delete(string $id): void
    {
        $file = $this->getOne($id);
        if (Core::getValue('deleted', $file) === 'y') {
            $sql = "DELETE from FILES WHERE id = :id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->bindValue(':id', $id);
//            $this->ftsSave(['deleted' => null, 'id' => $id]);
            $stmt->execute();
        }
    }


}
