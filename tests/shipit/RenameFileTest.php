    $temp_dir = new ShipItTempDir('rename-file-test');
      $temp_dir->getPath().'/initial.txt',
      $temp_dir->getPath(),
    );
    $this->configureHg($temp_dir);

    $this->execSteps(
      $temp_dir->getPath(),
      $temp_dir->getPath(),
    shell_exec('rm -rf '.escapeshellarg($temp_dir->getPath()));