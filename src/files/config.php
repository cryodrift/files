<?php

//declare(strict_types=1);

/**
 * @env USER_STORAGEDIRS="G_ROOTDIR.cryodrift/users/"
 */

use cryodrift\fw\Core;

if (!isset($ctx)) {
    $ctx = Core::newContext(new \cryodrift\fw\Config());
}

$cfg = $ctx->config();

\cryodrift\fw\Events::addConfig($cfg, \cryodrift\uploader\Api::EVENT_UPLOAD, \cryodrift\files\Api::class, 'save', ['skip' => false]);

if (Core::env('USER_USEAUTH')) {
    \cryodrift\user\Auth::addConfigs($ctx, [
      'files',
    ]);
}

\cryodrift\fw\Router::addConfigs($ctx, [
  'files/cli' => \cryodrift\files\Cli::class,
], \cryodrift\fw\Router::TYP_CLI);

\cryodrift\fw\Router::addConfigs($ctx, [
  'files' => \cryodrift\files\Web::class,
  'files/api' => \cryodrift\files\Api::class,
], \cryodrift\fw\Router::TYP_WEB);


$cfg[\cryodrift\files\db\Repository::class] = \cryodrift\files\Web::class;
$cfg[\cryodrift\files\Cli::class] = \cryodrift\files\Web::class;
$cfg[\cryodrift\files\Api::class] = \cryodrift\files\Web::class;
$cfg[\cryodrift\files\Web::class] = [
  'storagedir' => Core::env('USER_STORAGEDIRS'),
  'duration' => 60 * 60 * 24 * 7 * 10,
  'maxthumbsize' => 200000,
  'templatedir_shared' => \cryodrift\fw\Main::$rootdir . 'shared/ui/'
];
