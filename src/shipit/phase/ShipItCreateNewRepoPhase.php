<?hh // strict
/**
 * Copyright (c) Facebook, Inc. and its affiliates.
 *
 * This source code is licensed under the MIT license found in the
 * LICENSE file in the root directory of this source tree.
 */

/**
 * This file was moved from fbsource to www. View old history in diffusion:
 * https://fburl.com/4zrm06z0
 */
namespace Facebook\ShipIt;

final class ShipItCreateNewRepoPhase extends ShipItPhase {
  private ?string $sourceCommit = null;
  private ?string $outputPath = null;

  public function __construct(
    private (function(ShipItChangeset): ShipItChangeset) $filter,
    private shape('name' => string, 'email' => string) $committer,
  ) {
    $this->skip();
  }

  <<__Override>>
  protected function isProjectSpecific(): bool {
    return false;
  }

  <<__Override>>
  public function getReadableName(): string {
    return 'Create a new git repo with an initial commit';
  }

  <<__Override>>
  public function getCLIArguments(): ImmVector<ShipItCLIArgument> {
    return ImmVector {
      shape(
        'long_name' => 'create-new-repo',
        'description' =>
          'Create a new git repository with a single commit, then exit',
        'write' => $_ ==> $this->unskip(),
      ),
      shape(
        'long_name' => 'create-new-repo-from-commit::',
        'description' =>
          'Like --create-new-repo, but at a specified source commit',
        'write' => $rev ==> {
          $this->sourceCommit = $rev;
          $this->unskip();
          return true;
        },
      ),
      shape(
        'long_name' => 'create-new-repo-output-path::',
        'description' =>
          'When using --create-new-repo or --create-new-repo-from-commit, '.
          'create the new repository in this directory',
        'write' => $path ==> $this->outputPath = $path,
      ),
      shape( // deprecated, renamed for consistency with verify
        'long_name' => 'special-create-new-repo',
        'replacement' => 'create-new-repo',
      ),
    };
  }

  <<__Override>>
  public function runImpl(ShipItBaseConfig $config): void {
    $output = $this->outputPath;
    try {
      if ($output === null) {
        $temp_dir = self::createNewGitRepo(
          $config,
          $this->filter,
          $this->committer,
          $this->sourceCommit,
        );
        // Do not delete the output directory.
        $temp_dir->keep();
        $output = $temp_dir->getPath();
      } else {
        self::createNewGitRepoAt(
          $config,
          $output,
          $this->filter,
          $this->committer,
          $this->sourceCommit,
        );
      }
    } catch (\Exception $e) {
      ShipItLogger::err("  Error: %s\n", $e->getMessage());
      exit(1);
    }

    print('  New repository created at '.$output."\n");
    exit(0);
  }

  private static function initGitRepo(
    string $path,
    shape('name' => string, 'email' => string) $committer,
  ): void {
    self::execSteps(
      $path,
      ImmVector {
        ImmVector {'git', 'init'},
        ImmVector {'git', 'config', 'user.name', $committer['name']},
        ImmVector {'git', 'config', 'user.email', $committer['email']},
      },
    );
  }

  public static function createNewGitRepo(
    ShipItBaseConfig $config,
    (function(ShipItChangeset): ShipItChangeset) $filter,
    shape('name' => string, 'email' => string) $committer,
    ?string $revision = null,
  ): ShipItTempDir {
    $temp_dir = new ShipItTempDir('git-with-initial-commit');
    self::createNewGitRepoImpl(
      $temp_dir->getPath(),
      $config,
      $filter,
      $committer,
      $revision,
    );
    return $temp_dir;
  }

  public static function createNewGitRepoAt(
    ShipItBaseConfig $config,
    string $output_dir,
    (function(ShipItChangeset): ShipItChangeset) $filter,
    shape('name' => string, 'email' => string) $committer,
    ?string $revision = null,
  ): void {
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    if (\file_exists($output_dir)) {
      throw new ShipItException("path '$output_dir' already exists");
    }
    /* HH_IGNORE_ERROR[2049] __PHPStdLib */
    /* HH_IGNORE_ERROR[4107] __PHPStdLib */
    \mkdir($output_dir, 0755, /* recursive = */ true);

    try {
      self::createNewGitRepoImpl(
        $output_dir,
        $config,
        $filter,
        $committer,
        $revision,
      );
    } catch (\Exception $e) {
      (
        new ShipItShellCommand(null, 'rm', '-rf', $output_dir)
      )->runSynchronously();
      throw $e;
    }
  }

  private static function createNewGitRepoImpl(
    string $output_dir,
    ShipItBaseConfig $config,
    (function(ShipItChangeset): ShipItChangeset) $filter,
    shape('name' => string, 'email' => string) $committer,
    ?string $revision = null,
  ): void {
    $source = ShipItRepo::typedOpen(
      ShipItSourceRepo::class,
      $config->getSourcePath(),
      $config->getSourceBranch(),
    );

    print("  Exporting...\n");
    $export = $source->export($config->getSourceRoots(), $revision);
    $export_dir = $export['tempDir'];
    $rev = $export['revision'];

    print("  Creating unfiltered commit...\n");

    self::initGitRepo($export_dir->getPath(), $committer);
    self::execSteps(
      $export_dir->getPath(),
      ImmVector {
        ImmVector {'git', 'add', '.', '-f'},
        ImmVector {
          'git',
          'commit',
          '-m',
          'initial unfiltered commit',
        },
      },
    );

    print("  Filtering...\n");
    $exported_repo = ShipItRepo::typedOpen(
      ShipItSourceRepo::class,
      $export_dir->getPath(),
      'master',
    );
    $changeset = $exported_repo->getChangesetFromID('HEAD');
    invariant($changeset !== null, 'got a null changeset :/');
    $changeset = $changeset->withID($rev);
    $changeset = $filter($changeset)->withSubject('Initial commit');
    $changeset = ShipItSync::addTrackingData($changeset, $rev);

    if ($config->isVerboseEnabled()) {
      $changeset->dumpDebugMessages();
    }

    print("  Creating new repo...\n");

    self::initGitRepo($output_dir, $committer);
    $filtered_repo = ShipItRepo::typedOpen(
      ShipItDestinationRepo::class,
      $output_dir,
      '--orphan='.$config->getDestinationBranch(),
    );
    $filtered_repo->commitPatch($changeset);

    print("  Cleaning up...\n");
    // As we're done with these and nothing else has the random paths, the lock
    // files aren't needed
    foreach (vec[$export_dir->getPath(), $output_dir] as $repo) {
      $lock_file = ShipItRepo::getLockFilePathForRepoPath($repo);
      /* HH_IGNORE_ERROR[2049] __PHPStdLib */
      /* HH_IGNORE_ERROR[4107] __PHPStdLib */
      if (\file_exists($lock_file)) {
        /* HH_IGNORE_ERROR[2049] __PHPStdLib */
        /* HH_IGNORE_ERROR[4107] __PHPStdLib */
        \unlink($lock_file);
      }
    }
  }

  private static function execSteps(
    string $path,
    ImmVector<ImmVector<string>> $steps,
  ): void {
    foreach ($steps as $step) {
      /* HH_FIXME[4128] Use ShipItShellCommand */
      ShipItUtil::shellExec(
        $path,
        /* stdin = */ null,
        ShipItUtil::DONT_VERBOSE,
        ...$step
      );
    }
  }
}
